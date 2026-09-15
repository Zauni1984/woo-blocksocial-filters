<?php
/**
 * Bulk indexer.
 *
 * Indexing avoids wc_get_product() entirely. A batch is resolved with a handful
 * of set based queries (posts, postmeta, term relationships, variations) and
 * written back with chunked multi row inserts, so tens of thousands of products
 * can be indexed without exhausting memory or wall clock.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Index;

use BlockSocial\Filters\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and refreshes the filter index.
 */
class Indexer {

	public const STATE_OPTION = 'bsf_index_state';
	public const CRON_QUEUE   = 'bsf_process_queue';

	/** Meta keys always pulled for the product table. */
	private const CORE_META = array(
		'_price',
		'_regular_price',
		'_sale_price',
		'_stock_status',
		'_stock',
		'_sku',
		'_weight',
		'_length',
		'_width',
		'_height',
		'_wc_average_rating',
		'_wc_review_count',
		'total_sales',
		'_downloadable',
		'_virtual',
	);

	/** @var array<string,array<string,int>> Taxonomy => slug => term_id. */
	private $slug_cache = array();

	/**
	 * Register cron and queue hooks.
	 */
	public function hooks(): void {
		// The schedule has to exist before anything is scheduled against it.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval

		add_action( self::CRON_QUEUE, array( $this, 'process_queue' ) );
		add_action( 'shutdown', array( $this, 'flush_queue_inline' ), 100 );

		if ( ! wp_next_scheduled( self::CRON_QUEUE ) ) {
			wp_schedule_event( time() + 60, 'bsf_minute', self::CRON_QUEUE );
		}
	}

	/**
	 * Register a one minute schedule for queue draining.
	 *
	 * @param array<string,array> $schedules Existing schedules.
	 *
	 * @return array<string,array>
	 */
	public function add_cron_schedule( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules['bsf_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (BlockSocial Filters)', 'woo-blocksocial-filters' ),
		);

		return $schedules;
	}

	/* ---------------------------------------------------------------------
	 * Progress state
	 * ------------------------------------------------------------------ */

	/**
	 * Current rebuild state.
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		$defaults = array(
			'status'    => 'idle',
			'total'     => 0,
			'processed' => 0,
			'last_id'   => 0,
			'started'   => 0,
			'updated'   => 0,
			'message'   => '',
		);

		$state = get_option( self::STATE_OPTION, array() );

		return array_merge( $defaults, is_array( $state ) ? $state : array() );
	}

	/**
	 * Persist state changes.
	 *
	 * @param array<string,mixed> $changes Partial state.
	 *
	 * @return array<string,mixed> New state.
	 */
	private function set_state( array $changes ): array {
		$state            = array_merge( $this->state(), $changes );
		$state['updated'] = time();

		update_option( self::STATE_OPTION, $state, false );

		return $state;
	}

	/**
	 * Begin a full rebuild.
	 *
	 * @param bool $truncate Whether to empty the tables first.
	 *
	 * @return array<string,mixed> State.
	 */
	public function start( bool $truncate = true ): array {
		if ( ! Schema::installed() ) {
			Schema::install();
		}

		if ( $truncate ) {
			Schema::truncate();
		}

		return $this->set_state(
			array(
				'status'    => 'running',
				'total'     => $this->count_products(),
				'processed' => 0,
				'last_id'   => 0,
				'started'   => time(),
				'message'   => '',
			)
		);
	}

	/**
	 * Abort a running rebuild.
	 *
	 * @return array<string,mixed> State.
	 */
	public function cancel(): array {
		return $this->set_state( array( 'status' => 'cancelled' ) );
	}

	/**
	 * Total number of indexable products.
	 */
	public function count_products(): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish','private')"
		);
	}

	/**
	 * Process the next batch of a running rebuild.
	 *
	 * @param int $limit Batch size.
	 *
	 * @return array<string,mixed> State after the batch.
	 */
	public function run_batch( int $limit = 250 ): array {
		global $wpdb;

		$state = $this->state();

		if ( 'running' !== $state['status'] ) {
			return $state;
		}

		$limit = max( 10, min( 2000, $limit ) );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'product' AND post_status IN ('publish','private') AND ID > %d
				 ORDER BY ID ASC LIMIT %d",
				(int) $state['last_id'],
				$limit
			)
		);

		$ids = array_map( 'absint', (array) $ids );

		if ( empty( $ids ) ) {
			Cache::flush();

			return $this->set_state(
				array(
					'status'  => 'done',
					'message' => __( 'Index complete.', 'woo-blocksocial-filters' ),
				)
			);
		}

		$this->index_products( $ids );

		return $this->set_state(
			array(
				'processed' => (int) $state['processed'] + count( $ids ),
				'last_id'   => (int) max( $ids ),
			)
		);
	}

	/**
	 * Index everything in one go. Only safe from CLI.
	 *
	 * @param int           $batch    Batch size.
	 * @param callable|null $progress Optional progress callback.
	 */
	public function run_all( int $batch = 500, ?callable $progress = null ): void {
		$this->start( true );

		do {
			$state = $this->run_batch( $batch );

			if ( $progress ) {
				$progress( $state );
			}
		} while ( 'running' === $state['status'] );
	}

	/* ---------------------------------------------------------------------
	 * Incremental updates
	 * ------------------------------------------------------------------ */

	/**
	 * Queue products for a deferred reindex.
	 *
	 * @param int[] $ids Product ids.
	 */
	public function enqueue( array $ids ): void {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( empty( $ids ) ) {
			return;
		}

		$now    = time();
		$values = array();

		foreach ( array_unique( $ids ) as $id ) {
			$values[] = $wpdb->prepare( '(%d,%d)', $id, $now );
		}

		$table = Schema::table_queue();

		$wpdb->query( // phpcs:ignore WordPress.DB
			"INSERT INTO {$table} (product_id, queued_at) VALUES " . implode( ',', $values ) .
			' ON DUPLICATE KEY UPDATE queued_at = VALUES(queued_at)'
		);
	}

	/**
	 * Drain the queue (cron).
	 *
	 * @param int $limit Maximum products per run.
	 */
	public function process_queue( int $limit = 200 ): int {
		global $wpdb;

		if ( ! Schema::installed() ) {
			return 0;
		}

		$table = Schema::table_queue();
		$limit = max( 1, min( 1000, $limit ) );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT product_id FROM {$table} ORDER BY queued_at ASC LIMIT %d", $limit )
		);

		$ids = array_filter( array_map( 'absint', (array) $ids ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$this->index_products( $ids );

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE product_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB

		Cache::flush();

		return count( $ids );
	}

	/**
	 * Drain a small number of queued products at the end of admin requests so
	 * edits show up immediately even when cron is unreliable.
	 */
	public function flush_queue_inline(): void {
		if ( wp_doing_cron() || ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( 'running' === $this->state()['status'] ) {
			return;
		}

		$this->process_queue( 20 );
	}

	/**
	 * Remove a product from the index.
	 *
	 * @param int $product_id Product id.
	 */
	public function delete_product( int $product_id ): void {
		global $wpdb;

		$product_id = absint( $product_id );

		if ( ! $product_id || ! Schema::installed() ) {
			return;
		}

		foreach ( array( Schema::table_index(), Schema::table_product(), Schema::table_numeric(), Schema::table_queue() ) as $table ) {
			$wpdb->delete( $table, array( 'product_id' => $product_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		}
	}

	/* ---------------------------------------------------------------------
	 * The actual indexing
	 * ------------------------------------------------------------------ */

	/**
	 * Index a batch of products.
	 *
	 * @param int[] $ids Product ids.
	 */
	public function index_products( array $ids ): void {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) || ! Schema::installed() ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$posts = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT ID, post_title, post_excerpt, post_status, post_date_gmt, menu_order
				 FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
				$ids
			),
			ARRAY_A
		);

		if ( empty( $posts ) ) {
			foreach ( $ids as $id ) {
				$this->delete_product( $id );
			}

			return;
		}

		$meta       = $this->fetch_meta( $ids );
		$terms      = $this->fetch_terms( $ids );
		$variations = $this->fetch_variations( $ids );
		$languages  = apply_filters( 'bsf_index_languages', array(), $ids );

		// Clear previous rows for this batch before rewriting them.
		$index_table   = Schema::table_index();
		$numeric_table = Schema::table_numeric();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$index_table} WHERE product_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$numeric_table} WHERE product_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB

		$product_rows = array();
		$index_rows   = array();
		$numeric_rows = array();

		foreach ( $posts as $post ) {
			$product_id = (int) $post['ID'];
			$pmeta      = $meta[ $product_id ] ?? array();
			$pterms     = $terms[ $product_id ] ?? array();
			$pvars      = $variations[ $product_id ] ?? array();

			$visibility = $pterms['product_visibility'] ?? array();
			$vis_slugs  = wp_list_pluck( $visibility, 'slug' );

			$in_stock  = ! in_array( 'outofstock', $vis_slugs, true );
			$visible   = 'publish' === $post['post_status'] && ! in_array( 'exclude-from-catalog', $vis_slugs, true );
			$searchable = ! in_array( 'exclude-from-search', $vis_slugs, true );
			$featured  = in_array( 'featured', $vis_slugs, true );

			$prices    = $this->resolve_prices( $product_id, $pmeta, $pvars );
			$type      = $this->resolve_type( $pterms );
			$sku       = (string) ( $pmeta['_sku'][0] ?? '' );

			$product_rows[] = sprintf(
				'(%d,%d,%s,%d,%d,%d,%d,%d,%d,%d,%d,%s,%d,%d,%s,%s,%s,%s,%d,%s,%s)',
				$product_id,
				0,
				$this->quote_string( $type ),
				$visible ? 1 : 0,
				$searchable ? 1 : 0,
				$in_stock ? 1 : 0,
				'onbackorder' === ( $pmeta['_stock_status'][0] ?? '' ) ? 1 : 0,
				$this->is_on_sale( $pmeta, $pvars ) ? 1 : 0,
				$featured ? 1 : 0,
				'yes' === ( $pmeta['_downloadable'][0] ?? '' ) ? 1 : 0,
				'yes' === ( $pmeta['_virtual'][0] ?? '' ) ? 1 : 0,
				$this->quote_number( (float) ( $pmeta['_wc_average_rating'][0] ?? 0 ) ),
				(int) ( $pmeta['_wc_review_count'][0] ?? 0 ),
				(int) ( $pmeta['total_sales'][0] ?? 0 ),
				$this->quote_number( isset( $pmeta['_stock'][0] ) && is_numeric( $pmeta['_stock'][0] ) ? (int) $pmeta['_stock'][0] : null ),
				$this->quote_number( $prices['min'] ),
				$this->quote_number( $prices['max'] ),
				$this->quote_date( (string) $post['post_date_gmt'] ),
				(int) $post['menu_order'],
				$this->quote_string( (string) ( $languages[ $product_id ] ?? '' ) ),
				$this->quote_string( $this->build_search_text( $post, $sku ) )
			);

			// Term rows, parent level.
			$variation_taxonomies = $this->variation_taxonomies( $pvars );

			foreach ( $pterms as $taxonomy => $term_list ) {
				if ( 'product_visibility' === $taxonomy || 'product_type' === $taxonomy ) {
					continue;
				}

				foreach ( $term_list as $term ) {
					$index_rows[] = $wpdb->prepare(
						'(%d,%d,%d,%s,%d,%d)',
						$product_id,
						$product_id,
						(int) $term['term_id'],
						$taxonomy,
						0,
						$in_stock ? 1 : 0
					);
				}
			}

			// Term rows, variation level.
			foreach ( $pvars as $variation ) {
				$var_id       = (int) $variation['ID'];
				$var_in_stock = 'outofstock' !== ( $variation['meta']['_stock_status'][0] ?? 'instock' );

				foreach ( $variation_taxonomies as $taxonomy ) {
					$value = $variation['meta'][ 'attribute_' . $taxonomy ][0] ?? '';

					if ( '' !== $value ) {
						$term_id = $this->term_id_from_slug( $taxonomy, (string) $value );

						if ( $term_id ) {
							$index_rows[] = $wpdb->prepare(
								'(%d,%d,%d,%s,%d,%d)',
								$product_id,
								$var_id,
								$term_id,
								$taxonomy,
								1,
								$var_in_stock ? 1 : 0
							);
						}

						continue;
					}

					// "Any" variation: it covers every parent term of that attribute.
					foreach ( $pterms[ $taxonomy ] ?? array() as $term ) {
						$index_rows[] = $wpdb->prepare(
							'(%d,%d,%d,%s,%d,%d)',
							$product_id,
							$var_id,
							(int) $term['term_id'],
							$taxonomy,
							1,
							$var_in_stock ? 1 : 0
						);
					}
				}
			}

			// Numeric facets.
			foreach ( $this->numeric_values( $product_id, $pmeta, $pvars, $post ) as $key => $range ) {
				$numeric_rows[] = $wpdb->prepare( '(%d,%s,%s,%s)', $product_id, $key, (string) $range[0], (string) $range[1] );
			}
		}

		$this->insert_chunks(
			Schema::table_product(),
			'(product_id,parent_id,product_type,visible,searchable,in_stock,on_backorder,on_sale,featured,downloadable,virtual_product,rating,rating_count,total_sales,stock_quantity,min_price,max_price,date_created,menu_order,lang,search_text)',
			$product_rows,
			'product_type=VALUES(product_type),visible=VALUES(visible),searchable=VALUES(searchable),in_stock=VALUES(in_stock),on_backorder=VALUES(on_backorder),on_sale=VALUES(on_sale),featured=VALUES(featured),downloadable=VALUES(downloadable),virtual_product=VALUES(virtual_product),rating=VALUES(rating),rating_count=VALUES(rating_count),total_sales=VALUES(total_sales),stock_quantity=VALUES(stock_quantity),min_price=VALUES(min_price),max_price=VALUES(max_price),date_created=VALUES(date_created),menu_order=VALUES(menu_order),lang=VALUES(lang),search_text=VALUES(search_text)'
		);

		$this->insert_chunks(
			Schema::table_index(),
			'(product_id,object_id,term_id,taxonomy,is_variation,in_stock)',
			$index_rows,
			'taxonomy=VALUES(taxonomy),is_variation=VALUES(is_variation),in_stock=VALUES(in_stock)'
		);

		$this->insert_chunks(
			Schema::table_numeric(),
			'(product_id,meta_key,min_value,max_value)',
			$numeric_rows,
			'min_value=VALUES(min_value),max_value=VALUES(max_value)'
		);
	}

	/**
	 * Quote a string for inline SQL, or emit NULL.
	 *
	 * @param string|null $value Value.
	 */
	private function quote_string( ?string $value ): string {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}

		return $wpdb->prepare( '%s', $value );
	}

	/**
	 * Quote a datetime for inline SQL, or emit NULL for a missing date.
	 *
	 * @param string $value Datetime string.
	 */
	private function quote_date( string $value ): string {
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return 'NULL';
		}

		return $this->quote_string( $value );
	}

	/**
	 * Quote a number for inline SQL, or emit NULL.
	 *
	 * @param int|float|null $value Value.
	 */
	private function quote_number( $value ): string {
		if ( null === $value || ! is_numeric( $value ) ) {
			return 'NULL';
		}

		return (string) ( 0 + $value );
	}

	/**
	 * Insert prepared value tuples in chunks.
	 *
	 * @param string   $table   Target table.
	 * @param string   $columns Column list including parentheses.
	 * @param string[] $rows    Prepared value tuples.
	 * @param string   $update  ON DUPLICATE KEY UPDATE clause body.
	 */
	private function insert_chunks( string $table, string $columns, array $rows, string $update ): void {
		global $wpdb;

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$wpdb->query( // phpcs:ignore WordPress.DB
				"INSERT INTO {$table} {$columns} VALUES " . implode( ',', $chunk ) .
				' ON DUPLICATE KEY UPDATE ' . $update
			);
		}
	}

	/**
	 * Fetch the relevant postmeta for a batch.
	 *
	 * @param int[] $ids Product ids.
	 *
	 * @return array<int,array<string,string[]>>
	 */
	private function fetch_meta( array $ids ): array {
		global $wpdb;

		$keys = array_merge( self::CORE_META, $this->extra_meta_keys() );
		$keys = array_values( array_unique( array_filter( $keys ) ) );

		$id_placeholders  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$key_placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id IN ({$id_placeholders}) AND meta_key IN ({$key_placeholders})",
				array_merge( $ids, $keys )
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_id'] ][ $row['meta_key'] ][] = $row['meta_value'];
		}

		return $out;
	}

	/**
	 * Meta keys registered as numeric filters in the admin.
	 *
	 * @return string[]
	 */
	private function extra_meta_keys(): array {
		/**
		 * Filter the additional meta keys pulled into the numeric index.
		 *
		 * @param string[] $keys Meta keys.
		 */
		$keys = apply_filters( 'bsf_indexed_meta_keys', (array) get_option( 'bsf_numeric_meta_keys', array() ) );

		return array_map( 'sanitize_text_field', array_filter( (array) $keys, 'is_string' ) );
	}

	/**
	 * Fetch term relationships for a batch, grouped by product and taxonomy.
	 *
	 * @param int[] $ids Product ids.
	 *
	 * @return array<int,array<string,array<int,array{term_id:int,slug:string}>>>
	 */
	private function fetch_terms( array $ids ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT tr.object_id, tt.term_id, tt.taxonomy, t.slug
				 FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				 WHERE tr.object_id IN ({$placeholders})",
				$ids
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['object_id'] ][ $row['taxonomy'] ][] = array(
				'term_id' => (int) $row['term_id'],
				'slug'    => (string) $row['slug'],
			);
		}

		return $out;
	}

	/**
	 * Fetch variations and their attribute/stock meta.
	 *
	 * @param int[] $ids Parent product ids.
	 *
	 * @return array<int,array<int,array{ID:int,meta:array<string,string[]>}>>
	 */
	private function fetch_variations( array $ids ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT ID, post_parent FROM {$wpdb->posts}
				 WHERE post_type = 'product_variation' AND post_status IN ('publish','private')
				 AND post_parent IN ({$placeholders})",
				$ids
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$variation_ids = array_map( static fn( $row ) => (int) $row['ID'], $rows );
		$parent_of     = array();

		foreach ( $rows as $row ) {
			$parent_of[ (int) $row['ID'] ] = (int) $row['post_parent'];
		}

		$out = array();

		foreach ( array_chunk( $variation_ids, 500 ) as $chunk ) {
			$chunk_placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			$meta_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
					 WHERE post_id IN ({$chunk_placeholders})
					 AND (meta_key LIKE 'attribute\_%' OR meta_key IN ('_price','_stock_status','_sale_price','_regular_price'))",
					$chunk
				),
				ARRAY_A
			);

			foreach ( (array) $meta_rows as $row ) {
				$variation_id = (int) $row['post_id'];
				$parent       = $parent_of[ $variation_id ] ?? 0;

				if ( ! $parent ) {
					continue;
				}

				if ( ! isset( $out[ $parent ][ $variation_id ] ) ) {
					$out[ $parent ][ $variation_id ] = array(
						'ID'   => $variation_id,
						'meta' => array(),
					);
				}

				$out[ $parent ][ $variation_id ]['meta'][ $row['meta_key'] ][] = $row['meta_value'];
			}
		}

		return $out;
	}

	/**
	 * Attribute taxonomies that drive variations in this product set.
	 *
	 * @param array<int,array{ID:int,meta:array<string,string[]>}> $variations Variation rows.
	 *
	 * @return string[]
	 */
	private function variation_taxonomies( array $variations ): array {
		$taxonomies = array();

		foreach ( $variations as $variation ) {
			foreach ( array_keys( $variation['meta'] ) as $key ) {
				if ( 0 !== strpos( $key, 'attribute_' ) ) {
					continue;
				}

				$taxonomy = substr( $key, 10 );

				if ( '' !== $taxonomy && taxonomy_exists( $taxonomy ) ) {
					$taxonomies[ $taxonomy ] = $taxonomy;
				}
			}
		}

		return array_values( $taxonomies );
	}

	/**
	 * Resolve a term id from a slug within a taxonomy, memoised per request.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $slug     Term slug.
	 */
	private function term_id_from_slug( string $taxonomy, string $slug ): int {
		if ( ! isset( $this->slug_cache[ $taxonomy ] ) ) {
			global $wpdb;

			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT t.term_id, t.slug FROM {$wpdb->terms} t
					 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
					 WHERE tt.taxonomy = %s",
					$taxonomy
				),
				ARRAY_A
			);

			$map = array();

			foreach ( (array) $rows as $row ) {
				$map[ (string) $row['slug'] ] = (int) $row['term_id'];
			}

			$this->slug_cache[ $taxonomy ] = $map;
		}

		return $this->slug_cache[ $taxonomy ][ $slug ] ?? 0;
	}

	/**
	 * Determine the product type from its terms.
	 *
	 * @param array<string,array<int,array{term_id:int,slug:string}>> $terms Terms by taxonomy.
	 */
	private function resolve_type( array $terms ): string {
		$type = $terms['product_type'][0]['slug'] ?? 'simple';

		return substr( (string) $type, 0, 32 );
	}

	/**
	 * Compute the price range for a product.
	 *
	 * @param int                                                    $product_id Product id.
	 * @param array<string,string[]>                                 $meta       Parent meta.
	 * @param array<int,array{ID:int,meta:array<string,string[]>}>   $variations Variations.
	 *
	 * @return array{min:float|null,max:float|null}
	 */
	private function resolve_prices( int $product_id, array $meta, array $variations ): array {
		$prices = array();

		foreach ( (array) ( $meta['_price'] ?? array() ) as $value ) {
			if ( '' !== $value && is_numeric( $value ) ) {
				$prices[] = (float) $value;
			}
		}

		foreach ( $variations as $variation ) {
			foreach ( (array) ( $variation['meta']['_price'] ?? array() ) as $value ) {
				if ( '' !== $value && is_numeric( $value ) ) {
					$prices[] = (float) $value;
				}
			}
		}

		if ( empty( $prices ) ) {
			return array(
				'min' => null,
				'max' => null,
			);
		}

		return array(
			'min' => round( min( $prices ), 6 ),
			'max' => round( max( $prices ), 6 ),
		);
	}

	/**
	 * Whether any price of the product is discounted.
	 *
	 * @param array<string,string[]>                               $meta       Parent meta.
	 * @param array<int,array{ID:int,meta:array<string,string[]>}> $variations Variations.
	 */
	private function is_on_sale( array $meta, array $variations ): bool {
		$sets = array( $meta );

		foreach ( $variations as $variation ) {
			$sets[] = $variation['meta'];
		}

		foreach ( $sets as $set ) {
			$sale    = $set['_sale_price'][0] ?? '';
			$regular = $set['_regular_price'][0] ?? '';

			if ( '' !== $sale && is_numeric( $sale ) && is_numeric( $regular ) && (float) $sale < (float) $regular ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Numeric facet values for a product.
	 *
	 * @param int                                                  $product_id Product id.
	 * @param array<string,string[]>                               $meta       Parent meta.
	 * @param array<int,array{ID:int,meta:array<string,string[]>}> $variations Variations.
	 * @param array<string,mixed>                                  $post       Post row.
	 *
	 * @return array<string,array{0:float,1:float}>
	 */
	private function numeric_values( int $product_id, array $meta, array $variations, array $post ): array {
		$out  = array();
		$keys = array_merge( array( '_weight', '_length', '_width', '_height' ), $this->extra_meta_keys() );

		foreach ( array_unique( $keys ) as $key ) {
			$values = array();

			foreach ( (array) ( $meta[ $key ] ?? array() ) as $value ) {
				if ( '' !== $value && is_numeric( $value ) ) {
					$values[] = (float) $value;
				}
			}

			if ( empty( $values ) ) {
				continue;
			}

			$out[ substr( $key, 0, 64 ) ] = array( round( min( $values ), 6 ), round( max( $values ), 6 ) );
		}

		$timestamp = strtotime( (string) $post['post_date_gmt'] . ' UTC' );

		if ( $timestamp ) {
			$out['_date'] = array( (float) $timestamp, (float) $timestamp );
		}

		/**
		 * Filter the numeric facet values written for a product.
		 *
		 * @param array $out        Facet values keyed by meta key.
		 * @param int   $product_id Product id.
		 */
		return apply_filters( 'bsf_index_numeric_values', $out, $product_id );
	}

	/**
	 * Build the text blob used by the keyword filter.
	 *
	 * @param array<string,mixed> $post Post row.
	 * @param string              $sku  Product SKU.
	 */
	private function build_search_text( array $post, string $sku ): string {
		$text = trim( (string) $post['post_title'] . ' ' . $sku . ' ' . wp_strip_all_tags( (string) $post['post_excerpt'] ) );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return mb_substr( (string) $text, 0, 2000 );
	}
}

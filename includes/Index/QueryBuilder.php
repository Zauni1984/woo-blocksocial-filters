<?php
/**
 * SQL builder for filtered product queries and facet counts.
 *
 * Every filter combination compiles to a single statement against the index
 * tables: one INNER JOIN per filter group, no subqueries, no post__in arrays of
 * unbounded size. Facet counts reuse the same compiled joins, so a page with
 * twelve filters still issues a small, bounded number of indexed queries.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Index;

use BlockSocial\Filters\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Compiles constraint arrays into SQL.
 */
class QueryBuilder {

	/** Columns on the product table that can be used as boolean flags. */
	private const FLAG_COLUMNS = array(
		'in_stock',
		'on_sale',
		'featured',
		'downloadable',
		'virtual_product',
		'on_backorder',
		'searchable',
	);

	/** Sort keys mapped to product table columns. */
	private const SORT_COLUMNS = array(
		'price'      => 'p.min_price ASC',
		'price-desc' => 'p.max_price DESC',
		'rating'     => 'p.rating DESC, p.rating_count DESC',
		'popularity' => 'p.total_sales DESC',
		'date'       => 'p.date_created DESC',
		'title'      => 'p.search_text ASC',
		'menu_order' => 'p.menu_order ASC, p.product_id ASC',
	);

	/** @var int Counter for unique join aliases. */
	private $alias_seed = 0;

	/**
	 * Compile constraints into join and where fragments.
	 *
	 * @param array<int,array<string,mixed>> $constraints Constraint list.
	 * @param array<string,mixed>            $args        Extra options.
	 *
	 * @return array{joins:string,where:string}
	 */
	public function compile( array $constraints, array $args = array() ): array {
		global $wpdb;

		$index_table   = Schema::table_index();
		$numeric_table = Schema::table_numeric();

		$joins  = array();
		$where  = array();
		$variation_aliases = array();

		$where[] = ! empty( $args['searchable'] ) ? 'p.searchable = 1' : 'p.visible = 1';

		if ( ! empty( $args['lang'] ) ) {
			$where[] = $wpdb->prepare( '(p.lang = %s OR p.lang = %s)', (string) $args['lang'], '' );
		}

		if ( ! empty( $args['in_stock_only'] ) ) {
			$where[] = 'p.in_stock = 1';
		}

		foreach ( $constraints as $constraint ) {
			if ( ! is_array( $constraint ) || empty( $constraint['type'] ) ) {
				continue;
			}

			switch ( $constraint['type'] ) {
				case 'terms':
					$term_ids = array_values( array_filter( array_map( 'absint', (array) ( $constraint['term_ids'] ?? array() ) ) ) );

					if ( empty( $term_ids ) ) {
						break;
					}

					$logic = ( isset( $constraint['logic'] ) && 'and' === $constraint['logic'] ) ? 'and' : 'or';

					if ( 'and' === $logic ) {
						// One join per term: the product must carry all of them.
						foreach ( array_slice( $term_ids, 0, 10 ) as $term_id ) {
							$alias   = 'ix' . ( ++$this->alias_seed );
							$joins[] = "INNER JOIN {$index_table} {$alias} ON {$alias}.product_id = p.product_id AND {$alias}.term_id = " . (int) $term_id;

							if ( ! empty( $constraint['variation_scope'] ) ) {
								$variation_aliases[] = $alias;
							}
						}

						break;
					}

					$alias   = 'ix' . ( ++$this->alias_seed );
					$in      = implode( ',', $term_ids );
					$joins[] = "INNER JOIN {$index_table} {$alias} ON {$alias}.product_id = p.product_id AND {$alias}.term_id IN ({$in})";

					if ( ! empty( $constraint['variation_scope'] ) ) {
						$variation_aliases[] = $alias;
					}
					break;

				case 'price':
					$min = isset( $constraint['min'] ) ? $constraint['min'] : null;
					$max = isset( $constraint['max'] ) ? $constraint['max'] : null;

					if ( null !== $min ) {
						$where[] = $wpdb->prepare( 'p.max_price >= %f', (float) $min );
					}

					if ( null !== $max ) {
						$where[] = $wpdb->prepare( 'p.min_price <= %f', (float) $max );
					}
					break;

				case 'numeric':
					$key = isset( $constraint['key'] ) ? (string) $constraint['key'] : '';

					if ( '' === $key ) {
						break;
					}

					$alias   = 'nm' . ( ++$this->alias_seed );
					$joins[] = $wpdb->prepare(
						"INNER JOIN {$numeric_table} {$alias} ON {$alias}.product_id = p.product_id AND {$alias}.meta_key = %s",
						$key
					);

					if ( isset( $constraint['min'] ) && null !== $constraint['min'] ) {
						$where[] = $wpdb->prepare( "{$alias}.max_value >= %f", (float) $constraint['min'] );
					}

					if ( isset( $constraint['max'] ) && null !== $constraint['max'] ) {
						$where[] = $wpdb->prepare( "{$alias}.min_value <= %f", (float) $constraint['max'] );
					}
					break;

				case 'rating':
					if ( isset( $constraint['min'] ) && null !== $constraint['min'] ) {
						$where[] = $wpdb->prepare( 'p.rating >= %f', (float) $constraint['min'] );
					}
					break;

				case 'flag':
					$field = isset( $constraint['field'] ) ? (string) $constraint['field'] : '';

					if ( in_array( $field, self::FLAG_COLUMNS, true ) ) {
						$where[] = $wpdb->prepare( "p.{$field} = %d", empty( $constraint['value'] ) ? 0 : 1 );
					}
					break;

				case 'search':
					$text = isset( $constraint['text'] ) ? (string) $constraint['text'] : '';

					if ( '' !== $text ) {
						$where[] = $this->search_clause( $text );
					}
					break;

				case 'ids':
					$include = array_values( array_filter( array_map( 'absint', (array) ( $constraint['include'] ?? array() ) ) ) );

					if ( empty( $include ) ) {
						$where[] = '1 = 0';
						break;
					}

					$where[] = 'p.product_id IN (' . implode( ',', $include ) . ')';
					break;

				case 'exclude_ids':
					$exclude = array_values( array_filter( array_map( 'absint', (array) ( $constraint['exclude'] ?? array() ) ) ) );

					if ( ! empty( $exclude ) ) {
						$where[] = 'p.product_id NOT IN (' . implode( ',', $exclude ) . ')';
					}
					break;
			}
		}

		// "The same variation must satisfy every selected attribute." Products
		// that are not variable are unaffected, their rows all share object_id.
		if ( count( $variation_aliases ) > 1 ) {
			$first  = $variation_aliases[0];
			$parts  = array();

			foreach ( $variation_aliases as $alias ) {
				$parts[] = "{$alias}.is_variation = 1";

				if ( $alias !== $first ) {
					$parts[] = "{$alias}.object_id = {$first}.object_id";
				}
			}

			$where[] = "( p.product_type <> 'variable' OR ( " . implode( ' AND ', $parts ) . ' ) )';
		}

		/**
		 * Filter the compiled SQL fragments.
		 *
		 * @param array $compiled    Joins and where clauses.
		 * @param array $constraints Original constraints.
		 * @param array $args        Extra options.
		 */
		return apply_filters(
			'bsf_compiled_query',
			array(
				'joins' => implode( ' ', $joins ),
				'where' => implode( ' AND ', $where ),
			),
			$constraints,
			$args
		);
	}

	/**
	 * Build the keyword clause.
	 *
	 * A full text match is used when the index exists and every word is long
	 * enough for the server's minimum token size; otherwise a LIKE scan keeps
	 * the behaviour correct, just slower.
	 *
	 * @param string $text Search phrase.
	 */
	private function search_clause( string $text ): string {
		global $wpdb;

		$like = $wpdb->prepare( 'p.search_text LIKE %s', '%' . $wpdb->esc_like( $text ) . '%' );

		if ( ! Schema::has_fulltext() ) {
			return $like;
		}

		// Boolean mode operators are stripped: the phrase is data, not syntax.
		$cleaned = preg_replace( '/[+\-><()~*"@]+/', ' ', $text );
		$words   = preg_split( '/\s+/u', (string) $cleaned, -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $words ) ) {
			return $like;
		}

		$terms = array();

		foreach ( $words as $word ) {
			if ( mb_strlen( $word ) < 3 ) {
				// Short words fall outside the full text token size.
				return $like;
			}

			$terms[] = '+' . $word . '*';
		}

		$expression = $wpdb->prepare( '%s', implode( ' ', $terms ) );

		return "MATCH (p.search_text) AGAINST ({$expression} IN BOOLEAN MODE)";
	}

	/**
	 * Build the SELECT statement returning matching product ids.
	 *
	 * @param array<int,array<string,mixed>> $constraints Constraints.
	 * @param array<string,mixed>            $args        Options: orderby, limit, offset.
	 */
	public function id_sql( array $constraints, array $args = array() ): string {
		$this->alias_seed = 0;

		$product_table = Schema::table_product();
		$compiled      = $this->compile( $constraints, $args );

		$order = '';

		if ( ! empty( $args['orderby'] ) && isset( self::SORT_COLUMNS[ $args['orderby'] ] ) ) {
			$order = ' ORDER BY ' . self::SORT_COLUMNS[ $args['orderby'] ];
		}

		$limit = '';

		if ( ! empty( $args['limit'] ) ) {
			$limit = ' LIMIT ' . absint( $args['limit'] );

			if ( ! empty( $args['offset'] ) ) {
				$limit .= ' OFFSET ' . absint( $args['offset'] );
			}
		}

		return "SELECT DISTINCT p.product_id FROM {$product_table} p {$compiled['joins']} WHERE {$compiled['where']}{$order}{$limit}";
	}

	/**
	 * Matching product ids.
	 *
	 * @param array<int,array<string,mixed>> $constraints Constraints.
	 * @param array<string,mixed>            $args        Options.
	 *
	 * @return int[]
	 */
	public function ids( array $constraints, array $args = array() ): array {
		global $wpdb;

		$sql = $this->id_sql( $constraints, $args );
		$key = Cache::key( 'ids', $sql );

		return (array) Cache::remember(
			$key,
			static function () use ( $wpdb, $sql ) {
				return array_map( 'absint', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB
			},
			(int) apply_filters( 'bsf_cache_ttl', 900 )
		);
	}

	/**
	 * Number of matching products.
	 *
	 * @param array<int,array<string,mixed>> $constraints Constraints.
	 * @param array<string,mixed>            $args        Options.
	 */
	public function count( array $constraints, array $args = array() ): int {
		global $wpdb;

		$this->alias_seed = 0;

		$product_table = Schema::table_product();
		$compiled      = $this->compile( $constraints, $args );
		$sql           = "SELECT COUNT(DISTINCT p.product_id) FROM {$product_table} p {$compiled['joins']} WHERE {$compiled['where']}";

		return (int) Cache::remember(
			Cache::key( 'count', $sql ),
			static function () use ( $wpdb, $sql ) {
				return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB
			},
			(int) apply_filters( 'bsf_cache_ttl', 900 )
		);
	}

	/**
	 * Product counts per term for one taxonomy.
	 *
	 * @param string                         $taxonomy    Taxonomy name.
	 * @param array<int,array<string,mixed>> $constraints Constraints (already excluding this facet when OR logic).
	 * @param array<string,mixed>            $args        Options.
	 *
	 * @return array<int,int> term_id => count.
	 */
	public function term_counts( string $taxonomy, array $constraints, array $args = array() ): array {
		global $wpdb;

		if ( '' === $taxonomy ) {
			return array();
		}

		$this->alias_seed = 100;

		$product_table = Schema::table_product();
		$index_table   = Schema::table_index();
		$compiled      = $this->compile( $constraints, $args );

		$stock_clause = ! empty( $args['in_stock_only'] ) ? ' AND facet.in_stock = 1' : '';

		// The compiled WHERE may already contain a LIKE pattern with % in it, so
		// the taxonomy is quoted on its own and the statement is concatenated.
		$taxonomy_sql = $wpdb->prepare( '%s', $taxonomy );

		$sql = "SELECT facet.term_id AS term_id, COUNT(DISTINCT facet.product_id) AS cnt
			 FROM {$index_table} facet
			 INNER JOIN {$product_table} p ON p.product_id = facet.product_id
			 {$compiled['joins']}
			 WHERE facet.taxonomy = {$taxonomy_sql}{$stock_clause} AND {$compiled['where']}
			 GROUP BY facet.term_id";

		$rows = Cache::remember(
			Cache::key( 'facet', $sql ),
			static function () use ( $wpdb, $sql ) {
				return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
			},
			(int) apply_filters( 'bsf_cache_ttl', 900 )
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['term_id'] ] = (int) $row['cnt'];
		}

		return $out;
	}

	/**
	 * Min and max price across the current result set.
	 *
	 * @param array<int,array<string,mixed>> $constraints Constraints.
	 * @param array<string,mixed>            $args        Options.
	 *
	 * @return array{min:float,max:float}
	 */
	public function price_bounds( array $constraints, array $args = array() ): array {
		global $wpdb;

		$this->alias_seed = 200;

		$product_table = Schema::table_product();
		$compiled      = $this->compile( $constraints, $args );

		$sql = "SELECT MIN(p.min_price) AS lo, MAX(p.max_price) AS hi
				FROM {$product_table} p {$compiled['joins']} WHERE {$compiled['where']} AND p.min_price IS NOT NULL";

		$row = Cache::remember(
			Cache::key( 'price_bounds', $sql ),
			static function () use ( $wpdb, $sql ) {
				return (array) $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
			},
			(int) apply_filters( 'bsf_cache_ttl', 900 )
		);

		return array(
			'min' => (float) ( $row['lo'] ?? 0 ),
			'max' => (float) ( $row['hi'] ?? 0 ),
		);
	}

	/**
	 * Min and max for a numeric facet across the current result set.
	 *
	 * @param string                         $key         Meta key.
	 * @param array<int,array<string,mixed>> $constraints Constraints.
	 * @param array<string,mixed>            $args        Options.
	 *
	 * @return array{min:float,max:float}
	 */
	public function numeric_bounds( string $key, array $constraints, array $args = array() ): array {
		global $wpdb;

		$this->alias_seed = 300;

		$product_table = Schema::table_product();
		$numeric_table = Schema::table_numeric();
		$compiled      = $this->compile( $constraints, $args );

		$key_sql = $wpdb->prepare( '%s', $key );

		$sql = "SELECT MIN(nb.min_value) AS lo, MAX(nb.max_value) AS hi
			 FROM {$numeric_table} nb
			 INNER JOIN {$product_table} p ON p.product_id = nb.product_id
			 {$compiled['joins']}
			 WHERE nb.meta_key = {$key_sql} AND {$compiled['where']}";

		$row = Cache::remember(
			Cache::key( 'numeric_bounds', $sql ),
			static function () use ( $wpdb, $sql ) {
				return (array) $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
			},
			(int) apply_filters( 'bsf_cache_ttl', 900 )
		);

		return array(
			'min' => (float) ( $row['lo'] ?? 0 ),
			'max' => (float) ( $row['hi'] ?? 0 ),
		);
	}

	/**
	 * Available sort keys.
	 *
	 * @return string[]
	 */
	public static function sort_keys(): array {
		return array_keys( self::SORT_COLUMNS );
	}
}

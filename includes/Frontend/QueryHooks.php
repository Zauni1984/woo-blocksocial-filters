<?php
/**
 * Applies the active filters to WordPress queries.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Frontend;

use BlockSocial\Filters\Filters\FilterDefinition;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges the index into WP_Query.
 */
class QueryHooks {

	/** @var array<int,array<string,mixed>>|null Constraints for the query currently being built. */
	private $pending = null;

	/** @var array<string,mixed> Context forced by a shortcode or block. */
	private $context = array();

	/** @var FilterDefinition[]|null */
	private $definitions = null;

	/** @var bool */
	private $applied = false;

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'pre_get_posts', array( $this, 'apply' ), 20 );
		add_filter( 'woocommerce_get_catalog_ordering_args', array( $this, 'ordering_args' ), 20 );
		add_filter( 'posts_clauses', array( $this, 'inject_join' ), 20, 2 );
	}

	/**
	 * Force a context, used when a shortcode renders its own grid.
	 *
	 * @param array<string,mixed> $context Context values.
	 */
	public function set_context( array $context ): void {
		$this->context = array_merge( $this->context, $context );
	}

	/**
	 * Every filter definition across all sets, deduplicated by URL key.
	 *
	 * @return FilterDefinition[]
	 */
	public function definitions(): array {
		if ( null !== $this->definitions ) {
			return $this->definitions;
		}

		$registry = bsf()->registry();
		$seen     = array();
		$out      = array();

		foreach ( $registry->sets() as $set ) {
			foreach ( $registry->definitions( $set ) as $definition ) {
				$key = $definition->url_key();

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$out[]        = $definition;
			}
		}

		$this->definitions = $out;

		return $out;
	}

	/**
	 * Constraints derived from the page being viewed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function context_constraints(): array {
		$constraints = array();

		if ( ! empty( $this->context['term_ids'] ) && ! empty( $this->context['taxonomy'] ) ) {
			$constraints[] = array(
				'type'     => 'terms',
				'taxonomy' => (string) $this->context['taxonomy'],
				'term_ids' => array_map( 'absint', (array) $this->context['term_ids'] ),
				'logic'    => 'or',
			);
		}

		if ( ! empty( $this->context['include'] ) ) {
			$constraints[] = array(
				'type'    => 'ids',
				'include' => array_map( 'absint', (array) $this->context['include'] ),
			);
		}

		if ( is_admin() || ! did_action( 'template_redirect' ) && ! did_action( 'wp' ) ) {
			return $constraints;
		}

		$object = get_queried_object();

		if ( $object instanceof \WP_Term && ! isset( $this->context['taxonomy'] ) ) {
			$taxonomies = array_keys( bsf()->registry()->taxonomies() );

			if ( in_array( $object->taxonomy, $taxonomies, true ) ) {
				$term_ids = array( (int) $object->term_id );

				if ( is_taxonomy_hierarchical( $object->taxonomy ) ) {
					$term_ids = array_merge( $term_ids, array_map( 'absint', (array) get_term_children( $object->term_id, $object->taxonomy ) ) );
				}

				$constraints[] = array(
					'type'     => 'terms',
					'taxonomy' => $object->taxonomy,
					'term_ids' => $term_ids,
					'logic'    => 'or',
				);
			}
		}

		if ( is_search() ) {
			$term = get_search_query( false );

			if ( is_string( $term ) && '' !== $term ) {
				$constraints[] = array(
					'type' => 'search',
					'text' => sanitize_text_field( $term ),
				);
			}
		}

		return $constraints;
	}

	/**
	 * Whether a query should be filtered.
	 *
	 * @param WP_Query $query Query.
	 */
	private function should_apply( WP_Query $query ): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		if ( ! $query->is_main_query() && empty( $query->get( 'bsf_filtered' ) ) ) {
			return false;
		}

		$post_type = $query->get( 'post_type' );

		// Conditionals are read off the query object itself: the global
		// template tags are not reliable this early in the request.
		$is_product_query = 'product' === $post_type
			|| ( is_array( $post_type ) && in_array( 'product', $post_type, true ) )
			|| $query->is_post_type_archive( 'product' )
			|| $query->is_tax( get_object_taxonomies( 'product' ) );

		/**
		 * Filter whether a query should receive the active filters.
		 *
		 * @param bool     $is_product_query Decision.
		 * @param WP_Query $query            Query.
		 */
		return (bool) apply_filters( 'bsf_should_filter_query', $is_product_query, $query );
	}

	/**
	 * Apply the filters to a query.
	 *
	 * @param WP_Query $query Query.
	 */
	public function apply( $query ): void {
		if ( ! $query instanceof WP_Query || ! $this->should_apply( $query ) ) {
			return;
		}

		$state       = bsf()->state();
		$definitions = $this->definitions();
		$constraints = $state->constraints( $definitions, '', $this->context_constraints() );

		if ( empty( $constraints ) ) {
			return;
		}

		$settings  = bsf()->settings();
		$builder   = bsf()->query();
		$args      = bsf()->renderer()->query_args();
		$threshold = $settings->int( 'post_in_threshold', 0, 20000 );

		$total = $builder->count( $constraints, $args );

		if ( 0 === $total ) {
			$query->set( 'post__in', array( 0 ) );
			$this->applied = true;

			return;
		}

		if ( $threshold > 0 && $total <= $threshold ) {
			$ids      = $builder->ids( $constraints, $args );
			$existing = (array) $query->get( 'post__in' );

			if ( ! empty( $existing ) ) {
				$ids = array_values( array_intersect( array_map( 'absint', $existing ), $ids ) );
			}

			$query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );
			$this->applied = true;

			return;
		}

		// Large result sets join the index instead of inflating post__in.
		$this->pending = array(
			'sql' => $builder->id_sql( $constraints, $args ),
		);

		$query->set( 'bsf_join', 1 );
		$this->applied = true;
	}

	/**
	 * Inject the index join for large result sets.
	 *
	 * @param array<string,string> $clauses Query clauses.
	 * @param WP_Query             $query   Query.
	 *
	 * @return array<string,string>
	 */
	public function inject_join( $clauses, $query ) {
		global $wpdb;

		if ( ! is_array( $clauses ) || ! $query instanceof WP_Query ) {
			return $clauses;
		}

		if ( empty( $query->get( 'bsf_join' ) ) || null === $this->pending ) {
			return $clauses;
		}

		$sql = $this->pending['sql'];

		$clauses['join'] .= " INNER JOIN ({$sql}) AS bsf_match ON bsf_match.product_id = {$wpdb->posts}.ID ";

		return $clauses;
	}

	/**
	 * Translate the plugin's sort parameter into WooCommerce ordering args.
	 *
	 * @param array<string,mixed> $args Ordering args.
	 *
	 * @return array<string,mixed>
	 */
	public function ordering_args( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$sort = bsf()->state()->sort();

		if ( '' === $sort ) {
			return $args;
		}

		switch ( $sort ) {
			case 'price':
				return array(
					'orderby'  => 'meta_value_num',
					'order'    => 'ASC',
					'meta_key' => '_price', // phpcs:ignore WordPress.DB.SlowDBQuery
				);

			case 'price-desc':
				return array(
					'orderby'  => 'meta_value_num',
					'order'    => 'DESC',
					'meta_key' => '_price', // phpcs:ignore WordPress.DB.SlowDBQuery
				);

			case 'rating':
				return array(
					'orderby'  => 'meta_value_num',
					'order'    => 'DESC',
					'meta_key' => '_wc_average_rating', // phpcs:ignore WordPress.DB.SlowDBQuery
				);

			case 'popularity':
				return array(
					'orderby'  => 'meta_value_num',
					'order'    => 'DESC',
					'meta_key' => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery
				);

			case 'date':
				return array(
					'orderby' => 'date ID',
					'order'   => 'DESC',
				);

			case 'title':
				return array(
					'orderby' => 'title',
					'order'   => 'ASC',
				);
		}

		return $args;
	}

	/**
	 * Whether filters were applied to the current page.
	 */
	public function applied(): bool {
		return $this->applied;
	}
}

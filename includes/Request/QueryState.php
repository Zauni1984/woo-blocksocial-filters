<?php
/**
 * Parsed state of the current filter request.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Request;

use BlockSocial\Filters\Filters\FilterDefinition;
use BlockSocial\Filters\Index\QueryBuilder;
use BlockSocial\Filters\Support\Cache;
use BlockSocial\Filters\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Turns request input into sanitised selections and, from there, into query
 * constraints and links.
 */
class QueryState {

	public const SORT_PARAM   = 'ordr';
	public const SEARCH_PARAM = 'srch';
	public const RANGE_GLUE   = '..';

	/** @var array<string,array<string,mixed>>|null */
	private $selections = null;

	/** @var array<string,mixed>|null Raw input, overridden for AJAX and pretty URLs. */
	private $raw = null;

	/** @var array<string,array<string,int>> Cached slug => term id maps. */
	private $term_maps = array();

	/**
	 * Replace the raw input, used by the REST endpoint and the pretty URL parser.
	 *
	 * @param array<string,mixed> $raw Raw parameters.
	 */
	public function set_raw( array $raw ): void {
		$this->raw        = $raw;
		$this->selections = null;
	}

	/**
	 * Raw request parameters.
	 *
	 * @return array<string,mixed>
	 */
	public function raw(): array {
		if ( null !== $this->raw ) {
			return $this->raw;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, fully sanitised below.
		return is_array( $_GET ) ? wp_unslash( $_GET ) : array();
	}

	/**
	 * Parameter prefix used in query strings.
	 */
	public function prefix(): string {
		$prefix = Sanitizer::key( bsf()->settings()->get( 'query_prefix', 'f_' ) );

		return '' === $prefix ? 'f_' : $prefix;
	}

	/**
	 * Parse the request into normalised selections keyed by filter url key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function selections(): array {
		if ( null !== $this->selections ) {
			return $this->selections;
		}

		$raw      = $this->raw();
		$prefix   = $this->prefix();
		$settings = bsf()->settings();
		$max      = (int) $settings->int( 'max_filters', 1, Sanitizer::MAX_FILTERS );
		$max_vals = (int) $settings->int( 'max_values', 1, Sanitizer::MAX_VALUES );

		$out = array();

		foreach ( $raw as $param => $value ) {
			if ( count( $out ) >= $max ) {
				break;
			}

			if ( ! is_string( $param ) || 0 !== strpos( $param, $prefix ) ) {
				continue;
			}

			$key = Sanitizer::key( substr( $param, strlen( $prefix ) ) );

			if ( '' === $key ) {
				continue;
			}

			$parsed = $this->parse_value( $value, $max_vals );

			if ( null !== $parsed ) {
				$out[ $key ] = $parsed;
			}
		}

		// WooCommerce's native price widget parameters stay compatible.
		$min_price = Sanitizer::decimal( $raw['min_price'] ?? null );
		$max_price = Sanitizer::decimal( $raw['max_price'] ?? null );

		if ( ( null !== $min_price || null !== $max_price ) && ! isset( $out['price'] ) ) {
			$out['price'] = array(
				'type' => 'range',
				'min'  => $min_price,
				'max'  => $max_price,
			);
		}

		$search = Sanitizer::search( $raw[ self::SEARCH_PARAM ] ?? '' );

		if ( '' !== $search ) {
			$out[ self::SEARCH_PARAM ] = array(
				'type' => 'text',
				'text' => $search,
			);
		}

		$this->selections = $out;

		return $out;
	}

	/**
	 * Parse one raw parameter value.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $max_vals Maximum list length.
	 *
	 * @return array<string,mixed>|null
	 */
	private function parse_value( $value, int $max_vals ): ?array {
		if ( is_string( $value ) && false !== strpos( $value, self::RANGE_GLUE ) ) {
			[ $min, $max ] = array_pad( explode( self::RANGE_GLUE, $value, 2 ), 2, '' );

			$min_num = Sanitizer::decimal( $min );
			$max_num = Sanitizer::decimal( $max );

			if ( null !== $min_num || null !== $max_num ) {
				return array(
					'type' => 'range',
					'min'  => $min_num,
					'max'  => $max_num,
				);
			}

			$min_date = Sanitizer::date( $min );
			$max_date = Sanitizer::date( $max );

			if ( null !== $min_date || null !== $max_date ) {
				return array(
					'type'    => 'range',
					'min'     => null === $min_date ? null : (float) $min_date,
					'max'     => null === $max_date ? null : (float) $max_date,
					'is_date' => true,
				);
			}

			return null;
		}

		$slugs = Sanitizer::slug_list( $value, $max_vals );

		if ( empty( $slugs ) ) {
			return null;
		}

		return array(
			'type'   => 'terms',
			'values' => $slugs,
		);
	}

	/**
	 * Selection for one filter.
	 *
	 * @param FilterDefinition $definition Filter.
	 *
	 * @return array<string,mixed>|null
	 */
	public function selection( FilterDefinition $definition ): ?array {
		$selections = $this->selections();

		return $selections[ $definition->url_key() ] ?? null;
	}

	/**
	 * Selected slugs for a filter.
	 *
	 * @param FilterDefinition $definition Filter.
	 *
	 * @return string[]
	 */
	public function selected_values( FilterDefinition $definition ): array {
		$selection = $this->selection( $definition );

		if ( ! $selection || 'terms' !== $selection['type'] ) {
			return array();
		}

		return (array) $selection['values'];
	}

	/**
	 * Whether a value is currently selected.
	 *
	 * @param FilterDefinition $definition Filter.
	 * @param string           $value      Slug.
	 */
	public function is_selected( FilterDefinition $definition, string $value ): bool {
		return in_array( $value, $this->selected_values( $definition ), true );
	}

	/**
	 * Whether anything at all is filtered.
	 */
	public function is_filtered(): bool {
		return ! empty( $this->selections() );
	}

	/**
	 * Requested sort key.
	 */
	public function sort(): string {
		$raw = $this->raw();

		return Sanitizer::choice( $raw[ self::SORT_PARAM ] ?? '', QueryBuilder::sort_keys(), '' );
	}

	/**
	 * Requested keyword.
	 */
	public function search(): string {
		$selections = $this->selections();

		return (string) ( $selections[ self::SEARCH_PARAM ]['text'] ?? '' );
	}

	/**
	 * Requested page number.
	 */
	public function page(): int {
		$raw = $this->raw();

		return max( 1, absint( $raw['paged'] ?? ( $raw['page'] ?? 1 ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Constraints
	 * ------------------------------------------------------------------ */

	/**
	 * Build QueryBuilder constraints for a list of filters.
	 *
	 * @param FilterDefinition[] $definitions Filters in the set.
	 * @param string             $exclude_id  Filter id to leave out (facet counting).
	 * @param array              $extra       Extra constraints to merge in.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function constraints( array $definitions, string $exclude_id = '', array $extra = array() ): array {
		$constraints = $extra;
		$settings    = bsf()->settings();
		$variation   = $settings->bool( 'variation_match', true );

		foreach ( $definitions as $definition ) {
			if ( $exclude_id === $definition->id() ) {
				continue;
			}

			$selection = $this->selection( $definition );

			if ( null === $selection ) {
				continue;
			}

			$constraint = $this->constraint_for( $definition, $selection, $variation );

			if ( $constraint ) {
				$constraints[] = $constraint;
			}
		}

		$search = $this->search();

		if ( '' !== $search ) {
			$constraints[] = array(
				'type' => 'search',
				'text' => $search,
			);
		}

		/**
		 * Filter the constraints compiled from the request.
		 *
		 * @param array      $constraints Constraints.
		 * @param QueryState $state       Request state.
		 */
		return apply_filters( 'bsf_constraints', $constraints, $this );
	}

	/**
	 * Turn one selection into a constraint.
	 *
	 * @param FilterDefinition    $definition Filter.
	 * @param array<string,mixed> $selection  Selection.
	 * @param bool                $variation  Whether variation matching is on.
	 *
	 * @return array<string,mixed>|null
	 */
	private function constraint_for( FilterDefinition $definition, array $selection, bool $variation ): ?array {
		$source = $definition->source();

		if ( $definition->is_taxonomy() && 'terms' === $selection['type'] ) {
			$term_ids = $this->term_ids( $definition->taxonomy(), (array) $selection['values'], $definition->get( 'hierarchical', false ) );

			if ( empty( $term_ids ) ) {
				// A selection that matches nothing must yield no results rather
				// than silently widening the query.
				return array(
					'type'     => 'terms',
					'taxonomy' => $definition->taxonomy(),
					'term_ids' => array( 0 ),
					'logic'    => 'or',
				);
			}

			return array(
				'type'            => 'terms',
				'taxonomy'        => $definition->taxonomy(),
				'term_ids'        => $term_ids,
				'logic'           => $definition->get( 'logic', 'or' ),
				'variation_scope' => $variation && $definition->get( 'variation', true ) && 'attribute' === $source,
			);
		}

		switch ( $source ) {
			case 'price':
				return array(
					'type' => 'price',
					'min'  => $selection['min'] ?? null,
					'max'  => $selection['max'] ?? null,
				);

			case 'numeric':
				return array(
					'type' => 'numeric',
					'key'  => (string) $definition->get( 'meta_key', '' ),
					'min'  => $selection['min'] ?? null,
					'max'  => $selection['max'] ?? null,
				);

			case 'date':
				return array(
					'type' => 'numeric',
					'key'  => '_date',
					'min'  => $selection['min'] ?? null,
					'max'  => $selection['max'] ?? null,
				);

			case 'rating':
				$values = 'terms' === $selection['type'] ? (array) $selection['values'] : array();
				$min    = 0.0;

				foreach ( $values as $value ) {
					$min = max( $min, (float) $value );
				}

				if ( isset( $selection['min'] ) ) {
					$min = max( $min, (float) $selection['min'] );
				}

				return $min > 0 ? array(
					'type' => 'rating',
					'min'  => $min,
				) : null;

			case 'stock':
				return array(
					'type'  => 'flag',
					'field' => 'in_stock',
					'value' => 1,
				);

			case 'sale':
				return array(
					'type'  => 'flag',
					'field' => 'on_sale',
					'value' => 1,
				);

			case 'featured':
				return array(
					'type'  => 'flag',
					'field' => 'featured',
					'value' => 1,
				);
		}

		return null;
	}

	/**
	 * Resolve slugs to term ids inside a taxonomy, optionally including children.
	 *
	 * @param string   $taxonomy     Taxonomy.
	 * @param string[] $slugs        Term slugs.
	 * @param bool     $with_children Include descendants.
	 *
	 * @return int[]
	 */
	public function term_ids( string $taxonomy, array $slugs, bool $with_children = false ): array {
		if ( '' === $taxonomy || empty( $slugs ) ) {
			return array();
		}

		$map = $this->term_map( $taxonomy );
		$ids = array();

		foreach ( $slugs as $slug ) {
			if ( ! isset( $map[ $slug ] ) ) {
				continue;
			}

			$term_id       = $map[ $slug ];
			$ids[ $term_id ] = $term_id;

			if ( $with_children && is_taxonomy_hierarchical( $taxonomy ) ) {
				foreach ( (array) get_term_children( $term_id, $taxonomy ) as $child ) {
					$child             = (int) $child;
					$ids[ $child ] = $child;
				}
			}
		}

		return array_values( $ids );
	}

	/**
	 * Slug => term id map for a taxonomy, cached.
	 *
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return array<string,int>
	 */
	public function term_map( string $taxonomy ): array {
		if ( isset( $this->term_maps[ $taxonomy ] ) ) {
			return $this->term_maps[ $taxonomy ];
		}

		$map = Cache::remember(
			Cache::key( 'term_map', $taxonomy ),
			static function () use ( $taxonomy ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'fields'     => 'id=>slug',
					)
				);

				if ( is_wp_error( $terms ) ) {
					return array();
				}

				return array_flip( array_map( 'strval', (array) $terms ) );
			},
			12 * HOUR_IN_SECONDS
		);

		$this->term_maps[ $taxonomy ] = is_array( $map ) ? $map : array();

		return $this->term_maps[ $taxonomy ];
	}

	/* ---------------------------------------------------------------------
	 * Link building
	 * ------------------------------------------------------------------ */

	/**
	 * URL with one term value toggled on or off.
	 *
	 * @param FilterDefinition $definition Filter.
	 * @param string           $value      Slug.
	 */
	public function toggle_url( FilterDefinition $definition, string $value ): string {
		$selections = $this->selections();
		$key        = $definition->url_key();
		$current    = $this->selected_values( $definition );

		if ( in_array( $value, $current, true ) ) {
			$current = array_values( array_diff( $current, array( $value ) ) );
		} elseif ( $definition->get( 'multi', true ) && 'radio' !== $definition->display() && 'dropdown' !== $definition->display() ) {
			$current[] = $value;
		} else {
			$current = array( $value );
		}

		if ( empty( $current ) ) {
			unset( $selections[ $key ] );
		} else {
			$selections[ $key ] = array(
				'type'   => 'terms',
				'values' => $current,
			);
		}

		return bsf()->url()->build( $selections );
	}

	/**
	 * URL with a range applied.
	 *
	 * @param FilterDefinition $definition Filter.
	 * @param float|null       $min        Lower bound.
	 * @param float|null       $max        Upper bound.
	 */
	public function range_url( FilterDefinition $definition, ?float $min, ?float $max ): string {
		$selections = $this->selections();
		$key        = $definition->url_key();

		if ( null === $min && null === $max ) {
			unset( $selections[ $key ] );
		} else {
			$selections[ $key ] = array(
				'type' => 'range',
				'min'  => $min,
				'max'  => $max,
			);
		}

		return bsf()->url()->build( $selections );
	}

	/**
	 * URL with one filter cleared.
	 *
	 * @param FilterDefinition $definition Filter.
	 */
	public function clear_url( FilterDefinition $definition ): string {
		$selections = $this->selections();
		unset( $selections[ $definition->url_key() ] );

		return bsf()->url()->build( $selections );
	}

	/**
	 * URL with every filter cleared.
	 */
	public function reset_url(): string {
		return bsf()->url()->build( array() );
	}
}

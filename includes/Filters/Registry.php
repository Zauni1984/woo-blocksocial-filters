<?php
/**
 * Filter set storage and discovery of filterable data sources.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Filters;

use BlockSocial\Filters\Support\Cache;
use BlockSocial\Filters\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Filter sets live in a single autoloaded option, so rendering a filter panel
 * costs no extra queries.
 */
class Registry {

	public const OPTION = 'bsf_filter_sets';

	/** @var array<string,array<string,mixed>>|null */
	private $sets = null;

	/* ---------------------------------------------------------------------
	 * Filter sets
	 * ------------------------------------------------------------------ */

	/**
	 * All stored filter sets.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function sets(): array {
		if ( null === $this->sets ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			$this->sets = array();

			foreach ( $stored as $id => $set ) {
				$id = Sanitizer::key( $id );

				if ( '' === $id || ! is_array( $set ) ) {
					continue;
				}

				$this->sets[ $id ] = $this->normalize_set( $set, $id );
			}

			if ( empty( $this->sets ) ) {
				$this->sets['default'] = $this->auto_set();
			}
		}

		/**
		 * Filter the registered filter sets.
		 *
		 * @param array $sets Filter sets keyed by id.
		 */
		return apply_filters( 'bsf_filter_sets', $this->sets );
	}

	/**
	 * Fetch a single set, falling back to the first available one.
	 *
	 * @param string $id Set id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function set( string $id = '' ): ?array {
		$sets = $this->sets();

		$id = Sanitizer::key( $id );

		if ( '' !== $id && isset( $sets[ $id ] ) ) {
			return $sets[ $id ];
		}

		if ( '' !== $id ) {
			return null;
		}

		return $sets ? reset( $sets ) : null;
	}

	/**
	 * Persist a filter set.
	 *
	 * @param string              $id  Set id.
	 * @param array<string,mixed> $set Raw set data.
	 */
	public function save_set( string $id, array $set ): string {
		$id = Sanitizer::key( $id );

		if ( '' === $id ) {
			$id = 'set-' . wp_generate_password( 6, false, false );
			$id = Sanitizer::key( $id );
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored[ $id ] = $this->normalize_set( $set, $id );

		update_option( self::OPTION, $stored, true );

		$this->sets = null;
		Cache::flush();

		return $id;
	}

	/**
	 * Delete a filter set.
	 *
	 * @param string $id Set id.
	 */
	public function delete_set( string $id ): void {
		$id     = Sanitizer::key( $id );
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		unset( $stored[ $id ] );

		update_option( self::OPTION, $stored, true );

		$this->sets = null;
		Cache::flush();
	}

	/**
	 * Normalise a set and its filters.
	 *
	 * @param array<string,mixed> $set Raw set.
	 * @param string              $id  Set id.
	 *
	 * @return array<string,mixed>
	 */
	public function normalize_set( array $set, string $id ): array {
		$settings = bsf()->settings();

		$normalised = array(
			'id'            => $id,
			'title'         => sanitize_text_field( wp_unslash( (string) ( $set['title'] ?? __( 'Product filters', 'woo-blocksocial-filters' ) ) ) ),
			'layout'        => Sanitizer::choice( $set['layout'] ?? $settings->get( 'layout' ), array( 'vertical', 'horizontal' ), 'vertical' ),
			'mode'          => Sanitizer::choice( $set['mode'] ?? $settings->get( 'mode' ), array( 'auto', 'apply', 'step' ), 'auto' ),
			'columns'       => max( 1, min( 6, (int) ( $set['columns'] ?? 1 ) ) ),
			'show_chips'    => filter_var( $set['show_chips'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			'show_reset'    => filter_var( $set['show_reset'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			'show_count'    => filter_var( $set['show_count'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			'mobile_drawer' => filter_var( $set['mobile_drawer'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			'sticky'        => filter_var( $set['sticky'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			'filters'       => array(),
		);

		$filters = isset( $set['filters'] ) && is_array( $set['filters'] ) ? $set['filters'] : array();
		$seen    = array();

		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}

			$config = FilterDefinition::normalize( $filter );

			if ( '' === $config['id'] || isset( $seen[ $config['id'] ] ) ) {
				$config['id'] = $config['id'] . '-' . count( $seen );
			}

			$seen[ $config['id'] ]   = true;
			$normalised['filters'][] = $config;
		}

		return $normalised;
	}

	/**
	 * Build the FilterDefinition objects of a set.
	 *
	 * @param array<string,mixed> $set Normalised set.
	 *
	 * @return FilterDefinition[]
	 */
	public function definitions( array $set ): array {
		$out = array();

		foreach ( (array) ( $set['filters'] ?? array() ) as $config ) {
			if ( empty( $config['enabled'] ) ) {
				continue;
			}

			$out[] = new FilterDefinition( $config );
		}

		/**
		 * Filter the definitions of a rendered set.
		 *
		 * @param FilterDefinition[] $out Definitions.
		 * @param array              $set Set configuration.
		 */
		return apply_filters( 'bsf_filter_definitions', $out, $set );
	}

	/* ---------------------------------------------------------------------
	 * Sources
	 * ------------------------------------------------------------------ */

	/**
	 * Taxonomies that can back a filter.
	 *
	 * @return array<string,string> taxonomy => label.
	 */
	public function taxonomies(): array {
		$out = array();

		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy ) {
			if ( in_array( $taxonomy->name, array( 'product_type', 'product_visibility', 'product_shipping_class' ), true ) ) {
				continue;
			}

			if ( ! $taxonomy->public && ! $taxonomy->show_ui ) {
				continue;
			}

			$out[ $taxonomy->name ] = $taxonomy->labels->singular_name;
		}

		/**
		 * Filter the taxonomies offered as filter sources.
		 *
		 * @param array $out Taxonomy => label.
		 */
		return apply_filters( 'bsf_filterable_taxonomies', $out );
	}

	/**
	 * Global product attribute taxonomies.
	 *
	 * @return array<string,string>
	 */
	public function attribute_taxonomies(): array {
		$out = array();

		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return $out;
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );

			if ( taxonomy_exists( $taxonomy ) ) {
				$out[ $taxonomy ] = $attribute->attribute_label;
			}
		}

		return $out;
	}

	/**
	 * Meta keys registered for numeric filtering.
	 *
	 * @return string[]
	 */
	public function numeric_keys(): array {
		$keys = get_option( 'bsf_numeric_meta_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();

		return array_values( array_unique( array_merge( array( '_weight', '_length', '_width', '_height' ), array_map( 'sanitize_text_field', $keys ) ) ) );
	}

	/**
	 * Register an additional numeric meta key (used by the ACF integration).
	 *
	 * @param string $key Meta key.
	 */
	public function add_numeric_key( string $key ): void {
		$key = sanitize_text_field( $key );

		if ( '' === $key ) {
			return;
		}

		$keys = get_option( 'bsf_numeric_meta_keys', array() );
		$keys = is_array( $keys ) ? $keys : array();

		if ( in_array( $key, $keys, true ) ) {
			return;
		}

		$keys[] = $key;

		update_option( 'bsf_numeric_meta_keys', array_values( $keys ), true );
	}

	/**
	 * Build a sensible starter set from the shop's own attributes.
	 *
	 * @return array<string,mixed>
	 */
	public function auto_set(): array {
		$filters = array(
			array(
				'source'      => 'price',
				'display'     => 'range',
				'id'          => 'price',
				'url_key'     => 'price',
				'title'       => __( 'Price', 'woo-blocksocial-filters' ),
			),
		);

		foreach ( $this->attribute_taxonomies() as $taxonomy => $label ) {
			$slug    = substr( $taxonomy, 3 );
			$display = 'checkbox';

			if ( preg_match( '/(color|colour|farbe)/i', $slug ) ) {
				$display = 'color';
			} elseif ( preg_match( '/(size|groesse|größe|grosse)/i', $slug ) ) {
				$display = 'label';
			}

			$filters[] = array(
				'source'   => 'attribute',
				'taxonomy' => $taxonomy,
				'display'  => $display,
				'title'    => $label,
				'id'       => Sanitizer::key( $slug ),
				'url_key'  => Sanitizer::key( $slug ),
			);
		}

		$filters[] = array(
			'source'   => 'taxonomy',
			'taxonomy' => 'product_cat',
			'display'  => 'hierarchy',
			'id'       => 'category',
			'url_key'  => 'category',
			'hierarchical' => true,
		);

		$filters[] = array(
			'source'  => 'rating',
			'display' => 'rating',
			'id'      => 'rating',
			'url_key' => 'rating',
		);

		$filters[] = array(
			'source'  => 'stock',
			'display' => 'toggle',
			'id'      => 'instock',
			'url_key' => 'instock',
			'title'   => __( 'In stock only', 'woo-blocksocial-filters' ),
		);

		return $this->normalize_set(
			array(
				'title'   => __( 'Product filters', 'woo-blocksocial-filters' ),
				'filters' => $filters,
			),
			'default'
		);
	}
}

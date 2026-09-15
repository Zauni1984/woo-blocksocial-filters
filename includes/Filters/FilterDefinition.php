<?php
/**
 * A single configured filter.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Filters;

use BlockSocial\Filters\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Value object describing one filter and how it renders.
 */
class FilterDefinition {

	public const SOURCES = array( 'taxonomy', 'attribute', 'price', 'numeric', 'rating', 'stock', 'sale', 'featured', 'date', 'search', 'sort' );

	public const DISPLAYS = array( 'checkbox', 'radio', 'dropdown', 'label', 'color', 'image', 'range', 'rating', 'date', 'text', 'toggle', 'hierarchy' );

	/** @var array<string,mixed> */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $config Normalised configuration.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Defaults for a filter definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'id'            => '',
			'source'        => 'attribute',
			'taxonomy'      => '',
			'meta_key'      => '',
			'title'         => '',
			'url_key'       => '',
			'display'       => 'checkbox',
			'logic'         => 'or',
			'show_counts'   => true,
			'hide_empty'    => true,
			'hide_zero'     => true,
			'collapsible'   => true,
			'collapsed'     => false,
			'search_box'    => false,
			'limit'         => 0,
			'order'         => 'name',
			'multi'         => true,
			'variation'     => true,
			'hierarchical'  => false,
			'step'          => 1,
			'prefix'        => '',
			'suffix'        => '',
			'placeholder'   => '',
			'description'   => '',
			'css_class'     => '',
			'enabled'       => true,
		);
	}

	/**
	 * Normalise and sanitise a raw configuration array.
	 *
	 * @param array<string,mixed> $raw Raw configuration.
	 */
	public static function normalize( array $raw ): array {
		$config = array_merge( self::defaults(), $raw );

		$config['source']  = Sanitizer::choice( $config['source'], self::SOURCES, 'attribute' );
		$config['display'] = Sanitizer::choice( $config['display'], self::DISPLAYS, 'checkbox' );
		$config['logic']   = Sanitizer::choice( $config['logic'], array( 'or', 'and' ), 'or' );
		$config['order']   = Sanitizer::choice( $config['order'], array( 'name', 'count', 'term_order', 'slug', 'id' ), 'name' );

		$config['taxonomy'] = in_array( $config['source'], array( 'taxonomy', 'attribute' ), true )
			? Sanitizer::taxonomy( $config['taxonomy'] )
			: '';

		$config['meta_key'] = 'numeric' === $config['source']
			? sanitize_text_field( wp_unslash( (string) $config['meta_key'] ) )
			: '';

		$config['title']       = sanitize_text_field( wp_unslash( (string) $config['title'] ) );
		$config['description'] = sanitize_text_field( wp_unslash( (string) $config['description'] ) );
		$config['placeholder'] = sanitize_text_field( wp_unslash( (string) $config['placeholder'] ) );
		$config['prefix']      = sanitize_text_field( wp_unslash( (string) $config['prefix'] ) );
		$config['suffix']      = sanitize_text_field( wp_unslash( (string) $config['suffix'] ) );
		$config['css_class']   = sanitize_html_class( (string) $config['css_class'] );

		$config['url_key'] = Sanitizer::key( $config['url_key'] );

		if ( '' === $config['url_key'] ) {
			$config['url_key'] = self::derive_url_key( $config );
		}

		// The keyword filter always reads the parameter the request parser owns.
		if ( 'search' === $config['source'] ) {
			$config['url_key'] = \BlockSocial\Filters\Request\QueryState::SEARCH_PARAM;
		}

		$config['id'] = Sanitizer::key( $config['id'] );

		if ( '' === $config['id'] ) {
			$config['id'] = $config['url_key'];
		}

		foreach ( array( 'show_counts', 'hide_empty', 'hide_zero', 'collapsible', 'collapsed', 'search_box', 'multi', 'variation', 'hierarchical', 'enabled' ) as $flag ) {
			$config[ $flag ] = filter_var( $config[ $flag ], FILTER_VALIDATE_BOOLEAN );
		}

		$config['limit'] = max( 0, min( 500, (int) $config['limit'] ) );
		$config['step']  = max( 0, (float) $config['step'] );

		return $config;
	}

	/**
	 * Derive a readable URL key from the source.
	 *
	 * @param array<string,mixed> $config Configuration.
	 */
	private static function derive_url_key( array $config ): string {
		if ( ! empty( $config['taxonomy'] ) ) {
			$taxonomy = (string) $config['taxonomy'];

			if ( 0 === strpos( $taxonomy, 'pa_' ) ) {
				return Sanitizer::key( substr( $taxonomy, 3 ) );
			}

			if ( 'product_cat' === $taxonomy ) {
				return 'category';
			}

			if ( 'product_tag' === $taxonomy ) {
				return 'tag';
			}

			return Sanitizer::key( $taxonomy );
		}

		if ( ! empty( $config['meta_key'] ) ) {
			return Sanitizer::key( ltrim( (string) $config['meta_key'], '_' ) );
		}

		return Sanitizer::key( (string) $config['source'] );
	}

	/**
	 * Read a configuration value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return array_key_exists( $key, $this->config ) ? $this->config[ $key ] : $default;
	}

	/**
	 * Whole configuration array.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		return $this->config;
	}

	/**
	 * Unique identifier.
	 */
	public function id(): string {
		return (string) $this->config['id'];
	}

	/**
	 * URL parameter name.
	 */
	public function url_key(): string {
		return (string) $this->config['url_key'];
	}

	/**
	 * Source type.
	 */
	public function source(): string {
		return (string) $this->config['source'];
	}

	/**
	 * Display type.
	 */
	public function display(): string {
		return (string) $this->config['display'];
	}

	/**
	 * Taxonomy name for taxonomy backed filters.
	 */
	public function taxonomy(): string {
		return (string) $this->config['taxonomy'];
	}

	/**
	 * Whether this filter reads terms from a taxonomy.
	 */
	public function is_taxonomy(): bool {
		return in_array( $this->source(), array( 'taxonomy', 'attribute' ), true ) && '' !== $this->taxonomy();
	}

	/**
	 * Whether the filter accepts a range of values.
	 */
	public function is_range(): bool {
		return in_array( $this->display(), array( 'range', 'date' ), true );
	}

	/**
	 * Human readable heading.
	 */
	public function title(): string {
		$title = (string) $this->config['title'];

		if ( '' !== $title ) {
			return $title;
		}

		if ( $this->is_taxonomy() ) {
			$taxonomy = get_taxonomy( $this->taxonomy() );

			if ( $taxonomy ) {
				return $taxonomy->labels->singular_name;
			}
		}

		switch ( $this->source() ) {
			case 'price':
				return __( 'Price', 'woo-blocksocial-filters' );
			case 'rating':
				return __( 'Rating', 'woo-blocksocial-filters' );
			case 'stock':
				return __( 'In stock only', 'woo-blocksocial-filters' );
			case 'sale':
				return __( 'On sale', 'woo-blocksocial-filters' );
			case 'featured':
				return __( 'Featured', 'woo-blocksocial-filters' );
			case 'search':
				return __( 'Search', 'woo-blocksocial-filters' );
			case 'sort':
				return __( 'Sort by', 'woo-blocksocial-filters' );
			case 'date':
				return __( 'Date', 'woo-blocksocial-filters' );
		}

		return (string) $this->config['meta_key'];
	}
}

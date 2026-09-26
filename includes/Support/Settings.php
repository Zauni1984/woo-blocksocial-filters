<?php
/**
 * Global plugin settings.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin, cached wrapper around a single autoloaded option.
 */
class Settings {

	public const OPTION = 'bsf_settings';

	/** @var array<string,mixed>|null */
	private $data = null;

	/**
	 * Default values for every supported setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// Behaviour.
			'ajax'                => true,
			'mode'                => 'auto',          // auto | apply | step.
			'layout'              => 'vertical',      // vertical | horizontal.
			'show_counts'         => true,
			'hide_empty'          => true,
			'hide_zero_counts'    => true,
			'collapse'            => true,
			'collapse_default'    => 'open',          // open | closed.
			'scroll_top'          => true,
			'variation_match'     => true,
			'keep_scroll'         => true,

			// URLs.
			'url_mode'            => 'query',         // query | pretty.
			'query_prefix'        => 'f_',
			'pretty_separator'    => '-',
			'seo_noindex_multi'   => true,
			'canonical_clean'     => true,

			// Archive placement.
			'auto_archive'        => false,
			'archive_set'         => '',
			'archive_layout'      => 'horizontal',
			'archive_hook'        => '',
			'archive_priority'    => 25,

			// Product grid integration.
			'pagination_mode'     => 'auto',         // auto | theme | loadmore | infinite.
			'products_container'  => '',
			'pagination_selector' => '',
			'result_count_selector' => '',

			// Swatches.
			'swatches_single'     => true,
			'swatches_archive'    => false,
			'swatch_shape'        => 'circle',        // circle | square | rounded.
			'swatch_size'         => 34,
			'swatch_tooltips'     => true,
			'swatch_out_of_stock' => 'crossed',       // crossed | hidden | dimmed.

			// Performance.
			'index_batch'         => 250,
			'cache_ttl'           => 3600,
			'post_in_threshold'   => 2000,
			'async_index'         => true,
			'max_filters'         => 30,
			'max_values'          => 80,

			// Colors and shape.
			'colors'              => array(),
			'radius'              => 8,
			'gap'                 => 8,
			'font_size'           => 14,
			'custom_css'          => '',
		);
	}

	/**
	 * Read the full settings array.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->data ) {
			$stored     = get_option( self::OPTION, array() );
			$stored     = is_array( $stored ) ? $stored : array();
			$this->data = array_merge( self::defaults(), $stored );

			/**
			 * Filter the resolved plugin settings.
			 *
			 * @param array $settings Settings array.
			 */
			$this->data = apply_filters( 'bsf_settings', $this->data );
		}

		return $this->data;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 *
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Read a boolean setting.
	 *
	 * @param string $key     Setting key.
	 * @param bool   $default Fallback.
	 */
	public function bool( string $key, bool $default = false ): bool {
		$value = $this->get( $key, $default );

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Read an integer setting clamped to a range.
	 *
	 * @param string $key Setting key.
	 * @param int    $min Minimum.
	 * @param int    $max Maximum.
	 */
	public function int( string $key, int $min, int $max ): int {
		return max( $min, min( $max, (int) $this->get( $key, $min ) ) );
	}

	/**
	 * Persist settings, merging over the current values.
	 *
	 * @param array<string,mixed> $values Raw values.
	 */
	public function update( array $values ): void {
		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$merged  = array_merge( $current, $values );

		update_option( self::OPTION, $merged, true );

		$this->data = null;
		Cache::flush_terms();
	}

	/**
	 * Reset the in-memory cache (used by tests and CLI).
	 */
	public function refresh(): void {
		$this->data = null;
	}
}

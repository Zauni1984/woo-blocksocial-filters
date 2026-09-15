<?php
/**
 * Theme integration, including automatic placement above product archives.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Compat;

use BlockSocial\Filters\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Places the filter bar into the theme without template edits.
 */
class Themes {

	/**
	 * Hooks known to sit directly above the product grid, per theme.
	 *
	 * @return array<string,array{hook:string,priority:int}>
	 */
	public static function theme_hooks(): array {
		return array(
			'oceanwp'     => array(
				'hook'     => 'woocommerce_before_shop_loop',
				'priority' => 15,
			),
			'storefront'  => array(
				'hook'     => 'woocommerce_before_shop_loop',
				'priority' => 25,
			),
			'astra'       => array(
				'hook'     => 'woocommerce_before_shop_loop',
				'priority' => 25,
			),
			'generatepress' => array(
				'hook'     => 'woocommerce_before_shop_loop',
				'priority' => 25,
			),
			'flatsome'    => array(
				'hook'     => 'woocommerce_before_shop_loop',
				'priority' => 25,
			),
			'blocksy'     => array(
				'hook'     => 'woocommerce_before_shop_loop',
				'priority' => 25,
			),
		);
	}

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_filter( 'body_class', array( $this, 'body_class' ) );

		if ( ! bsf()->settings()->bool( 'auto_archive', false ) ) {
			return;
		}

		$placement = $this->placement();

		add_action( $placement['hook'], array( $this, 'render_archive_filters' ), $placement['priority'] );
	}

	/**
	 * Resolve where the archive filter bar should be printed.
	 *
	 * @return array{hook:string,priority:int}
	 */
	public function placement(): array {
		$settings = bsf()->settings();
		$hook     = (string) $settings->get( 'archive_hook', '' );
		$priority = (int) $settings->get( 'archive_priority', 0 );

		if ( '' !== $hook ) {
			return array(
				'hook'     => sanitize_key( $hook ),
				'priority' => $priority > 0 ? $priority : 25,
			);
		}

		$theme  = $this->theme_slug();
		$known  = self::theme_hooks();

		$placement = $known[ $theme ] ?? array(
			'hook'     => 'woocommerce_before_shop_loop',
			'priority' => 25,
		);

		/**
		 * Filter where the automatic archive filter bar is rendered.
		 *
		 * @param array  $placement Hook and priority.
		 * @param string $theme     Active theme slug.
		 */
		return (array) apply_filters( 'bsf_archive_placement', $placement, $theme );
	}

	/**
	 * Active theme slug, taking child themes into account.
	 */
	public function theme_slug(): string {
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		$slug = $parent ? $parent->get_stylesheet() : $theme->get_stylesheet();

		return sanitize_key( (string) $slug );
	}

	/**
	 * Render the filter bar above the product grid.
	 */
	public function render_archive_filters(): void {
		if ( ! function_exists( 'is_shop' ) ) {
			return;
		}

		if ( ! is_shop() && ! is_product_taxonomy() ) {
			return;
		}

		$settings = bsf()->settings();
		$set      = bsf()->registry()->set( Sanitizer::key( (string) $settings->get( 'archive_set', '' ) ) );

		if ( ! $set ) {
			return;
		}

		bsf()->assets()->enqueue();

		$layout = Sanitizer::choice( $settings->get( 'archive_layout', 'horizontal' ), array( 'vertical', 'horizontal' ), 'horizontal' );

		echo '<div class="bsf-archive-bar">';
		echo bsf()->renderer()->render_set( $set, array( 'layout' => $layout ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
	}

	/**
	 * Add helper classes to the body element.
	 *
	 * @param string[] $classes Body classes.
	 *
	 * @return string[]
	 */
	public function body_class( $classes ) {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}

		$classes[] = 'bsf-theme-' . $this->theme_slug();

		if ( bsf()->state()->is_filtered() ) {
			$classes[] = 'bsf-is-filtered';
		}

		return $classes;
	}
}

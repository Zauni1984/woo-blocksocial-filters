<?php
/**
 * Front end asset loading.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Frontend;

use BlockSocial\Filters\Support\Colors;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and conditionally enqueues styles and scripts.
 */
class Assets {

	public const HANDLE = 'bsf-frontend';

	/** @var bool */
	private $enqueued = false;

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
	}

	/**
	 * Register the handles.
	 */
	public function register(): void {
		wp_register_style( self::HANDLE, BSF_PLUGIN_URL . 'assets/css/frontend.css', array(), BSF_VERSION );
		wp_register_style( 'bsf-swatches', BSF_PLUGIN_URL . 'assets/css/swatches.css', array( self::HANDLE ), BSF_VERSION );

		wp_register_script( self::HANDLE, BSF_PLUGIN_URL . 'assets/js/frontend.js', array(), BSF_VERSION, true );
		wp_register_script( 'bsf-swatches', BSF_PLUGIN_URL . 'assets/js/swatches.js', array( 'jquery' ), BSF_VERSION, true );
	}

	/**
	 * Enqueue on pages that show products.
	 */
	public function maybe_enqueue(): void {
		$needed = false;

		if ( function_exists( 'is_woocommerce' ) ) {
			$needed = is_woocommerce() || is_shop() || is_product_taxonomy()
				|| ( is_search() && 'product' === get_query_var( 'post_type' ) );
		}

		/**
		 * Filter whether the front end assets are needed on this request.
		 *
		 * @param bool $needed Decision.
		 */
		if ( apply_filters( 'bsf_enqueue_assets', $needed ) ) {
			$this->enqueue();
		}
	}

	/**
	 * Enqueue the assets, once.
	 */
	public function enqueue(): void {
		if ( $this->enqueued ) {
			return;
		}

		$this->enqueued = true;
		$settings       = bsf()->settings();

		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, Colors::inline_css( $settings ) );

		wp_enqueue_script( self::HANDLE );

		wp_localize_script(
			self::HANDLE,
			'bsfData',
			array(
				'rest'      => esc_url_raw( rest_url( Ajax::NAMESPACE . '/filter' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'ajax'      => $settings->bool( 'ajax', true ),
				'mode'      => (string) $settings->get( 'mode', 'auto' ),
				'urlMode'   => (string) $settings->get( 'url_mode', 'query' ),
				'prefix'    => bsf()->state()->prefix(),
				'separator' => (string) $settings->get( 'pretty_separator', '-' ),
				'rangeGlue' => \BlockSocial\Filters\Request\QueryState::RANGE_GLUE,
				'scrollTop' => $settings->bool( 'scroll_top', true ),
				'paging'    => \BlockSocial\Filters\Support\Sanitizer::choice(
					$settings->get( 'pagination_mode', 'auto' ),
					array( 'auto', 'theme', 'loadmore', 'infinite' ),
					'auto'
				),
				/**
				 * Filter the elements a theme's own endless loading hangs off.
				 *
				 * When the plugin takes paging over it stands these down, so the
				 * theme needs no configuration change.
				 *
				 * @param string[] $selectors CSS selectors.
				 */
				'themeLoaders' => (array) apply_filters(
					'bsf_theme_loader_selectors',
					array(
						'.woocommerce-pagination',
						'.oceanwp-pagination',
						'.owp-pagination',
						'.ocean-infinite-scroll',
						'#owp-infinite-scroll',
						'.infinite-scroll-request',
						'.scroller-status',
						'#infinite-handle',
					)
				),
				'selectors' => array(
					'products'   => (string) $settings->get( 'products_container', '' ),
					'pagination' => (string) $settings->get( 'pagination_selector', '' ),
					'count'      => (string) $settings->get( 'result_count_selector', '' ),
				),
				'i18n'      => array(
					'loading'   => __( 'Loading…', 'woo-blocksocial-filters' ),
					'showMore'  => __( 'Show more', 'woo-blocksocial-filters' ),
					'showLess'  => __( 'Show less', 'woo-blocksocial-filters' ),
					'apply'     => __( 'Apply filters', 'woo-blocksocial-filters' ),
					'noResults' => __( 'No products were found matching your selection.', 'woo-blocksocial-filters' ),
					'error'     => __( 'Something went wrong. Please try again.', 'woo-blocksocial-filters' ),
					'loadMore'  => __( 'Load more products', 'woo-blocksocial-filters' ),
					'allLoaded' => __( 'All products loaded', 'woo-blocksocial-filters' ),
				),
			)
		);
	}

	/**
	 * Enqueue the swatch assets used on product pages and archives.
	 */
	public function enqueue_swatches(): void {
		$settings = bsf()->settings();

		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, Colors::inline_css( $settings ) );
		wp_enqueue_style( 'bsf-swatches' );
		wp_enqueue_script( 'bsf-swatches' );

		wp_localize_script(
			'bsf-swatches',
			'bsfSwatchData',
			array(
				'shape'      => (string) $settings->get( 'swatch_shape', 'circle' ),
				'outOfStock' => (string) $settings->get( 'swatch_out_of_stock', 'crossed' ),
				'tooltips'   => $settings->bool( 'swatch_tooltips', true ),
				'i18n'       => array(
					'clear'       => __( 'Clear selection', 'woo-blocksocial-filters' ),
					'unavailable' => __( 'Unavailable', 'woo-blocksocial-filters' ),
				),
			)
		);
	}
}

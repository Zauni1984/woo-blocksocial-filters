<?php
/**
 * Page builder and block editor integration.
 *
 * Divi, Bricks, Oxygen, Breakdance and Beaver Builder all render shortcodes, so
 * the shortcodes are the integration there. Gutenberg and Elementor get native
 * modules.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Compat;

use BlockSocial\Filters\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the block, the Elementor widget and the classic widget.
 */
class Builders {

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widget' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
	}

	/* ---------------------------------------------------------------------
	 * Gutenberg
	 * ------------------------------------------------------------------ */

	/**
	 * Register the server rendered block.
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'bsf-block-editor',
			BSF_PLUGIN_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			BSF_VERSION,
			true
		);

		wp_localize_script(
			'bsf-block-editor',
			'bsfBlockData',
			array(
				'sets' => $this->set_choices(),
			)
		);

		register_block_type(
			'blocksocial/filters',
			array(
				'api_version'     => 2,
				'title'           => __( 'Product filters', 'woo-blocksocial-filters' ),
				'category'        => 'woocommerce',
				'icon'            => 'filter',
				'editor_script'   => 'bsf-block-editor',
				'attributes'      => array(
					'set'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'layout'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'mode'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'columns' => array(
						'type'    => 'number',
						'default' => 1,
					),
				),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Server side rendering for the block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public function render_block( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();

		return bsf()->shortcodes()->filters(
			array(
				'set'     => Sanitizer::key( $attributes['set'] ?? '' ),
				'layout'  => Sanitizer::choice( $attributes['layout'] ?? '', array( 'vertical', 'horizontal' ), '' ),
				'mode'    => Sanitizer::choice( $attributes['mode'] ?? '', array( 'auto', 'apply', 'step' ), '' ),
				'columns' => (int) ( $attributes['columns'] ?? 1 ),
			)
		);
	}

	/**
	 * Available sets for editor dropdowns.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	public function set_choices(): array {
		$out = array();

		foreach ( bsf()->registry()->sets() as $id => $set ) {
			$out[] = array(
				'value' => (string) $id,
				'label' => (string) $set['title'],
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Elementor
	 * ------------------------------------------------------------------ */

	/**
	 * Register the Elementor widget.
	 *
	 * @param mixed $widgets_manager Elementor widget manager.
	 */
	public function register_elementor_widget( $widgets_manager ): void {
		if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		$widgets_manager->register( new ElementorWidget() );
	}

	/* ---------------------------------------------------------------------
	 * Classic widget
	 * ------------------------------------------------------------------ */

	/**
	 * Register the sidebar widget.
	 */
	public function register_widget(): void {
		register_widget( FilterWidget::class );
	}
}

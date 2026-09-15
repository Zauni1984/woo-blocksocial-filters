<?php
/**
 * Elementor widget wrapper.
 *
 * Only loaded when Elementor is active.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a filter set inside Elementor.
 */
class ElementorWidget extends \Elementor\Widget_Base {

	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'bsf-filters';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Product filters', 'woo-blocksocial-filters' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-filter';
	}

	/**
	 * Widget categories.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return array( 'woocommerce-elements', 'general' );
	}

	/**
	 * Control registration.
	 */
	protected function register_controls(): void {
		$sets = array();

		foreach ( bsf()->registry()->sets() as $id => $set ) {
			$sets[ (string) $id ] = (string) $set['title'];
		}

		$this->start_controls_section(
			'content',
			array( 'label' => __( 'Filters', 'woo-blocksocial-filters' ) )
		);

		$this->add_control(
			'set',
			array(
				'label'   => __( 'Filter set', 'woo-blocksocial-filters' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $sets,
				'default' => (string) array_key_first( $sets ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'woo-blocksocial-filters' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'vertical'   => __( 'Vertical', 'woo-blocksocial-filters' ),
					'horizontal' => __( 'Horizontal', 'woo-blocksocial-filters' ),
				),
				'default' => 'vertical',
			)
		);

		$this->add_control(
			'mode',
			array(
				'label'   => __( 'Mode', 'woo-blocksocial-filters' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'auto'  => __( 'Auto submit', 'woo-blocksocial-filters' ),
					'apply' => __( 'Select and apply', 'woo-blocksocial-filters' ),
					'step'  => __( 'Step by step', 'woo-blocksocial-filters' ),
				),
				'default' => 'auto',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Front end output.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		echo bsf()->shortcodes()->filters( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'set'    => (string) ( $settings['set'] ?? '' ),
				'layout' => (string) ( $settings['layout'] ?? '' ),
				'mode'   => (string) ( $settings['mode'] ?? '' ),
			)
		);
	}
}

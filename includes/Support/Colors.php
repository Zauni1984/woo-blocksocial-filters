<?php
/**
 * Design tokens: every colour, radius and size the front end uses.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the CSS custom property block that themes the whole UI.
 */
class Colors {

	/**
	 * Token definitions grouped for the settings screen.
	 *
	 * @return array<string,array{label:string,tokens:array<string,array{label:string,default:string}>}>
	 */
	public static function groups(): array {
		return array(
			'base'    => array(
				'label'  => __( 'Base', 'woo-blocksocial-filters' ),
				'tokens' => array(
					'accent'        => array(
						'label'   => __( 'Accent', 'woo-blocksocial-filters' ),
						'default' => '#1f6feb',
					),
					'accent_hover'  => array(
						'label'   => __( 'Accent (hover)', 'woo-blocksocial-filters' ),
						'default' => '#1a5fd0',
					),
					'on_accent'     => array(
						'label'   => __( 'Text on accent', 'woo-blocksocial-filters' ),
						'default' => '#ffffff',
					),
					'text'          => array(
						'label'   => __( 'Text', 'woo-blocksocial-filters' ),
						'default' => '#1c1f24',
					),
					'muted'         => array(
						'label'   => __( 'Muted text', 'woo-blocksocial-filters' ),
						'default' => '#6b7280',
					),
					'surface'       => array(
						'label'   => __( 'Panel background', 'woo-blocksocial-filters' ),
						'default' => '#ffffff',
					),
					'surface_alt'   => array(
						'label'   => __( 'Secondary background', 'woo-blocksocial-filters' ),
						'default' => '#f6f7f9',
					),
					'border'        => array(
						'label'   => __( 'Border', 'woo-blocksocial-filters' ),
						'default' => '#e3e6ea',
					),
					'border_strong' => array(
						'label'   => __( 'Strong border', 'woo-blocksocial-filters' ),
						'default' => '#c8ced6',
					),
				),
			),
			'labels'  => array(
				'label'  => __( 'Labels and chips', 'woo-blocksocial-filters' ),
				'tokens' => array(
					'label_bg'            => array(
						'label'   => __( 'Label background', 'woo-blocksocial-filters' ),
						'default' => '#ffffff',
					),
					'label_text'          => array(
						'label'   => __( 'Label text', 'woo-blocksocial-filters' ),
						'default' => '#25292e',
					),
					'label_border'        => array(
						'label'   => __( 'Label border', 'woo-blocksocial-filters' ),
						'default' => '#d9dee4',
					),
					'label_hover_bg'      => array(
						'label'   => __( 'Label background (hover)', 'woo-blocksocial-filters' ),
						'default' => '#f2f5f9',
					),
					'label_hover_border'  => array(
						'label'   => __( 'Label border (hover)', 'woo-blocksocial-filters' ),
						'default' => '#9fb2c8',
					),
					'label_active_bg'     => array(
						'label'   => __( 'Label background (selected)', 'woo-blocksocial-filters' ),
						'default' => '#1f6feb',
					),
					'label_active_text'   => array(
						'label'   => __( 'Label text (selected)', 'woo-blocksocial-filters' ),
						'default' => '#ffffff',
					),
					'label_active_border' => array(
						'label'   => __( 'Label border (selected)', 'woo-blocksocial-filters' ),
						'default' => '#1f6feb',
					),
					'label_disabled_bg'   => array(
						'label'   => __( 'Label background (unavailable)', 'woo-blocksocial-filters' ),
						'default' => '#f4f5f7',
					),
					'label_disabled_text' => array(
						'label'   => __( 'Label text (unavailable)', 'woo-blocksocial-filters' ),
						'default' => '#a8aeb8',
					),
					'count_bg'            => array(
						'label'   => __( 'Counter background', 'woo-blocksocial-filters' ),
						'default' => '#eef0f2',
					),
					'count_text'          => array(
						'label'   => __( 'Counter text', 'woo-blocksocial-filters' ),
						'default' => '#5b636e',
					),
				),
			),
			'controls' => array(
				'label'  => __( 'Controls', 'woo-blocksocial-filters' ),
				'tokens' => array(
					'control_bg'       => array(
						'label'   => __( 'Checkbox / radio background', 'woo-blocksocial-filters' ),
						'default' => '#ffffff',
					),
					'control_border'   => array(
						'label'   => __( 'Checkbox / radio border', 'woo-blocksocial-filters' ),
						'default' => '#c2c8d0',
					),
					'control_checked'  => array(
						'label'   => __( 'Checkbox / radio checked', 'woo-blocksocial-filters' ),
						'default' => '#1f6feb',
					),
					'swatch_border'    => array(
						'label'   => __( 'Swatch border', 'woo-blocksocial-filters' ),
						'default' => '#dfe3e8',
					),
					'swatch_selected'  => array(
						'label'   => __( 'Swatch selection ring', 'woo-blocksocial-filters' ),
						'default' => '#1c1f24',
					),
					'slider_track'     => array(
						'label'   => __( 'Slider track', 'woo-blocksocial-filters' ),
						'default' => '#e3e6ea',
					),
					'slider_fill'      => array(
						'label'   => __( 'Slider range', 'woo-blocksocial-filters' ),
						'default' => '#1f6feb',
					),
					'slider_handle'    => array(
						'label'   => __( 'Slider handle', 'woo-blocksocial-filters' ),
						'default' => '#ffffff',
					),
					'star'             => array(
						'label'   => __( 'Rating stars', 'woo-blocksocial-filters' ),
						'default' => '#f5a623',
					),
					'chip_clear_bg'    => array(
						'label'   => __( 'Active filter chip', 'woo-blocksocial-filters' ),
						'default' => '#eef4ff',
					),
					'chip_clear_text'  => array(
						'label'   => __( 'Active filter chip text', 'woo-blocksocial-filters' ),
						'default' => '#14448f',
					),
					'focus_ring'       => array(
						'label'   => __( 'Focus ring', 'woo-blocksocial-filters' ),
						'default' => '#7aa7f5',
					),
				),
			),
		);
	}

	/**
	 * Flat map of token => default value.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		$out = array();

		foreach ( self::groups() as $group ) {
			foreach ( $group['tokens'] as $token => $meta ) {
				$out[ $token ] = $meta['default'];
			}
		}

		return $out;
	}

	/**
	 * Merge stored overrides over the defaults.
	 *
	 * @param array<string,mixed> $stored Stored colour overrides.
	 *
	 * @return array<string,string>
	 */
	public static function resolve( array $stored ): array {
		$defaults = self::defaults();
		$out      = $defaults;

		foreach ( $defaults as $token => $default ) {
			if ( empty( $stored[ $token ] ) ) {
				continue;
			}

			$value = Sanitizer::color( $stored[ $token ], $default );

			if ( '' !== $value ) {
				$out[ $token ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Build the inline stylesheet exposing the tokens as CSS variables.
	 *
	 * @param Settings $settings Settings service.
	 */
	public static function inline_css( Settings $settings ): string {
		$stored = $settings->get( 'colors', array() );
		$colors = self::resolve( is_array( $stored ) ? $stored : array() );

		$lines = array();

		foreach ( $colors as $token => $value ) {
			$lines[] = sprintf( '--bsf-%s:%s;', str_replace( '_', '-', $token ), $value );
		}

		$lines[] = sprintf( '--bsf-radius:%dpx;', $settings->int( 'radius', 0, 40 ) );
		$lines[] = sprintf( '--bsf-gap:%dpx;', $settings->int( 'gap', 0, 40 ) );
		$lines[] = sprintf( '--bsf-font-size:%dpx;', $settings->int( 'font_size', 10, 24 ) );
		$lines[] = sprintf( '--bsf-swatch-size:%dpx;', $settings->int( 'swatch_size', 16, 96 ) );

		$css = ':root{' . implode( '', $lines ) . '}';

		$custom = (string) $settings->get( 'custom_css', '' );

		if ( '' !== $custom ) {
			$css .= wp_strip_all_tags( $custom );
		}

		/**
		 * Filter the generated design token stylesheet.
		 *
		 * @param string $css    Generated CSS.
		 * @param array  $colors Resolved tokens.
		 */
		return apply_filters( 'bsf_inline_css', $css, $colors );
	}
}

<?php
/**
 * Variation swatches for single products and archives.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Frontend;

use BlockSocial\Filters\Index\Schema;
use BlockSocial\Filters\Support\Cache;
use BlockSocial\Filters\Support\ColorNames;
use BlockSocial\Filters\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the variation dropdowns with labels, colour and image swatches, and
 * can show the same swatches on product cards in the catalogue.
 */
class Swatches {

	public const DISPLAY_OPTION = 'bsf_attribute_display';

	/** @var array<string,string>|null */
	private $display_map = null;

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		$settings = bsf()->settings();

		if ( $settings->bool( 'swatches_single', true ) ) {
			add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( $this, 'render_variation_swatches' ), 20, 2 );
		}

		if ( $settings->bool( 'swatches_archive', false ) ) {
			add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_loop_swatches' ), 15 );
		}

		// Assets are decided during wp_enqueue_scripts so the stylesheet still
		// reaches the document head rather than being printed late.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
	}

	/**
	 * Enqueue swatch assets.
	 */
	public function enqueue(): void {
		bsf()->assets()->enqueue_swatches();
	}

	/**
	 * Enqueue on the pages that actually render swatches.
	 */
	public function maybe_enqueue(): void {
		if ( ! function_exists( 'is_product' ) ) {
			return;
		}

		$settings = bsf()->settings();

		$single  = $settings->bool( 'swatches_single', true ) && is_product();
		$archive = $settings->bool( 'swatches_archive', false ) && ( is_shop() || is_product_taxonomy() );

		if ( $single || $archive ) {
			$this->enqueue();
		}
	}

	/**
	 * Configured display type per attribute taxonomy.
	 *
	 * @return array<string,string>
	 */
	public function display_map(): array {
		if ( null === $this->display_map ) {
			$stored = get_option( self::DISPLAY_OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$map    = array();

			foreach ( $stored as $taxonomy => $display ) {
				$taxonomy = sanitize_key( (string) $taxonomy );
				$display  = Sanitizer::choice( $display, array( 'auto', 'label', 'color', 'image', 'select' ), 'auto' );

				if ( '' !== $taxonomy ) {
					$map[ $taxonomy ] = $display;
				}
			}

			$this->display_map = $map;
		}

		return $this->display_map;
	}

	/**
	 * Decide how an attribute should render.
	 *
	 * @param string   $taxonomy Attribute taxonomy.
	 * @param string[] $slugs    Option slugs.
	 */
	private function resolve_display( string $taxonomy, array $slugs ): string {
		$map       = $this->display_map();
		$configured = $map[ $taxonomy ] ?? 'auto';

		if ( 'auto' !== $configured ) {
			return $configured;
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 'label';
		}

		foreach ( bsf()->renderer()->terms( $taxonomy ) as $term ) {
			if ( ! in_array( $term['slug'], $slugs, true ) ) {
				continue;
			}

			// Only a colour the shop actually set turns an attribute into
			// colour swatches; a guessed one must not hijack, say, sizes.
			if ( ! empty( $term['explicit'] ) ) {
				return 'color';
			}

			if ( '' !== $term['image'] ) {
				return 'image';
			}
		}

		return 'label';
	}

	/**
	 * Replace the variation select with swatches.
	 *
	 * @param string              $html Original markup.
	 * @param array<string,mixed> $args Dropdown arguments.
	 */
	public function render_variation_swatches( $html, $args ) {
		if ( ! is_string( $html ) || ! is_array( $args ) ) {
			return $html;
		}

		$attribute = (string) ( $args['attribute'] ?? '' );
		$options   = (array) ( $args['options'] ?? array() );
		$product   = $args['product'] ?? null;
		$selected  = (string) ( $args['selected'] ?? '' );

		if ( '' === $attribute || empty( $options ) || ! $product ) {
			return $html;
		}

		$is_taxonomy = taxonomy_exists( $attribute );
		$slugs       = array_map( 'strval', $options );
		$display     = $this->resolve_display( $attribute, $slugs );

		if ( 'select' === $display ) {
			return $html;
		}

		$items = $this->build_items( $attribute, $slugs, $is_taxonomy, $product );

		if ( empty( $items ) ) {
			return $html;
		}

		$settings = bsf()->settings();
		$shape    = Sanitizer::choice( $settings->get( 'swatch_shape', 'circle' ), array( 'circle', 'square', 'rounded' ), 'circle' );

		ob_start();
		?>
		<div class="bsf-swatches bsf-swatches--<?php echo esc_attr( $display ); ?> bsf-swatches--<?php echo esc_attr( $shape ); ?>"
			data-attribute="<?php echo esc_attr( $attribute ); ?>"
			role="radiogroup"
			aria-label="<?php echo esc_attr( $this->attribute_label( $attribute, $product ) ); ?>">
			<?php foreach ( $items as $item ) : ?>
				<button type="button"
					class="bsf-swatch-item<?php echo $selected === $item['slug'] ? ' is-selected' : ''; ?>"
					role="radio"
					aria-checked="<?php echo $selected === $item['slug'] ? 'true' : 'false'; ?>"
					data-value="<?php echo esc_attr( $item['slug'] ); ?>"
					title="<?php echo esc_attr( $item['tooltip'] ?: $item['name'] ); ?>">
					<?php if ( 'color' === $display ) : ?>
						<span class="bsf-swatch-item__color" style="<?php echo esc_attr( $item['style'] ); ?>" aria-hidden="true"></span>
					<?php elseif ( 'image' === $display && $item['image'] ) : ?>
						<img class="bsf-swatch-item__image" src="<?php echo esc_url( $item['image'] ); ?>" alt="" loading="lazy" decoding="async" width="48" height="48" />
					<?php else : ?>
						<span class="bsf-swatch-item__label"><?php echo esc_html( $item['name'] ); ?></span>
					<?php endif; ?>
					<span class="screen-reader-text"><?php echo esc_html( $item['name'] ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
		<?php

		$swatches = (string) ob_get_clean();

		/**
		 * Filter the swatch markup rendered for a variation attribute.
		 *
		 * @param string $swatches Swatch markup.
		 * @param string $attribute Attribute name.
		 * @param array  $args      Dropdown args.
		 */
		$swatches = apply_filters( 'bsf_variation_swatches', $swatches, $attribute, $args );

		// The original select stays in the DOM, hidden, so WooCommerce's own
		// variation script keeps working untouched.
		return '<div class="bsf-swatch-wrap">' . $swatches . '<div class="bsf-swatch-select">' . $html . '</div></div>';
	}

	/**
	 * Build the renderable items for one attribute.
	 *
	 * @param string   $attribute   Attribute name.
	 * @param string[] $slugs       Option slugs.
	 * @param bool     $is_taxonomy Whether the attribute is a taxonomy.
	 * @param mixed    $product     Product object.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function build_items( string $attribute, array $slugs, bool $is_taxonomy, $product ): array {
		$items = array();

		if ( ! $is_taxonomy ) {
			foreach ( $slugs as $slug ) {
				$items[] = array(
					'slug'    => $slug,
					'name'    => $slug,
					'image'   => '',
					'tooltip' => '',
					'style'   => '',
				);
			}

			return $items;
		}

		$terms = bsf()->renderer()->terms( $attribute );
		$order = array_flip( $slugs );

		foreach ( $terms as $term ) {
			if ( ! isset( $order[ $term['slug'] ] ) ) {
				continue;
			}

			$style = ColorNames::style(
				(string) $term['color'],
				(string) $term['color2'],
				(string) ( $term['gradient'] ?? '' )
			);

			$items[ $order[ $term['slug'] ] ] = array(
				'slug'    => $term['slug'],
				'name'    => $term['name'],
				'image'   => $term['image'],
				'tooltip' => $term['tooltip'],
				'style'   => $style,
			);
		}

		ksort( $items );

		return array_values( $items );
	}

	/**
	 * Readable attribute label.
	 *
	 * @param string $attribute Attribute name.
	 * @param mixed  $product   Product object.
	 */
	private function attribute_label( string $attribute, $product ): string {
		if ( function_exists( 'wc_attribute_label' ) ) {
			return (string) wc_attribute_label( $attribute, $product );
		}

		return $attribute;
	}

	/* ---------------------------------------------------------------------
	 * Archive swatches
	 * ------------------------------------------------------------------ */

	/**
	 * Show the available variation swatches on a product card.
	 */
	public function render_loop_swatches(): void {
		global $product;

		if ( ! $product || ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'variable' ) ) {
			return;
		}

		$product_id = (int) $product->get_id();
		$groups     = $this->loop_terms( $product_id );

		if ( empty( $groups ) ) {
			return;
		}

		$permalink = get_permalink( $product_id );

		foreach ( $groups as $taxonomy => $slugs ) {
			$display = $this->resolve_display( $taxonomy, $slugs );

			if ( 'label' === $display && count( $slugs ) > 6 ) {
				continue;
			}

			$items = $this->build_items( $taxonomy, $slugs, true, $product );

			if ( empty( $items ) ) {
				continue;
			}

			printf( '<div class="bsf-loop-swatches bsf-swatches--%s">', esc_attr( $display ) );

			foreach ( array_slice( $items, 0, 8 ) as $item ) {
				printf(
					'<a class="bsf-swatch-item" href="%s" title="%s">%s<span class="screen-reader-text">%s</span></a>',
					esc_url( add_query_arg( 'attribute_' . $taxonomy, $item['slug'], $permalink ) ),
					esc_attr( $item['name'] ),
					'color' === $display
						? '<span class="bsf-swatch-item__color" style="' . esc_attr( $item['style'] ) . '" aria-hidden="true"></span>'
						: ( 'image' === $display && $item['image']
							? '<img class="bsf-swatch-item__image" src="' . esc_url( $item['image'] ) . '" alt="" loading="lazy" width="32" height="32" />'
							: '<span class="bsf-swatch-item__label">' . esc_html( $item['name'] ) . '</span>' ),
					esc_html( $item['name'] )
				);
			}

			echo '</div>';
		}
	}

	/**
	 * Variation attribute terms of a product, read straight from the index.
	 *
	 * @param int $product_id Product id.
	 *
	 * @return array<string,string[]> taxonomy => slugs.
	 */
	private function loop_terms( int $product_id ): array {
		global $wpdb;

		return (array) Cache::remember(
			Cache::key( 'loop_swatches', $product_id ),
			static function () use ( $wpdb, $product_id ) {
				$table = Schema::table_index();

				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
					$wpdb->prepare(
						"SELECT DISTINCT i.taxonomy, t.slug
						 FROM {$table} i
						 INNER JOIN {$wpdb->terms} t ON t.term_id = i.term_id
						 WHERE i.product_id = %d AND i.is_variation = 1 AND i.in_stock = 1",
						$product_id
					),
					ARRAY_A
				);

				$out = array();

				foreach ( (array) $rows as $row ) {
					$out[ (string) $row['taxonomy'] ][] = (string) $row['slug'];
				}

				return $out;
			},
			6 * HOUR_IN_SECONDS
		);
	}
}

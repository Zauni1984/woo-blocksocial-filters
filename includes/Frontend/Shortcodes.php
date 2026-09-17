<?php
/**
 * Shortcodes.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Frontend;

use BlockSocial\Filters\Support\Sanitizer;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Every filter surface is available as a shortcode so it drops into any
 * page builder that can render shortcodes.
 */
class Shortcodes {

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_shortcode( 'bsf_filters', array( $this, 'filters' ) );
		add_shortcode( 'bsf_products', array( $this, 'products' ) );
		add_shortcode( 'bsf_active_filters', array( $this, 'active_filters' ) );
		add_shortcode( 'bsf_sort', array( $this, 'sort' ) );
		add_shortcode( 'bsf_search', array( $this, 'search' ) );
		add_shortcode( 'bsf_result_count', array( $this, 'result_count' ) );
	}

	/**
	 * Render a filter set.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 */
	public function filters( $atts ): string {
		$atts = shortcode_atts(
			array(
				'set'     => '',
				'layout'  => '',
				'mode'    => '',
				'columns' => '',
				'title'   => '',
				'class'   => '',
			),
			(array) $atts,
			'bsf_filters'
		);

		$set = bsf()->registry()->set( Sanitizer::key( (string) $atts['set'] ) );

		if ( ! $set ) {
			return '';
		}

		$args = array();

		if ( '' !== $atts['layout'] ) {
			$args['layout'] = Sanitizer::choice( $atts['layout'], array( 'vertical', 'horizontal' ), 'vertical' );
		}

		if ( '' !== $atts['mode'] ) {
			$args['mode'] = Sanitizer::choice( $atts['mode'], array( 'auto', 'apply', 'step' ), 'auto' );
		}

		if ( '' !== $atts['columns'] ) {
			$args['columns'] = max( 1, min( 6, (int) $atts['columns'] ) );
		}

		if ( '' !== $atts['title'] ) {
			$args['title'] = sanitize_text_field( (string) $atts['title'] );
		}

		if ( '' !== $atts['class'] ) {
			$args['class'] = sanitize_html_class( (string) $atts['class'] );
		}

		bsf()->assets()->enqueue();

		return bsf()->renderer()->render_set( $set, $args );
	}

	/**
	 * Render a filtered product grid.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 */
	public function products( $atts ): string {
		$atts = shortcode_atts(
			array(
				'per_page' => 12,
				'columns'  => 4,
				'category' => '',
				'tag'      => '',
				'orderby'  => '',
				'ids'      => '',
			),
			(array) $atts,
			'bsf_products'
		);

		bsf()->assets()->enqueue();

		$context = array();

		if ( '' !== $atts['category'] ) {
			$slugs    = Sanitizer::slug_list( $atts['category'] );
			$term_ids = bsf()->state()->term_ids( 'product_cat', $slugs, true );

			if ( $term_ids ) {
				$context['taxonomy'] = 'product_cat';
				$context['term_ids'] = $term_ids;
			}
		}

		if ( '' !== $atts['ids'] ) {
			$context['include'] = Sanitizer::id_list( $atts['ids'] );
		}

		if ( $context ) {
			bsf()->query_hooks()->set_context( $context );
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 100, (int) $atts['per_page'] ) ),
			'paged'          => bsf()->state()->page(),
			'bsf_filtered'   => 1,
		);

		if ( '' !== $atts['tag'] ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_tag',
				'field'    => 'slug',
				'terms'    => Sanitizer::slug_list( $atts['tag'] ),
			);
		}

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			wp_reset_postdata();

			return '<p class="woocommerce-info bsf-empty">' . esc_html__( 'No products were found matching your selection.', 'woo-blocksocial-filters' ) . '</p>';
		}

		$columns = max( 1, min( 8, (int) $atts['columns'] ) );

		if ( function_exists( 'wc_set_loop_prop' ) ) {
			wc_set_loop_prop( 'columns', $columns );
		}

		ob_start();

		echo '<div class="bsf-products" data-bsf-products>';

		if ( function_exists( 'woocommerce_product_loop_start' ) ) {
			woocommerce_product_loop_start();
		}

		while ( $query->have_posts() ) {
			$query->the_post();
			wc_get_template_part( 'content', 'product' );
		}

		if ( function_exists( 'woocommerce_product_loop_end' ) ) {
			woocommerce_product_loop_end();
		}

		echo '</div>';

		wp_reset_postdata();

		return (string) ob_get_clean();
	}

	/**
	 * Render just the active filter chips.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 */
	public function active_filters( $atts ): string {
		$atts = shortcode_atts( array( 'set' => '' ), (array) $atts, 'bsf_active_filters' );

		$set = bsf()->registry()->set( Sanitizer::key( (string) $atts['set'] ) );

		if ( ! $set ) {
			return '';
		}

		bsf()->assets()->enqueue();

		$definitions = bsf()->registry()->definitions( $set );

		$set['show_chips'] = true;

		return '<div class="bsf bsf--chips-only">' . bsf()->renderer()->render_chips( $definitions, $set ) . '</div>';
	}

	/**
	 * Render the sorting select.
	 */
	public function sort(): string {
		bsf()->assets()->enqueue();

		$definition = new \BlockSocial\Filters\Filters\FilterDefinition(
			\BlockSocial\Filters\Filters\FilterDefinition::normalize(
				array(
					'source'      => 'sort',
					'display'     => 'dropdown',
					'id'          => 'sort',
					'url_key'     => 'sort',
					'collapsible' => false,
				)
			)
		);

		return '<div class="bsf bsf--inline">' . bsf()->renderer()->render_filter( $definition, array( $definition ) ) . '</div>';
	}

	/**
	 * Render the keyword search box.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 */
	public function search( $atts ): string {
		$atts = shortcode_atts( array( 'placeholder' => '' ), (array) $atts, 'bsf_search' );

		bsf()->assets()->enqueue();

		$definition = new \BlockSocial\Filters\Filters\FilterDefinition(
			\BlockSocial\Filters\Filters\FilterDefinition::normalize(
				array(
					'source'      => 'search',
					'display'     => 'text',
					'id'          => 'srch',
					'url_key'     => \BlockSocial\Filters\Request\QueryState::SEARCH_PARAM,
					'collapsible' => false,
					'placeholder' => (string) $atts['placeholder'],
				)
			)
		);

		return '<div class="bsf bsf--inline">' . bsf()->renderer()->render_filter( $definition, array( $definition ) ) . '</div>';
	}

	/**
	 * Render the result counter.
	 */
	public function result_count(): string {
		$definitions = bsf()->query_hooks()->definitions();
		$constraints = bsf()->state()->constraints( $definitions, '', bsf()->renderer()->context_constraints() );
		$total       = bsf()->query()->count( $constraints, bsf()->renderer()->query_args() );

		return sprintf(
			'<span class="bsf-result-count" data-bsf-count>%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: number of products. */
					_n( '%s product', '%s products', $total, 'woo-blocksocial-filters' ),
					number_format_i18n( $total )
				)
			)
		);
	}
}

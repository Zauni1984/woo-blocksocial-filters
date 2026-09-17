<?php
/**
 * Renders filter sets.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Frontend;

use BlockSocial\Filters\Filters\FilterDefinition;
use BlockSocial\Filters\Support\Cache;
use BlockSocial\Filters\Support\ColorNames;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the markup for a filter panel.
 */
class Renderer {

	/** @var array<int,array<string,mixed>> Memoised term lists. */
	private $term_cache = array();

	/** @var string Mode of the set currently being rendered. */
	private $mode = 'auto';

	/** @var bool Whether the set currently being rendered starts collapsed. */
	private $collapse_all = false;

	/**
	 * Render a whole filter set.
	 *
	 * @param array<string,mixed> $set  Normalised set.
	 * @param array<string,mixed> $args Rendering arguments.
	 */
	public function render_set( array $set, array $args = array() ): string {
		$registry    = bsf()->registry();
		$state       = bsf()->state();
		$settings    = bsf()->settings();
		$definitions = $registry->definitions( $set );

		if ( empty( $definitions ) ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			array(
				'layout'  => $set['layout'],
				'mode'    => $set['mode'],
				'columns' => $set['columns'],
				'title'   => $set['title'],
				'class'   => '',
				'collapse_all' => ! empty( $set['collapse_all'] ),
			)
		);

		$this->mode         = (string) $args['mode'];
		$this->collapse_all = ! empty( $args['collapse_all'] );

		$constraints = $state->constraints( $definitions, '', $this->context_constraints() );
		$total       = bsf()->query()->count( $constraints, $this->query_args() );

		$classes = array(
			'bsf',
			'bsf--' . sanitize_html_class( $args['layout'] ),
			'bsf--mode-' . sanitize_html_class( $args['mode'] ),
			'bsf--cols-' . (int) $args['columns'],
		);

		if ( ! empty( $set['sticky'] ) ) {
			$classes[] = 'bsf--sticky';
		}

		// Only a panel that renders the toggle may be turned into a drawer;
		// without this the panel would be hidden on mobile with no way to open it.
		if ( ! empty( $set['mobile_drawer'] ) ) {
			$classes[] = 'bsf--drawer';
		}

		if ( $state->is_filtered() ) {
			$classes[] = 'is-filtered';
		}

		if ( ! empty( $args['class'] ) ) {
			$classes[] = sanitize_html_class( $args['class'] );
		}

		global $wp_query;

		$current_page = 1;
		$max_pages    = 0;

		if ( $wp_query instanceof \WP_Query ) {
			$current_page = max( 1, (int) ( $wp_query->get( 'paged' ) ?: 1 ) );
			$max_pages    = (int) $wp_query->max_num_pages;
		}

		$data = array(
			'data-bsf-page'      => (string) $current_page,
			'data-bsf-maxpages'  => (string) $max_pages,
			'data-bsf-set'       => $set['id'],
			'data-bsf-mode'      => $args['mode'],
			'data-bsf-layout'    => $args['layout'],
			'data-bsf-ajax'      => $settings->bool( 'ajax', true ) ? '1' : '0',
			'data-bsf-base'      => bsf()->url()->base_url(),
			'data-bsf-prefix'    => $state->prefix(),
			'data-bsf-urlmode'   => (string) $settings->get( 'url_mode', 'query' ),
			'data-bsf-separator' => (string) $settings->get( 'pretty_separator', '-' ),
			'data-bsf-total'     => (string) $total,
		);

		$attributes = '';

		foreach ( $data as $key => $value ) {
			$attributes .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"<?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<?php if ( ! empty( $set['mobile_drawer'] ) ) : ?>
				<button type="button" class="bsf-drawer-toggle" aria-expanded="false">
					<span class="bsf-drawer-toggle__icon" aria-hidden="true"></span>
					<span class="bsf-drawer-toggle__label"><?php echo esc_html( $args['title'] ); ?></span>
					<span class="bsf-drawer-toggle__count"><?php echo esc_html( (string) count( $state->selections() ) ); ?></span>
				</button>
			<?php endif; ?>

			<div class="bsf-panel" role="region" aria-label="<?php echo esc_attr( $args['title'] ); ?>">
				<div class="bsf-panel__head">
					<span class="bsf-panel__title"><?php echo esc_html( $args['title'] ); ?></span>
					<button type="button" class="bsf-panel__close" aria-label="<?php esc_attr_e( 'Close filters', 'woo-blocksocial-filters' ); ?>">&times;</button>
				</div>

				<?php echo $this->render_chips( $definitions, $set ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

				<div class="bsf-filters">
					<?php
					foreach ( $definitions as $definition ) {
						echo $this->render_filter( $definition, $definitions ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
				</div>

				<div class="bsf-panel__foot">
					<?php if ( 'apply' === $args['mode'] ) : ?>
						<button type="button" class="bsf-apply" disabled>
							<?php esc_html_e( 'Apply filters', 'woo-blocksocial-filters' ); ?>
						</button>
					<?php endif; ?>

					<?php if ( ! empty( $set['show_count'] ) ) : ?>
						<span class="bsf-result-count" data-bsf-count>
							<?php
							/* translators: %s: number of products. */
							echo esc_html( sprintf( _n( '%s product', '%s products', $total, 'woo-blocksocial-filters' ), number_format_i18n( $total ) ) );
							?>
						</span>
					<?php endif; ?>
				</div>
			</div>
			<div class="bsf-backdrop" hidden></div>
		</div>
		<?php

		$html = (string) ob_get_clean();

		/**
		 * Filter the rendered filter set markup.
		 *
		 * @param string $html Markup.
		 * @param array  $set  Set configuration.
		 */
		return apply_filters( 'bsf_render_set', $html, $set );
	}

	/**
	 * Active filter chips with per value removal.
	 *
	 * @param FilterDefinition[]  $definitions Definitions.
	 * @param array<string,mixed> $set         Set configuration.
	 */
	public function render_chips( array $definitions, array $set ): string {
		if ( empty( $set['show_chips'] ) ) {
			return '';
		}

		$state = bsf()->state();

		if ( ! $state->is_filtered() ) {
			return '';
		}

		$chips = array();

		foreach ( $definitions as $definition ) {
			$selection = $state->selection( $definition );

			if ( null === $selection ) {
				continue;
			}

			if ( 'range' === $selection['type'] ) {
				$chips[] = array(
					'label' => $definition->title() . ': ' . $this->format_range( $definition, $selection ),
					'url'   => $state->clear_url( $definition ),
					'key'   => $definition->url_key(),
					'value' => '',
				);

				continue;
			}

			if ( 'text' === $selection['type'] ) {
				$chips[] = array(
					'label' => $definition->title() . ': ' . $selection['text'],
					'url'   => $state->clear_url( $definition ),
					'key'   => $definition->url_key(),
					'value' => '',
				);

				continue;
			}

			$labels = $this->labels_for( $definition, (array) $selection['values'] );

			foreach ( (array) $selection['values'] as $value ) {
				$chips[] = array(
					'label' => $labels[ $value ] ?? $value,
					'url'   => $state->toggle_url( $definition, (string) $value ),
					'key'   => $definition->url_key(),
					'value' => (string) $value,
				);
			}
		}

		if ( empty( $chips ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="bsf-chips">
			<?php foreach ( $chips as $chip ) : ?>
				<a class="bsf-chip" href="<?php echo esc_url( $chip['url'] ); ?>" rel="nofollow"
					data-bsf-key="<?php echo esc_attr( $chip['key'] ); ?>"
					data-bsf-value="<?php echo esc_attr( $chip['value'] ); ?>">
					<span class="bsf-chip__label"><?php echo esc_html( $chip['label'] ); ?></span>
					<span class="bsf-chip__remove" aria-hidden="true">&times;</span>
					<span class="screen-reader-text"><?php esc_html_e( 'Remove filter', 'woo-blocksocial-filters' ); ?></span>
				</a>
			<?php endforeach; ?>

			<?php if ( ! empty( $set['show_reset'] ) ) : ?>
				<a class="bsf-chip bsf-chip--reset" href="<?php echo esc_url( bsf()->state()->reset_url() ); ?>" rel="nofollow" data-bsf-reset>
					<?php esc_html_e( 'Clear all', 'woo-blocksocial-filters' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render one filter.
	 *
	 * @param FilterDefinition   $definition  Filter.
	 * @param FilterDefinition[] $definitions Every filter in the set.
	 */
	public function render_filter( FilterDefinition $definition, array $definitions ): string {
		$body = '';

		switch ( $definition->source() ) {
			case 'price':
			case 'numeric':
			case 'date':
				$body = $this->render_range( $definition, $definitions );
				break;

			case 'rating':
				$body = $this->render_rating( $definition, $definitions );
				break;

			case 'stock':
			case 'sale':
			case 'featured':
				$body = $this->render_toggle( $definition, $definitions );
				break;

			case 'search':
				$body = $this->render_search( $definition );
				break;

			case 'sort':
				$body = $this->render_sort( $definition );
				break;

			default:
				$body = $this->render_terms( $definition, $definitions );
		}

		if ( '' === trim( $body ) ) {
			return '';
		}

		$collapsible = (bool) $definition->get( 'collapsible', true );

		// A panel full of expanded attribute lists is unusable in a sidebar, so
		// sets can start every filter closed. Anything already filtered on stays
		// open, otherwise the shopper cannot see what is active.
		$start_closed = (bool) $definition->get( 'collapsed', false ) || $this->collapse_all;
		$collapsed    = $collapsible && $start_closed && ! bsf()->state()->selection( $definition );

		$classes = array(
			'bsf-filter',
			'bsf-filter--' . sanitize_html_class( $definition->display() ),
			'bsf-filter--' . sanitize_html_class( $definition->source() ),
		);

		if ( $collapsible ) {
			$classes[] = 'is-collapsible';
		}

		if ( $collapsed ) {
			$classes[] = 'is-collapsed';
		}

		if ( $definition->get( 'css_class' ) ) {
			$classes[] = sanitize_html_class( (string) $definition->get( 'css_class' ) );
		}

		$panel_id = 'bsf-body-' . sanitize_html_class( $definition->id() );

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-bsf-filter="<?php echo esc_attr( $definition->id() ); ?>"
			data-bsf-key="<?php echo esc_attr( $definition->url_key() ); ?>"
			data-bsf-display="<?php echo esc_attr( $definition->display() ); ?>"
			data-bsf-multi="<?php echo $definition->get( 'multi', true ) ? '1' : '0'; ?>">

			<?php if ( $collapsible ) : ?>
				<button type="button" class="bsf-filter__title" aria-expanded="<?php echo $collapsed ? 'false' : 'true'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>">
					<span><?php echo esc_html( $definition->title() ); ?></span>
					<span class="bsf-filter__chevron" aria-hidden="true"></span>
				</button>
			<?php else : ?>
				<div class="bsf-filter__title bsf-filter__title--static"><span><?php echo esc_html( $definition->title() ); ?></span></div>
			<?php endif; ?>

			<div class="bsf-filter__body" id="<?php echo esc_attr( $panel_id ); ?>"<?php echo $collapsed ? ' hidden' : ''; ?>>
				<?php if ( $definition->get( 'description' ) ) : ?>
					<p class="bsf-filter__desc"><?php echo esc_html( (string) $definition->get( 'description' ) ); ?></p>
				<?php endif; ?>
				<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
		</div>
		<?php

		$html = (string) ob_get_clean();

		/**
		 * Filter the markup of a single rendered filter.
		 *
		 * @param string           $html       Markup.
		 * @param FilterDefinition $definition Filter.
		 */
		return apply_filters( 'bsf_render_filter', $html, $definition );
	}

	/* ---------------------------------------------------------------------
	 * Display types
	 * ------------------------------------------------------------------ */

	/**
	 * Term based displays: checkbox, radio, label, colour, image, dropdown, hierarchy.
	 *
	 * @param FilterDefinition   $definition  Filter.
	 * @param FilterDefinition[] $definitions All filters.
	 */
	private function render_terms( FilterDefinition $definition, array $definitions ): string {
		$options = $this->term_options( $definition, $definitions );

		if ( empty( $options ) ) {
			return '';
		}

		$display = $definition->display();

		if ( 'dropdown' === $display ) {
			return $this->render_dropdown( $definition, $options );
		}

		$limit     = (int) $definition->get( 'limit', 0 );
		$search    = (bool) $definition->get( 'search_box', false );
		$is_swatch = in_array( $display, array( 'color', 'image', 'label' ), true );

		ob_start();

		if ( $search ) {
			printf(
				'<div class="bsf-optionsearch"><input type="search" class="bsf-optionsearch__input" placeholder="%s" aria-label="%s" /></div>',
				esc_attr__( 'Search…', 'woo-blocksocial-filters' ),
				esc_attr( $definition->title() )
			);
		}

		printf(
			'<ul class="bsf-options bsf-options--%s%s">',
			esc_attr( $display ),
			$is_swatch ? ' bsf-options--inline' : ''
		);

		$index = 0;

		foreach ( $options as $option ) {
			echo $this->render_option( $definition, $option, $index, $limit ); // phpcs:ignore WordPress.Security.EscapeOutput
			$index++;
		}

		echo '</ul>';

		if ( $limit > 0 && count( $options ) > $limit ) {
			printf(
				'<button type="button" class="bsf-showmore" data-more="%s" data-less="%s">%s</button>',
				esc_attr__( 'Show more', 'woo-blocksocial-filters' ),
				esc_attr__( 'Show less', 'woo-blocksocial-filters' ),
				esc_html__( 'Show more', 'woo-blocksocial-filters' )
			);
		}

		return (string) ob_get_clean();
	}

	/**
	 * Render one term option, recursing into children for hierarchies.
	 *
	 * @param FilterDefinition    $definition Filter.
	 * @param array<string,mixed> $option     Option data.
	 * @param int                 $index      Position.
	 * @param int                 $limit      Visible limit.
	 */
	private function render_option( FilterDefinition $definition, array $option, int $index, int $limit ): string {
		$display     = $definition->display();
		$selected    = (bool) $option['selected'];
		$count       = (int) $option['count'];
		$disabled    = 0 === $count && ! $selected;
		$show_counts = (bool) $definition->get( 'show_counts', true ) && bsf()->settings()->bool( 'show_counts', true );

		$classes = array( 'bsf-option' );

		if ( $selected ) {
			$classes[] = 'is-selected';
		}

		if ( $disabled ) {
			$classes[] = 'is-disabled';
		}

		if ( $limit > 0 && $index >= $limit ) {
			$classes[] = 'is-hidden-extra';
		}

		$input_type = 'radio' === $display ? 'radio' : 'checkbox';
		$input_id   = 'bsf-' . sanitize_html_class( $definition->id() . '-' . $option['slug'] );

		ob_start();
		?>
		<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-bsf-value="<?php echo esc_attr( $option['slug'] ); ?>" data-bsf-label="<?php echo esc_attr( $option['name'] ); ?>">
			<a class="bsf-option__link" href="<?php echo esc_url( $option['url'] ); ?>" rel="nofollow"
				<?php echo $disabled ? ' aria-disabled="true"' : ''; ?>>

				<?php if ( in_array( $display, array( 'checkbox', 'radio', 'hierarchy' ), true ) ) : ?>
					<span class="bsf-control bsf-control--<?php echo esc_attr( $input_type ); ?>" aria-hidden="true"></span>
					<input class="bsf-native" type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $input_id ); ?>"
						value="<?php echo esc_attr( $option['slug'] ); ?>" <?php checked( $selected ); ?> tabindex="-1" />
				<?php endif; ?>

				<?php if ( 'color' === $display ) : ?>
					<span class="bsf-swatch bsf-swatch--color<?php echo $option['color2'] ? ' bsf-swatch--duo' : ''; ?>"
						style="<?php echo esc_attr( $this->swatch_style( $option ) ); ?>" aria-hidden="true"></span>
				<?php elseif ( 'image' === $display && $option['image'] ) : ?>
					<span class="bsf-swatch bsf-swatch--image" aria-hidden="true">
						<img src="<?php echo esc_url( $option['image'] ); ?>" alt="" loading="lazy" decoding="async" width="40" height="40" />
					</span>
				<?php endif; ?>

				<span class="bsf-option__label"><?php echo esc_html( $option['name'] ); ?></span>

				<?php if ( $show_counts ) : ?>
					<span class="bsf-option__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
				<?php endif; ?>
			</a>

			<?php if ( ! empty( $option['children'] ) ) : ?>
				<ul class="bsf-options bsf-options--child">
					<?php
					$child_index = 0;

					foreach ( $option['children'] as $child ) {
						echo $this->render_option( $definition, $child, $child_index, 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
						$child_index++;
					}
					?>
				</ul>
			<?php endif; ?>
		</li>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Inline style for a colour swatch.
	 *
	 * @param array<string,mixed> $option Option data.
	 */
	private function swatch_style( array $option ): string {
		return ColorNames::style(
			(string) ( $option['color'] ?? '' ),
			(string) ( $option['color2'] ?? '' ),
			(string) ( $option['gradient'] ?? '' )
		);
	}

	/**
	 * Select control for term filters.
	 *
	 * @param FilterDefinition                 $definition Filter.
	 * @param array<int,array<string,mixed>>   $options    Options.
	 */
	private function render_dropdown( FilterDefinition $definition, array $options ): string {
		$show_counts = (bool) $definition->get( 'show_counts', true );
		$placeholder = (string) $definition->get( 'placeholder', '' );

		if ( '' === $placeholder ) {
			/* translators: %s: filter name. */
			$placeholder = sprintf( __( 'Any %s', 'woo-blocksocial-filters' ), $definition->title() );
		}

		ob_start();
		?>
		<div class="bsf-select-wrap">
			<select class="bsf-select" aria-label="<?php echo esc_attr( $definition->title() ); ?>" data-bsf-select>
				<option value="" data-bsf-url="<?php echo esc_url( bsf()->state()->clear_url( $definition ) ); ?>"><?php echo esc_html( $placeholder ); ?></option>
				<?php foreach ( $options as $option ) : ?>
					<option value="<?php echo esc_attr( $option['slug'] ); ?>"
						data-bsf-url="<?php echo esc_url( $option['url'] ); ?>"
						<?php selected( (bool) $option['selected'] ); ?>
						<?php disabled( 0 === (int) $option['count'] && ! $option['selected'] ); ?>>
						<?php
						echo esc_html( $option['name'] );

						if ( $show_counts ) {
							echo esc_html( ' (' . number_format_i18n( (int) $option['count'] ) . ')' );
						}
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Numeric or date range control.
	 *
	 * @param FilterDefinition   $definition  Filter.
	 * @param FilterDefinition[] $definitions All filters.
	 */
	private function render_range( FilterDefinition $definition, array $definitions ): string {
		$state       = bsf()->state();
		$constraints = $state->constraints( $definitions, $definition->id(), $this->context_constraints() );
		$args        = $this->query_args();

		if ( 'price' === $definition->source() ) {
			$bounds = bsf()->query()->price_bounds( $constraints, $args );
		} else {
			$key    = 'date' === $definition->source() ? '_date' : (string) $definition->get( 'meta_key', '' );
			$bounds = bsf()->query()->numeric_bounds( $key, $constraints, $args );
		}

		$lo = (float) $bounds['min'];
		$hi = (float) $bounds['max'];

		if ( $hi <= $lo ) {
			return '';
		}

		$selection = $state->selection( $definition );
		$current_lo = isset( $selection['min'] ) && null !== $selection['min'] ? (float) $selection['min'] : $lo;
		$current_hi = isset( $selection['max'] ) && null !== $selection['max'] ? (float) $selection['max'] : $hi;

		$current_lo = max( $lo, min( $hi, $current_lo ) );
		$current_hi = max( $current_lo, min( $hi, $current_hi ) );

		$is_date = 'date' === $definition->source() || 'date' === $definition->display();

		if ( $is_date ) {
			return $this->render_date_range( $definition, $lo, $hi, $current_lo, $current_hi );
		}

		$step   = (float) $definition->get( 'step', 1 );
		$step   = $step > 0 ? $step : 1;
		$prefix = (string) $definition->get( 'prefix', '' );
		$suffix = (string) $definition->get( 'suffix', '' );

		if ( 'price' === $definition->source() && '' === $prefix && '' === $suffix && function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$prefix = html_entity_decode( get_woocommerce_currency_symbol() );
		}

		ob_start();
		?>
		<div class="bsf-range" data-bsf-range
			data-min="<?php echo esc_attr( (string) floor( $lo ) ); ?>"
			data-max="<?php echo esc_attr( (string) ceil( $hi ) ); ?>"
			data-step="<?php echo esc_attr( (string) $step ); ?>"
			data-prefix="<?php echo esc_attr( $prefix ); ?>"
			data-suffix="<?php echo esc_attr( $suffix ); ?>">

			<div class="bsf-range__track">
				<div class="bsf-range__fill"></div>
				<input type="range" class="bsf-range__input bsf-range__input--min"
					min="<?php echo esc_attr( (string) floor( $lo ) ); ?>"
					max="<?php echo esc_attr( (string) ceil( $hi ) ); ?>"
					step="<?php echo esc_attr( (string) $step ); ?>"
					value="<?php echo esc_attr( (string) $current_lo ); ?>"
					aria-label="<?php esc_attr_e( 'Minimum', 'woo-blocksocial-filters' ); ?>" />
				<input type="range" class="bsf-range__input bsf-range__input--max"
					min="<?php echo esc_attr( (string) floor( $lo ) ); ?>"
					max="<?php echo esc_attr( (string) ceil( $hi ) ); ?>"
					step="<?php echo esc_attr( (string) $step ); ?>"
					value="<?php echo esc_attr( (string) $current_hi ); ?>"
					aria-label="<?php esc_attr_e( 'Maximum', 'woo-blocksocial-filters' ); ?>" />
			</div>

			<div class="bsf-range__values">
				<label class="bsf-range__field">
					<span class="screen-reader-text"><?php esc_html_e( 'Minimum', 'woo-blocksocial-filters' ); ?></span>
					<input type="text" inputmode="decimal" class="bsf-range__number bsf-range__number--min" value="<?php echo esc_attr( (string) $current_lo ); ?>" />
				</label>
				<span class="bsf-range__dash">&ndash;</span>
				<label class="bsf-range__field">
					<span class="screen-reader-text"><?php esc_html_e( 'Maximum', 'woo-blocksocial-filters' ); ?></span>
					<input type="text" inputmode="decimal" class="bsf-range__number bsf-range__number--max" value="<?php echo esc_attr( (string) $current_hi ); ?>" />
				</label>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Date range control.
	 *
	 * @param FilterDefinition $definition Filter.
	 * @param float            $lo         Lower bound timestamp.
	 * @param float            $hi         Upper bound timestamp.
	 * @param float            $current_lo Selected lower bound.
	 * @param float            $current_hi Selected upper bound.
	 */
	private function render_date_range( FilterDefinition $definition, float $lo, float $hi, float $current_lo, float $current_hi ): string {
		ob_start();
		?>
		<div class="bsf-daterange" data-bsf-daterange>
			<label class="bsf-daterange__field">
				<span class="screen-reader-text"><?php esc_html_e( 'From', 'woo-blocksocial-filters' ); ?></span>
				<input type="date" class="bsf-daterange__input bsf-daterange__input--from"
					min="<?php echo esc_attr( gmdate( 'Y-m-d', (int) $lo ) ); ?>"
					max="<?php echo esc_attr( gmdate( 'Y-m-d', (int) $hi ) ); ?>"
					value="<?php echo esc_attr( gmdate( 'Y-m-d', (int) $current_lo ) ); ?>" />
			</label>
			<span class="bsf-range__dash">&ndash;</span>
			<label class="bsf-daterange__field">
				<span class="screen-reader-text"><?php esc_html_e( 'To', 'woo-blocksocial-filters' ); ?></span>
				<input type="date" class="bsf-daterange__input bsf-daterange__input--to"
					min="<?php echo esc_attr( gmdate( 'Y-m-d', (int) $lo ) ); ?>"
					max="<?php echo esc_attr( gmdate( 'Y-m-d', (int) $hi ) ); ?>"
					value="<?php echo esc_attr( gmdate( 'Y-m-d', (int) $current_hi ) ); ?>" />
			</label>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Star rating filter.
	 *
	 * @param FilterDefinition   $definition  Filter.
	 * @param FilterDefinition[] $definitions All filters.
	 */
	private function render_rating( FilterDefinition $definition, array $definitions ): string {
		$state       = bsf()->state();
		$constraints = $state->constraints( $definitions, $definition->id(), $this->context_constraints() );
		$args        = $this->query_args();
		$selected    = $state->selected_values( $definition );
		$show_counts = (bool) $definition->get( 'show_counts', true );

		ob_start();
		echo '<ul class="bsf-options bsf-options--rating">';

		for ( $stars = 5; $stars >= 1; $stars-- ) {
			$count = bsf()->query()->count(
				array_merge(
					$constraints,
					array(
						array(
							'type' => 'rating',
							'min'  => (float) $stars,
						),
					)
				),
				$args
			);

			if ( 0 === $count && (bool) $definition->get( 'hide_zero', true ) && ! in_array( (string) $stars, $selected, true ) ) {
				continue;
			}

			$is_selected = in_array( (string) $stars, $selected, true );
			?>
			<li class="bsf-option<?php echo $is_selected ? ' is-selected' : ''; ?>" data-bsf-value="<?php echo esc_attr( (string) $stars ); ?>">
				<a class="bsf-option__link" href="<?php echo esc_url( $state->toggle_url( $definition, (string) $stars ) ); ?>" rel="nofollow">
					<span class="bsf-stars" aria-hidden="true">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<span class="bsf-star<?php echo $i <= $stars ? ' is-on' : ''; ?>"></span>
						<?php endfor; ?>
					</span>
					<span class="bsf-option__label">
						<?php
						/* translators: %d: star rating. */
						echo esc_html( sprintf( __( '%d stars and up', 'woo-blocksocial-filters' ), $stars ) );
						?>
					</span>
					<?php if ( $show_counts ) : ?>
						<span class="bsf-option__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
					<?php endif; ?>
				</a>
			</li>
			<?php
		}

		echo '</ul>';

		return (string) ob_get_clean();
	}

	/**
	 * Single boolean toggle (stock, sale, featured).
	 *
	 * @param FilterDefinition   $definition  Filter.
	 * @param FilterDefinition[] $definitions All filters.
	 */
	private function render_toggle( FilterDefinition $definition, array $definitions ): string {
		$state    = bsf()->state();
		$selected = ! empty( $state->selected_values( $definition ) );

		$field = 'stock' === $definition->source() ? 'in_stock' : ( 'sale' === $definition->source() ? 'on_sale' : 'featured' );

		$constraints = $state->constraints( $definitions, $definition->id(), $this->context_constraints() );
		$count       = bsf()->query()->count(
			array_merge(
				$constraints,
				array(
					array(
						'type'  => 'flag',
						'field' => $field,
						'value' => 1,
					),
				)
			),
			$this->query_args()
		);

		if ( 0 === $count && ! $selected && (bool) $definition->get( 'hide_empty', true ) ) {
			return '';
		}

		ob_start();
		?>
		<ul class="bsf-options bsf-options--toggle">
			<li class="bsf-option<?php echo $selected ? ' is-selected' : ''; ?>" data-bsf-value="1">
				<a class="bsf-option__link" href="<?php echo esc_url( $state->toggle_url( $definition, '1' ) ); ?>" rel="nofollow">
					<span class="bsf-control bsf-control--switch" aria-hidden="true"></span>
					<span class="bsf-option__label"><?php echo esc_html( $definition->title() ); ?></span>
					<?php if ( $definition->get( 'show_counts', true ) ) : ?>
						<span class="bsf-option__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
					<?php endif; ?>
				</a>
			</li>
		</ul>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Keyword search box.
	 *
	 * @param FilterDefinition $definition Filter.
	 */
	private function render_search( FilterDefinition $definition ): string {
		$placeholder = (string) $definition->get( 'placeholder', '' );

		if ( '' === $placeholder ) {
			$placeholder = __( 'Search products…', 'woo-blocksocial-filters' );
		}

		ob_start();
		?>
		<div class="bsf-search">
			<input type="search" class="bsf-search__input" data-bsf-search
				value="<?php echo esc_attr( bsf()->state()->search() ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				aria-label="<?php echo esc_attr( $definition->title() ); ?>" />
			<button type="button" class="bsf-search__submit" aria-label="<?php esc_attr_e( 'Search', 'woo-blocksocial-filters' ); ?>"></button>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Sorting select.
	 *
	 * @param FilterDefinition $definition Filter.
	 */
	private function render_sort( FilterDefinition $definition ): string {
		$options = array(
			''           => __( 'Default', 'woo-blocksocial-filters' ),
			'popularity' => __( 'Popularity', 'woo-blocksocial-filters' ),
			'rating'     => __( 'Average rating', 'woo-blocksocial-filters' ),
			'date'       => __( 'Newest', 'woo-blocksocial-filters' ),
			'price'      => __( 'Price: low to high', 'woo-blocksocial-filters' ),
			'price-desc' => __( 'Price: high to low', 'woo-blocksocial-filters' ),
		);

		$current = bsf()->state()->sort();

		ob_start();
		?>
		<div class="bsf-select-wrap">
			<select class="bsf-select" data-bsf-sort aria-label="<?php echo esc_attr( $definition->title() ); ?>">
				<?php foreach ( $options as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Data helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Build the option list for a term based filter.
	 *
	 * @param FilterDefinition   $definition  Filter.
	 * @param FilterDefinition[] $definitions All filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function term_options( FilterDefinition $definition, array $definitions ): array {
		$taxonomy = $definition->taxonomy();

		if ( '' === $taxonomy ) {
			return array();
		}

		$state = bsf()->state();

		// With OR logic a facet must not narrow itself, otherwise selecting one
		// colour would hide every other colour.
		$exclude     = 'and' === $definition->get( 'logic', 'or' ) ? '' : $definition->id();
		$constraints = $state->constraints( $definitions, $exclude, $this->context_constraints() );
		$counts      = bsf()->query()->term_counts( $taxonomy, $constraints, $this->query_args() );

		$terms    = $this->terms( $taxonomy, (string) $definition->get( 'order', 'name' ) );
		$selected = $state->selected_values( $definition );
		// Step by step filtering always drops options that cannot lead anywhere,
		// which is the whole point of the mode.
		$hide_zero = 'step' === $this->mode
			|| ( (bool) $definition->get( 'hide_zero', true ) && bsf()->settings()->bool( 'hide_zero_counts', true ) );

		$flat = array();

		foreach ( $terms as $term ) {
			$count       = (int) ( $counts[ $term['term_id'] ] ?? 0 );
			$is_selected = in_array( $term['slug'], $selected, true );

			if ( 0 === $count && $hide_zero && ! $is_selected ) {
				continue;
			}

			$flat[ $term['term_id'] ] = array(
				'id'       => $term['term_id'],
				'parent'   => $term['parent'],
				'slug'     => $term['slug'],
				'name'     => $term['name'],
				'count'    => $count,
				'selected' => $is_selected,
				'url'      => $state->toggle_url( $definition, $term['slug'] ),
				'color'    => $term['color'],
				'color2'   => $term['color2'],
				'gradient' => $term['gradient'] ?? '',
				'image'    => $term['image'],
				'tooltip'  => $term['tooltip'],
				'children' => array(),
			);
		}

		if ( 'count' === $definition->get( 'order' ) ) {
			uasort( $flat, static fn( $a, $b ) => $b['count'] <=> $a['count'] );
		}

		$options = $definition->get( 'hierarchical', false ) && is_taxonomy_hierarchical( $taxonomy )
			? $this->build_tree( $flat )
			: array_values( $flat );

		/**
		 * Filter the options rendered for a term based filter.
		 *
		 * @param array            $options    Options.
		 * @param FilterDefinition $definition Filter.
		 */
		return apply_filters( 'bsf_term_options', $options, $definition );
	}

	/**
	 * Nest flat options into a tree.
	 *
	 * @param array<int,array<string,mixed>> $flat Flat option map keyed by term id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function build_tree( array $flat ): array {
		$roots = array();

		foreach ( $flat as $id => $option ) {
			$parent = (int) $option['parent'];

			if ( $parent && isset( $flat[ $parent ] ) ) {
				continue;
			}

			$roots[ $id ] = $option;
		}

		foreach ( $roots as $id => $root ) {
			$roots[ $id ]['children'] = $this->children_of( $id, $flat );
		}

		return array_values( $roots );
	}

	/**
	 * Collect the children of a term.
	 *
	 * @param int                            $parent Parent term id.
	 * @param array<int,array<string,mixed>> $flat   Flat option map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function children_of( int $parent, array $flat ): array {
		$children = array();

		foreach ( $flat as $id => $option ) {
			if ( (int) $option['parent'] !== $parent ) {
				continue;
			}

			$option['children'] = $this->children_of( $id, $flat );
			$children[]         = $option;
		}

		return $children;
	}

	/**
	 * Terms of a taxonomy with their swatch meta, cached.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $orderby  Ordering.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function terms( string $taxonomy, string $orderby = 'name' ): array {
		$cache_key = $taxonomy . '|' . $orderby;

		if ( isset( $this->term_cache[ $cache_key ] ) ) {
			return $this->term_cache[ $cache_key ];
		}

		$terms = Cache::remember(
			Cache::key( 'terms', $cache_key ),
			static function () use ( $taxonomy, $orderby ) {
				$args = array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				);

				if ( 'term_order' === $orderby ) {
					$args['orderby']  = 'meta_value_num';
					$args['meta_key'] = 'order_' . $taxonomy; // phpcs:ignore WordPress.DB.SlowDBQuery
					$args['order']    = 'ASC';
				} elseif ( in_array( $orderby, array( 'name', 'slug', 'id' ), true ) ) {
					$args['orderby'] = 'id' === $orderby ? 'term_id' : $orderby;
				}

				$terms = get_terms( $args );

				if ( is_wp_error( $terms ) ) {
					return array();
				}

				$out = array();

				foreach ( $terms as $term ) {
					$image_id = (int) get_term_meta( $term->term_id, 'bsf_image', true );
					$color    = (string) get_term_meta( $term->term_id, 'bsf_color', true );
					$color2   = (string) get_term_meta( $term->term_id, 'bsf_color2', true );
					$gradient = '';
					$explicit = '' !== $color;

					// Nobody wants to pick a hex value for a hundred colour
					// terms by hand, so an unset colour is guessed from the name.
					if ( ! $explicit ) {
						$guess    = ColorNames::resolve( (string) $term->name, (string) $term->slug );
						$color    = $guess['color'];
						$gradient = $guess['gradient'];

						if ( '' === $color2 ) {
							$color2 = $guess['color2'];
						}
					}

					$out[] = array(
						'term_id'  => (int) $term->term_id,
						'parent'   => (int) $term->parent,
						'slug'     => (string) $term->slug,
						'name'     => (string) $term->name,
						'color'    => $color,
						'color2'   => $color2,
						'gradient' => $gradient,
						'explicit' => $explicit,
						'image'    => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '',
						'tooltip'  => (string) get_term_meta( $term->term_id, 'bsf_tooltip', true ),
					);
				}

				return $out;
			},
			6 * HOUR_IN_SECONDS
		);

		$this->term_cache[ $cache_key ] = is_array( $terms ) ? $terms : array();

		return $this->term_cache[ $cache_key ];
	}

	/**
	 * Map slugs to display names.
	 *
	 * @param FilterDefinition $definition Filter.
	 * @param string[]         $slugs      Slugs.
	 *
	 * @return array<string,string>
	 */
	private function labels_for( FilterDefinition $definition, array $slugs ): array {
		if ( ! $definition->is_taxonomy() ) {
			return array();
		}

		$out = array();

		foreach ( $this->terms( $definition->taxonomy() ) as $term ) {
			if ( in_array( $term['slug'], $slugs, true ) ) {
				$out[ $term['slug'] ] = $term['name'];
			}
		}

		return $out;
	}

	/**
	 * Human readable range for a chip.
	 *
	 * @param FilterDefinition    $definition Filter.
	 * @param array<string,mixed> $selection  Selection.
	 */
	private function format_range( FilterDefinition $definition, array $selection ): string {
		$min = $selection['min'] ?? null;
		$max = $selection['max'] ?? null;

		$format = static function ( $value ) use ( $definition ) {
			if ( null === $value ) {
				return '';
			}

			if ( 'price' === $definition->source() && function_exists( 'wc_price' ) ) {
				return wp_strip_all_tags( wc_price( (float) $value ) );
			}

			if ( 'date' === $definition->source() ) {
				return date_i18n( (string) get_option( 'date_format' ), (int) $value );
			}

			return (string) $value;
		};

		return trim( $format( $min ) . ' – ' . $format( $max ) );
	}

	/**
	 * Constraints that come from the page itself (current category, search, etc.).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function context_constraints(): array {
		/**
		 * Filter constraints applied to every query on the current page.
		 *
		 * @param array $constraints Constraints.
		 */
		return (array) apply_filters( 'bsf_context_constraints', bsf()->query_hooks()->context_constraints() );
	}

	/**
	 * Shared query arguments (language, stock visibility).
	 *
	 * @return array<string,mixed>
	 */
	public function query_args(): array {
		$args = array(
			'lang'          => bsf()->multilingual()->current_language(),
			'in_stock_only' => 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ),
		);

		/**
		 * Filter the shared query arguments.
		 *
		 * @param array $args Arguments.
		 */
		return apply_filters( 'bsf_query_args', $args );
	}
}

<?php
/**
 * Settings and design tabs.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Admin;

use BlockSocial\Filters\Support\Cache;
use BlockSocial\Filters\Support\ColorNames;
use BlockSocial\Filters\Support\Colors;
use BlockSocial\Filters\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and persists the global settings.
 */
class SettingsPage {

	/**
	 * Behaviour, URL, performance and swatch settings.
	 */
	public function render_settings(): void {
		$settings = bsf()->settings();
		?>
		<form method="post" class="bsf-form">
			<?php wp_nonce_field( 'bsf_save_settings' ); ?>
			<input type="hidden" name="bsf_action" value="save_settings" />

			<h2><?php esc_html_e( 'Behaviour', 'woo-blocksocial-filters' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'AJAX filtering', 'woo-blocksocial-filters' ); ?></th>
					<td>
						<label><input type="checkbox" name="ajax" value="1" <?php checked( $settings->bool( 'ajax', true ) ); ?> />
							<?php esc_html_e( 'Update results without reloading the page', 'woo-blocksocial-filters' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-mode"><?php esc_html_e( 'Default mode', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<select name="mode" id="bsf-mode">
							<option value="auto" <?php selected( $settings->get( 'mode' ), 'auto' ); ?>><?php esc_html_e( 'Auto submit — apply on every click', 'woo-blocksocial-filters' ); ?></option>
							<option value="apply" <?php selected( $settings->get( 'mode' ), 'apply' ); ?>><?php esc_html_e( 'Select and apply — collect choices, then submit', 'woo-blocksocial-filters' ); ?></option>
							<option value="step" <?php selected( $settings->get( 'mode' ), 'step' ); ?>><?php esc_html_e( 'Step by step — apply immediately and hide impossible options', 'woo-blocksocial-filters' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-layout"><?php esc_html_e( 'Default layout', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<select name="layout" id="bsf-layout">
							<option value="vertical" <?php selected( $settings->get( 'layout' ), 'vertical' ); ?>><?php esc_html_e( 'Vertical panel', 'woo-blocksocial-filters' ); ?></option>
							<option value="horizontal" <?php selected( $settings->get( 'layout' ), 'horizontal' ); ?>><?php esc_html_e( 'Horizontal toolbar', 'woo-blocksocial-filters' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'woo-blocksocial-filters' ); ?></th>
					<td>
						<label><input type="checkbox" name="show_counts" value="1" <?php checked( $settings->bool( 'show_counts', true ) ); ?> /> <?php esc_html_e( 'Show product counters', 'woo-blocksocial-filters' ); ?></label><br />
						<label><input type="checkbox" name="hide_zero_counts" value="1" <?php checked( $settings->bool( 'hide_zero_counts', true ) ); ?> /> <?php esc_html_e( 'Hide options with no matching products', 'woo-blocksocial-filters' ); ?></label><br />
						<label><input type="checkbox" name="collapse" value="1" <?php checked( $settings->bool( 'collapse', true ) ); ?> /> <?php esc_html_e( 'Allow filters to collapse', 'woo-blocksocial-filters' ); ?></label><br />
						<label><input type="checkbox" name="scroll_top" value="1" <?php checked( $settings->bool( 'scroll_top', true ) ); ?> /> <?php esc_html_e( 'Scroll to the results after filtering', 'woo-blocksocial-filters' ); ?></label><br />
						<label><input type="checkbox" name="variation_match" value="1" <?php checked( $settings->bool( 'variation_match', true ) ); ?> /> <?php esc_html_e( 'Require one variation to match every selected attribute', 'woo-blocksocial-filters' ); ?></label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Archive placement', 'woo-blocksocial-filters' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Show above product archives', 'woo-blocksocial-filters' ); ?></th>
					<td>
						<label><input type="checkbox" name="auto_archive" value="1" <?php checked( $settings->bool( 'auto_archive', false ) ); ?> />
							<?php esc_html_e( 'Print a filter bar above the product grid automatically', 'woo-blocksocial-filters' ); ?></label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: detected theme name. */
								esc_html__( 'Detected theme: %s. The bar is placed directly above the product grid and collapses into a drawer on mobile.', 'woo-blocksocial-filters' ),
								esc_html( bsf()->themes()->theme_slug() )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-archive-set"><?php esc_html_e( 'Filter set', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<select name="archive_set" id="bsf-archive-set">
							<?php foreach ( bsf()->registry()->sets() as $id => $set ) : ?>
								<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( (string) $settings->get( 'archive_set', '' ), (string) $id ); ?>>
									<?php echo esc_html( (string) $set['title'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-archive-layout"><?php esc_html_e( 'Archive layout', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<select name="archive_layout" id="bsf-archive-layout">
							<option value="horizontal" <?php selected( $settings->get( 'archive_layout', 'horizontal' ), 'horizontal' ); ?>><?php esc_html_e( 'Horizontal toolbar', 'woo-blocksocial-filters' ); ?></option>
							<option value="vertical" <?php selected( $settings->get( 'archive_layout', 'horizontal' ), 'vertical' ); ?>><?php esc_html_e( 'Vertical panel', 'woo-blocksocial-filters' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-archive-hook"><?php esc_html_e( 'Custom hook', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="bsf-archive-hook" name="archive_hook" value="<?php echo esc_attr( (string) $settings->get( 'archive_hook', '' ) ); ?>" placeholder="woocommerce_before_shop_loop" />
						<input type="number" min="1" max="200" name="archive_priority" value="<?php echo esc_attr( (string) ( $settings->get( 'archive_priority', 25 ) ?: 25 ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave empty to use the best hook for the active theme.', 'woo-blocksocial-filters' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'URLs and SEO', 'woo-blocksocial-filters' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bsf-url-mode"><?php esc_html_e( 'URL style', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<select name="url_mode" id="bsf-url-mode">
							<option value="query" <?php selected( $settings->get( 'url_mode' ), 'query' ); ?>><?php esc_html_e( 'Query string — /shop/?f_color=blue', 'woo-blocksocial-filters' ); ?></option>
							<option value="pretty" <?php selected( $settings->get( 'url_mode' ), 'pretty' ); ?>><?php esc_html_e( 'Readable path — /shop/color-blue/', 'woo-blocksocial-filters' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-query-prefix"><?php esc_html_e( 'Parameter prefix', 'woo-blocksocial-filters' ); ?></label></th>
					<td><input type="text" id="bsf-query-prefix" name="query_prefix" value="<?php echo esc_attr( (string) $settings->get( 'query_prefix', 'f_' ) ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Search engines', 'woo-blocksocial-filters' ); ?></th>
					<td>
						<label><input type="checkbox" name="seo_noindex_multi" value="1" <?php checked( $settings->bool( 'seo_noindex_multi', true ) ); ?> /> <?php esc_html_e( 'Add noindex when more than one filter is active', 'woo-blocksocial-filters' ); ?></label><br />
						<label><input type="checkbox" name="canonical_clean" value="1" <?php checked( $settings->bool( 'canonical_clean', true ) ); ?> /> <?php esc_html_e( 'Point the canonical URL at the unfiltered archive', 'woo-blocksocial-filters' ); ?></label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Variation swatches', 'woo-blocksocial-filters' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Where', 'woo-blocksocial-filters' ); ?></th>
					<td>
						<label><input type="checkbox" name="swatches_single" value="1" <?php checked( $settings->bool( 'swatches_single', true ) ); ?> /> <?php esc_html_e( 'Replace the variation dropdowns on the product page', 'woo-blocksocial-filters' ); ?></label><br />
						<label><input type="checkbox" name="swatches_archive" value="1" <?php checked( $settings->bool( 'swatches_archive', false ) ); ?> /> <?php esc_html_e( 'Show available variations on product cards', 'woo-blocksocial-filters' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-swatch-shape"><?php esc_html_e( 'Shape', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<select name="swatch_shape" id="bsf-swatch-shape">
							<option value="circle" <?php selected( $settings->get( 'swatch_shape' ), 'circle' ); ?>><?php esc_html_e( 'Circle', 'woo-blocksocial-filters' ); ?></option>
							<option value="rounded" <?php selected( $settings->get( 'swatch_shape' ), 'rounded' ); ?>><?php esc_html_e( 'Rounded', 'woo-blocksocial-filters' ); ?></option>
							<option value="square" <?php selected( $settings->get( 'swatch_shape' ), 'square' ); ?>><?php esc_html_e( 'Square', 'woo-blocksocial-filters' ); ?></option>
						</select>
						<label class="bsf-inline"><?php esc_html_e( 'Size', 'woo-blocksocial-filters' ); ?>
							<input type="number" min="16" max="96" name="swatch_size" value="<?php echo esc_attr( (string) $settings->int( 'swatch_size', 16, 96 ) ); ?>" class="small-text" /> px
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-swatch-oos"><?php esc_html_e( 'Unavailable variations', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<select name="swatch_out_of_stock" id="bsf-swatch-oos">
							<option value="crossed" <?php selected( $settings->get( 'swatch_out_of_stock' ), 'crossed' ); ?>><?php esc_html_e( 'Cross out', 'woo-blocksocial-filters' ); ?></option>
							<option value="dimmed" <?php selected( $settings->get( 'swatch_out_of_stock' ), 'dimmed' ); ?>><?php esc_html_e( 'Dim', 'woo-blocksocial-filters' ); ?></option>
							<option value="hidden" <?php selected( $settings->get( 'swatch_out_of_stock' ), 'hidden' ); ?>><?php esc_html_e( 'Hide', 'woo-blocksocial-filters' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Attribute display', 'woo-blocksocial-filters' ); ?></th>
					<td>
						<?php
						$map = bsf()->swatches()->display_map();

						foreach ( bsf()->registry()->attribute_taxonomies() as $taxonomy => $label ) :
							$current = $map[ $taxonomy ] ?? 'auto';
							?>
							<p>
								<label class="bsf-attr-row">
									<span><?php echo esc_html( $label ); ?></span>
									<select name="attribute_display[<?php echo esc_attr( $taxonomy ); ?>]">
										<option value="auto" <?php selected( $current, 'auto' ); ?>><?php esc_html_e( 'Automatic', 'woo-blocksocial-filters' ); ?></option>
										<option value="label" <?php selected( $current, 'label' ); ?>><?php esc_html_e( 'Labels', 'woo-blocksocial-filters' ); ?></option>
										<option value="color" <?php selected( $current, 'color' ); ?>><?php esc_html_e( 'Colour swatches', 'woo-blocksocial-filters' ); ?></option>
										<option value="image" <?php selected( $current, 'image' ); ?>><?php esc_html_e( 'Image swatches', 'woo-blocksocial-filters' ); ?></option>
										<option value="select" <?php selected( $current, 'select' ); ?>><?php esc_html_e( 'Keep the dropdown', 'woo-blocksocial-filters' ); ?></option>
									</select>
								</label>
							</p>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Performance', 'woo-blocksocial-filters' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bsf-index-batch"><?php esc_html_e( 'Index batch size', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<input type="number" min="10" max="2000" id="bsf-index-batch" name="index_batch" value="<?php echo esc_attr( (string) $settings->int( 'index_batch', 10, 2000 ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Products processed per request while the index is building. Lower it on constrained hosting.', 'woo-blocksocial-filters' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-threshold"><?php esc_html_e( 'post__in threshold', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<input type="number" min="0" max="20000" id="bsf-threshold" name="post_in_threshold" value="<?php echo esc_attr( (string) $settings->int( 'post_in_threshold', 0, 20000 ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Result sets up to this size are passed to WP_Query as ids; larger sets join the index table instead.', 'woo-blocksocial-filters' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-cache-ttl"><?php esc_html_e( 'Count cache lifetime', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<input type="number" min="0" max="86400" id="bsf-cache-ttl" name="cache_ttl" value="<?php echo esc_attr( (string) $settings->int( 'cache_ttl', 0, 86400 ) ); ?>" class="small-text" />
						<?php esc_html_e( 'seconds', 'woo-blocksocial-filters' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Indexing', 'woo-blocksocial-filters' ); ?></th>
					<td>
						<label><input type="checkbox" name="async_index" value="1" <?php checked( $settings->bool( 'async_index', true ) ); ?> />
							<?php esc_html_e( 'Queue index updates instead of writing them during the request', 'woo-blocksocial-filters' ); ?></label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Paging results', 'woo-blocksocial-filters' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bsf-pagination-mode"><?php esc_html_e( 'After filtering', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<select name="pagination_mode" id="bsf-pagination-mode">
							<option value="auto" <?php selected( $settings->get( 'pagination_mode' ), 'auto' ); ?>><?php esc_html_e( 'Automatic — take paging over when the theme loads endlessly', 'woo-blocksocial-filters' ); ?></option>
							<option value="theme" <?php selected( $settings->get( 'pagination_mode' ), 'theme' ); ?>><?php esc_html_e( 'Use the theme pagination', 'woo-blocksocial-filters' ); ?></option>
							<option value="loadmore" <?php selected( $settings->get( 'pagination_mode' ), 'loadmore' ); ?>><?php esc_html_e( 'Show a "Load more" button', 'woo-blocksocial-filters' ); ?></option>
							<option value="infinite" <?php selected( $settings->get( 'pagination_mode' ), 'infinite' ); ?>><?php esc_html_e( 'Load the next page automatically while scrolling', 'woo-blocksocial-filters' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'A theme that brings its own endless loading builds its next page URL once, when the page loads, so after an AJAX filter it would fetch the unfiltered next page. On "Automatic" the theme keeps doing the loading and the plugin rewrites those requests to carry the active filters, so nothing in the theme has to change.', 'woo-blocksocial-filters' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Grid selectors', 'woo-blocksocial-filters' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bsf-products-container"><?php esc_html_e( 'Products container', 'woo-blocksocial-filters' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="bsf-products-container" name="products_container" value="<?php echo esc_attr( (string) $settings->get( 'products_container', '' ) ); ?>" placeholder="ul.products" />
						<p class="description"><?php esc_html_e( 'Only needed when a page builder renders the grid with its own markup.', 'woo-blocksocial-filters' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-pagination-selector"><?php esc_html_e( 'Pagination', 'woo-blocksocial-filters' ); ?></label></th>
					<td><input type="text" class="regular-text code" id="bsf-pagination-selector" name="pagination_selector" value="<?php echo esc_attr( (string) $settings->get( 'pagination_selector', '' ) ); ?>" placeholder=".woocommerce-pagination" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-count-selector"><?php esc_html_e( 'Result count', 'woo-blocksocial-filters' ); ?></label></th>
					<td><input type="text" class="regular-text code" id="bsf-count-selector" name="result_count_selector" value="<?php echo esc_attr( (string) $settings->get( 'result_count_selector', '' ) ); ?>" placeholder=".woocommerce-result-count" /></td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<form method="post" class="bsf-form bsf-fillcolors">
			<?php wp_nonce_field( 'bsf_fill_colors' ); ?>
			<input type="hidden" name="bsf_action" value="fill_colors" />
			<h2><?php esc_html_e( 'Swatch colours', 'woo-blocksocial-filters' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Attribute terms without a swatch colour already fall back to a colour guessed from their name, in German and English. Write those guesses into the terms to make them editable one by one. Colours you have already set are never overwritten.', 'woo-blocksocial-filters' ); ?>
			</p>
			<?php submit_button( __( 'Fill swatch colours from term names', 'woo-blocksocial-filters' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Persist guessed swatch colours so they can be adjusted per term.
	 *
	 * @return int Number of terms that received a colour.
	 */
	public function fill_colors(): int {
		$filled = 0;

		foreach ( array_keys( bsf()->registry()->attribute_taxonomies() ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( '' !== (string) get_term_meta( $term->term_id, 'bsf_color', true ) ) {
					continue;
				}

				$guess = ColorNames::resolve( (string) $term->name, (string) $term->slug );

				// Multicoloured terms stay on the runtime gradient: a single hex
				// would be a downgrade.
				if ( '' === $guess['color'] || '' !== $guess['gradient'] ) {
					continue;
				}

				update_term_meta( $term->term_id, 'bsf_color', $guess['color'] );

				if ( '' !== $guess['color2'] ) {
					update_term_meta( $term->term_id, 'bsf_color2', $guess['color2'] );
				}

				$filled++;
			}
		}

		Cache::flush();

		return $filled;
	}

	/**
	 * Colour and shape tab.
	 */
	public function render_design(): void {
		$settings = bsf()->settings();
		$stored   = $settings->get( 'colors', array() );
		$colors   = Colors::resolve( is_array( $stored ) ? $stored : array() );
		?>
		<form method="post" class="bsf-form bsf-design">
			<?php wp_nonce_field( 'bsf_save_design' ); ?>
			<input type="hidden" name="bsf_action" value="save_design" />

			<p class="description"><?php esc_html_e( 'Every colour below is a CSS variable, so themes and child themes can override any of them without touching the plugin.', 'woo-blocksocial-filters' ); ?></p>

			<?php foreach ( Colors::groups() as $group_key => $group ) : ?>
				<h2><?php echo esc_html( $group['label'] ); ?></h2>
				<div class="bsf-color-grid">
					<?php foreach ( $group['tokens'] as $token => $meta ) : ?>
						<label class="bsf-color-field">
							<span class="bsf-color-field__label"><?php echo esc_html( $meta['label'] ); ?></span>
							<input type="text" class="bsf-color-picker"
								data-default-color="<?php echo esc_attr( $meta['default'] ); ?>"
								name="colors[<?php echo esc_attr( $token ); ?>]"
								value="<?php echo esc_attr( $colors[ $token ] ); ?>" />
							<code>--bsf-<?php echo esc_html( str_replace( '_', '-', $token ) ); ?></code>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Shape and spacing', 'woo-blocksocial-filters' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bsf-radius"><?php esc_html_e( 'Corner radius', 'woo-blocksocial-filters' ); ?></label></th>
					<td><input type="number" min="0" max="40" id="bsf-radius" name="radius" value="<?php echo esc_attr( (string) $settings->int( 'radius', 0, 40 ) ); ?>" class="small-text" /> px</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-gap"><?php esc_html_e( 'Gap between options', 'woo-blocksocial-filters' ); ?></label></th>
					<td><input type="number" min="0" max="40" id="bsf-gap" name="gap" value="<?php echo esc_attr( (string) $settings->int( 'gap', 0, 40 ) ); ?>" class="small-text" /> px</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-font-size"><?php esc_html_e( 'Font size', 'woo-blocksocial-filters' ); ?></label></th>
					<td><input type="number" min="10" max="24" id="bsf-font-size" name="font_size" value="<?php echo esc_attr( (string) $settings->int( 'font_size', 10, 24 ) ); ?>" class="small-text" /> px</td>
				</tr>
				<tr>
					<th scope="row"><label for="bsf-custom-css"><?php esc_html_e( 'Additional CSS', 'woo-blocksocial-filters' ); ?></label></th>
					<td><textarea id="bsf-custom-css" name="custom_css" rows="6" class="large-text code"><?php echo esc_textarea( (string) $settings->get( 'custom_css', '' ) ); ?></textarea></td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Persist the settings tab.
	 *
	 * @param array<string,mixed> $post Raw $_POST.
	 */
	public function save( array $post ): void {
		$values = array(
			'ajax'                  => ! empty( $post['ajax'] ),
			'mode'                  => Sanitizer::choice( $post['mode'] ?? '', array( 'auto', 'apply', 'step' ), 'auto' ),
			'layout'                => Sanitizer::choice( $post['layout'] ?? '', array( 'vertical', 'horizontal' ), 'vertical' ),
			'show_counts'           => ! empty( $post['show_counts'] ),
			'hide_zero_counts'      => ! empty( $post['hide_zero_counts'] ),
			'collapse'              => ! empty( $post['collapse'] ),
			'scroll_top'            => ! empty( $post['scroll_top'] ),
			'variation_match'       => ! empty( $post['variation_match'] ),
			'auto_archive'          => ! empty( $post['auto_archive'] ),
			'archive_set'           => Sanitizer::key( $post['archive_set'] ?? '' ),
			'archive_layout'        => Sanitizer::choice( $post['archive_layout'] ?? '', array( 'vertical', 'horizontal' ), 'horizontal' ),
			'archive_hook'          => sanitize_key( (string) ( $post['archive_hook'] ?? '' ) ),
			'archive_priority'      => max( 1, min( 200, (int) ( $post['archive_priority'] ?? 25 ) ) ),
			'url_mode'              => Sanitizer::choice( $post['url_mode'] ?? '', array( 'query', 'pretty' ), 'query' ),
			'query_prefix'          => Sanitizer::key( $post['query_prefix'] ?? 'f_' ) ?: 'f_',
			'seo_noindex_multi'     => ! empty( $post['seo_noindex_multi'] ),
			'canonical_clean'       => ! empty( $post['canonical_clean'] ),
			'swatches_single'       => ! empty( $post['swatches_single'] ),
			'swatches_archive'      => ! empty( $post['swatches_archive'] ),
			'swatch_shape'          => Sanitizer::choice( $post['swatch_shape'] ?? '', array( 'circle', 'rounded', 'square' ), 'circle' ),
			'swatch_size'           => max( 16, min( 96, (int) ( $post['swatch_size'] ?? 34 ) ) ),
			'swatch_out_of_stock'   => Sanitizer::choice( $post['swatch_out_of_stock'] ?? '', array( 'crossed', 'dimmed', 'hidden' ), 'crossed' ),
			'index_batch'           => max( 10, min( 2000, (int) ( $post['index_batch'] ?? 250 ) ) ),
			'post_in_threshold'     => max( 0, min( 20000, (int) ( $post['post_in_threshold'] ?? 2000 ) ) ),
			'cache_ttl'             => max( 0, min( 86400, (int) ( $post['cache_ttl'] ?? 3600 ) ) ),
			'async_index'           => ! empty( $post['async_index'] ),
			'pagination_mode'       => Sanitizer::choice( $post['pagination_mode'] ?? '', array( 'auto', 'theme', 'loadmore', 'infinite' ), 'auto' ),
			'products_container'    => Sanitizer::selector( $post['products_container'] ?? '' ),
			'pagination_selector'   => Sanitizer::selector( $post['pagination_selector'] ?? '' ),
			'result_count_selector' => Sanitizer::selector( $post['result_count_selector'] ?? '' ),
		);

		bsf()->settings()->update( $values );

		$display = array();

		foreach ( (array) ( $post['attribute_display'] ?? array() ) as $taxonomy => $choice ) {
			$taxonomy = sanitize_key( (string) $taxonomy );

			if ( '' === $taxonomy ) {
				continue;
			}

			$display[ $taxonomy ] = Sanitizer::choice( $choice, array( 'auto', 'label', 'color', 'image', 'select' ), 'auto' );
		}

		update_option( \BlockSocial\Filters\Frontend\Swatches::DISPLAY_OPTION, $display, true );

		if ( 'pretty' === $values['url_mode'] ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Persist the design tab.
	 *
	 * @param array<string,mixed> $post Raw $_POST.
	 */
	public function save_design( array $post ): void {
		$defaults = Colors::defaults();
		$colors   = array();

		foreach ( (array) ( $post['colors'] ?? array() ) as $token => $value ) {
			$token = sanitize_key( (string) $token );

			if ( ! isset( $defaults[ $token ] ) ) {
				continue;
			}

			$colors[ $token ] = Sanitizer::color( $value, $defaults[ $token ] );
		}

		bsf()->settings()->update(
			array(
				'colors'     => $colors,
				'radius'     => max( 0, min( 40, (int) ( $post['radius'] ?? 8 ) ) ),
				'gap'        => max( 0, min( 40, (int) ( $post['gap'] ?? 8 ) ) ),
				'font_size'  => max( 10, min( 24, (int) ( $post['font_size'] ?? 14 ) ) ),
				'custom_css' => wp_strip_all_tags( wp_unslash( (string) ( $post['custom_css'] ?? '' ) ) ),
			)
		);
	}
}

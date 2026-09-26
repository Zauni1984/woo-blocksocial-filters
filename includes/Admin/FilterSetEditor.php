<?php
/**
 * Filter set editor.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Admin;

use BlockSocial\Filters\Filters\FilterDefinition;
use BlockSocial\Filters\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Lists and edits filter sets.
 */
class FilterSetEditor {

	/**
	 * Render the tab.
	 */
	public function render(): void {
		$registry = bsf()->registry();
		$sets     = $registry->sets();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$current_id = Sanitizer::key( $_GET['set'] ?? '' );

		if ( '' === $current_id || ! isset( $sets[ $current_id ] ) ) {
			$current_id = (string) array_key_first( $sets );
		}

		$set = $sets[ $current_id ] ?? $registry->auto_set();
		?>
		<div class="bsf-sets">
			<div class="bsf-sets__list">
				<h2><?php esc_html_e( 'Sets', 'woo-blocksocial-filters' ); ?></h2>
				<ul>
					<?php foreach ( $sets as $id => $item ) : ?>
						<li class="<?php echo $id === $current_id ? 'is-current' : ''; ?>">
							<a href="<?php echo esc_url( $this->tab_url( array( 'set' => (string) $id ) ) ); ?>">
								<?php echo esc_html( (string) $item['title'] ); ?>
							</a>
							<code>[bsf_filters set="<?php echo esc_attr( (string) $id ); ?>"]</code>
						</li>
					<?php endforeach; ?>
				</ul>
				<a class="button" href="<?php echo esc_url( $this->tab_url( array( 'set' => 'new' ) ) ); ?>">
					<?php esc_html_e( 'Add set', 'woo-blocksocial-filters' ); ?>
				</a>
			</div>

			<div class="bsf-sets__editor">
				<form method="post" class="bsf-form" id="bsf-set-form">
					<?php wp_nonce_field( 'bsf_save_set' ); ?>
					<input type="hidden" name="bsf_action" value="save_set" />
					<input type="hidden" name="set_id" value="<?php echo esc_attr( 'new' === $current_id ? '' : $current_id ); ?>" />

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bsf-set-title"><?php esc_html_e( 'Title', 'woo-blocksocial-filters' ); ?></label></th>
							<td><input type="text" id="bsf-set-title" class="regular-text" name="title" value="<?php echo esc_attr( (string) $set['title'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Layout', 'woo-blocksocial-filters' ); ?></th>
							<td>
								<select name="layout">
									<option value="vertical" <?php selected( $set['layout'], 'vertical' ); ?>><?php esc_html_e( 'Vertical panel', 'woo-blocksocial-filters' ); ?></option>
									<option value="horizontal" <?php selected( $set['layout'], 'horizontal' ); ?>><?php esc_html_e( 'Horizontal toolbar', 'woo-blocksocial-filters' ); ?></option>
								</select>
								<select name="mode">
									<option value="auto" <?php selected( $set['mode'], 'auto' ); ?>><?php esc_html_e( 'Auto submit', 'woo-blocksocial-filters' ); ?></option>
									<option value="apply" <?php selected( $set['mode'], 'apply' ); ?>><?php esc_html_e( 'Select and apply', 'woo-blocksocial-filters' ); ?></option>
									<option value="step" <?php selected( $set['mode'], 'step' ); ?>><?php esc_html_e( 'Step by step', 'woo-blocksocial-filters' ); ?></option>
								</select>
								<label class="bsf-inline"><?php esc_html_e( 'Columns', 'woo-blocksocial-filters' ); ?>
									<input type="number" min="1" max="6" name="columns" value="<?php echo esc_attr( (string) $set['columns'] ); ?>" class="small-text" />
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Panel', 'woo-blocksocial-filters' ); ?></th>
							<td>
								<label><input type="checkbox" name="collapse_all" value="1" <?php checked( $set['collapse_all'] ); ?> /> <?php esc_html_e( 'Start every filter collapsed', 'woo-blocksocial-filters' ); ?></label><br />
								<label><input type="checkbox" name="show_chips" value="1" <?php checked( $set['show_chips'] ); ?> /> <?php esc_html_e( 'Show active filter chips', 'woo-blocksocial-filters' ); ?></label><br />
								<label><input type="checkbox" name="show_reset" value="1" <?php checked( $set['show_reset'] ); ?> /> <?php esc_html_e( 'Show a clear all button', 'woo-blocksocial-filters' ); ?></label><br />
								<label><input type="checkbox" name="show_count" value="1" <?php checked( $set['show_count'] ); ?> /> <?php esc_html_e( 'Show the result counter', 'woo-blocksocial-filters' ); ?></label><br />
								<label><input type="checkbox" name="mobile_drawer" value="1" <?php checked( $set['mobile_drawer'] ); ?> /> <?php esc_html_e( 'Collapse into a drawer on mobile', 'woo-blocksocial-filters' ); ?></label><br />
								<label><input type="checkbox" name="sticky" value="1" <?php checked( $set['sticky'] ); ?> /> <?php esc_html_e( 'Stick the panel while scrolling', 'woo-blocksocial-filters' ); ?></label>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Filters', 'woo-blocksocial-filters' ); ?></h2>
					<div class="bsf-filter-rows" id="bsf-filter-rows">
						<?php
						$index = 0;

						foreach ( (array) $set['filters'] as $filter ) {
							$this->render_row( $filter, $index );
							$index++;
						}
						?>
					</div>

					<p>
						<button type="button" class="button" id="bsf-add-filter"><?php esc_html_e( 'Add filter', 'woo-blocksocial-filters' ); ?></button>
					</p>

					<?php submit_button( __( 'Save set', 'woo-blocksocial-filters' ) ); ?>
				</form>

				<?php if ( 'new' !== $current_id && count( $sets ) > 1 ) : ?>
					<form method="post" class="bsf-delete-form">
						<?php wp_nonce_field( 'bsf_delete_set' ); ?>
						<input type="hidden" name="bsf_action" value="delete_set" />
						<input type="hidden" name="set_id" value="<?php echo esc_attr( $current_id ); ?>" />
						<button type="submit" class="button-link delete"><?php esc_html_e( 'Delete this set', 'woo-blocksocial-filters' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<script type="text/html" id="tmpl-bsf-filter-row">
			<?php $this->render_row( FilterDefinition::normalize( array() ), '__INDEX__' ); ?>
		</script>
		<?php
	}

	/**
	 * Render one filter row.
	 *
	 * @param array<string,mixed> $filter Filter configuration.
	 * @param int|string          $index  Row index.
	 */
	private function render_row( array $filter, $index ): void {
		$name = 'filters[' . $index . ']';
		?>
		<div class="bsf-filter-row" data-index="<?php echo esc_attr( (string) $index ); ?>">
			<div class="bsf-filter-row__head">
				<span class="bsf-filter-row__handle" aria-hidden="true">⋮⋮</span>
				<strong class="bsf-filter-row__title"><?php echo esc_html( $filter['title'] ?: $filter['source'] ); ?></strong>
				<label class="bsf-filter-row__enabled">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( ! empty( $filter['enabled'] ) ); ?> />
					<?php esc_html_e( 'Enabled', 'woo-blocksocial-filters' ); ?>
				</label>
				<button type="button" class="button-link bsf-filter-row__toggle"><?php esc_html_e( 'Edit', 'woo-blocksocial-filters' ); ?></button>
				<button type="button" class="button-link delete bsf-filter-row__remove"><?php esc_html_e( 'Remove', 'woo-blocksocial-filters' ); ?></button>
			</div>

			<div class="bsf-filter-row__body">
				<p class="bsf-field">
					<label><?php esc_html_e( 'Source', 'woo-blocksocial-filters' ); ?></label>
					<select name="<?php echo esc_attr( $name ); ?>[source]" class="bsf-source">
						<?php foreach ( $this->source_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filter['source'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="bsf-field bsf-field--taxonomy">
					<label><?php esc_html_e( 'Taxonomy', 'woo-blocksocial-filters' ); ?></label>
					<select name="<?php echo esc_attr( $name ); ?>[taxonomy]">
						<option value=""><?php esc_html_e( '— select —', 'woo-blocksocial-filters' ); ?></option>
						<?php foreach ( bsf()->registry()->taxonomies() as $taxonomy => $label ) : ?>
							<option value="<?php echo esc_attr( $taxonomy ); ?>" <?php selected( $filter['taxonomy'], $taxonomy ); ?>>
								<?php echo esc_html( $label . ' (' . $taxonomy . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="bsf-field bsf-field--meta">
					<label><?php esc_html_e( 'Meta key', 'woo-blocksocial-filters' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $name ); ?>[meta_key]" value="<?php echo esc_attr( (string) $filter['meta_key'] ); ?>" list="bsf-meta-keys" />
				</p>

				<p class="bsf-field">
					<label><?php esc_html_e( 'Heading', 'woo-blocksocial-filters' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( (string) $filter['title'] ); ?>" />
				</p>

				<p class="bsf-field">
					<label><?php esc_html_e( 'URL name', 'woo-blocksocial-filters' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $name ); ?>[url_key]" value="<?php echo esc_attr( (string) $filter['url_key'] ); ?>" />
				</p>

				<p class="bsf-field">
					<label><?php esc_html_e( 'Display', 'woo-blocksocial-filters' ); ?></label>
					<select name="<?php echo esc_attr( $name ); ?>[display]">
						<?php foreach ( $this->display_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filter['display'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="bsf-field">
					<label><?php esc_html_e( 'Logic', 'woo-blocksocial-filters' ); ?></label>
					<select name="<?php echo esc_attr( $name ); ?>[logic]">
						<option value="or" <?php selected( $filter['logic'], 'or' ); ?>><?php esc_html_e( 'OR — any selected value', 'woo-blocksocial-filters' ); ?></option>
						<option value="and" <?php selected( $filter['logic'], 'and' ); ?>><?php esc_html_e( 'AND — all selected values', 'woo-blocksocial-filters' ); ?></option>
					</select>
				</p>

				<p class="bsf-field">
					<label><?php esc_html_e( 'Sort options by', 'woo-blocksocial-filters' ); ?></label>
					<select name="<?php echo esc_attr( $name ); ?>[order]">
						<option value="name" <?php selected( $filter['order'], 'name' ); ?>><?php esc_html_e( 'Name', 'woo-blocksocial-filters' ); ?></option>
						<option value="count" <?php selected( $filter['order'], 'count' ); ?>><?php esc_html_e( 'Product count', 'woo-blocksocial-filters' ); ?></option>
						<option value="term_order" <?php selected( $filter['order'], 'term_order' ); ?>><?php esc_html_e( 'Custom attribute order', 'woo-blocksocial-filters' ); ?></option>
						<option value="slug" <?php selected( $filter['order'], 'slug' ); ?>><?php esc_html_e( 'Slug', 'woo-blocksocial-filters' ); ?></option>
					</select>
				</p>

				<p class="bsf-field">
					<label><?php esc_html_e( 'Show first N options', 'woo-blocksocial-filters' ); ?></label>
					<input type="number" min="0" max="500" name="<?php echo esc_attr( $name ); ?>[limit]" value="<?php echo esc_attr( (string) $filter['limit'] ); ?>" class="small-text" />
				</p>

				<p class="bsf-field bsf-field--wide">
					<label><?php esc_html_e( 'Description', 'woo-blocksocial-filters' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $name ); ?>[description]" value="<?php echo esc_attr( (string) $filter['description'] ); ?>" />
				</p>

				<p class="bsf-field bsf-field--checks">
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_counts]" value="1" <?php checked( ! empty( $filter['show_counts'] ) ); ?> /> <?php esc_html_e( 'Counters', 'woo-blocksocial-filters' ); ?></label>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hide_zero]" value="1" <?php checked( ! empty( $filter['hide_zero'] ) ); ?> /> <?php esc_html_e( 'Hide empty options', 'woo-blocksocial-filters' ); ?></label>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[collapsible]" value="1" <?php checked( ! empty( $filter['collapsible'] ) ); ?> /> <?php esc_html_e( 'Collapsible', 'woo-blocksocial-filters' ); ?></label>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[collapsed]" value="1" <?php checked( ! empty( $filter['collapsed'] ) ); ?> /> <?php esc_html_e( 'Start collapsed', 'woo-blocksocial-filters' ); ?></label>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[search_box]" value="1" <?php checked( ! empty( $filter['search_box'] ) ); ?> /> <?php esc_html_e( 'Search inside options', 'woo-blocksocial-filters' ); ?></label>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[multi]" value="1" <?php checked( ! empty( $filter['multi'] ) ); ?> /> <?php esc_html_e( 'Multi select', 'woo-blocksocial-filters' ); ?></label>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hierarchical]" value="1" <?php checked( ! empty( $filter['hierarchical'] ) ); ?> /> <?php esc_html_e( 'Show as tree', 'woo-blocksocial-filters' ); ?></label>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[variation]" value="1" <?php checked( ! empty( $filter['variation'] ) ); ?> /> <?php esc_html_e( 'Match per variation', 'woo-blocksocial-filters' ); ?></label>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Source labels.
	 *
	 * @return array<string,string>
	 */
	private function source_labels(): array {
		return array(
			'attribute' => __( 'Product attribute', 'woo-blocksocial-filters' ),
			'taxonomy'  => __( 'Taxonomy (category, tag, brand…)', 'woo-blocksocial-filters' ),
			'price'     => __( 'Price', 'woo-blocksocial-filters' ),
			'numeric'   => __( 'Custom field (numeric)', 'woo-blocksocial-filters' ),
			'rating'    => __( 'Rating', 'woo-blocksocial-filters' ),
			'stock'     => __( 'In stock', 'woo-blocksocial-filters' ),
			'sale'      => __( 'On sale', 'woo-blocksocial-filters' ),
			'featured'  => __( 'Featured', 'woo-blocksocial-filters' ),
			'date'      => __( 'Date', 'woo-blocksocial-filters' ),
			'search'    => __( 'Keyword search', 'woo-blocksocial-filters' ),
			'sort'      => __( 'Sorting', 'woo-blocksocial-filters' ),
		);
	}

	/**
	 * Display labels.
	 *
	 * @return array<string,string>
	 */
	private function display_labels(): array {
		return array(
			'checkbox'  => __( 'Checkboxes', 'woo-blocksocial-filters' ),
			'radio'     => __( 'Radio buttons', 'woo-blocksocial-filters' ),
			'dropdown'  => __( 'Dropdown', 'woo-blocksocial-filters' ),
			'label'     => __( 'Labels', 'woo-blocksocial-filters' ),
			'color'     => __( 'Colour swatches', 'woo-blocksocial-filters' ),
			'image'     => __( 'Image swatches', 'woo-blocksocial-filters' ),
			'range'     => __( 'Range slider', 'woo-blocksocial-filters' ),
			'rating'    => __( 'Rating stars', 'woo-blocksocial-filters' ),
			'date'      => __( 'Date range', 'woo-blocksocial-filters' ),
			'text'      => __( 'Text input', 'woo-blocksocial-filters' ),
			'toggle'    => __( 'Switch', 'woo-blocksocial-filters' ),
			'hierarchy' => __( 'Nested list', 'woo-blocksocial-filters' ),
		);
	}

	/**
	 * Data handed to the admin script.
	 *
	 * @return array<string,mixed>
	 */
	public function source_data(): array {
		return array(
			'taxonomies'  => bsf()->registry()->taxonomies(),
			'numericKeys' => bsf()->registry()->numeric_keys(),
		);
	}

	/**
	 * Build a URL inside this tab.
	 *
	 * @param array<string,string> $args Extra query arguments.
	 */
	private function tab_url( array $args ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => Admin::PAGE,
					'tab'  => 'sets',
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Persist a set.
	 *
	 * @param array<string,mixed> $post Raw $_POST.
	 */
	public function save( array $post ): string {
		$id = Sanitizer::key( $post['set_id'] ?? '' );

		$filters = array();

		foreach ( (array) ( $post['filters'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$filters[] = $row;
		}

		return bsf()->registry()->save_set(
			$id,
			array(
				'title'         => $post['title'] ?? '',
				'layout'        => $post['layout'] ?? 'vertical',
				'mode'          => $post['mode'] ?? 'auto',
				'columns'       => $post['columns'] ?? 1,
				'collapse_all'  => ! empty( $post['collapse_all'] ),
				'show_chips'    => ! empty( $post['show_chips'] ),
				'show_reset'    => ! empty( $post['show_reset'] ),
				'show_count'    => ! empty( $post['show_count'] ),
				'mobile_drawer' => ! empty( $post['mobile_drawer'] ),
				'sticky'        => ! empty( $post['sticky'] ),
				'filters'       => $filters,
			)
		);
	}

	/**
	 * Delete a set.
	 *
	 * @param array<string,mixed> $post Raw $_POST.
	 */
	public function delete( array $post ): void {
		$id = Sanitizer::key( $post['set_id'] ?? '' );

		if ( '' !== $id ) {
			bsf()->registry()->delete_set( $id );
		}
	}
}

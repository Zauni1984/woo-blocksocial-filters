<?php
/**
 * Swatch metadata on attribute terms.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Admin;

use BlockSocial\Filters\Support\Cache;
use BlockSocial\Filters\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Adds colour, second colour, image and tooltip fields to attribute terms.
 */
class TermMeta {

	/**
	 * Register hooks for every attribute taxonomy.
	 */
	public function hooks(): void {
		add_action( 'admin_init', array( $this, 'register_taxonomy_hooks' ) );
	}

	/**
	 * Attach the field callbacks once taxonomies are registered.
	 */
	public function register_taxonomy_hooks(): void {
		foreach ( array_keys( bsf()->registry()->taxonomies() ) as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( $this, 'add_fields' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'edit_fields' ), 10, 2 );
			add_action( 'created_' . $taxonomy, array( $this, 'save' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save' ) );

			add_filter( 'manage_edit-' . $taxonomy . '_columns', array( $this, 'columns' ) );
			add_filter( 'manage_' . $taxonomy . '_custom_column', array( $this, 'column_content' ), 10, 3 );
		}
	}

	/**
	 * Fields on the "add term" form.
	 */
	public function add_fields(): void {
		wp_nonce_field( 'bsf_term_meta', 'bsf_term_nonce' );
		?>
		<div class="form-field">
			<label for="bsf_color"><?php esc_html_e( 'Swatch colour', 'woo-blocksocial-filters' ); ?></label>
			<input type="text" name="bsf_color" id="bsf_color" class="bsf-color-picker" value="" />
		</div>
		<div class="form-field">
			<label for="bsf_color2"><?php esc_html_e( 'Second colour (optional)', 'woo-blocksocial-filters' ); ?></label>
			<input type="text" name="bsf_color2" id="bsf_color2" class="bsf-color-picker" value="" />
		</div>
		<div class="form-field">
			<label for="bsf_image"><?php esc_html_e( 'Swatch image', 'woo-blocksocial-filters' ); ?></label>
			<span class="bsf-image-field">
				<input type="hidden" name="bsf_image" id="bsf_image" value="" />
				<span class="bsf-image-field__preview"></span>
				<button type="button" class="button bsf-image-field__select"><?php esc_html_e( 'Select image', 'woo-blocksocial-filters' ); ?></button>
				<button type="button" class="button-link bsf-image-field__remove"><?php esc_html_e( 'Remove', 'woo-blocksocial-filters' ); ?></button>
			</span>
		</div>
		<div class="form-field">
			<label for="bsf_tooltip"><?php esc_html_e( 'Tooltip', 'woo-blocksocial-filters' ); ?></label>
			<input type="text" name="bsf_tooltip" id="bsf_tooltip" value="" />
		</div>
		<?php
	}

	/**
	 * Fields on the "edit term" form.
	 *
	 * @param \WP_Term $term     Term.
	 * @param string   $taxonomy Taxonomy.
	 */
	public function edit_fields( $term, $taxonomy ): void {
		unset( $taxonomy );

		$color    = (string) get_term_meta( $term->term_id, 'bsf_color', true );
		$color2   = (string) get_term_meta( $term->term_id, 'bsf_color2', true );
		$image_id = (int) get_term_meta( $term->term_id, 'bsf_image', true );
		$tooltip  = (string) get_term_meta( $term->term_id, 'bsf_tooltip', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="bsf_color"><?php esc_html_e( 'Swatch colour', 'woo-blocksocial-filters' ); ?></label></th>
			<td>
				<?php wp_nonce_field( 'bsf_term_meta', 'bsf_term_nonce' ); ?>
				<input type="text" name="bsf_color" id="bsf_color" class="bsf-color-picker" value="<?php echo esc_attr( $color ); ?>" />
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="bsf_color2"><?php esc_html_e( 'Second colour', 'woo-blocksocial-filters' ); ?></label></th>
			<td>
				<input type="text" name="bsf_color2" id="bsf_color2" class="bsf-color-picker" value="<?php echo esc_attr( $color2 ); ?>" />
				<p class="description"><?php esc_html_e( 'Set a second colour for two tone swatches such as "black and white".', 'woo-blocksocial-filters' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="bsf_image"><?php esc_html_e( 'Swatch image', 'woo-blocksocial-filters' ); ?></label></th>
			<td>
				<span class="bsf-image-field">
					<input type="hidden" name="bsf_image" id="bsf_image" value="<?php echo esc_attr( (string) $image_id ); ?>" />
					<span class="bsf-image-field__preview">
						<?php if ( $image_id ) : ?>
							<img src="<?php echo esc_url( (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ) ); ?>" alt="" width="48" height="48" />
						<?php endif; ?>
					</span>
					<button type="button" class="button bsf-image-field__select"><?php esc_html_e( 'Select image', 'woo-blocksocial-filters' ); ?></button>
					<button type="button" class="button-link bsf-image-field__remove"><?php esc_html_e( 'Remove', 'woo-blocksocial-filters' ); ?></button>
				</span>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="bsf_tooltip"><?php esc_html_e( 'Tooltip', 'woo-blocksocial-filters' ); ?></label></th>
			<td><input type="text" name="bsf_tooltip" id="bsf_tooltip" value="<?php echo esc_attr( $tooltip ); ?>" class="regular-text" /></td>
		</tr>
		<?php
	}

	/**
	 * Persist the fields.
	 *
	 * @param int $term_id Term id.
	 */
	public function save( $term_id ): void {
		$term_id = absint( $term_id );

		if ( ! $term_id || ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_categories' ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['bsf_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsf_term_nonce'] ) ), 'bsf_term_meta' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$color    = Sanitizer::color( $_POST['bsf_color'] ?? '', '' );
		$color2   = Sanitizer::color( $_POST['bsf_color2'] ?? '', '' );
		$image_id = absint( $_POST['bsf_image'] ?? 0 );
		$tooltip  = sanitize_text_field( wp_unslash( (string) ( $_POST['bsf_tooltip'] ?? '' ) ) );
		// phpcs:enable

		$this->update_meta( $term_id, 'bsf_color', $color );
		$this->update_meta( $term_id, 'bsf_color2', $color2 );
		$this->update_meta( $term_id, 'bsf_image', $image_id ? (string) $image_id : '' );
		$this->update_meta( $term_id, 'bsf_tooltip', $tooltip );

		Cache::flush_terms();
	}

	/**
	 * Write or delete a meta value.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key.
	 * @param string $value   Value.
	 */
	private function update_meta( int $term_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );

			return;
		}

		update_term_meta( $term_id, $key, $value );
	}

	/**
	 * Add a swatch preview column.
	 *
	 * @param array<string,string> $columns Columns.
	 *
	 * @return array<string,string>
	 */
	public function columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$columns['bsf_swatch'] = __( 'Swatch', 'woo-blocksocial-filters' );

		return $columns;
	}

	/**
	 * Render the swatch preview column.
	 *
	 * @param string $content Existing content.
	 * @param string $column  Column key.
	 * @param int    $term_id Term id.
	 */
	public function column_content( $content, $column, $term_id ) {
		if ( 'bsf_swatch' !== $column ) {
			return $content;
		}

		$color    = (string) get_term_meta( $term_id, 'bsf_color', true );
		$color2   = (string) get_term_meta( $term_id, 'bsf_color2', true );
		$image_id = (int) get_term_meta( $term_id, 'bsf_image', true );

		if ( $image_id ) {
			return sprintf(
				'<img src="%s" alt="" width="28" height="28" style="border-radius:4px;object-fit:cover;" />',
				esc_url( (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ) )
			);
		}

		if ( '' === $color ) {
			return '&mdash;';
		}

		$style = '' !== $color2
			? sprintf( 'background-image:linear-gradient(135deg,%s 0 50%%,%s 50%% 100%%);', $color, $color2 )
			: sprintf( 'background-color:%s;', $color );

		return sprintf(
			'<span style="display:inline-block;width:24px;height:24px;border-radius:50%%;border:1px solid #ccd0d4;%s"></span>',
			esc_attr( $style )
		);
	}
}

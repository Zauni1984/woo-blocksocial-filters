<?php
/**
 * Classic sidebar widget.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Compat;

use BlockSocial\Filters\Support\Sanitizer;
use WP_Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a filter set in a classic sidebar.
 */
class FilterWidget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'bsf_filters',
			__( 'BlockSocial Product Filters', 'woo-blocksocial-filters' ),
			array( 'description' => __( 'Show a filter set in a sidebar.', 'woo-blocksocial-filters' ) )
		);
	}

	/**
	 * Front end output.
	 *
	 * @param array<string,mixed> $args     Sidebar arguments.
	 * @param array<string,mixed> $instance Widget instance.
	 */
	public function widget( $args, $instance ) {
		$set_id = Sanitizer::key( $instance['set'] ?? '' );
		$set    = bsf()->registry()->set( $set_id );

		if ( ! $set ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput

		bsf()->assets()->enqueue();

		echo bsf()->renderer()->render_set( $set, array( 'layout' => 'vertical' ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Settings form.
	 *
	 * @param array<string,mixed> $instance Widget instance.
	 */
	public function form( $instance ) {
		$current = Sanitizer::key( $instance['set'] ?? '' );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'set' ) ); ?>">
				<?php esc_html_e( 'Filter set', 'woo-blocksocial-filters' ); ?>
			</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'set' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'set' ) ); ?>">
				<?php foreach ( bsf()->registry()->sets() as $id => $set ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $current, (string) $id ); ?>>
						<?php echo esc_html( (string) $set['title'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php

		return '';
	}

	/**
	 * Persist settings.
	 *
	 * @param array<string,mixed> $new_instance New values.
	 * @param array<string,mixed> $old_instance Previous values.
	 *
	 * @return array<string,mixed>
	 */
	public function update( $new_instance, $old_instance ) {
		unset( $old_instance );

		return array( 'set' => Sanitizer::key( $new_instance['set'] ?? '' ) );
	}
}

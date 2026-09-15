<?php
/**
 * Index tab with the first run wizard and progress bar.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Admin;

use BlockSocial\Filters\Index\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Shows index health and drives the batched rebuild from the browser.
 */
class IndexPage {

	/**
	 * Render the tab.
	 */
	public function render(): void {
		$indexer   = bsf()->indexer();
		$state     = $indexer->state();
		$total     = $indexer->count_products();
		$processed = (int) $state['processed'];
		$percent   = $total > 0 ? min( 100, (int) round( ( $processed / max( 1, (int) $state['total'] ?: $total ) ) * 100 ) ) : 0;
		$rows      = $this->row_counts();
		?>
		<div class="bsf-index">
			<h2><?php esc_html_e( 'Product index', 'woo-blocksocial-filters' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Filtering reads from a dedicated index rather than from post meta and term relationships. The first build has to run once; after that the index keeps itself up to date whenever a product changes.', 'woo-blocksocial-filters' ); ?>
			</p>

			<?php if ( ! Schema::installed() ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'The index tables are missing. Deactivate and reactivate the plugin to create them.', 'woo-blocksocial-filters' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped bsf-index__stats">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Products in catalogue', 'woo-blocksocial-filters' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Indexed products', 'woo-blocksocial-filters' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $rows['products'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Index rows', 'woo-blocksocial-filters' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $rows['index'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Waiting in the update queue', 'woo-blocksocial-filters' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $rows['queue'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Status', 'woo-blocksocial-filters' ); ?></th>
						<td><code data-bsf-index-status><?php echo esc_html( (string) $state['status'] ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<div class="bsf-progress" data-bsf-progress hidden>
				<div class="bsf-progress__bar"><span style="width:<?php echo esc_attr( (string) $percent ); ?>%"></span></div>
				<p class="bsf-progress__text" data-bsf-progress-text></p>
			</div>

			<p class="bsf-index__actions">
				<button type="button" class="button button-primary" data-bsf-index-start>
					<?php esc_html_e( 'Rebuild the index', 'woo-blocksocial-filters' ); ?>
				</button>
				<button type="button" class="button" data-bsf-index-cancel hidden>
					<?php esc_html_e( 'Cancel', 'woo-blocksocial-filters' ); ?>
				</button>
			</p>

			<p class="description">
				<?php
				printf(
					/* translators: %s: WP-CLI command. */
					esc_html__( 'On very large catalogues run %s from the command line instead; it is faster and not bound by browser timeouts.', 'woo-blocksocial-filters' ),
					'<code>wp bsf index</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Row counts from the index tables.
	 *
	 * @return array{products:int,index:int,queue:int}
	 */
	private function row_counts(): array {
		global $wpdb;

		if ( ! Schema::installed() ) {
			return array(
				'products' => 0,
				'index'    => 0,
				'queue'    => 0,
			);
		}

		$product = Schema::table_product();
		$index   = Schema::table_index();
		$queue   = Schema::table_queue();

		return array(
			'products' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$product}" ), // phpcs:ignore WordPress.DB
			'index'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index}" ), // phpcs:ignore WordPress.DB
			'queue'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue}" ), // phpcs:ignore WordPress.DB
		);
	}
}

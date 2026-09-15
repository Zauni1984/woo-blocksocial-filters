<?php
/**
 * Plugin Name:       BlockSocial Filters for WooCommerce
 * Plugin URI:        https://github.com/zauni1984/woo-blocksocial-filters
 * Description:       High performance, index driven product filters for WooCommerce. Attribute, price, rating, stock and custom field filters with swatches, AJAX, SEO friendly URLs and variation swatches on single products.
 * Version:           1.0.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            BlockSocial
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-blocksocial-filters
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 *
 * @package BlockSocial\Filters
 */

defined( 'ABSPATH' ) || exit;

define( 'BSF_VERSION', '1.0.2' );
define( 'BSF_PLUGIN_FILE', __FILE__ );
define( 'BSF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BSF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BSF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once BSF_PLUGIN_DIR . 'includes/Autoloader.php';

\BlockSocial\Filters\Autoloader::register();

/**
 * Main plugin accessor.
 *
 * @return \BlockSocial\Filters\Plugin
 */
function bsf() {
	return \BlockSocial\Filters\Plugin::instance();
}

// Declare compatibility with WooCommerce HPOS and the cart/checkout blocks.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BSF_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BSF_PLUGIN_FILE, true );
	}
);

add_action( 'plugins_loaded', static function () { bsf()->boot(); }, 11 );

register_activation_hook( __FILE__, array( \BlockSocial\Filters\Install\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \BlockSocial\Filters\Install\Installer::class, 'deactivate' ) );

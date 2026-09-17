<?php
/**
 * Uninstall routine: removes every table and option the plugin created.
 *
 * @package BlockSocial\Filters
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$tables = array(
	$wpdb->prefix . 'bsf_index',
	$wpdb->prefix . 'bsf_product',
	$wpdb->prefix . 'bsf_numeric',
	$wpdb->prefix . 'bsf_queue',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

$options = array(
	'bsf_settings',
	'bsf_filter_sets',
	'bsf_attribute_display',
	'bsf_numeric_meta_keys',
	'bsf_index_state',
	'bsf_db_version',
	'bsf_version',
	'bsf_cache_version',
	'bsf_onboarding_done',
);

foreach ( $options as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

$wpdb->query( // phpcs:ignore WordPress.DB
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_bsf\_%' OR option_name LIKE '\_transient\_timeout\_bsf\_%'"
);

$wpdb->query( // phpcs:ignore WordPress.DB
	"DELETE FROM {$wpdb->termmeta}
	 WHERE meta_key IN ('bsf_color','bsf_color2','bsf_image','bsf_tooltip')"
);

wp_clear_scheduled_hook( 'bsf_process_queue' );

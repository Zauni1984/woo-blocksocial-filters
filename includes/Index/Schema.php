<?php
/**
 * Index table names and DDL.
 *
 * The plugin keeps a denormalised copy of everything it filters on. A filtered
 * query then touches three small, purpose built tables instead of postmeta and
 * term_relationships, which is what keeps response times flat as a catalogue
 * grows into six figures.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Schema definition and creation.
 */
class Schema {

	public const DB_VERSION = '1.0.0';
	public const DB_OPTION       = 'bsf_db_version';
	public const FULLTEXT_OPTION = 'bsf_fulltext';

	/**
	 * Term relationship index: one row per product/term (and variation) pair.
	 */
	public static function table_index(): string {
		global $wpdb;

		return $wpdb->prefix . 'bsf_index';
	}

	/**
	 * Product level facts: price, stock, rating, visibility, sorting keys.
	 */
	public static function table_product(): string {
		global $wpdb;

		return $wpdb->prefix . 'bsf_product';
	}

	/**
	 * Numeric ranges: weight, dimensions, custom fields, dates.
	 */
	public static function table_numeric(): string {
		global $wpdb;

		return $wpdb->prefix . 'bsf_numeric';
	}

	/**
	 * Pending reindex queue for incremental updates.
	 */
	public static function table_queue(): string {
		global $wpdb;

		return $wpdb->prefix . 'bsf_queue';
	}

	/**
	 * All table names.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array(
			self::table_index(),
			self::table_product(),
			self::table_numeric(),
			self::table_queue(),
		);
	}

	/**
	 * Create or upgrade the tables through dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$index   = self::table_index();
		$product = self::table_product();
		$numeric = self::table_numeric();
		$queue   = self::table_queue();

		// object_id holds the variation id for variation level attributes and the
		// product id otherwise, which is what allows "the same variation must match
		// all selected attributes" filtering.
		$sql = array();

		$sql[] = "CREATE TABLE {$index} (
			product_id bigint(20) unsigned NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			term_id bigint(20) unsigned NOT NULL,
			taxonomy varchar(48) NOT NULL DEFAULT '',
			is_variation tinyint(1) NOT NULL DEFAULT 0,
			in_stock tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (product_id,term_id,object_id),
			KEY term_lookup (term_id,in_stock,product_id),
			KEY tax_lookup (taxonomy,term_id),
			KEY object_lookup (object_id,term_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$product} (
			product_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_type varchar(32) NOT NULL DEFAULT '',
			visible tinyint(1) NOT NULL DEFAULT 1,
			searchable tinyint(1) NOT NULL DEFAULT 1,
			in_stock tinyint(1) NOT NULL DEFAULT 1,
			on_backorder tinyint(1) NOT NULL DEFAULT 0,
			on_sale tinyint(1) NOT NULL DEFAULT 0,
			featured tinyint(1) NOT NULL DEFAULT 0,
			downloadable tinyint(1) NOT NULL DEFAULT 0,
			virtual_product tinyint(1) NOT NULL DEFAULT 0,
			rating decimal(3,2) NOT NULL DEFAULT 0.00,
			rating_count int(11) NOT NULL DEFAULT 0,
			total_sales bigint(20) NOT NULL DEFAULT 0,
			stock_quantity int(11) DEFAULT NULL,
			min_price decimal(19,6) DEFAULT NULL,
			max_price decimal(19,6) DEFAULT NULL,
			date_created datetime DEFAULT NULL,
			menu_order int(11) NOT NULL DEFAULT 0,
			lang varchar(20) NOT NULL DEFAULT '',
			search_text text,
			PRIMARY KEY  (product_id),
			KEY visible_price (visible,min_price),
			KEY visible_rating (visible,rating),
			KEY visible_stock (visible,in_stock),
			KEY lang_visible (lang,visible),
			KEY sale_lookup (visible,on_sale),
			FULLTEXT KEY search_ft (search_text)
		) {$charset};";

		$sql[] = "CREATE TABLE {$numeric} (
			product_id bigint(20) unsigned NOT NULL,
			meta_key varchar(64) NOT NULL DEFAULT '',
			min_value decimal(19,6) NOT NULL DEFAULT 0,
			max_value decimal(19,6) NOT NULL DEFAULT 0,
			PRIMARY KEY  (product_id,meta_key),
			KEY key_min (meta_key,min_value),
			KEY key_max (meta_key,max_value)
		) {$charset};";

		$sql[] = "CREATE TABLE {$queue} (
			product_id bigint(20) unsigned NOT NULL,
			queued_at int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (product_id),
			KEY queued_at (queued_at)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::DB_OPTION, self::DB_VERSION, true );
		update_option( self::FULLTEXT_OPTION, self::detect_fulltext() ? 1 : 0, true );
	}

	/**
	 * Whether the full text index on the product table exists.
	 *
	 * Not every host allows it, so the keyword filter checks this flag and
	 * falls back to a LIKE scan when it is missing.
	 */
	public static function detect_fulltext(): bool {
		global $wpdb;

		$table = self::table_product();

		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'search_ft'", ARRAY_A ); // phpcs:ignore WordPress.DB

		return ! empty( $rows );
	}

	/**
	 * Cached answer to detect_fulltext().
	 */
	public static function has_fulltext(): bool {
		return 1 === (int) get_option( self::FULLTEXT_OPTION, 0 );
	}

	/**
	 * Whether the tables exist.
	 */
	public static function installed(): bool {
		global $wpdb;

		$table = self::table_product();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB

		return $found === $table;
	}

	/**
	 * Drop every table. Only used on uninstall.
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( self::DB_OPTION );
		delete_option( self::FULLTEXT_OPTION );
	}

	/**
	 * Empty the index tables without dropping them.
	 */
	public static function truncate(): void {
		global $wpdb;

		foreach ( array( self::table_index(), self::table_product(), self::table_numeric(), self::table_queue() ) as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB
		}
	}
}

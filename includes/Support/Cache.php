<?php
/**
 * Cache helper with a version stamp so invalidation is O(1).
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the object cache, falling back to transients when persistent caching is absent.
 */
class Cache {

	public const GROUP         = 'bsf';
	public const VERSION_OPTION = 'bsf_cache_version';

	/** @var string|null */
	private static $version = null;

	/**
	 * Current cache generation.
	 */
	public static function version(): string {
		if ( null === self::$version ) {
			$stored = get_option( self::VERSION_OPTION );

			if ( ! is_string( $stored ) || '' === $stored ) {
				$stored = (string) time();
				update_option( self::VERSION_OPTION, $stored, true );
			}

			self::$version = $stored;
		}

		return self::$version;
	}

	/**
	 * Bump the generation, invalidating every cached entry at once.
	 */
	public static function flush(): void {
		self::$version = (string) time() . wp_rand( 10, 99 );
		update_option( self::VERSION_OPTION, self::$version, true );

		if ( function_exists( 'wp_cache_flush_group' ) && wp_using_ext_object_cache() ) {
			wp_cache_flush_group( self::GROUP );
		}
	}

	/**
	 * Build a namespaced cache key.
	 *
	 * @param string $name  Logical name.
	 * @param mixed  $parts Data to hash into the key.
	 */
	public static function key( string $name, $parts = null ): string {
		$hash = null === $parts ? '' : md5( (string) wp_json_encode( $parts ) );

		return 'bsf_' . self::version() . '_' . $name . '_' . $hash;
	}

	/**
	 * Read a cached value.
	 *
	 * @param string $key Cache key from key().
	 *
	 * @return mixed Value or false when missing.
	 */
	public static function get( string $key ) {
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_get( $key, self::GROUP );
		}

		return get_transient( $key );
	}

	/**
	 * Store a value.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 */
	public static function set( string $key, $value, int $ttl = 3600 ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, self::GROUP, $ttl );

			return;
		}

		set_transient( $key, $value, $ttl );
	}

	/**
	 * Fetch from cache or compute and store.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $callback Producer.
	 * @param int      $ttl      Lifetime.
	 *
	 * @return mixed
	 */
	public static function remember( string $key, callable $callback, int $ttl = 3600 ) {
		$cached = self::get( $key );

		if ( false !== $cached ) {
			return $cached;
		}

		$value = $callback();
		self::set( $key, $value, $ttl );

		return $value;
	}

	/**
	 * Remove every transient created by the plugin. Used on uninstall.
	 */
	public static function purge_transients(): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_bsf\_%' OR option_name LIKE '\_transient\_timeout\_bsf\_%'"
		);
	}
}

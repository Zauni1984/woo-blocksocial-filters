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

	public const GROUP          = 'bsf';
	public const VERSION_OPTION = 'bsf_cache_version';
	public const CRON_GC        = 'bsf_cache_gc';

	/** @var string|null */
	private static $version = null;

	/** @var bool Whether this request already started a new generation. */
	private static $flushed = false;

	/** @var bool Whether stale generations are waiting to be deleted. */
	private static $garbage = false;

	/**
	 * Register the safety net that collects stale generations even when a
	 * request dies before shutdown.
	 */
	public static function hooks(): void {
		add_action( self::CRON_GC, array( __CLASS__, 'collect_garbage' ) );

		if ( ! wp_next_scheduled( self::CRON_GC ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_GC );
		}
	}

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
	 *
	 * Invalidation is a version bump rather than a delete so that it stays O(1)
	 * even while an import fires the write hooks thousands of times. That leaves
	 * the previous generation behind, though, and nothing ever reads those keys
	 * again — so WordPress' expire-on-read never collects them. They are deleted
	 * at shutdown instead, once per request no matter how often this was called.
	 */
	public static function flush(): void {
		if ( function_exists( 'wp_cache_flush_group' ) && wp_using_ext_object_cache() ) {
			self::$version = (string) time() . wp_rand( 10, 99 );
			update_option( self::VERSION_OPTION, self::$version, true );
			wp_cache_flush_group( self::GROUP );

			return;
		}

		// One generation per request. A second bump would only throw away what
		// this request has already recomputed, and costs another option write.
		if ( ! self::$flushed ) {
			self::$flushed = true;
			self::$version = (string) time() . wp_rand( 10, 99 );
			update_option( self::VERSION_OPTION, self::$version, true );
		}

		if ( self::$garbage ) {
			return;
		}

		self::$garbage = true;

		add_action( 'shutdown', array( __CLASS__, 'collect_garbage' ), 20 );
	}

	/**
	 * Delete every stored generation except the current one.
	 *
	 * Runs once per request that invalidated something, and daily from cron in
	 * case a request died before shutdown.
	 */
	public static function collect_garbage(): void {
		self::$garbage = false;

		if ( wp_using_ext_object_cache() ) {
			return;
		}

		self::purge_transients( self::version() );
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
	 * Remove transients created by the plugin.
	 *
	 * @param string $keep Generation to spare. Empty removes everything, which
	 *                     is what uninstall wants.
	 *
	 * @return int Rows deleted.
	 */
	public static function purge_transients( string $keep = '' ): int {
		global $wpdb;

		$value   = $wpdb->esc_like( '_transient_bsf_' ) . '%';
		$timeout = $wpdb->esc_like( '_transient_timeout_bsf_' ) . '%';

		if ( '' === $keep ) {
			$sql = $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$value,
				$timeout
			);
		} else {
			$sql = $wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				  WHERE ( option_name LIKE %s OR option_name LIKE %s )
				    AND option_name NOT LIKE %s",
				$value,
				$timeout,
				'%' . $wpdb->esc_like( '_bsf_' . $keep . '_' ) . '%'
			);
		}

		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}
}

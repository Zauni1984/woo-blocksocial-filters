<?php
/**
 * Cache helper.
 *
 * Two rules keep this out of trouble in wp_options, which never evicts:
 *
 * 1. Anything keyed by the shopper's own selection (counts, ids, facets) is
 *    held for the length of the request unless a persistent object cache is
 *    present. That key space is combinatorial — a crawler walking filter links
 *    visits combinations without limit — and every entry written to the options
 *    table would be a row that stays until something deletes it.
 *
 * 2. What does persist is keyed by a generation stamp that changes only when
 *    terms or configuration change, never when a product does. Product edits
 *    are the frequent event on a shop; if they rotated the stamp, every
 *    taxonomy's term list (hundreds of kilobytes each) would be rewritten after
 *    each stock sync.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the object cache, falling back to transients for a small, bounded set of keys.
 */
class Cache {

	public const GROUP = 'bsf';

	/** Stamp for data that depends on products. Only meaningful behind an object cache. */
	public const VERSION_OPTION = 'bsf_cache_version';

	/** Stamp for data that depends on terms and configuration. This one reaches wp_options. */
	public const TERMS_VERSION_OPTION = 'bsf_terms_version';

	public const CRON_GC = 'bsf_cache_gc';

	/** Rows deleted per statement. Small enough not to hold a long lock. */
	public const GC_BATCH = 2000;

	/** Seconds a shutdown pass may spend deleting before it defers the rest. */
	public const GC_BUDGET_REQUEST = 2;

	/** Seconds a cron pass may spend. */
	public const GC_BUDGET_CRON = 20;

	/** @var string|null */
	private static $version = null;

	/** @var string|null */
	private static $terms_version = null;

	/** @var bool Whether this request already rotated the terms stamp. */
	private static $terms_flushed = false;

	/** @var bool Whether a shutdown collection pass is already registered. */
	private static $garbage = false;

	/** @var array<string,mixed> Values held for this request only. */
	private static $memo = array();

	/**
	 * Register the safety net that collects stale generations even when a
	 * request dies before shutdown.
	 */
	public static function hooks(): void {
		add_action( self::CRON_GC, array( __CLASS__, 'run_cron_gc' ) );

		if ( ! wp_next_scheduled( self::CRON_GC ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_GC );
		}
	}

	/**
	 * Cron entry point. Gets a longer budget than a page request and keeps
	 * chaining itself while there is still something to delete.
	 */
	public static function run_cron_gc(): void {
		self::collect_garbage( self::GC_BUDGET_CRON );
		self::purge_rate_limits( self::GC_BATCH );
	}

	/* ---------------------------------------------------------------------
	 * Generations
	 * ------------------------------------------------------------------ */

	/**
	 * Stamp for product dependent data.
	 */
	public static function version(): string {
		if ( null === self::$version ) {
			self::$version = self::read_stamp( self::VERSION_OPTION );
		}

		return self::$version;
	}

	/**
	 * Stamp for term and configuration dependent data.
	 */
	public static function terms_version(): string {
		if ( null === self::$terms_version ) {
			self::$terms_version = self::read_stamp( self::TERMS_VERSION_OPTION );
		}

		return self::$terms_version;
	}

	/**
	 * Read a stamp, creating it on first use.
	 *
	 * @param string $option Option name.
	 */
	private static function read_stamp( string $option ): string {
		$stored = get_option( $option );

		if ( ! is_string( $stored ) || '' === $stored ) {
			$stored = (string) time();
			update_option( $option, $stored, true );
		}

		return $stored;
	}

	/**
	 * A product changed: counts, ids and bounds are stale.
	 *
	 * Without a persistent object cache none of that outlives the request, so
	 * there is nothing to invalidate beyond this request's own memo. Behind one,
	 * the whole group is dropped and the stamp rotates for drop-ins that cannot
	 * flush a group.
	 */
	public static function flush(): void {
		self::$memo = array();

		if ( ! wp_using_ext_object_cache() ) {
			return;
		}

		self::$version = self::new_stamp();
		update_option( self::VERSION_OPTION, self::$version, true );

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}
	}

	/**
	 * A term or a setting changed: the persisted term lists are stale too.
	 *
	 * Rotates the terms stamp once per request, however often this is called —
	 * an import that touches a thousand terms must not write a thousand option
	 * updates — and registers one shutdown pass to delete the previous
	 * generation, since nothing will ever read those keys again.
	 */
	public static function flush_terms(): void {
		self::flush();

		if ( self::$terms_flushed ) {
			return;
		}

		self::$terms_flushed = true;
		self::$terms_version = self::new_stamp();
		update_option( self::TERMS_VERSION_OPTION, self::$terms_version, true );

		if ( wp_using_ext_object_cache() ) {
			return;
		}

		if ( ! self::$garbage ) {
			self::$garbage = true;
			add_action( 'shutdown', array( __CLASS__, 'collect_garbage' ), 20 );
		}
	}

	/**
	 * Fresh stamp. Time plus two random digits so two rotations within one
	 * second still differ.
	 */
	private static function new_stamp(): string {
		return (string) time() . wp_rand( 10, 99 );
	}

	/* ---------------------------------------------------------------------
	 * Keys
	 * ------------------------------------------------------------------ */

	/**
	 * Key for product dependent data. Never persisted to wp_options.
	 *
	 * @param string $name  Logical name.
	 * @param mixed  $parts Data to hash into the key.
	 */
	public static function key( string $name, $parts = null ): string {
		return self::build_key( self::version(), $name, $parts );
	}

	/**
	 * Key for term and configuration dependent data. May be persisted, so the
	 * caller is responsible for the key space being small and bounded — one
	 * entry per taxonomy, not one per product or per selection.
	 *
	 * @param string $name  Logical name.
	 * @param mixed  $parts Data to hash into the key.
	 */
	public static function persistent_key( string $name, $parts = null ): string {
		return self::build_key( self::terms_version(), $name, $parts );
	}

	/**
	 * @param string $stamp Generation.
	 * @param string $name  Logical name.
	 * @param mixed  $parts Data to hash into the key.
	 */
	private static function build_key( string $stamp, string $name, $parts ): string {
		$hash = null === $parts ? '' : md5( (string) wp_json_encode( $parts ) );

		return 'bsf_' . $stamp . '_' . $name . '_' . $hash;
	}

	/* ---------------------------------------------------------------------
	 * Read / write
	 * ------------------------------------------------------------------ */

	/**
	 * Read a cached value.
	 *
	 * @param string $key Cache key.
	 *
	 * @return mixed Value or false when missing.
	 */
	public static function get( string $key ) {
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		if ( wp_using_ext_object_cache() ) {
			return wp_cache_get( $key, self::GROUP );
		}

		return get_transient( $key );
	}

	/**
	 * Store a value.
	 *
	 * @param string $key     Cache key.
	 * @param mixed  $value   Value.
	 * @param int    $ttl     Lifetime in seconds.
	 * @param bool   $persist Whether the value may outlive the request.
	 */
	public static function set( string $key, $value, int $ttl = 3600, bool $persist = false ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, self::GROUP, $ttl );

			return;
		}

		// Request scoped is the default. Opting in per call site made it one
		// forgotten flag away from filling the options table again, so the safe
		// behaviour is the one you get by saying nothing.
		if ( ! $persist ) {
			self::$memo[ $key ] = $value;

			return;
		}

		set_transient( $key, $value, $ttl );
	}

	/**
	 * Fetch or compute; held for this request unless an object cache is present.
	 *
	 * @param string   $key      Cache key from key().
	 * @param callable $callback Producer.
	 * @param int      $ttl      Lifetime, only relevant behind an object cache.
	 *
	 * @return mixed
	 */
	public static function remember( string $key, callable $callback, int $ttl = 3600 ) {
		$cached = self::get( $key );

		if ( false !== $cached ) {
			return $cached;
		}

		$value = $callback();
		self::set( $key, $value, $ttl, false );

		return $value;
	}

	/**
	 * Fetch or compute, and keep the result across requests.
	 *
	 * Only with a key from persistent_key(), and only for a key space with a
	 * known small bound.
	 *
	 * @param string   $key      Cache key from persistent_key().
	 * @param callable $callback Producer.
	 * @param int      $ttl      Lifetime.
	 *
	 * @return mixed
	 */
	public static function remember_persisted( string $key, callable $callback, int $ttl = 3600 ) {
		$cached = self::get( $key );

		if ( false !== $cached ) {
			return $cached;
		}

		$value = $callback();
		self::set( $key, $value, $ttl, true );

		return $value;
	}

	/* ---------------------------------------------------------------------
	 * Garbage collection
	 * ------------------------------------------------------------------ */

	/**
	 * Delete every persisted generation except the current one.
	 *
	 * Batched and time boxed. A shop that ran an older version can have
	 * millions of orphaned rows, and a single unbounded DELETE on a table that
	 * size holds a lock long enough to take the site down — or times out and
	 * rolls the whole thing back. Whatever is left is picked up by the next
	 * pass, so this always makes progress and never blocks a request.
	 *
	 * @param int $budget Seconds this pass may spend.
	 *
	 * @return int Rows deleted.
	 */
	public static function collect_garbage( int $budget = self::GC_BUDGET_REQUEST ): int {
		self::$garbage = false;

		if ( wp_using_ext_object_cache() ) {
			return 0;
		}

		$keep    = self::terms_version();
		$started = microtime( true );
		$total   = 0;

		do {
			$deleted = self::purge_transients( $keep, self::GC_BATCH );
			$total  += $deleted;

			if ( $deleted < self::GC_BATCH ) {
				return $total;
			}
		} while ( ( microtime( true ) - $started ) < $budget );

		self::schedule_drain();

		return $total;
	}

	/**
	 * Queue one more pass a minute out. WordPress refuses a duplicate single
	 * event within ten minutes, so this cannot stack.
	 */
	private static function schedule_drain(): void {
		$next = wp_next_scheduled( self::CRON_GC );

		if ( false === $next || $next > time() + 90 ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_GC );
		}
	}

	/**
	 * Remove persisted transients created by the plugin.
	 *
	 * The prefix is a literal, so `option_name LIKE 'prefix%'` is a range scan
	 * on the unique key rather than a table scan. The rate limiter's keys start
	 * with `bsfrl_` and are deliberately outside this pattern.
	 *
	 * @param string $keep  Generation to spare. Empty removes everything, which
	 *                      is what uninstall wants.
	 * @param int    $limit Rows per statement. 0 is unbounded and only safe on a
	 *                      table known to be small.
	 *
	 * @return int Rows deleted.
	 */
	public static function purge_transients( string $keep = '', int $limit = 0 ): int {
		global $wpdb;

		$value   = $wpdb->esc_like( '_transient_bsf_' ) . '%';
		$timeout = $wpdb->esc_like( '_transient_timeout_bsf_' ) . '%';
		$bound   = $limit > 0 ? ' LIMIT ' . absint( $limit ) : '';

		if ( '' === $keep ) {
			$sql = $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s{$bound}",
				$value,
				$timeout
			);
		} else {
			$sql = $wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				  WHERE ( option_name LIKE %s OR option_name LIKE %s )
				    AND option_name NOT LIKE %s{$bound}",
				$value,
				$timeout,
				'%' . $wpdb->esc_like( '_bsf_' . $keep . '_' ) . '%'
			);
		}

		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Drop rate limiter entries whose lifetime has passed.
	 *
	 * Each is read only while its minute lasts, so expire-on-read never fires
	 * for them. Called from cron and, one time in fifty, from the endpoint
	 * itself so that cleanup scales with the traffic that creates the rows.
	 *
	 * @param int $limit Rows per statement.
	 *
	 * @return int Rows deleted.
	 */
	public static function purge_rate_limits( int $limit = 200 ): int {
		global $wpdb;

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				"DELETE value, timeout
				   FROM {$wpdb->options} timeout
				   JOIN {$wpdb->options} value
				     ON value.option_name = CONCAT( '_transient_', SUBSTRING( timeout.option_name, 20 ) )
				  WHERE timeout.option_name LIKE %s
				    AND CAST( timeout.option_value AS UNSIGNED ) < %d
				  LIMIT %d",
				$wpdb->esc_like( '_transient_timeout_bsfrl_' ) . '%',
				time(),
				max( 1, $limit )
			)
		);
	}
}

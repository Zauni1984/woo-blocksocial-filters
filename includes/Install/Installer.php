<?php
/**
 * Activation, upgrade and deactivation routines.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Install;

use BlockSocial\Filters\Index\Indexer;
use BlockSocial\Filters\Index\Schema;
use BlockSocial\Filters\Support\Cache;
use BlockSocial\Filters\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle handling.
 */
class Installer {

	public const VERSION_OPTION   = 'bsf_version';
	public const ONBOARDING_OPTION = 'bsf_onboarding_done';

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		Schema::install();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', true );
		}

		add_option( self::ONBOARDING_OPTION, 0, '', true );
		update_option( self::VERSION_OPTION, BSF_VERSION, true );

		// Flag the index as empty so the admin prompts for the first build.
		if ( ! get_option( Indexer::STATE_OPTION ) ) {
			add_option(
				Indexer::STATE_OPTION,
				array(
					'status'    => 'idle',
					'total'     => 0,
					'processed' => 0,
				),
				'',
				false
			);
		}

		flush_rewrite_rules();
	}

	/**
	 * Runs on deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Indexer::CRON_QUEUE );
		wp_clear_scheduled_hook( Cache::CRON_GC );
		flush_rewrite_rules();
	}

	/**
	 * Applies pending upgrades on load.
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( self::VERSION_OPTION );

		if ( BSF_VERSION === $installed && Schema::installed() ) {
			return;
		}

		Schema::install();

		// Everything persisted by the previous version is stale — its key format
		// may have changed too — so rotate the terms stamp, which also registers
		// the batched shutdown pass that removes the old generation.
		Cache::flush_terms();

		// A shop that ran 1.0.5 or earlier can be sitting on millions of orphaned
		// rows. That must never be one big DELETE: an upgrade that locks the
		// options table is worse than the rows are. The shutdown pass above is
		// time boxed, so make sure cron keeps draining until the table is clean.
		if ( is_string( $installed ) && '' !== $installed && ! wp_next_scheduled( Cache::CRON_GC ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Cache::CRON_GC );
		}

		update_option( self::VERSION_OPTION, BSF_VERSION, true );
	}
}

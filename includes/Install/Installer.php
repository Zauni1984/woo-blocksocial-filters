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

		Cache::flush();

		// Up to 1.0.5 a flush only bumped the generation stamp and left the
		// previous generation in wp_options, where nothing ever read it again.
		// A busy shop can be sitting on millions of orphaned rows by now, so
		// this must not be one big DELETE — an upgrade that locks the options
		// table is worse than the rows are. Cache::flush() above already queued
		// the batched drain; all this does is make sure it keeps running until
		// the table is clean.
		if ( is_string( $installed ) && '' !== $installed && version_compare( $installed, '1.0.6', '<' ) && ! wp_next_scheduled( Cache::CRON_GC ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Cache::CRON_GC );
		}

		update_option( self::VERSION_OPTION, BSF_VERSION, true );
	}
}

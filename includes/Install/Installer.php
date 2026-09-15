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

		update_option( self::VERSION_OPTION, BSF_VERSION, true );
	}
}

<?php
/**
 * PSR-4 style autoloader for the plugin namespace.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal, dependency free autoloader.
 */
final class Autoloader {

	/** Root namespace handled by this loader. */
	private const PREFIX = 'BlockSocial\\Filters\\';

	/**
	 * Register the autoloader with SPL.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve a class name to a file inside includes/.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function load( string $class ): void {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$path     = BSF_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}

<?php
/**
 * Plugin container and bootstrap.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters;

use BlockSocial\Filters\Admin\Admin;
use BlockSocial\Filters\Compat\Builders;
use BlockSocial\Filters\Compat\Multilingual;
use BlockSocial\Filters\Compat\Themes;
use BlockSocial\Filters\Filters\Registry;
use BlockSocial\Filters\Frontend\Ajax;
use BlockSocial\Filters\Frontend\Assets;
use BlockSocial\Filters\Frontend\QueryHooks;
use BlockSocial\Filters\Frontend\Renderer;
use BlockSocial\Filters\Frontend\Shortcodes;
use BlockSocial\Filters\Frontend\Swatches;
use BlockSocial\Filters\Index\Indexer;
use BlockSocial\Filters\Index\QueryBuilder;
use BlockSocial\Filters\Index\Writer;
use BlockSocial\Filters\Install\Installer;
use BlockSocial\Filters\Request\QueryState;
use BlockSocial\Filters\Request\UrlManager;
use BlockSocial\Filters\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Service container. Kept intentionally small: lazily built singletons, no magic.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var array<string,object> */
	private $services = array();

	/** @var bool */
	private $booted = false;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor, use instance().
	 */
	private function __construct() {}

	/**
	 * Boot the plugin once WordPress and WooCommerce are available.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );

			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'woo-blocksocial-filters', false, dirname( BSF_PLUGIN_BASENAME ) . '/languages' );

		Installer::maybe_upgrade();

		// Always on: index maintenance, query state, URL handling.
		$this->writer()->hooks();
		$this->indexer()->hooks();
		$this->url()->hooks();
		$this->multilingual()->hooks();

		if ( is_admin() ) {
			$this->admin()->hooks();
		}

		$this->ajax()->hooks();
		$this->shortcodes()->hooks();
		$this->builders()->hooks();

		if ( ! is_admin() || wp_doing_ajax() ) {
			$this->assets()->hooks();
			$this->query_hooks()->hooks();
			$this->themes()->hooks();
		}

		if ( $this->settings()->get( 'swatches_single', true ) || $this->settings()->get( 'swatches_archive', false ) ) {
			$this->swatches()->hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'bsf', CLI\Commands::class );
		}

		/**
		 * Fires once every plugin service is wired up.
		 *
		 * @param Plugin $plugin Plugin container.
		 */
		do_action( 'bsf_loaded', $this );
	}

	/**
	 * Whether WooCommerce is available.
	 */
	public function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice shown when WooCommerce is missing.
	 */
	public function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'BlockSocial Filters requires WooCommerce to be installed and active.', 'woo-blocksocial-filters' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Service accessors
	 * ------------------------------------------------------------------ */

	/**
	 * Build or fetch a service instance.
	 *
	 * @param string $key   Service key.
	 * @param string $class Class name to instantiate.
	 */
	private function service( string $key, string $class ): object {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = new $class();
		}

		return $this->services[ $key ];
	}

	public function settings(): Settings {
		return $this->service( 'settings', Settings::class );
	}

	public function registry(): Registry {
		return $this->service( 'registry', Registry::class );
	}

	public function query(): QueryBuilder {
		return $this->service( 'query', QueryBuilder::class );
	}

	public function indexer(): Indexer {
		return $this->service( 'indexer', Indexer::class );
	}

	public function writer(): Writer {
		return $this->service( 'writer', Writer::class );
	}

	public function state(): QueryState {
		return $this->service( 'state', QueryState::class );
	}

	public function url(): UrlManager {
		return $this->service( 'url', UrlManager::class );
	}

	public function renderer(): Renderer {
		return $this->service( 'renderer', Renderer::class );
	}

	public function assets(): Assets {
		return $this->service( 'assets', Assets::class );
	}

	public function shortcodes(): Shortcodes {
		return $this->service( 'shortcodes', Shortcodes::class );
	}

	public function ajax(): Ajax {
		return $this->service( 'ajax', Ajax::class );
	}

	public function query_hooks(): QueryHooks {
		return $this->service( 'query_hooks', QueryHooks::class );
	}

	public function swatches(): Swatches {
		return $this->service( 'swatches', Swatches::class );
	}

	public function admin(): Admin {
		return $this->service( 'admin', Admin::class );
	}

	public function multilingual(): Multilingual {
		return $this->service( 'multilingual', Multilingual::class );
	}

	public function themes(): Themes {
		return $this->service( 'themes', Themes::class );
	}

	public function builders(): Builders {
		return $this->service( 'builders', Builders::class );
	}
}

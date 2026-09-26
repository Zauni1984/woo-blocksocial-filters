<?php
/**
 * Admin bootstrap: menu, assets, notices and form dispatch.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Admin;

use BlockSocial\Filters\Index\Schema;
use BlockSocial\Filters\Install\Installer;
use BlockSocial\Filters\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Wires up the admin screens.
 */
class Admin {

	public const PAGE       = 'bsf-filters';
	public const CAPABILITY = 'manage_woocommerce';

	/** @var SettingsPage */
	private $settings_page;

	/** @var FilterSetEditor */
	private $editor;

	/** @var IndexPage */
	private $index_page;

	/** @var TermMeta */
	private $term_meta;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings_page = new SettingsPage();
		$this->editor        = new FilterSetEditor();
		$this->index_page    = new IndexPage();
		$this->term_meta     = new TermMeta();
	}

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'onboarding_notice' ) );
		add_filter( 'plugin_action_links_' . BSF_PLUGIN_BASENAME, array( $this, 'action_links' ) );

		$this->term_meta->hooks();
	}

	/**
	 * Add the admin menu entry.
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Product Filters', 'woo-blocksocial-filters' ),
			__( 'Product Filters', 'woo-blocksocial-filters' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_post' ) );
		}
	}

	/**
	 * Current tab.
	 */
	public function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return Sanitizer::choice( $_GET['tab'] ?? 'sets', array( 'sets', 'design', 'settings', 'index' ), 'sets' );
	}

	/**
	 * Handle form submissions before the page renders.
	 */
	public function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'woo-blocksocial-filters' ), 403 );
		}

		$action = Sanitizer::key( $_POST['bsf_action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'bsf_' . $action );

		$redirect = add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => $this->current_tab(),
			),
			admin_url( 'admin.php' )
		);

		switch ( $action ) {
			case 'save_settings':
				$this->settings_page->save( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$redirect = add_query_arg( 'bsf_message', 'settings-saved', $redirect );
				break;

			case 'save_design':
				$this->settings_page->save_design( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$redirect = add_query_arg( 'bsf_message', 'design-saved', $redirect );
				break;

			case 'save_set':
				$id       = $this->editor->save( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$redirect = add_query_arg(
					array(
						'bsf_message' => 'set-saved',
						'set'         => $id,
					),
					$redirect
				);
				break;

			case 'fill_colors':
				$filled   = $this->settings_page->fill_colors();
				$redirect = add_query_arg(
					array(
						'bsf_message' => 'colors-filled',
						'bsf_count'   => $filled,
					),
					$redirect
				);
				break;

			case 'delete_set':
				$this->editor->delete( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$redirect = add_query_arg( 'bsf_message', 'set-deleted', $redirect );
				break;
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Enqueue admin assets on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( false === strpos( (string) $hook, self::PAGE ) && ! $this->is_term_screen() ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'bsf-admin', BSF_PLUGIN_URL . 'assets/css/admin.css', array( 'wp-color-picker' ), BSF_VERSION );

		wp_enqueue_media();
		wp_enqueue_script( 'bsf-admin', BSF_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker' ), BSF_VERSION, true );

		wp_localize_script(
			'bsf-admin',
			'bsfAdmin',
			array(
				'rest'    => esc_url_raw( rest_url( \BlockSocial\Filters\Frontend\Ajax::NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'sources' => $this->editor->source_data(),
				'i18n'    => array(
					'confirmDelete' => __( 'Delete this filter?', 'woo-blocksocial-filters' ),
					'selectImage'   => __( 'Select image', 'woo-blocksocial-filters' ),
					'useImage'      => __( 'Use image', 'woo-blocksocial-filters' ),
					'building'      => __( 'Building index…', 'woo-blocksocial-filters' ),
					'done'          => __( 'Index complete.', 'woo-blocksocial-filters' ),
					'cancelled'     => __( 'Index build cancelled.', 'woo-blocksocial-filters' ),
					'failed'        => __( 'The index build failed. Check the error log and try again.', 'woo-blocksocial-filters' ),
				),
			)
		);
	}

	/**
	 * Whether the current screen is an attribute term screen.
	 */
	private function is_term_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && ! empty( $screen->taxonomy ) && 0 === strpos( (string) $screen->taxonomy, 'pa_' );
	}

	/**
	 * Prompt for the first index build.
	 */
	public function onboarding_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && false !== strpos( (string) $screen->id, self::PAGE ) ) {
			return;
		}

		$state = bsf()->indexer()->state();

		if ( 'done' === $state['status'] || ! Schema::installed() ) {
			return;
		}

		if ( (int) $state['processed'] > 0 && 'running' === $state['status'] ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'BlockSocial Filters', 'woo-blocksocial-filters' ),
			esc_html__( 'The product index has not been built yet. Filters stay empty until the first build finishes.', 'woo-blocksocial-filters' ),
			esc_url(
				add_query_arg(
					array(
						'page' => self::PAGE,
						'tab'  => 'index',
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html__( 'Build the index', 'woo-blocksocial-filters' )
		);
	}

	/**
	 * Plugin row action links.
	 *
	 * @param string[] $links Existing links.
	 *
	 * @return string[]
	 */
	public function action_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) ),
				esc_html__( 'Settings', 'woo-blocksocial-filters' )
			)
		);

		return $links;
	}

	/**
	 * Render the admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab  = $this->current_tab();
		$tabs = array(
			'sets'     => __( 'Filter sets', 'woo-blocksocial-filters' ),
			'design'   => __( 'Design', 'woo-blocksocial-filters' ),
			'settings' => __( 'Settings', 'woo-blocksocial-filters' ),
			'index'    => __( 'Index', 'woo-blocksocial-filters' ),
		);
		?>
		<div class="wrap bsf-admin">
			<h1><?php esc_html_e( 'Product Filters', 'woo-blocksocial-filters' ); ?></h1>

			<?php $this->render_message(); ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="bsf-admin__body">
				<?php
				switch ( $tab ) {
					case 'design':
						$this->settings_page->render_design();
						break;

					case 'settings':
						$this->settings_page->render_settings();
						break;

					case 'index':
						$this->index_page->render();
						break;

					default:
						$this->editor->render();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Print the result of the last action.
	 */
	private function render_message(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$message = Sanitizer::key( $_GET['bsf_message'] ?? '' );

		if ( 'colors-filled' === $message ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$count = absint( $_GET['bsf_count'] ?? 0 );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of attribute terms. */
						_n( 'Swatch colour set for %s term.', 'Swatch colours set for %s terms.', $count, 'woo-blocksocial-filters' ),
						number_format_i18n( $count )
					)
				)
			);

			return;
		}

		$messages = array(
			'settings-saved' => __( 'Settings saved.', 'woo-blocksocial-filters' ),
			'design-saved'   => __( 'Design saved.', 'woo-blocksocial-filters' ),
			'set-saved'      => __( 'Filter set saved.', 'woo-blocksocial-filters' ),
			'set-deleted'    => __( 'Filter set deleted.', 'woo-blocksocial-filters' ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $message ] ) );
	}
}

<?php
/**
 * REST endpoints for AJAX filtering and index building.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Frontend;

use BlockSocial\Filters\Index\Indexer;
use BlockSocial\Filters\Support\Sanitizer;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Read only filtering endpoint plus the authenticated indexer endpoints.
 */
class Ajax {

	public const NAMESPACE = 'blocksocial-filters/v1';

	/** Requests allowed per IP per minute on the public endpoint. */
	private const RATE_LIMIT = 120;

	/**
	 * Register routes.
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Route registration.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/filter',
			array(
				'methods'             => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
				'callback'            => array( $this, 'handle_filter' ),
				'permission_callback' => array( $this, 'public_permission' ),
				'args'                => array(
					'url' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
					'set'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => array( Sanitizer::class, 'key' ),
					),
					'sets' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/index/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_index_status' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/index/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_index_run' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'action' => array(
						'type'    => 'string',
						'default' => 'batch',
					),
				),
			)
		);
	}

	/**
	 * The filter endpoint is read only, so it stays open for cached anonymous
	 * traffic. It is rate limited and every parameter is re-sanitised.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error
	 */
	public function public_permission( WP_REST_Request $request ) {
		unset( $request );

		if ( $this->rate_limited() ) {
			return new WP_Error( 'bsf_rate_limited', __( 'Too many requests.', 'woo-blocksocial-filters' ), array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * Capability check for the indexer endpoints.
	 */
	public function admin_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Simple per IP throttle backed by the object cache.
	 */
	private function rate_limited(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $ip ) {
			return false;
		}

		// Deliberately not 'bsf_' + '_': the generation collector matches
		// _transient_bsf\_% and would otherwise reset the live counter.
		$key   = 'bsfrl_' . md5( $ip . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $key );

		if ( $count >= (int) apply_filters( 'bsf_rate_limit', self::RATE_LIMIT ) ) {
			return true;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Filtering
	 * ------------------------------------------------------------------ */

	/**
	 * Return the rendered products and filters for a filtered URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_filter( WP_REST_Request $request ): WP_REST_Response {
		$url = (string) $request->get_param( 'url' );
		$set_id = (string) $request->get_param( 'set' );

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $url_host || ( $home_host && strtolower( $url_host ) !== strtolower( $home_host ) ) ) {
			return new WP_REST_Response(
				array( 'error' => __( 'Invalid URL.', 'woo-blocksocial-filters' ) ),
				400
			);
		}

		$query = $this->run_query( $url );

		$products   = $this->render_products( $query );
		$pagination = $this->render_pagination( $query, $url );

		// A page can carry more than one panel (an archive bar and a sidebar
		// widget, say). All of them are re-rendered, otherwise the ones left
		// behind keep a stale selection and undo it on the next click.
		$requested = array_filter(
			array_map(
				array( Sanitizer::class, 'key' ),
				explode( ',', (string) $request->get_param( 'sets' ) )
			)
		);

		if ( empty( $requested ) && '' !== $set_id ) {
			$requested = array( $set_id );
		}

		$by_set = array();

		foreach ( array_slice( array_unique( $requested ), 0, 5 ) as $id ) {
			$set = bsf()->registry()->set( $id );

			if ( $set ) {
				$by_set[ $id ] = bsf()->renderer()->render_set( $set );
			}
		}

		$html = '' !== $set_id && isset( $by_set[ $set_id ] ) ? $by_set[ $set_id ] : (string) reset( $by_set );

		$found = (int) $query->found_posts;
		$page  = max( 1, (int) $query->get( 'paged', 1 ) );

		wp_reset_postdata();

		return new WP_REST_Response(
			array(
				'url'        => $url,
				'products'   => $products,
				'filters'    => $html,
				'filters_by_set' => $by_set,
				'pagination' => $pagination,
				'found'      => $found,
				'page'       => $page,
				'max_pages'  => (int) $query->max_num_pages,
				'count_text' => sprintf(
					/* translators: %s: number of products. */
					_n( '%s product', '%s products', $found, 'woo-blocksocial-filters' ),
					number_format_i18n( $found )
				),
			),
			200
		);
	}

	/**
	 * Rebuild the archive query described by a URL.
	 *
	 * @param string $url Target URL.
	 */
	private function run_query( string $url ): WP_Query {
		$parts = wp_parse_url( $url );
		$path  = (string) ( $parts['path'] ?? '/' );
		$query = array();

		if ( ! empty( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
		}

		// Feed the parsed parameters into the state object.
		bsf()->state()->set_raw( is_array( $query ) ? $query : array() );
		bsf()->url()->set_base_url( $url );

		$vars = $this->resolve_query_vars( $path, is_array( $query ) ? $query : array() );

		$vars['post_type']      = 'product';
		$vars['post_status']    = 'publish';
		$vars['bsf_filtered']   = 1;
		$vars['posts_per_page'] = $this->posts_per_page();
		$vars['paged']          = max( 1, absint( $query['paged'] ?? ( $vars['paged'] ?? 1 ) ) );

		$sort = bsf()->state()->sort();

		if ( '' !== $sort ) {
			$ordering = bsf()->query_hooks()->ordering_args( array() );
			$vars     = array_merge( $vars, $ordering );
		}

		/**
		 * Filter the query vars used to rebuild the grid during AJAX filtering.
		 *
		 * @param array  $vars Query vars.
		 * @param string $url  Requested URL.
		 */
		$vars = apply_filters( 'bsf_ajax_query_vars', $vars, $url );

		return new WP_Query( $vars );
	}

	/**
	 * Resolve archive query vars from a path by replaying WordPress' own parser.
	 *
	 * @param string              $path  Request path.
	 * @param array<string,mixed> $query Query parameters.
	 *
	 * @return array<string,mixed>
	 */
	private function resolve_query_vars( string $path, array $query ): array {
		global $wp_rewrite;

		$vars = array();

		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$relative  = $path;

		if ( $home_path && 0 === strpos( $path, $home_path ) ) {
			$relative = substr( $path, strlen( $home_path ) );
		}

		$relative = trim( (string) $relative, '/' );

		if ( '' === $relative ) {
			return $vars;
		}

		// Pagination segment.
		if ( preg_match( '#/page/(\d+)$#', $relative, $matches ) ) {
			$vars['paged'] = (int) $matches[1];
			$relative      = (string) preg_replace( '#/page/\d+$#', '', $relative );
		}

		// Strip any pretty filter segments the URL manager knows about.
		$relative = $this->strip_known_segments( $relative );

		if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
			return $vars;
		}

		foreach ( (array) $wp_rewrite->wp_rewrite_rules() as $pattern => $target ) {
			if ( ! preg_match( "#^{$pattern}#", $relative, $matches ) ) {
				continue;
			}

			$target = (string) preg_replace( '!^.+\?!', '', $target );
			$target = addslashes( \WP_MatchesMapRegex::apply( $target, $matches ) );

			$parsed = array();
			wp_parse_str( $target, $parsed );

			foreach ( $parsed as $key => $value ) {
				if ( is_string( $key ) && '' !== $value ) {
					$vars[ sanitize_key( $key ) ] = is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : $value;
				}
			}

			break;
		}

		if ( isset( $query['s'] ) ) {
			$vars['s'] = Sanitizer::search( $query['s'] );
		}

		unset( $vars['page'], $vars['name'], $vars['pagename'] );

		return $vars;
	}

	/**
	 * Remove pretty filter segments from a relative path.
	 *
	 * @param string $relative Relative path.
	 */
	private function strip_known_segments( string $relative ): string {
		$keys      = bsf()->url()->known_keys();
		$separator = (string) bsf()->settings()->get( 'pretty_separator', '-' );
		$segments  = array_values( array_filter( explode( '/', $relative ) ) );

		while ( ! empty( $segments ) ) {
			$last  = (string) end( $segments );
			$match = false;

			foreach ( array_keys( $keys ) as $key ) {
				if ( 0 === strpos( $last, $key . $separator ) ) {
					$match = true;
					break;
				}
			}

			if ( ! $match ) {
				break;
			}

			array_pop( $segments );
		}

		return implode( '/', $segments );
	}

	/**
	 * Products per page for the rebuilt query.
	 */
	private function posts_per_page(): int {
		$per_page = function_exists( 'wc_get_default_products_per_row' ) ? (int) apply_filters( 'loop_shop_per_page', get_option( 'posts_per_page' ) ) : (int) get_option( 'posts_per_page' );

		return max( 1, min( 200, $per_page ) );
	}

	/**
	 * Render the product loop.
	 *
	 * @param WP_Query $query Query.
	 */
	private function render_products( WP_Query $query ): string {
		/**
		 * Short circuit product rendering, for themes and builders with a custom grid.
		 *
		 * @param string|null $html  Markup, or null to use the default loop.
		 * @param WP_Query    $query Query.
		 */
		$custom = apply_filters( 'bsf_render_products', null, $query );

		if ( is_string( $custom ) ) {
			return $custom;
		}

		if ( ! $query->have_posts() ) {
			ob_start();

			if ( function_exists( 'wc_get_template' ) ) {
				wc_get_template( 'loop/no-products-found.php' );
			} else {
				printf( '<p class="woocommerce-info">%s</p>', esc_html__( 'No products were found matching your selection.', 'woo-blocksocial-filters' ) );
			}

			return (string) ob_get_clean();
		}

		ob_start();

		if ( function_exists( 'woocommerce_product_loop_start' ) ) {
			woocommerce_product_loop_start();
		}

		while ( $query->have_posts() ) {
			$query->the_post();

			if ( function_exists( 'wc_get_template_part' ) ) {
				wc_get_template_part( 'content', 'product' );
			}
		}

		if ( function_exists( 'woocommerce_product_loop_end' ) ) {
			woocommerce_product_loop_end();
		}

		wp_reset_postdata();

		return (string) ob_get_clean();
	}

	/**
	 * Render pagination for the rebuilt query.
	 *
	 * @param WP_Query $query Query.
	 * @param string   $url   The filtered URL being rendered.
	 */
	private function render_pagination( WP_Query $query, string $url ): string {
		$total = (int) $query->max_num_pages;

		if ( $total < 2 ) {
			return '';
		}

		$current = max( 1, (int) $query->get( 'paged', 1 ) );

		// Page links are built from the filtered URL so paging never drops the
		// active selection, in either URL mode.
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', remove_query_arg( 'paged', $url ) ),
				'format'    => '',
				'current'   => $current,
				'total'     => $total,
				'type'      => 'array',
				'prev_text' => '&larr;',
				'next_text' => '&rarr;',
			)
		);

		if ( empty( $links ) ) {
			return '';
		}

		return '<nav class="woocommerce-pagination bsf-pagination"><ul class="page-numbers"><li>' .
			implode( '</li><li>', array_map( 'wp_kses_post', (array) $links ) ) .
			'</li></ul></nav>';
	}

	/* ---------------------------------------------------------------------
	 * Indexing
	 * ------------------------------------------------------------------ */

	/**
	 * Current index state.
	 */
	public function handle_index_status(): WP_REST_Response {
		$indexer = bsf()->indexer();

		return new WP_REST_Response( array_merge( $indexer->state(), $indexer->stats() ), 200 );
	}

	/**
	 * Start, continue or cancel a rebuild.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_index_run( WP_REST_Request $request ): WP_REST_Response {
		$indexer = bsf()->indexer();
		$action  = Sanitizer::choice( $request->get_param( 'action' ), array( 'start', 'batch', 'cancel' ), 'batch' );

		switch ( $action ) {
			case 'start':
				$state = $indexer->start( true );
				break;

			case 'cancel':
				$state = $indexer->cancel();
				break;

			default:
				$state = $indexer->run_batch( bsf()->settings()->int( 'index_batch', 10, 2000 ) );
		}

		// Stats ride along so the admin screen never shows a stale table once
		// the build has finished.
		if ( 'running' !== ( $state['status'] ?? '' ) ) {
			$state = array_merge( $state, $indexer->stats() );
		}

		return new WP_REST_Response( $state, 200 );
	}
}

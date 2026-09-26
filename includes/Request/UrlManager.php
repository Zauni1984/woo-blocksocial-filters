<?php
/**
 * URL generation and SEO friendly permalink parsing.
 *
 * Two modes are supported. "query" keeps everything in the query string
 * (f_color=blue,red). "pretty" appends readable segments to the archive URL
 * (/product-category/shoes/color-blue/size-m/) and strips them again before
 * WordPress resolves the request.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Request;

use BlockSocial\Filters\Support\Cache;
use BlockSocial\Filters\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and parses filter URLs.
 */
class UrlManager {

	/** @var string|null */
	private $base_url = null;

	/** @var array<string,array<string,mixed>> Selections recovered from a pretty URL. */
	private $parsed = array();

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		if ( 'pretty' === bsf()->settings()->get( 'url_mode', 'query' ) ) {
			add_filter( 'do_parse_request', array( $this, 'intercept_request' ), 5, 3 );
			add_filter( 'redirect_canonical', array( $this, 'skip_canonical_redirect' ), 10, 2 );
		}

		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_action( 'wp_head', array( $this, 'print_canonical' ), 1 );
	}

	/**
	 * Override the base URL, used by the REST endpoint.
	 *
	 * @param string $url Absolute URL of the page being filtered.
	 */
	public function set_base_url( string $url ): void {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return;
		}

		// Only accept URLs on this site.
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( $home && $host && strtolower( $home ) !== strtolower( $host ) ) {
			return;
		}

		$this->base_url = $this->strip_filter_args( $url );
	}

	/**
	 * Current URL with filter parameters and pagination removed.
	 */
	public function base_url(): string {
		if ( null !== $this->base_url ) {
			return $this->base_url;
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		$url     = home_url( $request );

		$this->base_url = $this->strip_filter_args( $url );

		return $this->base_url;
	}

	/**
	 * Remove our own query parameters and /page/N/ from a URL.
	 *
	 * @param string $url URL.
	 */
	private function strip_filter_args( string $url ): string {
		$prefix = bsf()->state()->prefix();
		$parts  = wp_parse_url( $url );
		$path   = $parts['path'] ?? '/';
		$query  = array();

		if ( ! empty( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
		}

		foreach ( array_keys( $query ) as $key ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				unset( $query[ $key ] );
			}
		}

		unset(
			$query['min_price'],
			$query['max_price'],
			$query['paged'],
			$query[ QueryState::SEARCH_PARAM ],
			$query['bsf_ajax'],
			$query['_']
		);

		$path = (string) preg_replace( '#/page/\d+/?$#', '/', $path );

		$url = home_url( $path );

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		return $url;
	}

	/**
	 * Build a URL representing a set of selections.
	 *
	 * @param array<string,array<string,mixed>> $selections Selections keyed by url key.
	 */
	public function build( array $selections ): string {
		$base = $this->base_url();
		$sort = bsf()->state()->sort();

		if ( 'pretty' === bsf()->settings()->get( 'url_mode', 'query' ) ) {
			$url = $this->build_pretty( $base, $selections );
		} else {
			$url = $this->build_query( $base, $selections );
		}

		if ( '' !== $sort ) {
			$url = add_query_arg( QueryState::SORT_PARAM, $sort, $url );
		}

		/**
		 * Filter a generated filter URL.
		 *
		 * @param string $url        Generated URL.
		 * @param array  $selections Selections.
		 */
		return apply_filters( 'bsf_build_url', $url, $selections );
	}

	/**
	 * Query string flavoured URL.
	 *
	 * @param string                            $base       Base URL.
	 * @param array<string,array<string,mixed>> $selections Selections.
	 */
	private function build_query( string $base, array $selections ): string {
		$prefix = bsf()->state()->prefix();
		$args   = array();

		foreach ( $selections as $key => $selection ) {
			$key = Sanitizer::key( $key );

			if ( '' === $key ) {
				continue;
			}

			if ( QueryState::SEARCH_PARAM === $key ) {
				$args[ QueryState::SEARCH_PARAM ] = (string) ( $selection['text'] ?? '' );
				continue;
			}

			$value = $this->encode_selection( $selection );

			if ( '' !== $value ) {
				$args[ $prefix . $key ] = $value;
			}
		}

		return empty( $args ) ? $base : add_query_arg( $args, $base );
	}

	/**
	 * Permalink flavoured URL.
	 *
	 * @param string                            $base       Base URL.
	 * @param array<string,array<string,mixed>> $selections Selections.
	 */
	private function build_pretty( string $base, array $selections ): string {
		$separator = $this->separator();
		$parts     = wp_parse_url( $base );
		$path      = rtrim( $parts['path'] ?? '/', '/' );
		$segments  = array();
		$query     = array();

		if ( ! empty( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
		}

		ksort( $selections );

		foreach ( $selections as $key => $selection ) {
			$key = Sanitizer::key( $key );

			if ( '' === $key ) {
				continue;
			}

			if ( QueryState::SEARCH_PARAM === $key ) {
				$query[ QueryState::SEARCH_PARAM ] = (string) ( $selection['text'] ?? '' );
				continue;
			}

			$value = $this->encode_selection( $selection );

			if ( '' !== $value ) {
				// Multiple values stay comma separated: /color-blue,red/. Commas are
				// legal in a path segment and keep slugs containing the separator
				// unambiguous on the way back in.
				$segments[] = $key . $separator . $value;
			}
		}

		$url = home_url( $path . ( $segments ? '/' . implode( '/', $segments ) : '' ) . '/' );

		return empty( $query ) ? $url : add_query_arg( $query, $url );
	}

	/**
	 * Encode one selection as a URL value.
	 *
	 * @param array<string,mixed> $selection Selection.
	 */
	private function encode_selection( array $selection ): string {
		if ( 'range' === ( $selection['type'] ?? '' ) ) {
			$min = isset( $selection['min'] ) && null !== $selection['min'] ? $this->format_number( (float) $selection['min'] ) : '';
			$max = isset( $selection['max'] ) && null !== $selection['max'] ? $this->format_number( (float) $selection['max'] ) : '';

			return ( '' === $min && '' === $max ) ? '' : $min . QueryState::RANGE_GLUE . $max;
		}

		$values = array_map( 'sanitize_title', (array) ( $selection['values'] ?? array() ) );

		return implode( ',', array_filter( $values ) );
	}

	/**
	 * Format a float without trailing zeros.
	 *
	 * @param float $value Value.
	 */
	private function format_number( float $value ): string {
		$formatted = rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' );

		return '' === $formatted ? '0' : $formatted;
	}

	/**
	 * Separator used between key and value in pretty URLs.
	 */
	private function separator(): string {
		$separator = (string) bsf()->settings()->get( 'pretty_separator', '-' );

		return in_array( $separator, array( '-', '_' ), true ) ? $separator : '-';
	}

	/* ---------------------------------------------------------------------
	 * Pretty URL parsing
	 * ------------------------------------------------------------------ */

	/**
	 * Strip filter segments from the request before WordPress resolves it.
	 *
	 * @param bool   $continue Whether to continue parsing.
	 * @param object $wp       WP object.
	 * @param mixed  $extra    Extra query vars.
	 */
	public function intercept_request( $continue, $wp, $extra ) {
		unset( $wp, $extra );

		if ( is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return $continue;
		}

		$request_uri = esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
		$parts       = wp_parse_url( $request_uri );
		$path        = (string) ( $parts['path'] ?? '/' );

		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$relative  = $path;

		if ( $home_path && 0 === strpos( $path, $home_path ) ) {
			$relative = substr( $path, strlen( $home_path ) );
		}

		$segments = array_values( array_filter( explode( '/', (string) $relative ) ) );

		if ( empty( $segments ) ) {
			return $continue;
		}

		$keys       = $this->known_keys();
		$separator  = $this->separator();
		$selections = array();
		$kept       = $segments;

		while ( ! empty( $kept ) ) {
			$candidate = end( $kept );
			$parsed    = $this->parse_segment( (string) $candidate, $keys, $separator );

			if ( null === $parsed ) {
				break;
			}

			$selections = array_merge( $parsed, $selections );
			array_pop( $kept );
		}

		if ( empty( $selections ) ) {
			return $continue;
		}

		$this->parsed = $selections;

		$new_path = $home_path . ( $kept ? implode( '/', $kept ) . '/' : '' );
		$new_path = '/' . ltrim( (string) preg_replace( '#/+#', '/', $new_path ), '/' );

		$_SERVER['REQUEST_URI'] = $new_path . ( empty( $parts['query'] ) ? '' : '?' . $parts['query'] );

		$raw = array();

		foreach ( $selections as $key => $selection ) {
			if ( QueryState::SEARCH_PARAM === $key ) {
				$raw[ QueryState::SEARCH_PARAM ] = (string) ( $selection['text'] ?? '' );
				continue;
			}

			$raw[ bsf()->state()->prefix() . $key ] = $this->encode_selection( $selection );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$existing = is_array( $_GET ) ? wp_unslash( $_GET ) : array();

		bsf()->state()->set_raw( array_merge( $existing, $raw ) );

		return $continue;
	}

	/**
	 * Parse one URL segment into a selection.
	 *
	 * @param string              $segment   Segment.
	 * @param array<string,bool>  $keys      Known filter keys.
	 * @param string              $separator Separator.
	 *
	 * @return array<string,array<string,mixed>>|null
	 */
	private function parse_segment( string $segment, array $keys, string $separator ): ?array {
		$segment = rawurldecode( $segment );
		$segment = (string) preg_replace( '/[^\p{L}\p{N},._\-]/u', '', $segment );
		$segment = strtolower( $segment );

		if ( '' === $segment || false === strpos( $segment, $separator ) ) {
			return null;
		}

		// The key is the longest registered prefix of the segment.
		$key = '';

		foreach ( array_keys( $keys ) as $candidate ) {
			if ( 0 === strpos( $segment, $candidate . $separator ) && strlen( $candidate ) > strlen( $key ) ) {
				$key = (string) $candidate;
			}
		}

		if ( '' === $key ) {
			return null;
		}

		$value = substr( $segment, strlen( $key ) + strlen( $separator ) );

		if ( '' === $value ) {
			return null;
		}

		$range = $this->parse_pretty_range( $value );

		if ( null !== $range ) {
			return array( $key => $range );
		}

		$slugs = Sanitizer::slug_list( explode( ',', $value ) );

		if ( empty( $slugs ) ) {
			return null;
		}

		return array(
			$key => array(
				'type'   => 'terms',
				'values' => $slugs,
			),
		);
	}

	/**
	 * Detect a numeric range inside a pretty segment value.
	 *
	 * @param string $value Segment value.
	 *
	 * @return array<string,mixed>|null
	 */
	private function parse_pretty_range( string $value ): ?array {
		if ( ! preg_match( '/^(\d+(?:\.\d+)?)?(?:\.\.|-{1,2})(\d+(?:\.\d+)?)?$/', $value, $matches ) ) {
			return null;
		}

		$min = isset( $matches[1] ) && '' !== $matches[1] ? (float) $matches[1] : null;
		$max = isset( $matches[2] ) && '' !== $matches[2] ? (float) $matches[2] : null;

		if ( null === $min && null === $max ) {
			return null;
		}

		return array(
			'type' => 'range',
			'min'  => $min,
			'max'  => $max,
		);
	}

	/**
	 * Every url key registered by any filter set.
	 *
	 * @return array<string,bool>
	 */
	public function known_keys(): array {
		return (array) Cache::remember_persisted(
			Cache::key( 'url_keys' ),
			static function () {
				$keys = array();

				foreach ( bsf()->registry()->sets() as $set ) {
					foreach ( (array) ( $set['filters'] ?? array() ) as $filter ) {
						$key = Sanitizer::key( $filter['url_key'] ?? '' );

						if ( '' !== $key ) {
							$keys[ $key ] = true;
						}
					}
				}

				return $keys;
			},
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Selections recovered from the pretty URL.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function parsed(): array {
		return $this->parsed;
	}

	/**
	 * Do not let WordPress redirect away from a filtered URL.
	 *
	 * @param string|false $redirect Redirect target.
	 * @param string       $requested Requested URL.
	 *
	 * @return string|false
	 */
	public function skip_canonical_redirect( $redirect, $requested ) {
		unset( $requested );

		return empty( $this->parsed ) ? $redirect : false;
	}

	/* ---------------------------------------------------------------------
	 * SEO
	 * ------------------------------------------------------------------ */

	/**
	 * Add noindex to deeply filtered pages.
	 *
	 * @param array<string,mixed> $robots Robots directives.
	 *
	 * @return array<string,mixed>
	 */
	public function robots( $robots ) {
		if ( ! is_array( $robots ) || ! bsf()->settings()->bool( 'seo_noindex_multi', true ) ) {
			return $robots;
		}

		$selections = bsf()->state()->selections();

		if ( count( $selections ) > 1 ) {
			$robots['noindex']  = true;
			$robots['follow']   = true;
			unset( $robots['index'] );
		}

		return $robots;
	}

	/**
	 * Point the canonical URL at the unfiltered archive.
	 */
	public function print_canonical(): void {
		if ( ! bsf()->settings()->bool( 'canonical_clean', true ) ) {
			return;
		}

		if ( ! bsf()->state()->is_filtered() ) {
			return;
		}

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $this->base_url() ) );

		remove_action( 'wp_head', 'rel_canonical' );
	}
}

<?php
/**
 * WPML and Polylang support.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the index language aware so a filtered query never leaks products from
 * another language.
 */
class Multilingual {

	/** @var string|null */
	private $current = null;

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		if ( ! $this->active() ) {
			return;
		}

		add_filter( 'bsf_index_languages', array( $this, 'languages_for' ), 10, 2 );
		add_filter( 'bsf_build_url', array( $this, 'localize_url' ), 10, 2 );
	}

	/**
	 * Whether a supported multilingual plugin is active.
	 */
	public function active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_current_language' );
	}

	/**
	 * Current front end language code, or an empty string on single language sites.
	 */
	public function current_language(): string {
		if ( null !== $this->current ) {
			return $this->current;
		}

		$code = '';

		if ( function_exists( 'pll_current_language' ) ) {
			$code = (string) pll_current_language( 'slug' );
		} elseif ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			$code = (string) ICL_LANGUAGE_CODE;
		}

		$this->current = sanitize_key( $code );

		return $this->current;
	}

	/**
	 * Resolve the language of each indexed product.
	 *
	 * @param array<int,string> $languages Existing map.
	 * @param int[]             $ids       Product ids.
	 *
	 * @return array<int,string>
	 */
	public function languages_for( $languages, $ids ) {
		$languages = is_array( $languages ) ? $languages : array();
		$ids       = array_map( 'absint', (array) $ids );

		if ( empty( $ids ) ) {
			return $languages;
		}

		if ( function_exists( 'pll_get_post_language' ) ) {
			foreach ( $ids as $id ) {
				$languages[ $id ] = sanitize_key( (string) pll_get_post_language( $id, 'slug' ) );
			}

			return $languages;
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			global $wpdb;

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT element_id, language_code FROM {$wpdb->prefix}icl_translations
					 WHERE element_type = 'post_product' AND element_id IN ({$placeholders})",
					$ids
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$languages[ (int) $row['element_id'] ] = sanitize_key( (string) $row['language_code'] );
			}
		}

		return $languages;
	}

	/**
	 * Keep generated URLs on the current language.
	 *
	 * @param string $url        URL.
	 * @param array  $selections Selections.
	 */
	public function localize_url( $url, $selections ) {
		unset( $selections );

		if ( ! is_string( $url ) ) {
			return $url;
		}

		if ( function_exists( 'pll_home_url' ) ) {
			return $url;
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'apply_filters' ) ) {
			/** This filter is documented by WPML. */
			return (string) apply_filters( 'wpml_permalink', $url, $this->current_language() );
		}

		return $url;
	}
}

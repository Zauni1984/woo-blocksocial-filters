<?php
/**
 * Input sanitisation helpers.
 *
 * Every value that reaches a SQL builder passes through this class first.
 *
 * @package BlockSocial\Filters
 */

namespace BlockSocial\Filters\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless sanitisers with hard upper bounds, so a crafted request cannot
 * inflate the work the database has to do.
 */
class Sanitizer {

	/** Hard ceiling for the number of values accepted per filter. */
	public const MAX_VALUES = 200;

	/** Hard ceiling for the number of filters accepted per request. */
	public const MAX_FILTERS = 60;

	/**
	 * Turn a raw request value into a bounded list of term slugs.
	 *
	 * @param mixed $raw   Raw value (string or array).
	 * @param int   $limit Maximum number of slugs to keep.
	 *
	 * @return string[]
	 */
	public static function slug_list( $raw, int $limit = self::MAX_VALUES ): array {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$limit = max( 1, min( self::MAX_VALUES, $limit ) );
		$out   = array();

		foreach ( $raw as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$slug = sanitize_title( wp_unslash( (string) $value ) );

			if ( '' === $slug ) {
				continue;
			}

			$out[ $slug ] = $slug;

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return array_values( $out );
	}

	/**
	 * Cast to a list of positive integers.
	 *
	 * @param mixed $raw   Raw value.
	 * @param int   $limit Maximum entries.
	 *
	 * @return int[]
	 */
	public static function id_list( $raw, int $limit = self::MAX_VALUES ): array {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $value ) {
			$id = absint( $value );

			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return array_values( $out );
	}

	/**
	 * Parse a decimal value, returning null when the input is not numeric.
	 *
	 * @param mixed $raw Raw value.
	 *
	 * @return float|null
	 */
	public static function decimal( $raw ): ?float {
		if ( is_array( $raw ) || null === $raw || '' === $raw ) {
			return null;
		}

		$value = str_replace( array( ' ', ',' ), array( '', '.' ), (string) wp_unslash( $raw ) );

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$value = (float) $value;

		// Keep values inside the DECIMAL(19,6) range used by the index tables.
		if ( ! is_finite( $value ) || abs( $value ) > 1.0e12 ) {
			return null;
		}

		return round( $value, 6 );
	}

	/**
	 * Parse a date string into a UTC timestamp.
	 *
	 * @param mixed $raw Raw value such as 2024-05-01 or 2024-05-01t10.00.00.
	 *
	 * @return int|null
	 */
	public static function date( $raw ): ?int {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}

		$value = strtolower( trim( (string) wp_unslash( $raw ) ) );
		$value = str_replace( array( 't', '.' ), array( ' ', ':' ), $value );
		$value = preg_replace( '/[^0-9:\- ]/', '', $value );

		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false === $timestamp ? null : (int) $timestamp;
	}

	/**
	 * Sanitise a free text search phrase.
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function search( $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$text = sanitize_text_field( wp_unslash( (string) $raw ) );

		return trim( mb_substr( $text, 0, 128 ) );
	}

	/**
	 * Sanitise an option key such as a meta key or filter identifier.
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function key( $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$key = strtolower( (string) wp_unslash( $raw ) );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', $key );

		return is_string( $key ) ? substr( $key, 0, 64 ) : '';
	}

	/**
	 * Sanitise a taxonomy name and verify it exists.
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function taxonomy( $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$taxonomy = sanitize_key( wp_unslash( (string) $raw ) );

		return taxonomy_exists( $taxonomy ) ? $taxonomy : '';
	}

	/**
	 * Sanitise a hex colour, allowing rgba() through unchanged when valid.
	 *
	 * @param mixed  $raw      Raw value.
	 * @param string $fallback Value returned when invalid.
	 */
	public static function color( $raw, string $fallback = '' ): string {
		if ( ! is_scalar( $raw ) ) {
			return $fallback;
		}

		$value = trim( (string) wp_unslash( $raw ) );

		if ( '' === $value ) {
			return '';
		}

		$hex = sanitize_hex_color( $value );

		if ( $hex ) {
			return $hex;
		}

		if ( preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/i', $value ) ) {
			return $value;
		}

		return $fallback;
	}

	/**
	 * Sanitise a CSS selector supplied in settings.
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function selector( $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$value = trim( (string) wp_unslash( $raw ) );
		$value = preg_replace( '/[^a-zA-Z0-9 ,.#_\-\[\]="\':>()]/', '', $value );

		return is_string( $value ) ? substr( $value, 0, 200 ) : '';
	}

	/**
	 * Restrict a value to a set of allowed options.
	 *
	 * @param mixed    $raw     Raw value.
	 * @param string[] $allowed Allowed values.
	 * @param string   $default Fallback.
	 */
	public static function choice( $raw, array $allowed, string $default ): string {
		if ( ! is_scalar( $raw ) ) {
			return $default;
		}

		$value = sanitize_key( wp_unslash( (string) $raw ) );

		return in_array( $value, $allowed, true ) ? $value : $default;
	}
}

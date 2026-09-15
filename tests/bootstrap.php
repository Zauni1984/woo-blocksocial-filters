<?php
/**
 * Minimal WordPress stubs so the pure logic of the plugin can be exercised
 * without a WordPress install. Only what the tested classes touch is stubbed.
 *
 * @package BlockSocial\Filters
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'BSF_VERSION', '1.0.0' );
define( 'BSF_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BSF_PLUGIN_URL', 'https://example.test/wp-content/plugins/woo-blocksocial-filters/' );
define( 'BSF_PLUGIN_BASENAME', 'woo-blocksocial-filters/woo-blocksocial-filters.php' );
define( 'BSF_PLUGIN_FILE', BSF_PLUGIN_DIR . 'woo-blocksocial-filters.php' );

define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['bsf_test_options']  = array();
$GLOBALS['bsf_test_queries']  = array();
$GLOBALS['bsf_test_terms']    = array();
$GLOBALS['bsf_test_filters']  = array();

/* -------------------------------------------------------------------------
 * Hooks
 * ---------------------------------------------------------------------- */

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['bsf_test_filters'][ $hook ][] = $callback;

	return true;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_filter( $hook, $callback, $priority, $args );
}

function remove_action( $hook, $callback, $priority = 10 ) {
	return true;
}

function apply_filters( $hook, $value ) {
	$args = array_slice( func_get_args(), 2 );

	foreach ( $GLOBALS['bsf_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
	}

	return $value;
}

function do_action( $hook ) {
	return null;
}

function did_action( $hook ) {
	return 1;
}

function add_shortcode( $tag, $callback ) {
	return true;
}

/* -------------------------------------------------------------------------
 * Options and transients
 * ---------------------------------------------------------------------- */

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['bsf_test_options'] ) ? $GLOBALS['bsf_test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['bsf_test_options'][ $name ] = $value;

	return true;
}

function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( ! array_key_exists( $name, $GLOBALS['bsf_test_options'] ) ) {
		$GLOBALS['bsf_test_options'][ $name ] = $value;
	}

	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['bsf_test_options'][ $name ] );

	return true;
}

function get_transient( $key ) {
	return false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	return true;
}

function wp_using_ext_object_cache() {
	return false;
}

function wp_cache_get( $key, $group = '' ) {
	return false;
}

function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
	return true;
}

/* -------------------------------------------------------------------------
 * Sanitisation and escaping
 * ---------------------------------------------------------------------- */

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_title( $title ) {
	$title = strtolower( remove_accents( (string) $title ) );
	$title = preg_replace( '/[^a-z0-9_\-]/', '-', $title );
	$title = preg_replace( '/-+/', '-', (string) $title );

	return trim( (string) $title, '-' );
}

function remove_accents( $string ) {
	return (string) $string;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_html_class( $value ) {
	return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
}

function sanitize_hex_color( $color ) {
	return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $color ) ? $color : null;
}

function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $value ) {
	return (string) $value;
}

function esc_url_raw( $value ) {
	return (string) $value;
}

function esc_textarea( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function wp_kses_post( $value ) {
	return (string) $value;
}

/* -------------------------------------------------------------------------
 * URLs
 * ---------------------------------------------------------------------- */

function home_url( $path = '' ) {
	return 'https://shop.test' . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) );
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
}

function wp_parse_str( $string, &$result ) {
	parse_str( (string) $string, $result );
}

function add_query_arg() {
	$args = func_get_args();

	if ( is_array( $args[0] ) ) {
		$pairs = $args[0];
		$url   = $args[1] ?? '';
	} else {
		$pairs = array( $args[0] => $args[1] );
		$url   = $args[2] ?? '';
	}

	$parts = parse_url( (string) $url );
	$query = array();

	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}

	foreach ( $pairs as $key => $value ) {
		$query[ $key ] = $value;
	}

	$base = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? 'shop.test' ) . ( $parts['path'] ?? '/' );

	return $query ? $base . '?' . http_build_query( $query ) : $base;
}

function remove_query_arg( $key, $url = '' ) {
	$parts = parse_url( (string) $url );
	$query = array();

	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}

	foreach ( (array) $key as $name ) {
		unset( $query[ $name ] );
	}

	$base = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? 'shop.test' ) . ( $parts['path'] ?? '/' );

	return $query ? $base . '?' . http_build_query( $query ) : $base;
}

function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	}

	return is_array( $args ) ? array_merge( $defaults, $args ) : $defaults;
}

function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}

/* -------------------------------------------------------------------------
 * Taxonomies and terms
 * ---------------------------------------------------------------------- */

function taxonomy_exists( $taxonomy ) {
	return in_array( $taxonomy, array( 'pa_color', 'pa_size', 'product_cat', 'product_tag' ), true );
}

function is_taxonomy_hierarchical( $taxonomy ) {
	return 'product_cat' === $taxonomy;
}

function get_terms( $args = array() ) {
	$taxonomy = is_array( $args ) ? ( $args['taxonomy'] ?? '' ) : '';
	$terms    = $GLOBALS['bsf_test_terms'][ $taxonomy ] ?? array();

	if ( is_array( $args ) && 'id=>slug' === ( $args['fields'] ?? '' ) ) {
		$out = array();

		foreach ( $terms as $term ) {
			$out[ $term->term_id ] = $term->slug;
		}

		return $out;
	}

	return $terms;
}

function get_taxonomy( $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return false;
	}

	$labels = new stdClass();

	$labels->singular_name = ucfirst( str_replace( array( 'pa_', 'product_' ), '', (string) $taxonomy ) );

	$object = new stdClass();

	$object->name   = $taxonomy;
	$object->labels = $labels;
	$object->public = true;

	return $object;
}

function get_object_taxonomies( $type, $output = 'names' ) {
	$names = array( 'pa_color', 'pa_size', 'product_cat', 'product_tag' );

	if ( 'objects' !== $output ) {
		return $names;
	}

	return array_map( 'get_taxonomy', $names );
}

function wc_get_attribute_taxonomies() {
	return array();
}

function get_term_children( $term_id, $taxonomy ) {
	return array();
}

function get_term_meta( $term_id, $key, $single = false ) {
	return '';
}

function is_wp_error( $thing ) {
	return false;
}

/* -------------------------------------------------------------------------
 * i18n and misc
 * ---------------------------------------------------------------------- */

function __( $text, $domain = '' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = '' ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html__( $text, $domain = '' ) {
	return $text;
}

function esc_attr__( $text, $domain = '' ) {
	return $text;
}

function esc_html_e( $text, $domain = '' ) {
	echo esc_html( $text );
}

function esc_attr_e( $text, $domain = '' ) {
	echo esc_attr( $text );
}

function esc_attr_x( $text, $context, $domain = '' ) {
	return $text;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max ?: PHP_INT_MAX );
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( str_shuffle( str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789', 3 ) ), 0, $length );
}

function is_admin() {
	return false;
}

function wp_doing_ajax() {
	return false;
}

function wp_doing_cron() {
	return false;
}

function get_queried_object() {
	return null;
}

function is_search() {
	return false;
}

function get_search_query( $escaped = true ) {
	return '';
}

function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) {
	return 'https://shop.test/image.jpg';
}

function get_woocommerce_currency_symbol() {
	return '&euro;';
}

function wc_price( $value ) {
	return '&euro;' . number_format( (float) $value, 2 );
}

function date_i18n( $format, $timestamp ) {
	return gmdate( (string) $format, (int) $timestamp );
}

function checked( $checked, $current = true, $echo = true ) {
	return $checked == $current ? ' checked="checked"' : '';
}

function selected( $selected, $current = true, $echo = true ) {
	return $selected == $current ? ' selected="selected"' : '';
}

function disabled( $disabled, $current = true, $echo = true ) {
	return $disabled == $current ? ' disabled="disabled"' : '';
}

/* -------------------------------------------------------------------------
 * $wpdb stub
 * ---------------------------------------------------------------------- */

class BSF_Test_WPDB {

	public $prefix   = 'wp_';
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $terms    = 'wp_terms';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $options  = 'wp_options';
	public $termmeta = 'wp_termmeta';

	/** @var string[] Every statement that reached the database. */
	public $log = array();

	/** @var array<string,mixed> Canned results keyed by a substring of the query. */
	public $results = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$query = str_replace( array( "'%s'", '"%s"' ), '%s', $query );

		$index = 0;

		return preg_replace_callback(
			'/%[sdfF]/',
			function ( $match ) use ( &$index, $args ) {
				$value = $args[ $index ] ?? '';
				$index++;

				switch ( $match[0] ) {
					case '%d':
						return (string) (int) $value;
					case '%f':
					case '%F':
						return (string) (float) $value;
					default:
						return "'" . addslashes( (string) $value ) . "'";
				}
			},
			$query
		);
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_col( $query, $column = 0 ) {
		$this->log[] = $query;

		return $this->canned( $query, array() );
	}

	public function get_var( $query ) {
		$this->log[] = $query;

		return $this->canned( $query, 0 );
	}

	public function get_results( $query, $output = null ) {
		$this->log[] = $query;

		return $this->canned( $query, array() );
	}

	public function get_row( $query, $output = null ) {
		$this->log[] = $query;

		return $this->canned( $query, array() );
	}

	public function query( $query ) {
		$this->log[] = $query;

		return 1;
	}

	public function delete( $table, $where, $format = null ) {
		return 1;
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	private function canned( $query, $default ) {
		foreach ( $this->results as $needle => $value ) {
			if ( false !== strpos( $query, $needle ) ) {
				return $value;
			}
		}

		return $default;
	}
}

$GLOBALS['wpdb'] = new BSF_Test_WPDB();

/* -------------------------------------------------------------------------
 * Plugin
 * ---------------------------------------------------------------------- */

require_once BSF_PLUGIN_DIR . 'includes/Autoloader.php';

\BlockSocial\Filters\Autoloader::register();

function bsf() {
	return \BlockSocial\Filters\Plugin::instance();
}

/**
 * Register a term in the stub taxonomy store.
 *
 * @param string $taxonomy Taxonomy.
 * @param int    $id       Term id.
 * @param string $slug     Slug.
 * @param string $name     Name.
 * @param int    $parent   Parent term id.
 */
function bsf_test_add_term( $taxonomy, $id, $slug, $name, $parent = 0 ) {
	$term = new stdClass();

	$term->term_id = $id;
	$term->slug    = $slug;
	$term->name    = $name;
	$term->parent  = $parent;

	$GLOBALS['bsf_test_terms'][ $taxonomy ][] = $term;
}

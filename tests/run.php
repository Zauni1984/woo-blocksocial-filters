<?php
/**
 * Dependency free test runner for the plugin's core logic.
 *
 * Usage: php tests/run.php
 *
 * @package BlockSocial\Filters
 */

require_once __DIR__ . '/bootstrap.php';

use BlockSocial\Filters\Filters\FilterDefinition;
use BlockSocial\Filters\Index\QueryBuilder;
use BlockSocial\Filters\Support\Colors;
use BlockSocial\Filters\Support\Sanitizer;

$passed = 0;
$failed = 0;

/**
 * Assert a condition.
 *
 * @param string $name      Test name.
 * @param bool   $condition Result.
 * @param string $detail    Extra detail printed on failure.
 */
function it( string $name, bool $condition, string $detail = '' ): void {
	global $passed, $failed;

	if ( $condition ) {
		$passed++;
		echo "  ok   {$name}\n";

		return;
	}

	$failed++;
	echo "  FAIL {$name}" . ( '' === $detail ? '' : "\n       {$detail}" ) . "\n";
}

/**
 * Assert equality.
 *
 * @param string $name     Test name.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 */
function is_same( string $name, $expected, $actual ): void {
	it(
		$name,
		$expected === $actual,
		'expected ' . var_export( $expected, true ) . "\n       actual   " . var_export( $actual, true )
	);
}

bsf_test_add_term( 'pa_color', 11, 'blue', 'Blue' );
bsf_test_add_term( 'pa_color', 12, 'red', 'Red' );
bsf_test_add_term( 'pa_color', 13, 'light-green', 'Light Green' );
bsf_test_add_term( 'pa_size', 21, 'm', 'M' );
bsf_test_add_term( 'pa_size', 22, 'l', 'L' );
bsf_test_add_term( 'product_cat', 31, 'shoes', 'Shoes' );

echo "\nSanitizer\n";

is_same( 'slug list splits and sanitises', array( 'blue', 'red' ), Sanitizer::slug_list( 'blue,Red' ) );
is_same( 'slug list drops empties', array( 'blue' ), Sanitizer::slug_list( array( 'blue', '', '   ' ) ) );
is_same( 'slug list is bounded', 3, count( Sanitizer::slug_list( 'a,b,c,d,e', 3 ) ) );
is_same( 'slug list strips injection', array( 'drop-table-x' ), Sanitizer::slug_list( "'; DROP TABLE x" ) );
is_same( 'decimal parses', 12.5, Sanitizer::decimal( '12,5' ) );
is_same( 'decimal rejects text', null, Sanitizer::decimal( 'abc' ) );
is_same( 'decimal rejects arrays', null, Sanitizer::decimal( array( 1 ) ) );
is_same( 'decimal clamps huge values', null, Sanitizer::decimal( '99999999999999' ) );
is_same( 'key strips unsafe chars', 'color_1', Sanitizer::key( 'Color_1<>"' ) );
is_same( 'taxonomy must exist', '', Sanitizer::taxonomy( 'pa_nonexistent' ) );
is_same( 'taxonomy passes known', 'pa_color', Sanitizer::taxonomy( 'pa_color' ) );
is_same( 'choice falls back', 'or', Sanitizer::choice( 'nonsense', array( 'or', 'and' ), 'or' ) );
is_same( 'colour accepts hex', '#ff0000', Sanitizer::color( '#ff0000' ) );
is_same( 'colour rejects javascript', 'fallback', Sanitizer::color( 'javascript:alert(1)', 'fallback' ) );
is_same( 'colour accepts rgba', 'rgba(0,0,0,0.5)', Sanitizer::color( 'rgba(0,0,0,0.5)' ) );
is_same( 'date parses', gmmktime( 0, 0, 0, 5, 1, 2024 ), Sanitizer::date( '2024-05-01' ) );

echo "\nFilterDefinition\n";

$color = new FilterDefinition( FilterDefinition::normalize( array(
	'source'   => 'attribute',
	'taxonomy' => 'pa_color',
	'display'  => 'color',
) ) );

is_same( 'derives url key from attribute', 'color', $color->url_key() );
is_same( 'keeps display', 'color', $color->display() );
it( 'is a taxonomy filter', $color->is_taxonomy() );

$price = new FilterDefinition( FilterDefinition::normalize( array(
	'source'  => 'price',
	'display' => 'range',
) ) );

is_same( 'price url key', 'price', $price->url_key() );
is_same( 'price title', 'Price', $price->title() );

$search = new FilterDefinition( FilterDefinition::normalize( array( 'source' => 'search' ) ) );
is_same( 'search filter uses the reserved key', 'srch', $search->url_key() );

$bad = FilterDefinition::normalize( array( 'source' => 'evil', 'display' => '<script>' ) );
is_same( 'unknown source falls back', 'attribute', $bad['source'] );
is_same( 'unknown display falls back', 'checkbox', $bad['display'] );

echo "\nQueryState parsing\n";

$state = bsf()->state();

$state->set_raw( array(
	'f_color' => 'blue,red',
	'f_price' => '10..100',
	'srch'    => 'winter coat',
	'ordr'    => 'price',
	'unrelated' => 'x',
) );

$selections = $state->selections();

is_same( 'parses term list', array( 'blue', 'red' ), $selections['color']['values'] );
is_same( 'parses range low', 10.0, $selections['price']['min'] );
is_same( 'parses range high', 100.0, $selections['price']['max'] );
is_same( 'parses search', 'winter coat', $state->search() );
is_same( 'parses sort', 'price', $state->sort() );
it( 'ignores unprefixed parameters', ! isset( $selections['unrelated'] ) );

$state->set_raw( array( 'min_price' => '5', 'max_price' => '50' ) );
$selections = $state->selections();
is_same( 'accepts WooCommerce price params', 5.0, $selections['price']['min'] );

$state->set_raw( array( 'f_color' => str_repeat( 'a,', 500 ) ) );
it( 'caps the number of values per filter', count( $state->selections()['color']['values'] ) <= Sanitizer::MAX_VALUES );

$state->set_raw( array( 'f_color' => 'blue' ) );
is_same( 'resolves slugs to term ids', array( 11 ), $state->term_ids( 'pa_color', array( 'blue' ) ) );
is_same( 'unknown slugs resolve to nothing', array(), $state->term_ids( 'pa_color', array( 'purple' ) ) );

echo "\nURL building\n";

$url = bsf()->url();
$url->set_base_url( 'https://shop.test/shop/' );

$built = $url->build( array(
	'color' => array( 'type' => 'terms', 'values' => array( 'blue', 'red' ) ),
) );

it( 'query mode encodes a term list', false !== strpos( $built, 'f_color=blue%2Cred' ) || false !== strpos( $built, 'f_color=blue,red' ), $built );

$built = $url->build( array(
	'price' => array( 'type' => 'range', 'min' => 10.0, 'max' => 100.0 ),
) );

it( 'query mode encodes a range', false !== strpos( rawurldecode( $built ), 'f_price=10..100' ), $built );

is_same( 'empty selection returns the bare base', 'https://shop.test/shop/', $url->build( array() ) );

update_option( 'bsf_settings', array( 'url_mode' => 'pretty' ) );
bsf()->settings()->refresh();

$built = $url->build( array(
	'color' => array( 'type' => 'terms', 'values' => array( 'blue', 'red' ) ),
	'size'  => array( 'type' => 'terms', 'values' => array( 'm' ) ),
) );

is_same( 'pretty mode builds readable segments', 'https://shop.test/shop/color-blue,red/size-m/', $built );

// Round trip: the parser has to recover exactly what the builder produced.
$reflection = new ReflectionClass( $url );
$parse      = $reflection->getMethod( 'parse_segment' );
$parse->setAccessible( true );

$recovered = $parse->invoke( $url, 'color-blue,red', array( 'color' => true, 'size' => true ), '-' );
is_same( 'pretty segments round trip', array( 'blue', 'red' ), $recovered['color']['values'] );

$recovered = $parse->invoke( $url, 'color-light-green', array( 'color' => true ), '-' );
is_same( 'slugs containing the separator survive', array( 'light-green' ), $recovered['color']['values'] );

$recovered = $parse->invoke( $url, 'price-10..100', array( 'price' => true ), '-' );
is_same( 'pretty ranges round trip', 100.0, $recovered['price']['max'] );

is_same( 'unknown segments are left alone', null, $parse->invoke( $url, 'some-category-page', array( 'color' => true ), '-' ) );

update_option( 'bsf_settings', array( 'url_mode' => 'query' ) );
bsf()->settings()->refresh();

echo "\nQueryBuilder SQL\n";

global $wpdb;

$builder = new QueryBuilder();

$sql = $builder->id_sql( array(
	array( 'type' => 'terms', 'taxonomy' => 'pa_color', 'term_ids' => array( 11, 12 ), 'logic' => 'or' ),
) );

it( 'single OR facet uses one join', 1 === substr_count( $sql, 'INNER JOIN' ), $sql );
it( 'term ids are inlined as integers', false !== strpos( $sql, 'term_id IN (11,12)' ), $sql );
it( 'only visible products are returned', false !== strpos( $sql, 'p.visible = 1' ), $sql );

$sql = $builder->id_sql( array(
	array( 'type' => 'terms', 'taxonomy' => 'pa_color', 'term_ids' => array( 11, 12 ), 'logic' => 'and' ),
) );

it( 'AND logic uses one join per term', 2 === substr_count( $sql, 'INNER JOIN' ), $sql );

$sql = $builder->id_sql( array(
	array( 'type' => 'terms', 'taxonomy' => 'pa_color', 'term_ids' => array( 11 ), 'logic' => 'or', 'variation_scope' => true ),
	array( 'type' => 'terms', 'taxonomy' => 'pa_size', 'term_ids' => array( 21 ), 'logic' => 'or', 'variation_scope' => true ),
) );

it( 'variation matching ties the joins to one object', false !== strpos( $sql, '.object_id = ix1.object_id' ), $sql );
it( 'variation matching exempts non variable products', false !== strpos( $sql, "p.product_type <> 'variable'" ), $sql );

$sql = $builder->id_sql( array(
	array( 'type' => 'price', 'min' => 10.0, 'max' => 100.0 ),
) );

it( 'price uses the overlapping range test', false !== strpos( $sql, 'p.max_price >= 10' ) && false !== strpos( $sql, 'p.min_price <= 100' ), $sql );

$sql = $builder->id_sql( array(
	array( 'type' => 'flag', 'field' => 'on_sale', 'value' => 1 ),
	array( 'type' => 'flag', 'field' => 'nonsense; DROP TABLE wp_posts', 'value' => 1 ),
) );

it( 'known flags are applied', false !== strpos( $sql, 'p.on_sale = 1' ), $sql );
it( 'unknown flag columns are dropped', false === strpos( $sql, 'DROP TABLE' ), $sql );

$sql = $builder->id_sql(
	array( array( 'type' => 'search', 'text' => "coat' OR 1=1 --" ) )
);

it( 'search text is escaped', false === strpos( $sql, "coat' OR" ), $sql );
it( 'search falls back to LIKE without a full text index', false !== strpos( $sql, 'p.search_text LIKE' ), $sql );

// With the full text index available the keyword filter switches to MATCH.
update_option( \BlockSocial\Filters\Index\Schema::FULLTEXT_OPTION, 1 );

$sql = $builder->id_sql( array( array( 'type' => 'search', 'text' => 'winter coat' ) ) );
it( 'search uses the full text index when present', false !== strpos( $sql, 'MATCH (p.search_text) AGAINST' ), $sql );
it( 'full text words are prefix matched', false !== strpos( $sql, '+winter* +coat*' ), $sql );

$sql = $builder->id_sql( array( array( 'type' => 'search', 'text' => 'red +(-hats)~ cotton' ) ) );
it( 'boolean mode operators are stripped from the phrase', false !== strpos( $sql, '+red* +hats* +cotton*' ), $sql );
it( 'no raw operators survive into the expression', false === strpos( $sql, '~' ) && false === strpos( $sql, '(-' ), $sql );

$sql = $builder->id_sql( array( array( 'type' => 'search', 'text' => 'xs' ) ) );
it( 'short words fall back to LIKE', false !== strpos( $sql, 'LIKE' ), $sql );

update_option( \BlockSocial\Filters\Index\Schema::FULLTEXT_OPTION, 0 );

$sql = $builder->id_sql( array(), array( 'orderby' => 'price', 'limit' => 20, 'offset' => 40 ) );
it( 'ordering is whitelisted', false !== strpos( $sql, 'ORDER BY p.min_price ASC' ), $sql );
it( 'limits are integers', false !== strpos( $sql, 'LIMIT 20 OFFSET 40' ), $sql );

$sql = $builder->id_sql( array(), array( 'orderby' => 'price; DROP TABLE wp_posts' ) );
it( 'unknown ordering is ignored', false === strpos( $sql, 'DROP' ), $sql );

// The regression that matters: a LIKE pattern inside the compiled WHERE must
// not be re-parsed as a prepare() placeholder by the facet count query.
$wpdb->log = array();

$builder->term_counts(
	'pa_color',
	array( array( 'type' => 'search', 'text' => '100% cotton' ) )
);

$facet_sql = end( $wpdb->log );

// If prepare() had re-read the % as placeholders the phrase would be mangled.
it( 'facet counts keep the LIKE pattern intact', false !== strpos( (string) $facet_sql, 'LIKE' ) && false !== strpos( (string) $facet_sql, 'cotton' ), (string) $facet_sql );
it( 'facet counts still quote the taxonomy', false !== strpos( (string) $facet_sql, "facet.taxonomy = 'pa_color'" ), (string) $facet_sql );
it( 'facet counts group by term', false !== strpos( (string) $facet_sql, 'GROUP BY facet.term_id' ), (string) $facet_sql );

$wpdb->log = array();
$builder->numeric_bounds( '_weight', array( array( 'type' => 'search', 'text' => '50% off' ) ) );
$bounds_sql = end( $wpdb->log );

it( 'numeric bounds quote the meta key', false !== strpos( (string) $bounds_sql, "nb.meta_key = '_weight'" ), (string) $bounds_sql );

echo "\nRendering\n";

// Give the facet query some counts so options are not all filtered out.
$wpdb->results = array(
	'GROUP BY facet.term_id' => array(
		array( 'term_id' => 11, 'cnt' => 7 ),
		array( 'term_id' => 12, 'cnt' => 3 ),
		array( 'term_id' => 13, 'cnt' => 0 ),
	),
	'COUNT(DISTINCT p.product_id)' => 10,
);

$state->set_raw( array( 'f_color' => 'blue' ) );

$set = bsf()->registry()->normalize_set(
	array(
		'title'   => 'Filters',
		'filters' => array(
			array( 'source' => 'attribute', 'taxonomy' => 'pa_color', 'display' => 'label', 'id' => 'color', 'url_key' => 'color' ),
			array( 'source' => 'attribute', 'taxonomy' => 'pa_size', 'display' => 'checkbox', 'id' => 'size', 'url_key' => 'size' ),
		),
	),
	'test'
);

$html = bsf()->renderer()->render_set( $set );

it( 'renders a panel', false !== strpos( $html, 'class="bsf bsf--vertical' ), substr( $html, 0, 200 ) );
it( 'renders the label list', false !== strpos( $html, 'bsf-options--label' ) );
it( 'marks the selected option', false !== strpos( $html, 'bsf-option is-selected' ), $html );
it( 'renders counters', false !== strpos( $html, 'bsf-option__count' ) );
it( 'hides zero count options', false === strpos( $html, 'data-bsf-value="light-green"' ) );
it( 'renders a removable chip for the selection', false !== strpos( $html, 'bsf-chip' ) && false !== strpos( $html, 'data-bsf-value="blue"' ) );
it( 'exposes the set id to the script', false !== strpos( $html, 'data-bsf-set="test"' ) );
it( 'marks filter links nofollow', false !== strpos( $html, 'rel="nofollow"' ) );

// Output escaping: a hostile term name must not break out of the markup.
bsf_test_add_term( 'pa_size', 23, 'xl', '<img src=x onerror=alert(1)>' );
\BlockSocial\Filters\Support\Cache::flush();

$wpdb->results['GROUP BY facet.term_id'] = array( array( 'term_id' => 23, 'cnt' => 4 ) );

// A fresh renderer so the per request term memoisation does not hide the new term.
$fresh = new \BlockSocial\Filters\Frontend\Renderer();
$html  = $fresh->render_set( $set );

it( 'the hostile term is actually rendered', false !== strpos( $html, 'data-bsf-value="xl"' ), 'test would be vacuous otherwise' );

it( 'escapes term names', false === strpos( $html, '<img src=x' ), 'unescaped term name in output' );
it( 'escapes to entities instead', false !== strpos( $html, '&lt;img src=x' ) );

// A sidebar panel must not render every attribute list expanded.
$collapsing = bsf()->registry()->normalize_set(
	array(
		'title'        => 'Filters',
		'collapse_all' => true,
		'filters'      => array(
			array( 'source' => 'attribute', 'taxonomy' => 'pa_color', 'display' => 'label', 'id' => 'color', 'url_key' => 'color' ),
			array( 'source' => 'attribute', 'taxonomy' => 'pa_size', 'display' => 'checkbox', 'id' => 'size', 'url_key' => 'size' ),
		),
	),
	'collapsing'
);

is_same( 'collapse_all defaults to on', true, $collapsing['collapse_all'] );

$state->set_raw( array( 'f_color' => 'blue' ) );
$wpdb->results['GROUP BY facet.term_id'] = array( array( 'term_id' => 11, 'cnt' => 7 ), array( 'term_id' => 21, 'cnt' => 2 ) );

$renderer = new \BlockSocial\Filters\Frontend\Renderer();
$html     = $renderer->render_set( $collapsing );

it( 'collapsed filters are marked', false !== strpos( $html, 'is-collapsed' ), 'nothing collapsed' );
it( 'collapsed bodies carry the hidden attribute', false !== strpos( $html, 'bsf-filter__body" id="bsf-body-size" hidden' ), $html );
it( 'a filter with an active selection stays open', false === strpos( $html, 'id="bsf-body-color" hidden' ), 'the active filter was collapsed too' );

$expanded = bsf()->registry()->normalize_set(
	array( 'title' => 'Filters', 'collapse_all' => false, 'filters' => $collapsing['filters'] ),
	'expanded'
);

$renderer = new \BlockSocial\Filters\Frontend\Renderer();
$html     = $renderer->render_set( $expanded );

it( 'switching collapse_all off expands again', false === strpos( $html, 'is-collapsed' ), 'still collapsed with the option off' );

echo "\nDesign tokens\n";

$css = Colors::inline_css( bsf()->settings() );

it( 'emits an accent variable', false !== strpos( $css, '--bsf-accent:' ), $css );
it( 'emits label variables', false !== strpos( $css, '--bsf-label-active-bg:' ) );
it( 'emits the radius variable', false !== strpos( $css, '--bsf-radius:' ) );

$resolved = Colors::resolve( array( 'accent' => 'javascript:alert(1)', 'label_bg' => '#123456' ) );
is_same( 'invalid colours fall back to the default', '#1f6feb', $resolved['accent'] );
is_same( 'valid overrides win', '#123456', $resolved['label_bg'] );

echo "\n";
printf( "%d passed, %d failed\n\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );

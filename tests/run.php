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

echo "\nFail closed on broken filters\n";

// Regression: a filter whose taxonomy is gone must yield nothing, never
// everything. Silently dropping it showed products without the attribute.
$broken = new FilterDefinition( FilterDefinition::normalize( array(
	'source'   => 'attribute',
	'taxonomy' => 'pa_gone',
	'id'       => 'gone',
	'url_key'  => 'gone',
) ) );

$state->set_raw( array( 'f_gone' => 'sommer' ) );
$constraints = $state->constraints( array( $broken ) );

is_same( 'a missing taxonomy yields one constraint', 1, count( $constraints ) );
is_same( 'and that constraint matches nothing', 'none', $constraints[0]['type'] );

$sql = ( new QueryBuilder() )->id_sql( $constraints );
it( 'the SQL cannot match a row', false !== strpos( $sql, '1 = 0' ), $sql );

// The same selection against a live taxonomy must filter normally.
$working = new FilterDefinition( FilterDefinition::normalize( array(
	'source'   => 'attribute',
	'taxonomy' => 'pa_color',
	'id'       => 'color',
	'url_key'  => 'color',
) ) );

$state->set_raw( array( 'f_color' => 'blue' ) );
$constraints = $state->constraints( array( $working ) );
is_same( 'a live taxonomy still filters', 'terms', $constraints[0]['type'] );
is_same( 'and resolves the term', array( 11 ), $constraints[0]['term_ids'] );

// A prefixed parameter that no filter claims still has to narrow.
$state->set_raw( array( 'f_color' => 'blue' ) );
$constraints = $state->constraints( array() );
is_same( 'an unclaimed key is resolved by name', 'terms', $constraints[0]['type'] );
is_same( 'to the right taxonomy', 'pa_color', $constraints[0]['taxonomy'] );

$state->set_raw( array( 'f_nonsense' => 'whatever' ) );
$constraints = $state->constraints( array() );
is_same( 'an unresolvable key matches nothing', 'none', $constraints[0]['type'] );

// A numeric filter without a meta key must not widen either.
$numeric = new FilterDefinition( FilterDefinition::normalize( array(
	'source'  => 'numeric',
	'id'      => 'weight',
	'url_key' => 'weight',
) ) );

$state->set_raw( array( 'f_weight' => '1..5' ) );
$constraints = $state->constraints( array( $numeric ) );
is_same( 'a numeric filter with no meta key matches nothing', 'none', $constraints[0]['type'] );

// Sorting legitimately adds no constraint.
$sort = new FilterDefinition( FilterDefinition::normalize( array( 'source' => 'sort', 'id' => 'sort', 'url_key' => 'sort' ) ) );
$state->set_raw( array( 'f_sort' => 'price' ) );
is_same( 'sorting adds no constraint', 0, count( $state->constraints( array( $sort ) ) ) );

$state->set_raw( array() );

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

// Paging mode is a closed set: an unknown value must fall back, never reach the page.
is_same( 'paging mode falls back', 'auto', Sanitizer::choice( 'nonsense', array( 'auto', 'theme', 'loadmore', 'infinite' ), 'auto' ) );
is_same( 'paging mode accepts infinite', 'infinite', Sanitizer::choice( 'infinite', array( 'auto', 'theme', 'loadmore', 'infinite' ), 'auto' ) );
// Default is automatic: the plugin takes paging over so no theme setting has to change.
is_same( 'paging mode defaults to automatic', 'auto', bsf()->settings()->get( 'pagination_mode' ) );

// The mobile drawer styles must only apply where a toggle exists, otherwise the
// panel is hidden on mobile with no way to open it.
$with_drawer = bsf()->registry()->normalize_set(
	array( 'title' => 'Filters', 'mobile_drawer' => true, 'filters' => $collapsing['filters'] ),
	'drawered'
);

$renderer = new \BlockSocial\Filters\Frontend\Renderer();
$html     = $renderer->render_set( $with_drawer );

it( 'a drawer panel is marked', false !== strpos( $html, 'bsf--drawer' ), 'missing bsf--drawer' );
it( 'and renders the toggle', false !== strpos( $html, 'bsf-drawer-toggle' ) );

$no_drawer = bsf()->registry()->normalize_set(
	array( 'title' => 'Filters', 'mobile_drawer' => false, 'filters' => $collapsing['filters'] ),
	'plain'
);

$renderer = new \BlockSocial\Filters\Frontend\Renderer();
$html     = $renderer->render_set( $no_drawer );

it( 'a panel without a drawer is not marked', false === strpos( $html, 'bsf--drawer' ), 'panel would be hidden on mobile' );
it( 'and renders no toggle', false === strpos( $html, 'bsf-drawer-toggle' ) );

echo "\nColour guessing\n";

use BlockSocial\Filters\Support\ColorNames;

$cases = array(
	'Rot'          => '#d0021b',
	'Schwarz'      => '#111111',
	'Navy'         => '#001f3f',
	'Onyx'         => '#0f0f10',
	'Silber'       => '#c0c0c0',
	'Natur'        => '#e5d3b3',
	'Organic'      => '#8a9a5b',
	'Rosé'         => '#e8b4b8',
	'Purple'       => '#7b2fbf',
	'Orange'       => '#ff8c00',
	'Milky'        => '#f7f3ec',
	'dunkelblau'   => '#12306b',
	'Grün'         => '#2e9e4f',
	'WEISS'        => '#ffffff',
);

foreach ( $cases as $name => $expected ) {
	$guess = ColorNames::resolve( $name, sanitize_title( $name ) );
	is_same( 'guesses ' . $name, $expected, $guess['color'] );
}

$two = ColorNames::resolve( 'Rosa/Weiß', 'rosa-weiss' );
is_same( 'two tone name yields two colours', '#ff8fb1', $two['color'] );
is_same( 'two tone second colour', '#ffffff', $two['color2'] );

$two = ColorNames::resolve( 'Schwarz-Weiss', 'schwarz-weiss' );
is_same( 'hyphenated two tone works', '#111111', $two['color'] );
is_same( 'hyphenated second colour', '#ffffff', $two['color2'] );

$multi = ColorNames::resolve( 'Bunt', 'bunt' );
it( 'multicoloured names get a rainbow', '' !== $multi['gradient'], 'no gradient for Bunt' );

$multi = ColorNames::resolve( 'Mixed', 'mixed' );
it( 'Mixed is multicoloured too', '' !== $multi['gradient'] );

$none = ColorNames::resolve( 'Spion', 'spion' );
is_same( 'an unknown name guesses nothing', '', $none['color'] );

$size = ColorNames::resolve( 'XL', 'xl' );
is_same( 'a size is not a colour', '', $size['color'] );

it( 'style falls back to a neutral swatch', false !== strpos( ColorNames::style( '' ), '#dddddd' ) );
it( 'style builds a two tone gradient', false !== strpos( ColorNames::style( '#000000', '#ffffff' ), 'linear-gradient' ) );
it( 'an explicit gradient wins', false !== strpos( ColorNames::style( '#000000', '#ffffff', 'linear-gradient(red,blue)' ), 'red,blue' ) );

echo "\nDesign tokens\n";

$css = Colors::inline_css( bsf()->settings() );

it( 'emits an accent variable', false !== strpos( $css, '--bsf-accent:' ), $css );
it( 'emits label variables', false !== strpos( $css, '--bsf-label-active-bg:' ) );
it( 'emits the radius variable', false !== strpos( $css, '--bsf-radius:' ) );

// Every default has to be hex: the WordPress colour picker cannot parse rgba(),
// and a field it cannot parse renders blank instead of pre-filled.
$non_hex = array();

foreach ( Colors::defaults() as $token => $value ) {
	if ( ! preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $value ) ) {
		$non_hex[] = $token . ' = ' . $value;
	}
}

is_same( 'every colour default is a hex value the picker can pre-fill', array(), $non_hex );
it( 'the palette is not empty', count( Colors::defaults() ) > 30 );

$resolved = Colors::resolve( array( 'accent' => 'javascript:alert(1)', 'label_bg' => '#123456' ) );
is_same( 'invalid colours fall back to the default', '#1f6feb', $resolved['accent'] );
is_same( 'valid overrides win', '#123456', $resolved['label_bg'] );

echo "\nCache key space\n";

// This is the regression that mattered. Everything keyed by the shopper's own
// filter selection has a combinatorial key space: a crawler walking filter
// links visits combinations without limit. wp_options has no eviction, so each
// one used to leave rows behind that nobody would ever read again — a shop
// reported thirteen million. Fixing only the collector did not help, because
// the collector spares the current generation, which is exactly where these
// live. So none of it may reach the options table at all.
$builder = new \BlockSocial\Filters\Index\QueryBuilder();

$combination_a = array(
	array(
		'type'     => 'taxonomy',
		'taxonomy' => 'pa_color',
		'terms'    => array( 11 ),
		'operator' => 'OR',
	),
);

$combination_b = array(
	array(
		'type'     => 'taxonomy',
		'taxonomy' => 'pa_color',
		'terms'    => array( 11, 21, 23 ),
		'operator' => 'AND',
	),
	array(
		'type'  => 'price',
		'min'   => 10,
		'max'   => 50,
	),
);

$GLOBALS['bsf_test_transients'] = array();

foreach ( array( $combination_a, $combination_b ) as $combination ) {
	$builder->ids( $combination );
	$builder->count( $combination );
	$builder->term_counts( 'pa_color', $combination );
	$builder->price_bounds( $combination );
}

is_same(
	'a filter combination writes nothing to the options table',
	array(),
	$GLOBALS['bsf_test_transients']
);

// The same value asked for twice in one render still only costs one query.
$wpdb->log = array();
$builder->count( $combination_a );
$first_pass = count( $wpdb->log );
$builder->count( $combination_a );

is_same( 'a repeat read inside one request is served from memory', $first_pass, count( $wpdb->log ) );

// Per-taxonomy data is bounded, so it stays cached across requests.
// A taxonomy no earlier test touched, so the renderer's own per-request memo
// does not mask the write.
$GLOBALS['bsf_test_transients'] = array();
bsf()->renderer()->terms( 'pa_regression_probe' );

it(
	'bounded per-taxonomy data is still cached',
	count( $GLOBALS['bsf_test_transients'] ) > 0,
	wp_json_encode( $GLOBALS['bsf_test_transients'] )
);

// The rate limiter must sit outside the collector's pattern, or a collection
// pass resets the live counter.
it(
	'the rate limiter key is not matched by the generation collector',
	0 !== strpos( 'bsfrl_x', 'bsf_' )
);

// The whole render, end to end: with two different selections active, the
// only rows that may reach the options table are the three bounded ones. This
// is the guard for the class of bug, not for the instances found so far.
$allowed = array( '_terms_', '_term_map_', '_url_keys_' );

foreach ( array( array( 'f_color' => 'blue' ), array( 'f_color' => 'blue,red', 'f_size' => 'xl', 'ordr' => 'price' ) ) as $selection ) {
	$GLOBALS['bsf_test_transients'] = array();
	$state->set_raw( $selection );
	( new \BlockSocial\Filters\Frontend\Renderer() )->render_set( $set );

	$stray = array_filter(
		$GLOBALS['bsf_test_transients'],
		static function ( $key ) use ( $allowed ) {
			foreach ( $allowed as $name ) {
				if ( false !== strpos( $key, $name ) ) {
					return false;
				}
			}

			return true;
		}
	);

	is_same( 'a full render with ' . wp_json_encode( $selection ) . ' persists only bounded keys', array(), array_values( $stray ) );
}

$state->set_raw( array( 'f_color' => 'blue' ) );

echo "\nCache generations\n";

// esc_like() escapes the underscores, so the recorded SQL is compared with the
// backslashes stripped back out.
$unescape = static function ( $query ) {
	return str_replace( '\\', '', (string) $query );
};

$cache = '\BlockSocial\Filters\Support\Cache';

// The persisted term lists are hundreds of kilobytes per taxonomy. A product
// edit is the frequent event on a shop — a stock sync touches thousands — and
// it must not rotate the stamp those lists are keyed by, or every one of them
// is rewritten into wp_options after each sync.
$persisted_before = $cache::persistent_key( 'terms', 'pa_color|name' );

$cache::flush();
$cache::flush();

is_same( 'a product change leaves the persisted key alone', $persisted_before, $cache::persistent_key( 'terms', 'pa_color|name' ) );

// A term or setting change is the rare event and does rotate it — once per
// request, however many terms an import touches.
$GLOBALS['bsf_test_cron'] = array();
$cache::flush_terms();

$persisted_after = $cache::persistent_key( 'terms', 'pa_color|name' );

it( 'a term change rotates the persisted key', $persisted_before !== $persisted_after );

$cache::flush_terms();
$cache::flush_terms();

is_same( 'repeated term changes share one generation per request', $persisted_after, $cache::persistent_key( 'terms', 'pa_color|name' ) );

// The previous generation is never read again, so it is deleted rather than
// left for an expire-on-read that never comes.
$wpdb->log = array();
$cache::collect_garbage();

$deletes = array_values(
	array_filter(
		array_map( $unescape, $wpdb->log ),
		static function ( $query ) {
			return false !== strpos( $query, 'DELETE' ) && false !== strpos( $query, '_transient_bsf_' );
		}
	)
);

is_same( 'garbage collection issues exactly one delete', 1, count( $deletes ) );

$gc = (string) ( $deletes[0] ?? '' );

it( 'it deletes the value rows', false !== strpos( $gc, '_transient_bsf_%' ) );
it( 'it deletes the timeout rows', false !== strpos( $gc, '_transient_timeout_bsf_%' ) );
it( 'it spares the live terms generation', false !== strpos( $gc, '_bsf_' . $cache::terms_version() . '_' ), $gc );

// A shop upgrading from 1.0.5 can be sitting on millions of rows. One
// unbounded DELETE on a table that size locks it for minutes or times out and
// rolls back, so every collected batch has to be bounded.
it( 'garbage collection deletes in bounded batches', false !== strpos( $gc, 'LIMIT ' . $cache::GC_BATCH ), $gc );

// Uninstall wants everything gone, so the keep argument stays optional.
$wpdb->log = array();
$cache::purge_transients();

$all = $unescape( $wpdb->log[0] ?? '' );

it( 'an unscoped purge targets the plugin prefix', false !== strpos( $all, '_transient_bsf_%' ), $all );
it( 'an unscoped purge removes every generation', false === strpos( $all, 'NOT LIKE' ), $all );
it( 'an unscoped purge is unbounded so uninstall can drive its own loop', false === strpos( $all, 'LIMIT' ), $all );

$wpdb->log = array();
$cache::purge_transients( '', 500 );

it( 'a bounded purge carries the limit', false !== strpos( (string) ( $wpdb->log[0] ?? '' ), 'LIMIT 500' ), (string) ( $wpdb->log[0] ?? '' ) );

echo "\nRate limiter\n";

// One row per address, rewritten in place. The previous key included the
// minute, which made the key space visitors times minutes — and each row was
// read only during its own minute, so nothing ever collected it.
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$GLOBALS['bsf_test_transient_reads'] = true;
$GLOBALS['bsf_test_transients']      = array();

$request = new WP_REST_Request( 'GET', '/blocksocial-filters/v1/filter' );
$ajax    = bsf()->ajax();

$ajax->public_permission( $request );
$ajax->public_permission( $request );

$written = array_values( array_unique( $GLOBALS['bsf_test_transients'] ) );

is_same( 'two requests from one address share one row', 1, count( $written ), wp_json_encode( $GLOBALS['bsf_test_transients'] ) );
it( 'the rate limiter key sits outside the collector pattern', 0 === strpos( (string) ( $written[0] ?? '' ), 'bsfrl_' ), (string) ( $written[0] ?? '' ) );
it( 'the counter increments in place', 2 === (int) ( $GLOBALS['bsf_test_transient_store'][ $written[0] ][1] ?? 0 ) );

$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
$ajax->public_permission( $request );

is_same( 'a second address gets its own row', 2, count( array_unique( $GLOBALS['bsf_test_transients'] ) ) );

$GLOBALS['bsf_test_transient_reads'] = false;

$wpdb->log = array();
$cache::purge_rate_limits( 200 );

$rl = $unescape( $wpdb->log[0] ?? '' );

it( 'rate limit cleanup targets only its own prefix', false !== strpos( $rl, '_transient_timeout_bsfrl_%' ), $rl );
it( 'rate limit cleanup is bounded', false !== strpos( $rl, 'LIMIT 200' ), $rl );

echo "\n";
printf( "%d passed, %d failed\n\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );

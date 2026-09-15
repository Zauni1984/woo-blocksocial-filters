/**
 * Unit test for the request bridge in assets/js/frontend.js.
 *
 * The bridge rewrites the theme's own "next page" request so it carries the
 * active filters. Getting the scope wrong would either leave the bug in place
 * or corrupt unrelated requests, so the decision logic is tested against the
 * real source rather than a copy.
 *
 * Run: node tests/js/bridge.test.js
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const source = fs.readFileSync(
	path.join( __dirname, '..', '..', 'assets', 'js', 'frontend.js' ),
	'utf8'
);

// Lift the bridge out of the IIFE so it can be exercised directly.
const start = source.indexOf( 'var bridgeQuery = ' );
const end = source.indexOf( 'function themeLoaderPresent' );

if ( start === -1 || end === -1 ) {
	console.error( 'FAIL: could not locate the request bridge in frontend.js' );
	process.exit( 1 );
}

const fragment = source.slice( start, end );

const factory = new Function(
	'config',
	'window',
	fragment + '\nreturn { bridgeUrl: bridgeUrl, set: function (q, b) { bridgeQuery = q; bridgeBase = b; } };'
);

const bridge = factory( { prefix: 'f_' }, { location: { origin: 'https://shop.test' } } );

let passed = 0;
let failed = 0;

function is( name, expected, actual ) {
	if ( expected === actual ) {
		passed++;
		console.log( '  ok   ' + name );

		return;
	}

	failed++;
	console.log( '  FAIL ' + name + '\n       expected ' + expected + '\n       actual   ' + actual );
}

function rewrites( name, url, method ) {
	const result = bridge.bridgeUrl( url, method || 'GET' );

	is( name, true, result !== '' && result.indexOf( 'f_color=schwarz' ) !== -1 );
}

function leavesAlone( name, url, method ) {
	is( name, '', bridge.bridgeUrl( url, method || 'GET' ) );
}

console.log( '\nRequest bridge\n' );

bridge.set( 'f_color=schwarz', '/produkt-kategorie/pflanzen/' );

// The case this exists for: OceanWP asks for the unfiltered page 2.
rewrites( 'the theme next page request gets the filters', 'https://shop.test/produkt-kategorie/pflanzen/page/2/' );
rewrites( 'a relative next page URL works too', '/produkt-kategorie/pflanzen/page/3/' );
rewrites( 'the archive itself is covered', '/produkt-kategorie/pflanzen/' );

// Everything else has to be left untouched.
leavesAlone( 'another archive is not touched', '/produkt-kategorie/zubehoer/page/2/' );
leavesAlone( 'the REST endpoint is not touched', '/wp-json/blocksocial-filters/v1/filter?url=x' );
leavesAlone( 'admin-ajax is not touched', '/wp-admin/admin-ajax.php' );
leavesAlone( 'WooCommerce cart fragments are not touched', '/?wc-ajax=get_refreshed_fragments' );
leavesAlone( 'assets are not touched', '/wp-content/themes/oceanwp/assets/js/theme.min.js' );
leavesAlone( 'another origin is not touched', 'https://example.com/produkt-kategorie/pflanzen/page/2/' );
leavesAlone( 'POST is not touched', '/produkt-kategorie/pflanzen/page/2/', 'POST' );
leavesAlone(
	'a request that already carries filters is left as is',
	'/produkt-kategorie/pflanzen/page/2/?f_color=rot'
);

// With nothing filtered the bridge must be completely inert.
bridge.set( '', '/produkt-kategorie/pflanzen/' );
leavesAlone( 'inert while nothing is filtered', '/produkt-kategorie/pflanzen/page/2/' );

// Sorting and keyword travel along with the filters.
bridge.set( 'f_color=schwarz&ordr=price&srch=rose', '/shop/' );

const sorted = bridge.bridgeUrl( '/shop/page/2/', 'GET' );

is( 'sorting is carried over', true, sorted.indexOf( 'ordr=price' ) !== -1 );
is( 'the keyword is carried over', true, sorted.indexOf( 'srch=rose' ) !== -1 );
is( 'the page is preserved', true, sorted.indexOf( '/shop/page/2/' ) !== -1 );

console.log( '\n' + passed + ' passed, ' + failed + ' failed\n' );

process.exit( failed > 0 ? 1 : 0 );

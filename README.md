# BlockSocial Filters for WooCommerce

Index-driven product filters for WooCommerce: attributes, price, rating, stock,
taxonomies and custom fields, with swatches, AJAX, SEO-friendly URLs and
variation swatches on the single product page.

The design goal is stated plainly: **stay fast and stay safe on large
catalogues.** Everything else follows from that.

---

## Kurzüberblick (deutsch)

* Filter für Attribute, Preis, Kategorie, Marke, Bewertung, Lagerbestand,
  Gewicht und beliebige numerische Custom Fields (auch ACF).
* Anzeigearten: Checkboxen, Radio-Buttons, Dropdowns, **Labels**, Farbfelder,
  Bild-Swatches, Sternebewertung, Range-Slider, Datumsbereich.
* Drei Modi: *Auto-Submit*, *Auswählen und Anwenden*, *Schritt für Schritt*.
* Horizontale Leiste oder vertikale Spalte, auf dem Handy als Drawer.
* **Alle Farben einstellbar** über CSS-Variablen im Tab „Design“.
* Varianten-Swatches auf der Produktseite — kein Dropdown mehr nötig.
* OceanWP & Co.: Filterleiste kann automatisch über dem Produktarchiv
  ausgegeben werden, ohne Template-Änderungen.
* Einmaliger Index-Aufbau mit Fortschrittsanzeige beim ersten Einrichten.

---

## Why it is fast

A filtered query never touches `postmeta` or `term_relationships`. The plugin
keeps its own denormalised index in four purpose-built tables:

| Table | Holds |
| --- | --- |
| `bsf_index` | one row per product/term pair, plus per-variation rows |
| `bsf_product` | price range, stock, rating, sales, visibility, language, search text |
| `bsf_numeric` | numeric ranges: weight, dimensions, dates, custom fields |
| `bsf_queue` | products waiting to be re-indexed |

From there:

* **One statement per query.** Each active filter compiles to one `INNER JOIN`
  against `bsf_index`. No subqueries, no `IN (…)` list of ten thousand IDs.
* **Facet counts reuse the same joins.** A panel with twelve filters issues a
  small, bounded number of indexed queries instead of one query per term.
* **Two strategies for handing results to `WP_Query`.** Result sets up to a
  configurable threshold are passed as `post__in` (primary-key lookups); larger
  ones join the index table directly, so the query plan stays flat as the
  catalogue grows.
* **Counts are cached** in the object cache (or transients) behind a single
  version stamp, so invalidation is one option write rather than a table scan.
* **Indexing is set-based.** A batch is resolved with a handful of bulk queries
  and written back with chunked multi-row inserts — `wc_get_product()` is never
  called in the indexing path.
* **Full-text search** is used for the keyword filter when the server supports
  the index, with an automatic `LIKE` fallback when it does not.

Index updates are queued rather than applied inline, so imports, stock syncs and
order processing never pay the indexing cost inside the request.

## Security model

* Every request value passes through `Support\Sanitizer` before it can reach a
  query builder. Term slugs are resolved against real terms, so only integer
  term IDs are ever interpolated; numbers are cast and range-clamped; column and
  taxonomy names are checked against allow-lists.
* The number of filters per request and values per filter are capped, so a
  crafted URL cannot inflate the work the database has to do.
* The public AJAX endpoint is read-only and rate-limited per IP.
* Admin screens require `manage_woocommerce`, and every form is nonce-checked.
* All output is escaped at the point of printing (`tests/run.php` asserts this
  with a hostile term name).

## Installation

1. Copy the plugin folder into `wp-content/plugins/` and activate it.
2. Go to **WooCommerce → Product Filters → Index** and run the first build.
   The progress bar runs in batches from the browser; on very large catalogues
   use WP-CLI instead:

   ```
   wp bsf index --batch=1000
   ```
3. Configure a filter set under **Filter sets**, then place it on the page.

## Placing filters

| Where | How |
| --- | --- |
| Any page builder (Divi, Bricks, Oxygen, Breakdance, Beaver Builder) | `[bsf_filters set="default"]` |
| Gutenberg | the **Product filters** block |
| Elementor | the **Product filters** widget |
| Classic sidebar | the **BlockSocial Product Filters** widget |
| Above the product archive | **Settings → Archive placement** (no template edits) |

### Shortcodes

```
[bsf_filters set="default" layout="horizontal" mode="apply" columns="3"]
[bsf_products per_page="12" columns="4" category="shoes"]
[bsf_active_filters]
[bsf_sort]
[bsf_search placeholder="Search the shop"]
[bsf_result_count]
```

## URL styles

Query string (default):

```
/shop/?f_color=blue,red&f_price=10..100&ordr=price
```

Readable paths (**Settings → URLs and SEO → Readable path**):

```
/shop/color-blue,red/price-10..100/
```

Each filter has its own **URL name**, so `pa_color` can appear as `color`. Filter
segments are stripped before WordPress resolves the request, so archives,
pagination and canonical URLs keep working. Multi-filter pages get `noindex,
follow` and a canonical pointing at the clean archive.

## Variation swatches

With swatches enabled, the variation `<select>` on a product page is replaced by
labels, colour or image swatches. The original select stays in the DOM (hidden)
and remains the source of truth, so WooCommerce's own variation script keeps
working untouched — including availability, so combinations that do not exist
are crossed out, dimmed or hidden as configured.

Set the swatch colour, a second colour for two-tone swatches, an image and a
tooltip on each attribute term.

## Theming

Every colour is a CSS custom property, set in **Design** and emitted as an
inline `:root` block. A child theme can override any of them:

```css
:root {
	--bsf-label-active-bg: #111;
	--bsf-label-active-border: #111;
	--bsf-swatch-selected: #111;
	--bsf-radius: 2px;
}
```

## Hooks

```php
// Narrow every query on a page.
add_filter( 'bsf_context_constraints', function ( $constraints ) { … } );

// Add your own indexed numeric facets.
add_filter( 'bsf_indexed_meta_keys', function ( $keys ) {
	$keys[] = 'volume_ml';
	return $keys;
} );

add_filter( 'bsf_index_numeric_values', function ( $values, $product_id ) { … }, 10, 2 );

// Render the grid yourself during AJAX filtering.
add_filter( 'bsf_render_products', function ( $html, $query ) { … }, 10, 2 );

// Adjust markup.
add_filter( 'bsf_render_filter', function ( $html, $definition ) { … }, 10, 2 );
add_filter( 'bsf_term_options', function ( $options, $definition ) { … }, 10, 2 );
```

The front end also emits a `bsf:updated` DOM event after every AJAX update.

## WP-CLI

```
wp bsf index [--batch=<n>] [--resume]   # build or rebuild the index
wp bsf queue [--limit=<n>]              # drain pending updates
wp bsf status                           # index statistics
wp bsf flush                            # flush cached counts
```

## Compatibility

* WooCommerce 7.0+, WordPress 6.2+, PHP 7.4+
* HPOS (custom order tables) declared compatible
* WPML and Polylang: products are indexed per language and queries are scoped to
  the active language
* Themes: OceanWP, Storefront, Astra, GeneratePress, Blocksy, Flatsome have
  known placement hooks; anything else falls back to
  `woocommerce_before_shop_loop` or a hook you choose

## Page caches

Filtered pages are fine to cache. Three things must be left alone, or the panel
goes stale or stops working:

| Setting (LiteSpeed Cache) | Value |
| --- | --- |
| Cache → Excludes → **Do Not Cache URIs** | `/wp-json/blocksocial-filters/` |
| Page Optimization → Tuning → **JS Deferred/Delayed Excludes** | `woo-blocksocial-filters/assets/js/frontend.js` |
| Page Optimization → Tuning-CSS → **CSS Excludes** | `woo-blocksocial-filters/assets/css/frontend.css` |

The REST route returns the freshly filtered grid *and* the facet counts, so a
cached response keeps serving counts from before the last index build — which
looks like options going missing, because an option counted at zero is hidden.
LiteSpeed caches the REST API by default and WP Rocket does not, which is why
the same build can behave differently on two shops.

`frontend.js` patches `window.fetch` and `XMLHttpRequest.prototype.open` while
it loads so a theme's own endless loading carries the active filters. Deferring
or delaying it means the theme has already asked for its first unfiltered page.

Also make sure the filter parameters (`f_*`, `ordr`, `srch`) are **not** listed
under *Drop Query String*, and leave *Cache Logged-in Users* off — the page
carries a `wp_rest` nonce that expires after 12 hours.

[docs/CACHING.md](docs/CACHING.md) has the complete list, the equivalents for
WP Rocket, Cloudflare and Varnish, the UCSS selector allowlist, and a
step-by-step check for "too few attributes are shown".

## Development

```
php tests/run.php            # 141 assertions, no dependencies, no WordPress needed
node tests/js/bridge.test.js # 15 assertions on the theme request bridge
composer lint         # php -l across the tree
composer phpcs        # WordPress coding standards
```

`tests/bootstrap.php` stubs the small slice of WordPress the core classes touch,
so the sanitiser, URL builder, SQL builder and renderer can be exercised
directly.

## Translations

German (`de_DE`) ships with the plugin in `languages/`. Translations load through
`load_plugin_textdomain`, so a `.mo` dropped into `wp-content/languages/plugins/`
overrides the bundled one.

## License

GPL-2.0-or-later.

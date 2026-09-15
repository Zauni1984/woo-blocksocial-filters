=== BlockSocial Filters for WooCommerce ===
Contributors: blocksocial
Tags: woocommerce, product filter, attribute filter, variation swatches, ajax filter
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, index driven product filters for WooCommerce, with swatches, AJAX, readable URLs and variation swatches on the product page.

== Description ==

BlockSocial Filters keeps its own index of everything it filters on, so a
filtered query never touches postmeta or term relationships. That is what keeps
response times flat as a catalogue grows into six figures.

**Filter by virtually anything**

Price, brand, category, tags, attributes, colour, size, weight, rating, stock
status, sale status, dates and any numeric custom field, including fields
created with ACF.

**Flexible layouts and display options**

Checkboxes, radio buttons, dropdowns, labels, colour swatches, image swatches,
rating stars, numeric range sliders and date ranges. Show them as a horizontal
toolbar or a vertical panel; on mobile the panel becomes a drawer.

**Three filtering modes**

* Auto submit, applying each click immediately
* Select and apply, collecting choices behind an Apply button
* Step by step, applying immediately and hiding options that cannot lead
  anywhere

**Everything else you would expect**

Product counters, collapsible sections, hiding empty filters, searching within a
filter's options, AND/OR logic per filter, custom URL names, sorting, keyword
search within filtered results, and AJAX filtering with no page reloads.

**Variation swatches**

Replace the variation dropdowns on the product page with labels, colour swatches
or image swatches. The stock WooCommerce variation script keeps running
underneath, so unavailable combinations are still crossed out, dimmed or hidden.

**Every colour is configurable**

The whole UI is driven by CSS custom properties you set in the Design tab, so
the filters can match any theme without writing CSS. A child theme can still
override any token.

**Works where you build**

Shortcodes for Divi, Bricks, Oxygen, Breakdance and Beaver Builder; a native
Gutenberg block; an Elementor widget; a classic sidebar widget; and automatic
placement above the product archive for OceanWP, Storefront, Astra,
GeneratePress, Blocksy and Flatsome.

**Multilingual**

Compatible with WPML and Polylang. Products are indexed per language and
filtered queries are scoped to the active language.

**Developer friendly**

Actions and filters for constraints, indexed fields, option lists and markup,
plus WP-CLI commands for building and inspecting the index.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. Open WooCommerce > Product Filters > Index and run the first build. A
   progress bar reports how far along it is.
3. Configure a filter set and place it with the block, widget, Elementor widget
   or the `[bsf_filters]` shortcode.

On very large catalogues, build the index from the command line instead:

`wp bsf index --batch=1000`

== Frequently Asked Questions ==

= Do I have to rebuild the index after editing a product? =

No. Index updates are queued automatically whenever a product, variation, stock
level or term assignment changes. Rebuild manually only after a bulk database
change made outside WordPress.

= Does it work with page builders? =

Yes. Gutenberg and Elementor get native modules; every other builder can render
the shortcodes. If your builder outputs its own product grid, point the plugin
at it with the Products container selector setting.

= Will filtered URLs hurt my SEO? =

Pages with more than one active filter are marked `noindex, follow` and carry a
canonical URL pointing at the clean archive. Filter links are `nofollow`. Both
behaviours can be turned off.

= Is the AJAX endpoint safe to leave open? =

It is read-only, rate limited per IP, and every parameter is re-sanitised
server side. It returns nothing a visitor could not already see by loading the
filtered URL directly.

== Screenshots ==

1. A vertical filter panel with labels, colour swatches and a price slider.
2. The horizontal toolbar above a product archive.
3. Variation swatches replacing the dropdowns on a product page.
4. The Design tab, where every colour is configurable.
5. The first index build, with progress.

== Changelog ==

= 1.0.2 =
* Fixed: selecting one value could silently select others as well. After an AJAX
  update the panel was re-read from the previous URL, which put the old
  selection back over the markup the server had just produced.
* Fixed: with more than one panel on a page (an archive bar and a sidebar
  widget, say) only the clicked one was refreshed. The other kept a stale
  selection and undid it on the next click. Every panel is now refreshed from
  the same response.
* New: results can be paged by the plugin itself, either with a "Load more"
  button or by loading the next page while scrolling. A theme that brings its
  own infinite scroll cannot follow an AJAX filter, because its script is bound
  to the product list that was on the page when it loaded.
* AJAX updates no longer replace the product container and pagination elements,
  only their contents, so scripts bound to them keep working.
* New: swatch colours are guessed from the term name in German and English, so a
  catalogue of colour terms is not grey out of the box. An admin action writes
  the guesses into the terms to make them editable. Explicit colours always win,
  and a guessed colour never turns another attribute into colour swatches.
* Fixed: long option names in a narrow sidebar were truncated; they wrap now.

= 1.0.1 =
* German translation (de_DE) for the whole plugin, front end and admin.
* Fixed: filters could not be collapsed on mobile, so an archive filter bar grew
  endlessly long. A layout rule was overriding the hidden attribute.
* Filter sets now start with every filter collapsed, which keeps sidebar widgets
  and mobile drawers short. Filters with an active selection stay open, and the
  behaviour is a checkbox per set.
* On small screens filters collapse automatically on first paint.
* The index screen now refreshes its statistics when a build finishes instead of
  leaving the previous numbers on screen.

= 1.0.0 =
* First release.

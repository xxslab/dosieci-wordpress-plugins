=== DoSieci Instant Search ===
Contributors: vvalik
Tags: woocommerce, search, live search, autocomplete, ajax search
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live search suggestions for WooCommerce products, posts or pages. Runs on your own database: no API key, and no query leaves your site.

== Description ==

As visitors type in any standard WordPress search field, DoSieci Instant Search shows matching products (with image and price), posts or pages right below it. Choosing a suggestion takes the visitor straight to it.

= Works with your theme =

It attaches to every standard search field (`input name="s"`): the Search block, classic theme search forms and the WooCommerce product search. The suggestion list is placed under the field without changing the page layout, and it follows the field inside sticky headers.

= Finds what people type =

Every word typed has to start a word in the title, in any order: "shoes black" finds "Black running shoes", and "shirt" finds "Blue T-shirt". For products it also matches SKUs. Exact titles come first, then titles that start with the query, then the rest.

= Only what visitors may see =

Suggestions only include published content. Password-protected items, products hidden from search in WooCommerce, and (when the shop hides them) out-of-stock products never appear.

= Private and light =

Everything runs on your server. The plugin makes no external requests and needs no account or API key. It reads only titles and SKUs, never the full post content, and short-lived caching absorbs bursts of keystrokes.

= Accessible =

The field and the list use the ARIA combobox pattern. Arrow keys move through the suggestions, Enter opens the highlighted one, and Escape closes the list.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the ZIP file.
2. Activate it. Suggestions appear in your search fields straight away.
3. Optionally, go to Settings > Instant Search to choose what to search (WooCommerce products, posts or pages) and after how many characters.

== Frequently Asked Questions ==

= Does it replace the search results page? =

No. It only adds suggestions while typing. Pressing Enter without choosing a suggestion still submits the normal search.

= Does it send my search data anywhere? =

No. Nothing leaves your server.

= Will it slow my shop down? =

The suggestion query reads only the short title and SKU columns and returns at most eight items. Results are cached for a minute (in your persistent object cache, if you have one), and browsers may reuse a response for 30 seconds.

= Can I style the list? =

Yes. The list uses the classes `.dosieci-is-panel`, `.dosieci-is-item`, `.dosieci-is-title` and `.dosieci-is-price`.

== Screenshots ==

1. Product suggestions with prices under the Search block.
2. The settings page.

== Changelog ==

= 1.0.0 =
* Initial release.

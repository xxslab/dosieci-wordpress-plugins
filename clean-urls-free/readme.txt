=== DoSieci Clean URLs ===
Contributors: dosieci
Tags: permalinks, slugs, redirects, 301, seo
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clean up post, page and product URLs safely: a preview of every change, a collision check, and 301 redirects without chains or loops.

== Description ==

DoSieci Clean URLs turns messy addresses such as `/za%c5%bc%c3%b3%c5%82%c4%87-g%c4%99%c5%9bl%c4%85/` into clean ones such as `/zazolc-gesla/`, built from the title, and keeps every old address working with a 301 redirect.

Nothing changes until you have seen it and approved it.

= Preview first =

Choose posts, pages or WooCommerce products and you get the full list of proposed addresses. Encoded or non-ASCII slugs are ticked for you; clean slugs that simply differ from the title are usually chosen on purpose, so they stay unticked unless you tick them. Only the ticked rows are renamed, and only with the exact slug you saw.

= Collision check =

A rename is blocked when:

* the new slug is reserved by WordPress (for example `feed` or `wp-admin`),
* another item already uses it, or another item in the same batch wants it,
* WordPress itself would change it (for example to `kontakt-2`).

= Redirects without chains or loops =

Every renamed item gets a 301 redirect from its old address. When a page is renamed, its child pages get redirects too. If an address changes again later, the redirect is flattened so there is never a chain, and a change that would create a loop is refused. Old addresses are matched whether a browser sends them encoded, in upper or lower case, or typed with accents, and any query string (such as UTM parameters) is kept.

= Safe for your content =

Only the slug is changed. The rest of the post, including embeds, is saved exactly as it was, even for administrators without the unfiltered_html capability.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the ZIP file.
2. Activate it.
3. Go to Tools > Clean URLs, choose a content type and review the preview.

== Frequently Asked Questions ==

= Will my old links stop working? =

No. Each old address redirects to the new one with a 301 status, which also passes on search engine rankings.

= What happens when I deactivate or delete the plugin? =

Deactivating stops the redirects from being served but keeps the map, so reactivating restores them. Deleting the plugin also keeps the map in the `dosieci_clean_urls_redirects` option on purpose: it is the only record of where old addresses went. Renamed slugs stay renamed; they are ordinary post data.

= Does it handle categories and tags? =

Not yet. This version handles posts, pages and WooCommerce products.

= How many items can it handle? =

The preview checks every item of the chosen type and lists up to 300 changes at a time. After you apply a batch, the next ones appear.

= Who can use it? =

Administrators (users with the manage_options capability).

== Screenshots ==

1. The preview: proposed slugs, collision warnings and the rows selected for renaming.

== Changelog ==

= 1.0.0 =
* Initial release.

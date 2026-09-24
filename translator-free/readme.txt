=== DoSieci Translator ===
Contributors: dosieci
Tags: translation, deepl, woocommerce, translate, products
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate product, post and page texts with DeepL using your own API key. Always previewed before saving, with one-click restore of the previous text.

== Description ==

DoSieci Translator translates the title, the excerpt (a WooCommerce product's short description) or the content (a product's description) of one item at a time with DeepL, and replaces the original text only after you have compared both side by side.

A typical use: you imported products from a supplier in another language and want them in your shop's language.

= Always a preview =

You see the text before and after translation first. Nothing is written until you click "Save the translation", and what gets saved is exactly the text you saw.

= One-click restore =

Before a translation is saved, the previous text of that field is kept. If you change your mind, restore it with one click on the same screen. This matters for WooCommerce products, which have no revision history in WordPress.

= Safe for your content =

HTML and block markup are preserved (DeepL's HTML mode), backslashes and embedded media survive, and only the field you chose is changed.

= Your own DeepL key =

Translations use your own DeepL API key (Free or Pro, detected automatically) and your own character allowance. Requests go from your site straight to DeepL, never through DoSieci.

= What it is not =

This is not a multilingual plugin: it does not create a second language version of a page and does not replace WPML, Polylang or TranslatePress. It replaces the text of the field you translate.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the ZIP file.
2. Activate it.
3. Go to Tools > Translator and save your DeepL API key (create one at https://www.deepl.com/pro-api).
4. Click "Translate" under any post, page or product in its list.

== Frequently Asked Questions ==

= Which languages are supported? =

36 target languages, including English (British and American), German, French, Spanish, Italian, Polish, Czech, Ukrainian, Portuguese (European and Brazilian), Chinese, Japanese and Korean. The source language is detected by DeepL.

= Can I translate many products at once? =

Not in this version. It translates one field of one item at a time, on purpose: every machine translation is reviewed before it is saved.

= Where is the previous text kept? =

In a hidden custom field of the post, one per translated field, until you restore it or translate the same field again. Deleting the plugin removes these backups and the API key; translations you saved stay.

= Who can use it? =

Anyone who can edit a post can translate it. Only administrators can store or remove the DeepL key.

== External services ==

This plugin sends text to the DeepL API to translate it. This only happens when a user clicks "Preview the translation" for a field, and only that field's text is sent, together with the chosen target language. The request is authenticated with the DeepL API key the site administrator entered.

* Service: DeepL API (https://api-free.deepl.com for DeepL API Free keys, https://api.deepl.com for DeepL API Pro keys)
* DeepL terms and conditions: https://www.deepl.com/pro-license
* DeepL privacy policy: https://www.deepl.com/privacy

Nothing is sent to DoSieci.

== Screenshots ==

1. Choosing the field and the target language.
2. Restoring the text from before a translation.

== Changelog ==

= 1.0.0 =
* Initial release.

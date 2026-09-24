=== DoSieci SEO Doctor ===
Contributors: dosieci
Tags: seo, audit, meta description, woocommerce, ai
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A read-only SEO audit of posts, pages and products that explains every finding, with optional AI suggestions. Works alongside your SEO plugin.

== Description ==

DoSieci SEO Doctor checks one post, page or WooCommerce product at a time and tells you, in plain language, what to improve:

* the title length,
* the meta description (read from Yoast SEO, Rank Math, SEOPress or All in One SEO, or the excerpt),
* the amount of content,
* missing image alt text,
* the slug.

Open it from the "SEO audit" link under any item in the post, page or product list, or from Tools > SEO Doctor.

= Works alongside your SEO plugin =

The plugin never writes titles, canonicals, sitemaps or schema. There is not even code that could. It reads what your SEO plugin stored and leaves it alone, so it does not conflict with Yoast SEO, Rank Math, All in One SEO or SEOPress.

= Optional AI suggestions =

With one click, an AI model proposes a title, a meta description and a focus keyphrase for the item, in the language of the content. The prompt tells the model not to invent product features that are not in the content. Suggestions are shown for you to copy; nothing is saved automatically.

AI suggestions use the AI provider you configured in WordPress under Settings > Connectors (WordPress 7.0 or later). If there is none, you can enter your own OpenAI API key instead. Either way the request goes straight from your site to the provider, never through DoSieci, and the provider bills you directly. The audit itself works without any AI.

= Private by default =

Nothing is sent anywhere until you click the suggestion button. Your API key is stored in your database, never displayed in full, never logged, and removed when you uninstall the plugin.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the ZIP file.
2. Activate it.
3. Click "SEO audit" under any post, page or product, or go to Tools > SEO Doctor.
4. Optional: set up AI suggestions in Settings > Connectors, or enter an OpenAI API key at the bottom of Tools > SEO Doctor.

== Frequently Asked Questions ==

= Do I need an AI provider? =

No. The audit works on its own. AI is only used when you click "Suggest a title and description with AI".

= Which OpenAI model does it use? =

`gpt-4o-mini` by default. You can enter any chat model your key has access to.

= Who can use it? =

Anyone who can edit a post can audit it and request suggestions for it. Only administrators can store or remove an API key.

= Will it change my SEO settings? =

No. It only reads them.

== External services ==

This plugin only contacts an external service when a user clicks "Suggest a title and description with AI". What is sent is the item's title and up to 4,000 characters of its content (as plain text), together with instructions for the model. Nothing is sent automatically, and nothing is sent to DoSieci.

* **AI provider configured in WordPress (Settings > Connectors).** On WordPress 7.0 or later, if a provider is configured there, the request goes through the WordPress AI client to that provider (for example OpenAI, Anthropic or Google). The provider's own terms and privacy policy apply; see the provider plugin you installed for the links.
* **OpenAI API** (`https://api.openai.com/v1/chat/completions`), used only when no provider is configured in WordPress and you entered your own OpenAI API key. The request is authenticated with that key. OpenAI terms of use: https://openai.com/policies/terms-of-use/ and privacy policy: https://openai.com/policies/privacy-policy/

== Screenshots ==

1. The audit of a product, with AI suggestions.
2. The "SEO audit" link in the product list.

== Changelog ==

= 1.0.0 =
* Initial release.

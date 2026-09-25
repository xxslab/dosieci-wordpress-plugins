=== DoSieci AI Operator ===
Contributors: vvalik
Tags: ai, woocommerce, diagnostics, assistant, site health
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI assistant in wp-admin. You talk, the operator diagnoses your site and — once you approve it — builds it: WordPress and WooCommerce.

== Description ==

DoSieci AI Operator adds a chat with an assistant to wp-admin that can check the state of your site on its own — PHP version, plugins, theme, cron jobs, the size of autoloaded options, a database summary, the state of WooCommerce — and, once you turn on write mode, build it out: create pages and posts, install a theme or plugin from the WordPress.org directory, put together a menu, set the homepage and change site settings.

= Three ways to reach a model =

**DoSieci Hub** (the default) — the conversation goes as a signed request (HMAC-SHA256) to the DoSieci License Hub, which meters usage against your plan's credits and only then calls the model on its side. Your WordPress database never holds an AI key in this mode.

**WordPress AI connectors** — on WordPress 7.0 or later, the plugin uses whichever AI provider you connected under Settings > Connectors (OpenAI, Anthropic, Google or others). WordPress stores and manages that key; this plugin never sees it. No DoSieci credits are used.

**Your own API key (BYOK)** — the plugin connects directly to OpenAI or Anthropic with your own key. No DoSieci credits are used, and you pay the provider directly. The key is stored in this site's database and sent only to the chosen provider over HTTPS — never to DoSieci and never to the audit log.

= Security =

* The model **never** gets the ability to run arbitrary PHP, SQL or a shell command — it can only call a named, declared tool.
* Tools that change the site are **off by default**. Until you turn them on, they are not even registered — the model does not know they exist.
* Once turned on, **every single change needs its own “Approve” click** in the chat. There is no “trust and act” mode, and there is no blanket approval: one approval covers one action with the specific arguments you see before you click.
* Every tool requires its own WordPress capability, checked for the **logged-in human** running the conversation. The AI never has more permissions than the person at the keyboard.
* Themes and plugins are installed **only from the official WordPress.org directory, by slug**. There is no "install from a URL" tool — that would be an open door to running arbitrary code.
* Site settings can only be changed from a **closed list of allowed options**. Options that could take over the site (user registration, the default role, the site address, the list of active plugins) are not on it.
* Removing content sends it **to the trash**, never a permanent delete.
* Arguments produced by the model are validated against a schema before anything runs — treated like any other untrusted input.
* Every attempt to call a tool — including a refused one — is recorded in a local audit log. Secrets are never written to it.
* Deactivating the plugin deletes nothing. Uninstalling it deletes data only if you deliberately turned that option on beforehand in the settings.

= Requirements =

An active DoSieci AI Operator subscription and a pairing token from the DoSieci panel — or your own OpenAI or Anthropic API key, or an AI provider connected under Settings > Connectors on WordPress 7.0+.

== Installation ==

1. Upload the plugin and activate it.
2. Go to **DoSieci AI → Connection**.
3. Paste the one-time pairing token from the DoSieci panel and save — or skip pairing and choose your own key under **DoSieci AI → Settings** instead.
4. Go to **DoSieci AI → Chat**.

== Frequently Asked Questions ==

= Do I have to supply my own OpenAI/Anthropic key? =

No. DoSieci Hub mode uses a hosted model on DoSieci's side — your site never sees a provider key. Your own key is only needed if you choose the WordPress AI connectors mode or one of the BYOK modes instead.

= Can the operator break something on my site? =

Write tools are off by default: with them off, every tool is read-only and nothing writes, deletes or modifies data. Once you turn write tools on, every single change still stops for your explicit approval, showing the exact arguments, before anything runs.

= What happens if DoSieci is unreachable? =

The chat shows an error message. The rest of wp-admin and WooCommerce keep working normally — the AI being unavailable never blocks the site. Choosing the WordPress AI connectors mode or your own API key removes the dependency on DoSieci entirely for chat (pairing is still used for licensing).

== External services ==

What this plugin sends depends entirely on the mode you choose in Settings, and only happens when you send a chat message or approve a Site Builder step — nothing runs on a schedule or in the background.

* **DoSieci License Hub** (`https://license.dosieci.pl` by default), used in DoSieci Hub mode (the default) and for pairing regardless of mode. Your message, the recent conversation, and the results of any diagnostic tools the model asked for (site facts such as PHP version, plugin list, WooCommerce summary — never file contents or secrets) are sent as a signed request so the Hub can call the model on its side and meter usage against your plan. DoSieci's terms and privacy policy: https://dosieci.pl/assets/regulamin-dosieci.pdf and https://dosieci.pl/polityka-prywatnosci/
* **AI provider configured in WordPress (Settings > Connectors)**, used in "WordPress AI connectors" mode. The request goes through the WordPress AI client to whichever provider you connected there (for example OpenAI, Anthropic or Google). The provider's own terms and privacy policy apply; see the provider plugin you installed for the links.
* **Anthropic API** (`https://api.anthropic.com/v1/messages`), used only when you chose "Anthropic: your own API key" and entered a key. The request is authenticated with that key. Anthropic terms of service: https://www.anthropic.com/legal/consumer-terms and privacy policy: https://www.anthropic.com/legal/privacy
* **OpenAI API** (`https://api.openai.com/v1/chat/completions`), used only when you chose "OpenAI: your own API key" and entered a key. The request is authenticated with that key. OpenAI terms of use: https://openai.com/policies/terms-of-use/ and privacy policy: https://openai.com/policies/privacy-policy/

== Screenshots ==

1. The chat screen: a diagnostic answer and, with write tools on, a pending change waiting for approval.

== Changelog ==

= 1.2.0-dev =
* Note: development version, released from an unmerged working branch (no official release from the DoSieci team yet). Verified with unit tests (336/336) and, per the architecture documentation, by hand on a real WordPress installation for the core and WooCommerce parts.
* New "WordPress AI connectors" mode: uses whichever provider is configured under Settings > Connectors on WordPress 7.0+, with no key of its own to manage.
* English source strings throughout, with a bundled Polish translation — previously the plugin's own text was Polish-only, which is not allowed for a WordPress.org listing.
* Site Builder — batch plan mode: the model proposes a complete, ordered plan of several actions at once, a human approves the whole plan with one click (with every step visible beforehand), and execution goes through the same gates as a single 1.1.0-style approval. A step failing stops the rest of the plan instead of continuing silently.
* Rollback for the reversible steps of a plan that already ran.
* Gutenberg-block-aware content generation (instead of raw HTML) for part of the write tools.
* WooCommerce store builder: a plain-language store description → a typed blueprint (categories, products, prices, store pages) → a plan → execution with an audit and reconciliation against resources a previous run already created, so running it again does not duplicate anything.
* Known, deliberate gaps in this version: media import (search/upload/featured image), an Elementor adapter, importing a theme's demo content, composing from ready-made block patterns/template parts — see the repository README.

= 1.1.0 =
* Your own API key (BYOK): direct OpenAI and Anthropic support alongside DoSieci Hub mode.
* Site-building tools: creating and editing pages/posts, installing and activating themes and plugins from WordPress.org, a navigation menu, the homepage, categories and tags, selected site settings.
* Approving changes: every write action stops the conversation and waits for a separate human confirmation, showing the exact arguments.
* A closed list of allowed site settings; options that could take over the site are not available to the operator.
* Removing content sends it to the trash instead of deleting it permanently.

= 1.0.0 =
* Initial release: pairing with the DoSieci License Hub, a chat in wp-admin, 12 read-only diagnostic tools, a local audit log, per-user conversation history.

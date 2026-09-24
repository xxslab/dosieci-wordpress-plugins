=== DoSieci WP Doctor ===
Contributors: vvalik
Tags: site health, diagnostics, performance, security, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A read-only technical audit of WordPress and WooCommerce that explains every problem and what to do about it. It never changes anything.

== Description ==

DoSieci WP Doctor runs a quick technical check-up of your site and tells you, in plain language, what it found and what to do next. Every finding comes with an explanation and a recommendation, not just a red or green light.

It checks:

* PHP version, against the official PHP support and end-of-life dates
* HTTPS
* debug settings (including errors displayed to visitors)
* search engine visibility
* the permalink structure
* the size of autoloaded options
* post revisions
* transients
* the PHP memory limit
* overdue scheduled tasks (WP-Cron)
* inactive plugins left installed

= Read-only by design =

There is no "fix" button. The plugin does not delete revisions, clear transients or run OPTIMIZE TABLE. Each recommendation is written so that you can decide for yourself, including whether to make a backup first.

= Private =

The scan runs entirely on your server. The plugin makes no external requests and stores no scan history.

= The score =

The score out of 100 is only meant for comparing one scan with the next. A high score does not mean the site is secure: it is a summary, not a guarantee.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the ZIP file.
2. Activate it.
3. Go to Tools > WP Doctor.

== Frequently Asked Questions ==

= Will it change or delete anything? =

No. Every check only reads data. There is no code in the plugin that writes to the database or to files.

= Who can see the report? =

Administrators (users with the manage_options capability).

= Does it send my data anywhere? =

No. Nothing leaves your server.

= Does it work without WooCommerce? =

Yes. WooCommerce is only mentioned in some recommendations, for example the memory limit.

== Screenshots ==

1. The scan report with the score, each check and its recommendation.

== Changelog ==

= 1.0.0 =
* Initial release.

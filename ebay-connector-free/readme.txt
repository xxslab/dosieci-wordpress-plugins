=== DoSieci eBay Connector ===
Contributors: dosieci
Tags: ebay, woocommerce, marketplace, listings, api
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Check your eBay developer keys and browse eBay listings in wp-admin. Read-only, and Sandbox by default.

== Description ==

DoSieci eBay Connector checks that your eBay application keys work and lets you search eBay listings (with price, condition and seller) without leaving wp-admin. It is useful for checking your eBay developer setup and for looking up how items are listed and priced on the marketplace you sell on.

= Your own keys =

It uses your own App ID (Client ID) and Cert ID (Client Secret) from the eBay Developers Program. Requests go from your site straight to eBay. They do not pass through DoSieci servers, and no subscription is needed.

= Read-only =

This plugin does not publish listings, import orders or change stock. Its API client does not contain a single method that writes to eBay, so it cannot change anything on your eBay account.

= Sandbox by default =

New installs use the eBay Sandbox. The live Production environment has to be switched on deliberately, and a warning is shown while it is active.

= Your marketplace =

Search the eBay site you sell on (for example ebay.com, ebay.co.uk, ebay.de or ebay.pl). The default follows your site language.

This plugin is not affiliated with, endorsed by or sponsored by eBay Inc. eBay is a trademark of eBay Inc.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the ZIP file.
2. Activate it.
3. Create an application keyset at https://developer.ebay.com/ (Sandbox or Production).
4. Go to Tools > eBay Connector, enter the App ID and Cert ID, choose the environment and marketplace, and save.
5. Search for any product to test the connection.

== Frequently Asked Questions ==

= Does it sync my WooCommerce products with eBay? =

No. This version is read-only: it tests the connection and browses listings. WooCommerce is not required.

= Why do I see no results in Sandbox? =

The eBay Sandbox contains only test data, so most searches return few or no results. Switch to Production (with a Production keyset) to search real listings.

= Where are my keys stored? =

In your WordPress database (not autoloaded). The secret is never shown again after saving. Deleting the plugin removes the keys and the cached access token.

= Who can use it? =

Administrators can enter keys. Administrators and, with WooCommerce active, shop managers can browse listings.

== External services ==

This plugin connects to the eBay APIs, only when a user searches on the Tools > eBay Connector screen:

* eBay OAuth token endpoint (`https://api.ebay.com/identity/v1/oauth2/token`, or `https://api.sandbox.ebay.com/identity/v1/oauth2/token` in Sandbox): receives your App ID and Cert ID to issue an application access token, which is then cached on your site until shortly before it expires.
* eBay Browse API (`https://api.ebay.com/buy/browse/v1/item_summary/search`, or the Sandbox equivalent): receives the search phrase you typed and the marketplace you chose, and returns matching listings.

No data about your site's visitors or customers is sent. Nothing is sent to DoSieci.

* eBay API License Agreement: https://developer.ebay.com/join/api-license-agreement
* eBay User Privacy Notice: https://www.ebay.com/help/policies/member-behaviour-policies/user-privacy-notice-privacy-policy?id=4260

== Screenshots ==

1. Application keys, environment and marketplace settings.

== Changelog ==

= 1.0.0 =
* Initial release.

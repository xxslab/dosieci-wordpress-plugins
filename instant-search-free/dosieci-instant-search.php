<?php
/**
 * Plugin Name: DoSieci Instant Search
 * Plugin URI: https://dosieci.pl/wtyczki/instant-search/
 * Description: Fast search suggestions for WooCommerce products, posts or pages, shown under the standard search field as you type. Runs on your own database: no API key, and no query ever leaves your site.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: DoSieci
 * Author URI: https://dosieci.pl/
 * Text Domain: dosieci-instant-search
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 *
 * @package DoSieci\Instant\Search
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOSIECI_INSTANT_SEARCH_VERSION', '1.0.0' );
define( 'DOSIECI_INSTANT_SEARCH_FILE', __FILE__ );
define( 'DOSIECI_INSTANT_SEARCH_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'DoSieci\\Instant\\Search\\';

		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$path = DOSIECI_INSTANT_SEARCH_PATH . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		add_option( 'dosieci_instant_search_post_type', 'product', '', false );
		add_option( 'dosieci_instant_search_min_chars', 2, '', false );
	}
);

// The plugin never touches orders, so it is compatible with WooCommerce's
// custom order tables (HPOS).
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'init',
	static function (): void {
		// Bundled Polish translation; language packs from WordPress.org still take precedence.
		load_plugin_textdomain( 'dosieci-instant-search', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\Instant\Search\Plugin::instance()->boot();
	}
);

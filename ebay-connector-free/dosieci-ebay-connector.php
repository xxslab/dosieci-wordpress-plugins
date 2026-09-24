<?php
/**
 * Plugin Name: DoSieci eBay Connector
 * Plugin URI: https://dosieci.pl/wtyczki/ebay-connector-woocommerce/
 * Description: Connect your site to eBay with your own eBay developer keys: test the connection and browse eBay listings from wp-admin. Read-only, Sandbox by default. Not affiliated with eBay Inc.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: DoSieci
 * Author URI: https://dosieci.pl/
 * Text Domain: dosieci-ebay-connector
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 *
 * @package DoSieci\Ebay\Connector
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOSIECI_EBAY_CONNECTOR_VERSION', '1.0.0' );
define( 'DOSIECI_EBAY_CONNECTOR_FILE', __FILE__ );
define( 'DOSIECI_EBAY_CONNECTOR_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'DoSieci\\Ebay\\Connector\\';

		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$path = DOSIECI_EBAY_CONNECTOR_PATH . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		// Sandbox by default, always. Switching to Production is a deliberate,
		// separate action by an administrator.
		add_option( 'dosieci_ebay_environment', 'sandbox', '', false );
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
		load_plugin_textdomain( 'dosieci-ebay-connector', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\Ebay\Connector\Plugin::instance()->boot();
	}
);

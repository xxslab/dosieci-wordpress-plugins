<?php
/**
 * Plugin Name: DoSieci Translator
 * Plugin URI: https://dosieci.pl/wtyczki/translator-woocommerce/
 * Description: Translate the title, short description or description of WooCommerce products, posts and pages with DeepL, using your own API key. Always previewed before saving, and the previous text can be restored with one click.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: DoSieci
 * Author URI: https://dosieci.pl/
 * Text Domain: dosieci-translator
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 *
 * @package DoSieci\Translator
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOSIECI_TRANSLATOR_VERSION', '1.0.0' );
define( 'DOSIECI_TRANSLATOR_FILE', __FILE__ );
define( 'DOSIECI_TRANSLATOR_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'DoSieci\\Translator\\';

		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$path = DOSIECI_TRANSLATOR_PATH . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
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
		load_plugin_textdomain( 'dosieci-translator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\Translator\Plugin::instance()->boot();
	}
);

<?php
/**
 * Plugin Name: DoSieci Clean URLs
 * Plugin URI: https://dosieci.pl/wtyczki/clean-urls/
 * Description: Safe URL clean-up for posts, pages and WooCommerce products: a preview of every change, a collision scanner, and 301 redirects without chains or loops. Nothing changes until you approve it.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: DoSieci
 * Author URI: https://dosieci.pl/
 * Text Domain: dosieci-clean-urls
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package DoSieci\Clean\Urls
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOSIECI_CLEAN_URLS_VERSION', '1.0.0' );
define( 'DOSIECI_CLEAN_URLS_FILE', __FILE__ );
define( 'DOSIECI_CLEAN_URLS_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'DoSieci\\Clean\\Urls\\';

		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$path = DOSIECI_CLEAN_URLS_PATH . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		// Not autoloaded: the map is only needed when a request 404s.
		add_option( 'dosieci_clean_urls_redirects', array(), '', false );
	}
);

/*
 * No deactivation cleanup on purpose: the stored redirect map is the only
 * record of where old URLs went, and deleting it would break every one of them.
 */

add_action(
	'init',
	static function (): void {
		// Bundled Polish translation; language packs from WordPress.org still take precedence.
		load_plugin_textdomain( 'dosieci-clean-urls', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\Clean\Urls\Plugin::instance()->boot();
	}
);

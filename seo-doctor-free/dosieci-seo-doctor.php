<?php
/**
 * Plugin Name: DoSieci SEO Doctor
 * Plugin URI: https://dosieci.pl/wtyczki/seo-doctor/
 * Description: A read-only SEO audit of posts, pages and WooCommerce products, with optional AI suggestions for titles and descriptions. Works alongside Yoast SEO, Rank Math, All in One SEO and SEOPress instead of replacing them.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: DoSieci
 * Author URI: https://dosieci.pl/
 * Text Domain: dosieci-seo-doctor
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package DoSieci\SEO\Doctor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOSIECI_SEO_DOCTOR_VERSION', '1.0.0' );
define( 'DOSIECI_SEO_DOCTOR_FILE', __FILE__ );
define( 'DOSIECI_SEO_DOCTOR_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'DoSieci\\SEO\\Doctor\\';

		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$path = DOSIECI_SEO_DOCTOR_PATH . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/*
 * Deactivation deliberately keeps the stored API key: deleting a user's own
 * provider credential because the plugin was toggled off would be surprising.
 * Uninstalling removes it.
 */

add_action(
	'init',
	static function (): void {
		// Bundled Polish translation; language packs from WordPress.org still take precedence.
		load_plugin_textdomain( 'dosieci-seo-doctor', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\SEO\Doctor\Plugin::instance()->boot();
	}
);

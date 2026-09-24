<?php
/**
 * Plugin Name: DoSieci WP Doctor
 * Plugin URI: https://dosieci.pl/wtyczki/wp-doctor/
 * Description: An explainable, read-only technical audit of WordPress and WooCommerce. It shows what is wrong and what to do about it, but never changes anything automatically.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: DoSieci
 * Author URI: https://dosieci.pl/
 * Text Domain: dosieci-wp-doctor
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package DoSieci\WP\Doctor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOSIECI_WP_DOCTOR_VERSION', '1.0.0' );
define( 'DOSIECI_WP_DOCTOR_FILE', __FILE__ );
define( 'DOSIECI_WP_DOCTOR_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'DoSieci\\WP\\Doctor\\';

		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$path = DOSIECI_WP_DOCTOR_PATH . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'init',
	static function (): void {
		// Bundled Polish translation; language packs from WordPress.org still take precedence.
		load_plugin_textdomain( 'dosieci-wp-doctor', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\WP\Doctor\Plugin::instance()->boot();
	}
);

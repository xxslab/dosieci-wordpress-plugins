<?php
/**
 * Plugin Name: DoSieci SEO Doctor
 * Plugin URI: https://dosieci.pl/wtyczki/seo-doctor/
 * Description: Audyt SEO wpisów i produktów WooCommerce plus opcjonalne podpowiedzi AI na Twoim własnym kluczu API (BYOK). Tylko do odczytu — współpracuje z Yoast, Rank Math, AIOSEO i SEOPress, nie zastępuje ich.
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
define( 'DOSIECI_SEO_DOCTOR_URL', plugin_dir_url( __FILE__ ) );

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

register_activation_hook( __FILE__, static function (): void {} );

/**
 * Deactivation never touches the stored API key. Deleting a user's own
 * provider credential because they toggled the plugin off would be both
 * surprising and annoying to recover from; removal is an explicit action
 * (save an empty key) or an uninstall.
 */
register_deactivation_hook( __FILE__, static function (): void {} );

add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\SEO\Doctor\Plugin::instance()->boot();
	}
);

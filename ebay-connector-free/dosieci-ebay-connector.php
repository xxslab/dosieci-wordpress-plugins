<?php
/**
 * Plugin Name: DoSieci eBay Connector for WooCommerce
 * Plugin URI: https://dosieci.pl/wtyczki/ebay-connector/
 * Description: Połączenie z eBay na Twoich własnych danych aplikacji (BYOK). Wersja darmowa jest wyłącznie do odczytu: test połączenia i przeglądanie ofert. Domyślnie Sandbox.
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
 * WC tested up to: 9.6
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
define( 'DOSIECI_EBAY_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );

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
		// Sandbox by default, always. Switching to Production is a
		// deliberate, separate action by an administrator.
		add_option( 'dosieci_ebay_environment', 'sandbox', '', false );
	}
);

register_deactivation_hook( __FILE__, static function (): void {} );

add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\Ebay\Connector\Plugin::instance()->boot();
	}
);

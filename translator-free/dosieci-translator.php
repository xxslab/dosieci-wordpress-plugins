<?php
/**
 * Plugin Name: DoSieci Translator for WooCommerce
 * Plugin URI: https://dosieci.pl/wtyczki/translator-woocommerce/
 * Description: Tłumaczenie tytułów i opisów produktów przez DeepL na Twoim własnym kluczu API (BYOK). Zawsze z podglądem przed zapisem — nic nie jest nadpisywane automatycznie.
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
 * WC tested up to: 9.6
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
define( 'DOSIECI_TRANSLATOR_URL', plugin_dir_url( __FILE__ ) );

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

register_activation_hook( __FILE__, static function (): void {} );
register_deactivation_hook( __FILE__, static function (): void {} );

add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\Translator\Plugin::instance()->boot();
	}
);

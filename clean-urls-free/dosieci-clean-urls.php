<?php
/**
 * Plugin Name: DoSieci Clean URLs
 * Plugin URI: https://dosieci.pl/wtyczki/clean-urls/
 * Description: Bezpieczna migracja adresów URL: podgląd zmian, skaner kolizji i automatyczne przekierowania 301 bez łańcuchów i pętli. Nic nie zmienia się bez Twojej akceptacji.
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
define( 'DOSIECI_CLEAN_URLS_URL', plugin_dir_url( __FILE__ ) );

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
		add_option( 'dosieci_clean_urls_post_type', 'post', '', false );
		add_option( 'dosieci_clean_urls_redirects', array(), '', false );
	}
);

/**
 * Deactivation is non-destructive and this matters more here than in most
 * plugins: the stored redirect map is the ONLY record of where the old URLs
 * went. Deleting it on deactivate would 404 every migrated URL.
 */
register_deactivation_hook( __FILE__, static function (): void {} );

add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\Clean\Urls\Plugin::instance()->boot();
	}
);

<?php
/**
 * Plugin Name: DoSieci Instant Search
 * Plugin URI: https://dosieci.pl/wtyczki/instant-search/
 * Description: Szybkie podpowiedzi wyszukiwania dla WooCommerce i WordPressa. Działa lokalnie — żadne zapytanie nie opuszcza Twojej witryny, nie wymaga klucza API.
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
 * WC tested up to: 9.6
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
define( 'DOSIECI_INSTANT_SEARCH_URL', plugin_dir_url( __FILE__ ) );

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

// Deactivation is non-destructive on purpose: settings survive so
// re-activating restores the previous configuration.
register_deactivation_hook( __FILE__, static function (): void {} );

add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\Instant\Search\Plugin::instance()->boot();
	}
);

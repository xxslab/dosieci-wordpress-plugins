<?php
/**
 * Plugin Name: DoSieci eBay Connector Pro
 * Plugin URI: https://dosieci.pl/wtyczki/ebay-connector/
 * Description: Szkielet Fazy 0. Logika domenowa nie jest jeszcze zaimplementowana — patrz IMPLEMENTATION_STATUS.md i PRODUCT_SCOPE.md w korzeniu monorepo.
 * Version: 0.0.1-dev
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: DoSieci
 * Text Domain: dosieci-ebay-connector-pro
 * Domain Path: /languages
 *
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DOSIECI_EBAY_CONNECTOR_PRO_VERSION', '0.0.1-dev' );
define( 'DOSIECI_EBAY_CONNECTOR_PRO_FILE', __FILE__ );
define( 'DOSIECI_EBAY_CONNECTOR_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'DOSIECI_EBAY_CONNECTOR_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'DOSIECI_EBAY_CONNECTOR_PRO_CAPABILITY', 'manage_dosieci_ebay_connector' );

// Faza 0: brak autoloadera Composer i klas domenowych — celowy szkielet, patrz ARCHITECTURE.md.
// Faza 1+: require_once DOSIECI_EBAY_CONNECTOR_PRO_PATH . 'vendor/autoload.php'; oraz bootstrap klas Domain/Adapter/UI.

register_activation_hook( __FILE__, static function () {
    // Faza 1+: rejestracja tabel, capability, wersjonowana migracja idempotentna.
} );

register_deactivation_hook( __FILE__, static function () {
    // Celowo puste: brak automatycznego usuwania danych przy deaktywacji (sekcja 7 master promptu).
} );

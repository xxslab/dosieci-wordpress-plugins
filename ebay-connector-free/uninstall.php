<?php
/**
 * Uninstall handler for DoSieci eBay Connector.
 *
 * Removes the stored eBay application credentials and any cached OAuth
 * token: leaving live third-party credentials in the database of a site
 * that no longer has the plugin installed is a liability with no upside.
 *
 * @package DoSieci\Ebay\Connector
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dosieci_ebay_client_id' );
delete_option( 'dosieci_ebay_client_secret' );
delete_option( 'dosieci_ebay_environment' );
delete_option( 'dosieci_ebay_marketplace' );

delete_transient( 'dosieci_ebay_app_token_sandbox' );
delete_transient( 'dosieci_ebay_app_token_production' );

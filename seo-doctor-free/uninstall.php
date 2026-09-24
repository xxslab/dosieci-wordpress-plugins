<?php
/**
 * Uninstall handler for DoSieci SEO Doctor.
 *
 * Unlike this plugin's data-preserving deactivation, uninstall DOES remove
 * the stored provider API key unconditionally. That asymmetry is
 * deliberate: leaving a live third-party credential in the database of a
 * site that no longer has the plugin installed is a liability with no
 * upside — nothing here can ever use it again.
 *
 * @package DoSieci\SEO\Doctor
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dosieci_seo_doctor_api_key' );
delete_option( 'dosieci_seo_doctor_model' );

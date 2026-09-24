<?php
/**
 * Uninstall handler for DoSieci Clean URLs.
 *
 * DELIBERATELY KEEPS THE REDIRECT MAP.
 *
 * The stored redirect map is the only record of where migrated URLs went.
 * Deleting it would 404 every old address that search engines and external
 * links still point at — permanently, and with no way to reconstruct it.
 * Renamed slugs also stay renamed: they are ordinary post data now.
 *
 * An administrator who genuinely wants the map gone can delete the
 * `dosieci_clean_urls_redirects` option directly; that is a deliberate,
 * informed act, which is exactly the bar this kind of destruction should
 * have to clear.
 *
 * @package DoSieci\Clean\Urls
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dosieci_clean_urls_post_type' );

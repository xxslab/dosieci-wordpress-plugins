<?php
/**
 * Uninstall handler for DoSieci Instant Search.
 *
 * Removes only this plugin's two settings. No content, no posts and no
 * search data are touched — the plugin never created any, it only reads.
 *
 * @package DoSieci\Instant\Search
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dosieci_instant_search_post_type' );
delete_option( 'dosieci_instant_search_min_chars' );

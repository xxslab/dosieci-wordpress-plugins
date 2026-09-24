<?php
/**
 * Uninstall handler for DoSieci Translator.
 *
 * Removes the stored DeepL key (a live third-party credential has no business
 * staying in the database of a site that no longer has the plugin) and the
 * pre-translation backups. Translations already saved are NOT reverted: they
 * are ordinary post content at that point.
 *
 * @package DoSieci\Translator
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dosieci_translator_deepl_key' );
delete_post_meta_by_key( '_dosieci_translator_backup' );

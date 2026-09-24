<?php
/**
 * Uninstall handler for DoSieci Translator.
 *
 * Removes the stored DeepL key: leaving a live third-party credential in
 * the database of a site that no longer has the plugin is a liability with
 * no upside. Translations already written to posts are NOT reverted --
 * they are ordinary post content at that point, and the pre-translation
 * text remains in the WordPress revision history.
 *
 * @package DoSieci\Translator
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dosieci_translator_deepl_key' );

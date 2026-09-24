<?php
/**
 * Uninstall handler for DoSieci AI Operator.
 *
 * DOCUMENTED BEHAVIOUR (deliberate, see the Settings screen):
 *
 * By default this removes NOTHING. Deleting a site's pairing, chat history
 * and audit trail because someone uninstalled a plugin — possibly to
 * reinstall it a minute later — is destructive and unrecoverable, and the
 * audit trail in particular is the evidence record of what the AI did on
 * this site.
 *
 * Data is removed only when the administrator has explicitly opted in
 * beforehand via Ustawienia → "Usuń wszystkie dane wtyczki przy jej
 * odinstalowaniu". That opt-in is stored as its own option and checked here.
 *
 * Note: even with the opt-in, this cannot revoke the site's key on the Hub
 * side (an uninstall handler must not depend on an outbound network call
 * that may fail or hang). Revoking the key in the DoSieci panel is a
 * separate, documented step.
 *
 * @package DoSieci\AiOperator
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== get_option( 'dosieci_ai_operator_delete_data_on_uninstall', '0' ) ) {
	return;
}

global $wpdb;

delete_option( 'dosieci_ai_operator_connection' );
delete_option( 'dosieci_ai_operator_version' );
delete_option( 'dosieci_ai_operator_delete_data_on_uninstall' );
delete_option( 'dosieci_ai_operator_enable_writes' );

// The BYOK provider key. Removing it on an opted-in uninstall is not
// optional housekeeping: leaving a live third-party API key behind in the
// options table of a site that no longer has the plugin to manage it is a
// credential left unattended.
delete_option( 'dosieci_ai_operator_provider' );

// Chat history and any half-finished confirmation are per-user meta.
delete_metadata( 'user', 0, 'dosieci_ai_operator_history', '', true );
delete_metadata( 'user', 0, 'dosieci_ai_operator_pending', '', true );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name derives from $wpdb->prefix, not user input.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dosieci_ai_audit' );

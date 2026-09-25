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
 * beforehand via Settings → "Delete all of this plugin's data when it is
 * uninstalled". That opt-in is stored as its own option and checked here.
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
delete_option( \DoSieci\AiOperator\Adapter\WordPress\SchemaMigrator::OPTION_SCHEMA_VERSION );
delete_option( \DoSieci\AiOperator\Plugin::OPTION_PROJECT_ID );

// The BYOK provider key. Removing it on an opted-in uninstall is not
// optional housekeeping: leaving a live third-party API key behind in the
// options table of a site that no longer has the plugin to manage it is a
// credential left unattended.
delete_option( 'dosieci_ai_operator_provider' );

// Chat history and any half-finished confirmation are per-user meta.
delete_metadata( 'user', 0, 'dosieci_ai_operator_history', '', true );
delete_metadata( 'user', 0, 'dosieci_ai_operator_pending', '', true );

// Wrapped in a closure so its loop variable is not a bare global -- this
// whole file executes at the top level, and WordPress.org requires even a
// throwaway loop variable here to be plugin-prefixed or, as here, scoped
// out of the global namespace entirely.
( static function ( \wpdb $wpdb ): void {
	// %i (an identifier placeholder, WordPress 6.2+) is what lets a table
	// name built from $wpdb->prefix go through prepare() at all -- %s would
	// quote it as a string value, which is not valid SQL in this position.
	foreach (
		array(
			$wpdb->prefix . 'dosieci_ai_audit',
			$wpdb->prefix . \DoSieci\AiOperator\Adapter\WordPress\WpdbPlanRepository::ACTIONS_TABLE_SUFFIX,
			$wpdb->prefix . \DoSieci\AiOperator\Adapter\WordPress\WpdbPlanRepository::PLANS_TABLE_SUFFIX,
		) as $table
	) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dropping this plugin's own tables, once, on an opted-in uninstall; there is nothing to cache.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
} )( $wpdb );

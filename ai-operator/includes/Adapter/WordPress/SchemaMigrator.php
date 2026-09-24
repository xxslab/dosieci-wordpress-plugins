<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress;

/**
 * Owns the plugin's database schema version, independent of the plugin's
 * display version string (DOSIECI_AI_OPERATOR_VERSION).
 *
 * WordPress only fires register_activation_hook() on an explicit
 * deactivate/activate cycle -- never when a site's files are simply
 * replaced in place by an upgrade (the normal path for a managed update).
 * A schema created solely in that hook is therefore missing after every
 * upgrade that does not also reactivate the plugin, which is the common
 * case. migrate() and migrateIfNeeded() give activation and every other
 * bootstrap (plugins_loaded -- WP-CLI, cron, AJAX and browser requests
 * alike) the same idempotent path to a current schema, so Site Builder can
 * never observe a request where its tables are missing.
 */
final class SchemaMigrator {

	public const OPTION_SCHEMA_VERSION = 'dosieci_ai_operator_schema_version';

	/**
	 * Unconditionally (re)creates every table at its current definition and
	 * records the schema version. dbDelta() diffs against what already
	 * exists, so this is safe to call on a fresh database, an unchanged
	 * current schema, or an old 1.1 schema alike -- and never drops or
	 * truncates anything.
	 */
	public static function migrate(): void {
		WpdbAuditLog::createTable();
		WpdbPlanRepository::createTables();

		update_option( self::OPTION_SCHEMA_VERSION, DOSIECI_AI_OPERATOR_SCHEMA_VERSION, false );
		update_option( 'dosieci_ai_operator_version', DOSIECI_AI_OPERATOR_VERSION, false );
	}

	/**
	 * The cheap path for every normal bootstrap: one option read, and a
	 * migrate() only when the stored schema version is behind. A 1.1
	 * installation upgraded in place has no schema-version option at all
	 * (get_option default 0), so its very next bootstrap -- regardless of
	 * whether that request is a browser page load, an AJAX call, WP-CLI or
	 * cron -- brings the schema current before anything else runs.
	 */
	public static function migrateIfNeeded(): void {
		$installed = (int) get_option( self::OPTION_SCHEMA_VERSION, 0 );

		if ( $installed >= DOSIECI_AI_OPERATOR_SCHEMA_VERSION ) {
			return;
		}

		self::migrate();
	}
}

<?php
/**
 * Plugin Name: DoSieci AI Operator
 * Plugin URI: https://dosieci.pl/wtyczki/ai-operator/
 * Description: An AI assistant for WordPress and WooCommerce. You talk in wp-admin, and the operator diagnoses your site and — once you approve it — builds it: creates content, installs themes and plugins, configures settings. Works through the DoSieci License Hub, WordPress's own AI connectors, or directly with your own OpenAI/Anthropic key.
 * Version: 1.2.0-dev
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: DoSieci
 * Author URI: https://dosieci.pl/
 * Text Domain: dosieci-ai-operator
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package DoSieci\AiOperator
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOSIECI_AI_OPERATOR_VERSION', '1.2.0-dev' );
define( 'DOSIECI_AI_OPERATOR_FILE', __FILE__ );
define( 'DOSIECI_AI_OPERATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'DOSIECI_AI_OPERATOR_URL', plugin_dir_url( __FILE__ ) );

/**
 * The database schema version, bumped only when a table definition
 * changes. Deliberately independent of DOSIECI_AI_OPERATOR_VERSION (which
 * tracks display/release versioning and can change without any schema
 * change) so SchemaMigrator has a single, explicit number to compare
 * against instead of parsing or string-comparing release versions.
 */
define( 'DOSIECI_AI_OPERATOR_SCHEMA_VERSION', 2 );

/**
 * Namespaced autoloader. This plugin ships no Composer runtime
 * dependencies on purpose: bundling a vendor/ directory into a WordPress
 * plugin is the classic source of "two plugins ship different versions of
 * the same library and the first one loaded wins" conflicts (ADR-0001).
 * Everything under includes/ is first-party code in one namespace.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'DoSieci\\AiOperator\\';

		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = DOSIECI_AI_OPERATOR_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Activation: create the schema and record the installed version.
 * Deliberately does NOT contact the Hub -- activation must never depend on
 * an outbound network call succeeding, or a site with a firewalled egress
 * cannot even activate the plugin.
 *
 * Table creation itself lives in SchemaMigrator::migrate(), which is also
 * called unconditionally from plugins_loaded below: activation is not the
 * only way a site ends up running this plugin's code, and a WordPress
 * upgrade that only replaces files (no deactivate/reactivate) never fires
 * this hook at all.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		\DoSieci\AiOperator\Adapter\WordPress\SchemaMigrator::migrate();
	}
);

/**
 * Deactivation: intentionally empty.
 *
 * Deactivation is routinely used for debugging ("turn it off, see if the
 * error goes away") and must be non-destructive: the pairing, the chat
 * history and the audit log all survive, so re-activating restores a
 * working plugin without re-pairing. Data removal happens only in
 * uninstall.php, and only if the administrator explicitly opted in.
 */
register_deactivation_hook( __FILE__, static function (): void {} );

/**
 * plugins_loaded fires on every bootstrap of every request type -- browser,
 * AJAX, WP-CLI and cron alike -- unlike the activation hook, which fires
 * only on an explicit activate. Running the cheap migrateIfNeeded() check
 * here, before boot() wires up anything Site Builder can be reached
 * through, guarantees the schema is current before any request can reach
 * WpdbPlanRepository -- including the very first request after a file-only
 * upgrade from a version that never ran this schema.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		\DoSieci\AiOperator\Adapter\WordPress\SchemaMigrator::migrateIfNeeded();
		\DoSieci\AiOperator\Plugin::instance()->boot();
	}
);

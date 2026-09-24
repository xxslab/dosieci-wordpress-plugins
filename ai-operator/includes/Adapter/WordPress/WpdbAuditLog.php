<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress;

use DoSieci\AiOperator\Domain\Audit\AuditEntry;
use DoSieci\AiOperator\Domain\Audit\AuditLogInterface;
use DoSieci\AiOperator\Domain\Audit\SecretScrubber;

/**
 * Local audit log in its own table. Deliberately not the options table
 * (unbounded growth in an autoloaded blob) and not a custom post type
 * (audit records are not content and must not appear in any content
 * listing, export or search).
 *
 * All queries use $wpdb->prepare(); the table name is interpolated from
 * $wpdb->prefix, which is not user input.
 */
final class WpdbAuditLog implements AuditLogInterface {

	public const TABLE_SUFFIX = 'dosieci_ai_audit';

	public function __construct( private SecretScrubber $scrubber ) {
	}

	public static function tableName(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Idempotent: safe to call on every activation, including upgrades.
	 */
	public static function createTable(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::tableName();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				tool_name VARCHAR(100) NOT NULL,
				outcome VARCHAR(20) NOT NULL,
				reason VARCHAR(255) NULL,
				context LONGTEXT NULL,
				request_id VARCHAR(100) NOT NULL DEFAULT '',
				occurred_at BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY (id),
				KEY tool_name (tool_name),
				KEY outcome (outcome),
				KEY occurred_at (occurred_at)
			) {$collate};"
		);
	}

	public function record( AuditEntry $entry ): void {
		global $wpdb;

		$wpdb->insert(
			self::tableName(),
			array(
				'user_id'     => $entry->userId,
				'tool_name'   => $entry->toolName,
				'outcome'     => $entry->outcome,
				'reason'      => $entry->reason,
				// Scrubbed even though call sites are not supposed to put a
				// credential here -- see SecretScrubber's docblock for the
				// specific legacy failure this defends against.
				'context'     => (string) wp_json_encode( $this->scrubber->scrub( $entry->context ) ),
				'request_id'  => $entry->requestId,
				'occurred_at' => $entry->occurredAt,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	public function recent( int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );
		$table = self::tableName();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table comes from $wpdb->prefix, not user input; $limit is prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): AuditEntry {
				$context = json_decode( (string) $row['context'], true );

				return new AuditEntry(
					(int) $row['user_id'],
					(string) $row['tool_name'],
					(string) $row['outcome'],
					null !== $row['reason'] ? (string) $row['reason'] : null,
					is_array( $context ) ? $context : array(),
					(string) $row['request_id'],
					(int) $row['occurred_at']
				);
			},
			$rows
		);
	}
}

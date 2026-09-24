<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress;

use DoSieci\AiOperator\Domain\SiteBuilder\ActionPlan;
use DoSieci\AiOperator\Domain\SiteBuilder\ActionState;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanRecord;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanRepositoryInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteBlueprint;

/**
 * Plan storage in two dedicated tables.
 *
 * Deliberately NOT user meta, which is where a first draft would put it:
 * a plan is up to sixty actions with per-step results and rollback
 * snapshots, it is queried by status and owner, and it must survive long
 * enough to be audited. Serialising all of that into one meta blob would
 * mean rewriting the whole structure on every single step -- forty times
 * per build -- and would make "show me this user's failed plans" a full
 * table scan of unrelated meta. 1.1 already established the pattern for
 * this with its own audit table (WpdbAuditLog) and the same reasoning
 * applies.
 *
 * Actions live in their own table rather than a JSON column on the plan so
 * a single step's runtime state can be updated without rewriting the
 * approved definition of the other fifty-nine -- which also means a bug in
 * step-state persistence can never corrupt the approved plan the hash is
 * computed over.
 *
 * Every query uses $wpdb->prepare(); table names interpolate $wpdb->prefix,
 * which is configuration, not user input.
 */
final class WpdbPlanRepository implements PlanRepositoryInterface {

	public const PLANS_TABLE_SUFFIX   = 'dosieci_ai_plans';
	public const ACTIONS_TABLE_SUFFIX = 'dosieci_ai_plan_actions';

	public static function plansTable(): string {
		global $wpdb;

		return $wpdb->prefix . self::PLANS_TABLE_SUFFIX;
	}

	public static function actionsTable(): string {
		global $wpdb;

		return $wpdb->prefix . self::ACTIONS_TABLE_SUFFIX;
	}

	/** Idempotent; safe on every activation including upgrades. */
	public static function createTables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$plans   = self::plansTable();
		$actions = self::actionsTable();

		dbDelta(
			"CREATE TABLE {$plans} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				plan_id VARCHAR(64) NOT NULL,
				plan_version INT UNSIGNED NOT NULL DEFAULT 1,
				plan_hash CHAR(64) NOT NULL,
				conversation_id VARCHAR(64) NOT NULL DEFAULT '',
				owner_user_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(32) NOT NULL,
				blueprint LONGTEXT NULL,
				failure_reason TEXT NULL,
				approved_at BIGINT UNSIGNED NULL,
				approved_by BIGINT UNSIGNED NULL,
				approved_hash CHAR(64) NULL,
				cancelled_at BIGINT UNSIGNED NULL,
				audit_report LONGTEXT NULL,
				project_id VARCHAR(64) NOT NULL DEFAULT 'default',
				conflicts LONGTEXT NULL,
				created_at BIGINT UNSIGNED NOT NULL,
				expires_at BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY plan_id (plan_id),
				KEY owner_user_id (owner_user_id),
				KEY status (status),
				KEY created_at (created_at)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$actions} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				plan_id VARCHAR(64) NOT NULL,
				action_id VARCHAR(64) NOT NULL,
				sequence INT UNSIGNED NOT NULL DEFAULT 0,
				definition LONGTEXT NOT NULL,
				status VARCHAR(32) NOT NULL,
				started_at BIGINT UNSIGNED NULL,
				finished_at BIGINT UNSIGNED NULL,
				result LONGTEXT NULL,
				error TEXT NULL,
				rollback_data LONGTEXT NULL,
				verification_status VARCHAR(20) NULL,
				verification_detail TEXT NULL,
				verification_expected LONGTEXT NULL,
				verification_actual LONGTEXT NULL,
				verified_at BIGINT UNSIGNED NULL,
				resolved_arguments LONGTEXT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY plan_action (plan_id, action_id),
				KEY plan_sequence (plan_id, sequence)
			) {$collate};"
		);
	}

	public function save( PlanRecord $record ): void {
		global $wpdb;

		$plan = $record->plan;

		$row = array(
			'plan_id'         => $plan->planId,
			'plan_version'    => $plan->planVersion,
			'plan_hash'       => $plan->planHash,
			'conversation_id' => $plan->conversationId,
			'owner_user_id'   => $plan->ownerUserId,
			'status'          => $record->status,
			'blueprint'       => (string) wp_json_encode( $plan->blueprint->toArray() ),
			'failure_reason'  => $record->failureReason,
			'approved_at'     => $record->approvedAt,
			'approved_by'     => $record->approvedBy,
			'approved_hash'   => $record->approvedHash,
			'cancelled_at'    => $record->cancelledAt,
			'audit_report'    => null === $record->auditReport ? null : (string) wp_json_encode( $record->auditReport ),
			'project_id'      => $plan->projectId,
			// Hash-covered, so it MUST round-trip: a plan restored without
			// its conflicts recomputes a different hash and every approval
			// is rejected as stale.
			'conflicts'       => (string) wp_json_encode( $plan->conflicts ),
			'created_at'      => $plan->createdAt,
			'expires_at'      => $plan->expiresAt,
		);

		$existing = $this->planRow( $plan->planId );

		if ( null === $existing ) {
			$wpdb->insert( self::plansTable(), $row );
		} else {
			$wpdb->update( self::plansTable(), $row, array( 'plan_id' => $plan->planId ) );
		}

		foreach ( $plan->actions as $action ) {
			$state = $record->state( $action->actionId ) ?? new ActionState( $action->actionId );

			$actionRow = array(
				'plan_id'             => $plan->planId,
				'action_id'           => $action->actionId,
				'sequence'            => $action->sequence,
				'definition'          => (string) wp_json_encode( $action->toArray() ),
				'status'              => $state->status,
				'started_at'          => $state->startedAt,
				'finished_at'         => $state->finishedAt,
				'result'              => null === $state->result ? null : (string) wp_json_encode( $state->result ),
				'error'               => $state->error,
				'rollback_data'       => null === $state->rollbackData ? null : (string) wp_json_encode( $state->rollbackData ),
				'verification_status' => $state->verificationStatus,
				'verification_detail' => $state->verificationDetail,
				'verification_expected' => null === $state->verificationExpected ? null : (string) wp_json_encode( $state->verificationExpected ),
				'verification_actual'   => null === $state->verificationActual ? null : (string) wp_json_encode( $state->verificationActual ),
				'verified_at'           => $state->verifiedAt,
				'resolved_arguments'    => null === $state->resolvedArguments ? null : (string) wp_json_encode( $state->resolvedArguments ),
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; values prepared.
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::actionsTable() . ' WHERE plan_id = %s AND action_id = %s',
					$plan->planId,
					$action->actionId
				)
			);

			if ( null === $exists ) {
				$wpdb->insert( self::actionsTable(), $actionRow );
			} else {
				$wpdb->update(
					self::actionsTable(),
					$actionRow,
					array(
						'plan_id'   => $plan->planId,
						'action_id' => $action->actionId,
					)
				);
			}
		}
	}

	public function find( string $planId ): ?PlanRecord {
		$row = $this->planRow( $planId );

		return null === $row ? null : $this->hydrate( $row );
	}

	public function findForUser( string $planId, int $userId ): ?PlanRecord {
		$record = $this->find( $planId );

		// Both "no such plan" and "not yours" return null, deliberately
		// indistinguishable -- see PlanRepositoryInterface's docblock.
		if ( null === $record || $record->plan->ownerUserId !== $userId ) {
			return null;
		}

		return $record;
	}

	public function listForUser( int $userId, int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; values prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::plansTable() . ' WHERE owner_user_id = %d ORDER BY created_at DESC LIMIT %d',
				$userId,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$record = $this->hydrate( $row );

			if ( null !== $record ) {
				$out[] = $record;
			}
		}

		return $out;
	}

	public function delete( string $planId ): void {
		global $wpdb;

		$wpdb->delete( self::actionsTable(), array( 'plan_id' => $planId ) );
		$wpdb->delete( self::plansTable(), array( 'plan_id' => $planId ) );
	}

	/** @return array<string, mixed>|null */
	private function planRow( string $planId ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; value prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::plansTable() . ' WHERE plan_id = %s', $planId ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/** @param array<string, mixed> $row */
	private function hydrate( array $row ): ?PlanRecord {
		global $wpdb;

		$planId = (string) $row['plan_id'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; value prepared.
		$actionRows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::actionsTable() . ' WHERE plan_id = %s ORDER BY sequence ASC, id ASC',
				$planId
			),
			ARRAY_A
		);

		if ( ! is_array( $actionRows ) || array() === $actionRows ) {
			return null;
		}

		$blueprintRaw = json_decode( (string) $row['blueprint'], true );

		if ( ! is_array( $blueprintRaw ) ) {
			return null;
		}

		try {
			$blueprint = SiteBlueprint::fromArray( $blueprintRaw );
		} catch ( \Throwable ) {
			// A blueprint that no longer validates means the stored row is
			// not something we can safely reconstruct a plan from. Returning
			// null is the fail-closed answer: no plan, therefore nothing
			// executable.
			return null;
		}

		$actions = array();
		$states  = array();

		foreach ( $actionRows as $actionRow ) {
			$definition = json_decode( (string) $actionRow['definition'], true );

			if ( ! is_array( $definition ) ) {
				return null;
			}

			$actions[] = PlanAction::fromArray( $definition );

			$states[ (string) $actionRow['action_id'] ] = new ActionState(
				(string) $actionRow['action_id'],
				(string) $actionRow['status'],
				null === $actionRow['started_at'] ? null : (int) $actionRow['started_at'],
				null === $actionRow['finished_at'] ? null : (int) $actionRow['finished_at'],
				$this->decodeArray( $actionRow['result'] ),
				null === $actionRow['error'] ? null : (string) $actionRow['error'],
				$this->decodeArray( $actionRow['rollback_data'] ),
				null === $actionRow['verification_status'] ? null : (string) $actionRow['verification_status'],
				null === $actionRow['verification_detail'] ? null : (string) $actionRow['verification_detail'],
				$this->decodeArray( $actionRow['verification_expected'] ?? null ),
				$this->decodeArray( $actionRow['verification_actual'] ?? null ),
				isset( $actionRow['verified_at'] ) && null !== $actionRow['verified_at'] ? (int) $actionRow['verified_at'] : null,
				$this->decodeArray( $actionRow['resolved_arguments'] ?? null )
			);
		}

		try {
			$plan = ActionPlan::create(
				$planId,
				(string) $row['conversation_id'],
				(int) $row['owner_user_id'],
				$blueprint,
				$actions,
				(int) $row['created_at'],
				(int) $row['plan_version'],
				max( 1, (int) $row['expires_at'] - (int) $row['created_at'] ),
				isset( $row['project_id'] ) ? (string) $row['project_id'] : 'default',
				$this->decodeArray( $row['conflicts'] ?? null ) ?? array(),
				// The hash as recorded at creation, so hashMatches() compares
				// stored-vs-content rather than content-vs-itself.
				(string) $row['plan_hash']
			);
		} catch ( \Throwable ) {
			return null;
		}

		return new PlanRecord(
			$plan,
			(string) $row['status'],
			$states,
			null === $row['approved_at'] ? null : (int) $row['approved_at'],
			null === $row['approved_by'] ? null : (int) $row['approved_by'],
			null === $row['approved_hash'] ? null : (string) $row['approved_hash'],
			null === $row['failure_reason'] ? null : (string) $row['failure_reason'],
			null === $row['cancelled_at'] ? null : (int) $row['cancelled_at'],
			$this->decodeArray( $row['audit_report'] ?? null )
		);
	}

	/** @return array<string, mixed>|null */
	private function decodeArray( mixed $raw ): ?array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Tools;

use DoSieci\AiOperator\Domain\Audit\AuditEntry;
use DoSieci\AiOperator\Domain\Audit\AuditLogInterface;

/**
 * The single place where a tool the model asked for actually runs -- and
 * the single place where it can be refused.
 *
 * Gate order is deliberate and each gate is independent of the last:
 *   1. Is the tool in the local allowlist at all?          (ToolRegistry)
 *   2. Do the model's arguments match the declared schema? (ArgumentsValidator)
 *   3. Is this risk level enabled on this installation?    (maxRiskLevel)
 *   4. May the LOGGED-IN HUMAN do this?                    (CapabilityChecker)
 *   5. Does a write/destructive call carry explicit human confirmation?
 * Only then is the handler invoked, under a bounded time limit.
 *
 * Every outcome -- allowed, denied at any gate, or failed while running --
 * is written to the audit log before this method returns. There is no path
 * through this class that runs a handler without an audit record.
 *
 * Note that gates 3-5 are enforced here, on the WordPress side, EVEN
 * THOUGH the Hub also authorises the call before sending it. That is not
 * redundancy for its own sake: the Hub cannot see current_user_can(), and
 * a plugin that trusted the Hub's "this is allowed" verdict would be one
 * compromised/spoofed Hub response away from running anything. Neither
 * side is trusted to be the only gate (AI_OPERATOR_SECURITY.md section 3).
 */
final class ToolDispatcher {

	/**
	 * Phase 1 ships read-only tools only. Raising this constant is NOT
	 * sufficient to enable writes: a write tool must additionally provide
	 * preview + confirmation + rollback per AI_OPERATOR_SECURITY.md section
	 * 3, and gate 5 below refuses an unconfirmed write regardless of this
	 * setting.
	 */
	private const RISK_ORDER = array(
		ToolDefinition::RISK_READ_ONLY        => 0,
		ToolDefinition::RISK_REVERSIBLE_WRITE => 1,
		ToolDefinition::RISK_DESTRUCTIVE      => 2,
	);

	public function __construct(
		private ToolRegistry $registry,
		private ArgumentsValidator $validator,
		private CapabilityCheckerInterface $capabilities,
		private AuditLogInterface $auditLog,
		private string $maxRiskLevel = ToolDefinition::RISK_READ_ONLY
	) {
	}

	/**
	 * @param array<string, mixed> $arguments as produced by the model (untrusted)
	 */
	public function dispatch( string $toolName, array $arguments, int $userId, string $requestId, bool $confirmed = false ): ToolOutcome {
		try {
			$tool = $this->registry->get( $toolName );
		} catch ( UnknownToolException $e ) {
			return $this->deny( $userId, $toolName, 'unknown_tool', $e->getMessage(), array(), $requestId );
		}

		try {
			$validated = $this->validator->validate( $tool->name, $tool->argumentsSchema, $arguments );
		} catch ( InvalidArgumentsException $e ) {
			return $this->deny( $userId, $tool->name, 'invalid_arguments', $e->getMessage(), array(), $requestId );
		}

		if ( self::RISK_ORDER[ $tool->riskLevel ] > self::RISK_ORDER[ $this->maxRiskLevel ] ) {
			return $this->deny(
				$userId,
				$tool->name,
				'risk_level_not_enabled',
				sprintf( 'Tool risk level "%s" exceeds this installation\'s maximum ("%s").', $tool->riskLevel, $this->maxRiskLevel ),
				array( 'arguments' => $validated ),
				$requestId
			);
		}

		if ( ! $this->capabilities->currentUserCan( $tool->requiredCapability ) ) {
			return $this->deny(
				$userId,
				$tool->name,
				'missing_capability',
				sprintf( 'The current user lacks the "%s" capability.', $tool->requiredCapability ),
				array( 'arguments' => $validated ),
				$requestId
			);
		}

		if ( ToolDefinition::RISK_READ_ONLY !== $tool->riskLevel && ! $confirmed ) {
			// A blanket "trust the agent" setting can never satisfy this --
			// confirmation is per-action, passed in by the UI for one
			// specific pending call.
			return $this->deny(
				$userId,
				$tool->name,
				'confirmation_required',
				'This tool changes data and requires explicit per-action confirmation.',
				array( 'arguments' => $validated ),
				$requestId
			);
		}

		$previousTimeLimit = null;
		if ( function_exists( 'set_time_limit' ) ) {
			// Bounded execution (AI_OPERATOR_SECURITY.md section 3). Best
			// effort: set_time_limit() is a no-op in some SAPI/safe-mode
			// configurations, which is why handlers are also written to be
			// individually cheap rather than relying on this alone.
			$previousTimeLimit = (int) ini_get( 'max_execution_time' );
			@set_time_limit( $tool->timeoutSeconds );
		}

		try {
			$result = ( $tool->handler )( $validated );
			$result = is_array( $result ) ? $result : array( 'result' => $result );
		} catch ( \Throwable $e ) {
			// A handler that blows up must produce a visible, audited
			// failure -- never a partially-applied silent one.
			return $this->fail( $userId, $tool->name, $e->getMessage(), array( 'arguments' => $validated ), $requestId );
		} finally {
			if ( null !== $previousTimeLimit && function_exists( 'set_time_limit' ) ) {
				@set_time_limit( $previousTimeLimit );
			}
		}

		$this->auditLog->record(
			new AuditEntry( $userId, $tool->name, AuditEntry::OUTCOME_ALLOWED, null, array( 'arguments' => $validated ), $requestId, time() )
		);

		return ToolOutcome::success( $tool->name, $result );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function deny( int $userId, string $toolName, string $reasonCode, string $message, array $context, string $requestId ): ToolOutcome {
		$this->auditLog->record(
			new AuditEntry( $userId, $toolName, AuditEntry::OUTCOME_DENIED, $reasonCode, $context, $requestId, time() )
		);

		return ToolOutcome::denied( $toolName, $reasonCode, $message );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function fail( int $userId, string $toolName, string $message, array $context, string $requestId ): ToolOutcome {
		$this->auditLog->record(
			new AuditEntry( $userId, $toolName, AuditEntry::OUTCOME_FAILED, $message, $context, $requestId, time() )
		);

		return ToolOutcome::failed( $toolName, $message );
	}
}

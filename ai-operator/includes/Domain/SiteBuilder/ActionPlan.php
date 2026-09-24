<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * The immutable, human-approvable unit of work: an ordered list of exactly
 * the tool calls that will be dispatched, bound to the blueprint they were
 * derived from.
 *
 * ## Why this is hashed
 *
 * 1.1's confirmation model works because the human approves ONE call and
 * the server re-reads that call from its own storage. A plan is the same
 * promise scaled up: the human sees eighteen steps, approves once, and
 * every one of those eighteen must execute exactly as shown. The hash is
 * what makes "exactly as shown" checkable rather than assumed -- if
 * anything about the plan's content differs at execution time from what was
 * approved, the hash differs and the executor refuses.
 *
 * The hash covers the blueprint AND every action's tool name, arguments,
 * ordering and dependencies. It deliberately does NOT cover mutable runtime
 * state (status, timestamps, per-step results): those change constantly
 * during a legitimate run, and folding them in would make the hash useless
 * as an identity check.
 *
 * Canonicalisation matters more than it looks. `{"a":1,"b":2}` and
 * `{"b":2,"a":1}` are the same plan and must hash the same, or a harmless
 * round-trip through JSON invalidates a valid approval; conversely list
 * order is preserved, because step 3 running before step 2 is a different
 * plan. See PlanAction::canonicalise().
 *
 * ## Why approval does not weaken any gate
 *
 * Approving a plan replaces eighteen clicks with one. It does not replace
 * schema validation, the capability check, the risk ceiling, or the
 * canonical tool catalog -- PlanExecutor still routes every step through
 * ToolDispatcher, which applies all of 1.1's gates unchanged. The single
 * thing plan approval satisfies is ToolDispatcher's per-action
 * confirmation flag, and only for steps whose tool name and arguments hash
 * to the approved plan.
 */
final class ActionPlan {

	/** Plans go stale: an approval from last week should not still execute. */
	public const DEFAULT_TTL_SECONDS = 86400;

	private const MAX_ACTIONS = 60;

	/** @param PlanAction[] $actions */
	private function __construct(
		public readonly string $planId,
		public readonly int $planVersion,
		public readonly string $conversationId,
		public readonly int $ownerUserId,
		/**
		 * Scopes managed-resource identity. Stable per site, so a second
		 * run of any blueprint reconciles against the first run's work
		 * rather than duplicating it -- and so one project can never claim
		 * or roll back another's resources.
		 */
		public readonly string $projectId,
		public readonly SiteBlueprint $blueprint,
		public readonly array $actions,
		/**
		 * Resources the planner deliberately declined to touch, with the
		 * reason. Part of the approved plan and of its hash: "we will leave
		 * your hand-edited Kontakt page alone" is a promise the human is
		 * agreeing to, not a display detail.
		 *
		 * @var array<string, string> resource key => human-readable reason
		 */
		public readonly array $conflicts,
		public readonly int $createdAt,
		public readonly int $expiresAt,
		public readonly string $planHash
	) {
	}

	/**
	 * @param PlanAction[] $actions
	 *
	 * @throws PlanValidationException
	 */
	public static function create(
		string $planId,
		string $conversationId,
		int $ownerUserId,
		SiteBlueprint $blueprint,
		array $actions,
		int $createdAt,
		int $planVersion = 1,
		int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
		string $projectId = 'default',
		array $conflicts = array(),
		/**
		 * Only set when restoring a stored plan. Passing the hash recorded
		 * at creation -- rather than recomputing it -- is what lets
		 * hashMatches() detect a plan that was altered in storage. A
		 * rehydrate that recomputed both sides could never fail, which
		 * would make the whole integrity check decorative.
		 */
		?string $restoredHash = null
	): self {
		self::assertValidActions( $actions );

		$hash = $restoredHash ?? self::canonicalHash( $blueprint, $actions, $planVersion, $conflicts );

		return new self(
			$planId,
			$planVersion,
			$conversationId,
			$ownerUserId,
			$projectId,
			$blueprint,
			array_values( $actions ),
			$conflicts,
			$createdAt,
			$createdAt + $ttlSeconds,
			$hash
		);
	}

	/**
	 * Produces the NEXT version of this plan from edited actions.
	 *
	 * Any change to the steps -- one argument, one added step, one
	 * reordering -- must come through here. The result is a different
	 * version with a different hash and, critically, no approval: the caller
	 * has to send it back to the human. There is deliberately no method that
	 * mutates an approved plan in place.
	 *
	 * @param PlanAction[] $actions
	 *
	 * @throws PlanValidationException
	 */
	public function withRevisedActions( array $actions, int $revisedAt ): self {
		return self::create(
			$this->planId,
			$this->conversationId,
			$this->ownerUserId,
			$this->blueprint,
			$actions,
			$revisedAt,
			$this->planVersion + 1,
			$this->expiresAt - $this->createdAt,
			$this->projectId,
			$this->conflicts
		);
	}

	public function isExpired( int $now ): bool {
		return $now >= $this->expiresAt;
	}

	public function actionCount(): int {
		return count( $this->actions );
	}

	public function findAction( string $actionId ): ?PlanAction {
		foreach ( $this->actions as $action ) {
			if ( $action->actionId === $actionId ) {
				return $action;
			}
		}

		return null;
	}

	/**
	 * Recomputes the hash from current content and compares it to the one
	 * recorded at creation. A mismatch means the stored plan was altered
	 * after the fact -- by a bug, a partial write, or tampering -- and the
	 * only safe response is to refuse to run it.
	 */
	public function hashMatches(): bool {
		return hash_equals(
			$this->planHash,
			self::canonicalHash( $this->blueprint, $this->actions, $this->planVersion, $this->conflicts )
		);
	}

	/**
	 * @param PlanAction[] $actions
	 */
	public static function canonicalHash( SiteBlueprint $blueprint, array $actions, int $planVersion, array $conflicts = array() ): string {
		ksort( $conflicts );

		$payload = array(
			'actions'      => array_map(
				static fn( PlanAction $action ): array => $action->toArray(),
				array_values( $actions )
			),
			'blueprint'    => PlanAction::canonicalise( $blueprint->toArray() ),
			'conflicts'    => $conflicts,
			'plan_version' => $planVersion,
		);

		// JSON_UNESCAPED_* so the same string does not hash differently
		// depending on whether PHP chose to escape a Polish character.
		return hash(
			'sha256',
			(string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * @param PlanAction[] $actions
	 *
	 * @throws PlanValidationException
	 */
	private static function assertValidActions( array $actions ): void {
		if ( array() === $actions ) {
			throw new PlanValidationException( 'A plan must contain at least one action.' );
		}

		if ( count( $actions ) > self::MAX_ACTIONS ) {
			throw new PlanValidationException(
				sprintf( 'A plan is limited to %d actions.', self::MAX_ACTIONS )
			);
		}

		$seen = array();
		foreach ( $actions as $action ) {
			if ( ! $action instanceof PlanAction ) {
				throw new PlanValidationException( 'Every plan entry must be a PlanAction.' );
			}

			if ( isset( $seen[ $action->actionId ] ) ) {
				throw new PlanValidationException(
					sprintf( 'Duplicate action id "%s".', $action->actionId )
				);
			}

			$seen[ $action->actionId ] = true;
		}

		// Dependencies must point at steps that exist AND run earlier.
		// A forward or circular dependency can never be satisfied, so a plan
		// containing one would stall mid-run rather than fail up front.
		foreach ( $actions as $index => $action ) {
			foreach ( $action->dependsOn as $dependency ) {
				if ( ! isset( $seen[ $dependency ] ) ) {
					throw new PlanValidationException(
						sprintf( 'Action "%s" depends on unknown action "%s".', $action->actionId, $dependency )
					);
				}

				$dependencyIndex = null;
				foreach ( $actions as $candidateIndex => $candidate ) {
					if ( $candidate->actionId === $dependency ) {
						$dependencyIndex = $candidateIndex;
						break;
					}
				}

				if ( null !== $dependencyIndex && $dependencyIndex >= $index ) {
					throw new PlanValidationException(
						sprintf(
							'Action "%s" depends on "%s", which does not run before it.',
							$action->actionId,
							$dependency
						)
					);
				}
			}
		}
	}
}

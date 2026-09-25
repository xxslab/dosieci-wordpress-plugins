<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * A stored plan: the immutable approved definition plus everything that has
 * happened to it since.
 *
 * The approval fields are the security core. `$approvedHash` is the hash as
 * it stood at the moment a specific human clicked approve; `$approvedBy` is
 * who that human was. Execution requires both to still line up with the
 * plan in front of us, which is what stops:
 *
 *  - a plan edited after approval from running (hash differs),
 *  - user B from executing user A's approved plan (owner differs),
 *  - an approval from three days ago from still being live (expiry).
 *
 * assertExecutable() is the single choke point for all of that, so no call
 * site has to remember the list.
 */
final class PlanRecord {

	/** @param array<string, ActionState> $actionStates keyed by action id */
	public function __construct(
		public readonly ActionPlan $plan,
		public string $status = PlanStatus::DRAFT,
		public array $actionStates = array(),
		public ?int $approvedAt = null,
		public ?int $approvedBy = null,
		public ?string $approvedHash = null,
		public ?string $failureReason = null,
		public ?int $cancelledAt = null,
		/** @var array<string, mixed>|null persisted SiteAuditReport */
		public ?array $auditReport = null
	) {
		if ( array() === $this->actionStates ) {
			foreach ( $plan->actions as $action ) {
				$this->actionStates[ $action->actionId ] = new ActionState( $action->actionId );
			}
		}
	}

	public function state( string $actionId ): ?ActionState {
		return $this->actionStates[ $actionId ] ?? null;
	}

	/**
	 * Records a specific human's approval of the plan AS IT IS RIGHT NOW.
	 *
	 * @throws PlanStateException
	 */
	public function approve( int $userId, int $now ): void {
		PlanStatus::assertTransition( $this->status, PlanStatus::APPROVED );

		if ( $userId !== $this->plan->ownerUserId ) {
			// Approval is not transferable. The person who owns the plan is
			// the person whose capabilities were (and will be) checked
			// against its steps.
			throw new PlanStateException( 'Only the plan owner may approve it.' );
		}

		if ( $this->plan->isExpired( $now ) ) {
			throw new PlanStateException( 'This plan has expired. Generate a new one.' );
		}

		if ( ! $this->plan->hashMatches() ) {
			throw new PlanStateException( 'Plan contents do not match their hash; refusing to approve.' );
		}

		$this->status       = PlanStatus::APPROVED;
		$this->approvedAt   = $now;
		$this->approvedBy   = $userId;
		$this->approvedHash = $this->plan->planHash;
	}

	/**
	 * Every condition that must hold before ANY step of this plan runs.
	 * Called by PlanExecutor before each step, not just once at the start:
	 * a plan can be cancelled or expire between step 4 and step 5.
	 *
	 * @throws PlanStateException
	 */
	public function assertExecutable( int $userId, int $now ): void {
		if ( ! PlanStatus::isExecutable( $this->status ) ) {
			throw new PlanStateException(
				sprintf( 'A plan in state “%s” cannot execute steps.', esc_html( $this->status ) )
			);
		}

		if ( null === $this->approvedHash ) {
			throw new PlanStateException( 'This plan was never approved.' );
		}

		if ( $userId !== $this->plan->ownerUserId ) {
			throw new PlanStateException( 'This plan belongs to a different user.' );
		}

		if ( $this->plan->isExpired( $now ) ) {
			throw new PlanStateException( 'This plan has expired.' );
		}

		if ( ! hash_equals( $this->approvedHash, $this->plan->planHash ) ) {
			throw new PlanStateException(
				'Plan hash differs from the approved hash; the plan changed after approval.'
			);
		}

		if ( ! $this->plan->hashMatches() ) {
			throw new PlanStateException( 'Stored plan contents do not match their own hash.' );
		}
	}

	/**
	 * The next step to run: the first PENDING action, in sequence, whose
	 * dependencies have all succeeded.
	 *
	 * Returns null when there is nothing left to do -- which the executor
	 * reads as "the plan is finished", not "skip ahead and try later steps".
	 */
	public function nextRunnableAction(): ?PlanAction {
		foreach ( $this->plan->actions as $action ) {
			$state = $this->state( $action->actionId );

			if ( null === $state || ActionState::PENDING !== $state->status ) {
				continue;
			}

			foreach ( $action->dependsOn as $dependency ) {
				$dependencyState = $this->state( $dependency );

				if ( null === $dependencyState || ActionState::SUCCEEDED !== $dependencyState->status ) {
					// A dependency that has not succeeded means this step is
					// not runnable. Because plan validation guarantees
					// dependencies run earlier, and because execution stops
					// on failure, this can only be reached when the run has
					// already halted.
					return null;
				}
			}

			return $action;
		}

		return null;
	}

	/** @return array{total:int, succeeded:int, failed:int, pending:int, rolled_back:int} */
	public function progress(): array {
		$counts = array(
			'total'       => $this->plan->actionCount(),
			'succeeded'   => 0,
			'failed'      => 0,
			'pending'     => 0,
			'rolled_back' => 0,
		);

		foreach ( $this->actionStates as $state ) {
			switch ( $state->status ) {
				case ActionState::SUCCEEDED:
					++$counts['succeeded'];
					break;
				case ActionState::FAILED:
					++$counts['failed'];
					break;
				case ActionState::PENDING:
					++$counts['pending'];
					break;
				case ActionState::ROLLED_BACK:
					++$counts['rolled_back'];
					break;
			}
		}

		return $counts;
	}

	/** @return ActionState[] succeeded steps, newest first -- rollback order */
	public function rollbackCandidates(): array {
		$candidates = array();

		foreach ( $this->plan->actions as $action ) {
			$state = $this->state( $action->actionId );

			if ( null !== $state && $state->isRollbackCandidate() && $action->isReversible() ) {
				$candidates[] = $state;
			}
		}

		// Reverse order: undo the last thing done first, so a step that
		// depended on an earlier one is undone before its dependency.
		return array_reverse( $candidates );
	}
}

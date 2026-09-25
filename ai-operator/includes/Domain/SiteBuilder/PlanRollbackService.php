<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * Undoes the reversible steps of a plan that already ran, newest first.
 *
 * ## Rollback is a write, and is gated like one
 *
 * It is tempting to treat "undo" as inherently safe. It is not: restoring a
 * post's old content is still an edit, reactivating the previous theme
 * still changes what visitors see, and a rollback driven by attacker-chosen
 * data would be a write primitive with a friendly name. So rollback runs
 * through the same ownership check as execution, and every operation it
 * performs uses ONLY the snapshot this plugin recorded before the original
 * write -- never anything supplied by the caller.
 *
 * ## What is deliberately not undone
 *
 * install_plugin and install_theme roll back to DEACTIVATED, not deleted.
 * Removing third-party code from disk without a human looking at it is a
 * far bigger blast radius than leaving it present and inactive, and a
 * deletion that races with another request can leave WordPress referencing
 * files that no longer exist. Same reasoning as 1.1's refusal to offer
 * permanent post deletion at all.
 *
 * A partially successful rollback reports PARTIALLY_ROLLED_BACK rather than
 * pretending. Knowing that three of five undos worked is actionable;
 * "rollback complete" when it was not is how people lose trust in the
 * feature and stop using it.
 */
final class PlanRollbackService {

	/** @var callable():int */
	private $clock;

	public function __construct(
		private PlanRepositoryInterface $plans,
		private RollbackExecutorInterface $rollbackExecutor,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * Rolls back every reversible, succeeded step of a finished plan.
	 *
	 * @throws PlanStateException
	 */
	public function rollback( string $planId, int $userId ): PlanRecord {
		$record = $this->plans->findForUser( $planId, $userId );

		if ( null === $record ) {
			throw new PlanStateException( 'No such plan for this user.' );
		}

		if ( $userId !== $record->plan->ownerUserId ) {
			throw new PlanStateException( 'This plan belongs to a different user.' );
		}

		// Only a finished plan can be rolled back -- rolling back underneath
		// a running executor would race with it.
		PlanStatus::assertTransition( $record->status, PlanStatus::ROLLING_BACK );
		$record->status = PlanStatus::ROLLING_BACK;
		$this->plans->save( $record );

		$attempted = 0;
		$failed    = 0;

		foreach ( $record->rollbackCandidates() as $state ) {
			$action = $record->plan->findAction( $state->actionId );

			if ( null === $action ) {
				continue;
			}

			++$attempted;

			try {
				$undone = $this->rollbackExecutor->undo( $action, $state, $userId );

				if ( $undone ) {
					$state->status = ActionState::ROLLED_BACK;
				} else {
					$state->status = ActionState::ROLLBACK_FAILED;
					++$failed;
				}
			} catch ( \Throwable $e ) {
				// One step failing to undo must not abandon the remaining
				// ones -- the others are still worth reverting.
				$state->status = ActionState::ROLLBACK_FAILED;
				$state->error  = $e->getMessage();
				++$failed;
			}

			$this->plans->save( $record );
		}

		$record->status = ( 0 === $failed )
			? PlanStatus::ROLLED_BACK
			: PlanStatus::PARTIALLY_ROLLED_BACK;

		if ( 0 === $attempted ) {
			// Nothing was reversible. Still a legitimate terminal state, but
			// say so rather than implying work was undone.
			$record->failureReason = __( 'No reversible steps to undo.', 'dosieci-ai-operator' );
		}

		$this->plans->save( $record );

		return $record;
	}
}

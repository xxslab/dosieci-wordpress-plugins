<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolDispatcher;

/**
 * Runs an approved plan one bounded batch at a time.
 *
 * ## Why one step at a time
 *
 * A forty-step plan installs two plugins, a theme, and creates a dozen
 * pages. Attempting that inside a single PHP request means one
 * max_execution_time or one memory limit away from a half-built site with
 * no record of where it stopped. So each executeNext() call runs a bounded
 * number of steps, persists the result, and returns -- the UI polls for the
 * next batch. A browser refresh mid-build loses nothing, because nothing
 * important lived in the browser.
 *
 * ## Why every step still goes through ToolDispatcher
 *
 * Plan approval satisfies exactly ONE of ToolDispatcher's gates: the
 * per-action confirmation flag. Schema validation, the risk ceiling, and
 * current_user_can() all still run per step, unchanged from 1.1. That is
 * deliberate and load-bearing -- a user whose capabilities were revoked
 * between approval and execution must start failing steps immediately, and
 * a plan approved on a site with write mode on must stop working the moment
 * write mode is turned off.
 *
 * The confirmation flag is passed only after assertExecutable() has
 * confirmed the plan's hash still matches what the human approved. An
 * argument altered after approval changes the hash, so it never reaches a
 * handler with confirmed=true.
 *
 * ## Why failure stops the run
 *
 * If step 7 (create the Contact page) fails, step 12 (add Contact to the
 * menu) cannot meaningfully succeed. Continuing produces a site that is
 * broken in a way nobody inspected. Stopping produces "6 done, 1 failed, 11
 * not attempted", which is honest and resumable once the cause is fixed.
 */
final class PlanExecutor {

	/**
	 * Steps per executeNext() call. One by default: theme and plugin
	 * installs are the slowest steps in a typical plan and each downloads a
	 * package, so batching them is how a request times out.
	 */
	public const DEFAULT_BATCH_SIZE = 1;

	/** @var callable():int */
	private $clock;

	/**
	 * @param callable():int|null $clock injectable so tests do not depend on wall time
	 */
	public function __construct(
		private PlanRepositoryInterface $plans,
		private ToolDispatcher $dispatcher,
		private RollbackDataCollectorInterface $rollbackCollector,
		private ?PlanVerifierInterface $verifier = null,
		private ?SiteBuildAuditorInterface $auditor = null,
		private ?ManagedResourceRecorderInterface $recorder = null,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * Runs up to $batchSize steps of a plan and returns its state afterward.
	 *
	 * @throws PlanStateException when the plan may not run at all
	 */
	public function executeNext( string $planId, int $userId, int $batchSize = self::DEFAULT_BATCH_SIZE ): PlanRecord {
		$record = $this->loadOwnedPlan( $planId, $userId );
		$now    = ( $this->clock )();

		$record->assertExecutable( $userId, $now );

		if ( PlanStatus::APPROVED === $record->status ) {
			$record->status = PlanStatus::RUNNING;
			$this->plans->save( $record );
		}

		$batchSize = max( 1, min( 10, $batchSize ) );

		for ( $i = 0; $i < $batchSize; $i++ ) {
			// Re-checked every iteration, not just once: a plan cancelled
			// from another tab between step 3 and step 4 must stop at 3.
			$record->assertExecutable( $userId, ( $this->clock )() );

			$action = $record->nextRunnableAction();

			if ( null === $action ) {
				$this->finish( $record );
				break;
			}

			if ( ! $this->runStep( $record, $action, $userId ) ) {
				break;
			}
		}

		// Settle the plan in the same call that ran its last step, rather
		// than leaving it RUNNING until the UI happens to poll once more.
		// Without this a completed build reports "running" to the user for
		// one extra round trip, which reads as a hang.
		if ( PlanStatus::RUNNING === $record->status && null === $record->nextRunnableAction() ) {
			$this->finish( $record );
		}

		$this->plans->save( $record );

		return $record;
	}

	/** @return bool whether execution may continue to the next step */
	private function runStep( PlanRecord $record, PlanAction $action, int $userId ): bool {
		$state = $record->state( $action->actionId );

		if ( null === $state ) {
			return false;
		}

		$state->status    = ActionState::RUNNING;
		$state->startedAt = ( $this->clock )();
		$this->plans->save( $record );

		// Resolved first, then everything downstream uses the same values.
		// Both the rollback snapshot and the verifier need the arguments
		// that will ACTUALLY be dispatched: a resolver-driven page_id is 0
		// in the approved plan and only becomes a real id here.
		$state->resolvedArguments = $this->resolveArguments( $record, $action );

		// Captured BEFORE the write: an update_post that already ran cannot
		// tell us what it replaced.
		$state->rollbackData = $this->rollbackCollector->capture( $action, $state->resolvedArguments );

		$outcome = $this->dispatcher->dispatch(
			$action->toolName,
			$state->resolvedArguments,
			$userId,
			$this->stepRequestId( $record, $action ),
			// Confirmation is satisfied by the plan-level approval that
			// assertExecutable() just re-verified -- for THIS action, with
			// THESE arguments, because both are covered by the hash.
			ToolDefinition::RISK_READ_ONLY !== $action->riskLevel
		);

		$state->finishedAt = ( $this->clock )();

		// Two distinct kinds of failure, both of which must stop the run.
		// ToolOutcome::isError covers a refusal at one of ToolDispatcher's
		// gates or a thrown handler. The second check covers WriteToolFactory's
		// own convention of RETURNING array('success' => false, 'error' => ...)
		// for a predictable refusal (see its failure() helper) -- that is a
		// successful dispatch of a tool that declined to do the thing, and
		// treating it as a succeeded step would let the plan march on
		// reporting green while the site was never touched.
		$softError = ( false === ( $outcome->data['success'] ?? true ) )
			? (string) ( $outcome->data['error'] ?? __( 'The tool refused to run.', 'dosieci-ai-operator' ) )
			: null;

		if ( $outcome->isError || null !== $softError ) {
			$message = $outcome->isError ? (string) $outcome->message : (string) $softError;

			$state->status = ActionState::FAILED;
			$state->error  = $message;

			$record->status        = PlanStatus::FAILED;
			$record->failureReason = sprintf(
				/* translators: 1: step sequence number, 2: internal tool name, 3: failure message */
				__( 'Step %1$d (%2$s) failed: %3$s', 'dosieci-ai-operator' ),
				$action->sequence,
				$action->toolName,
				$message
			);

			$this->plans->save( $record );

			return false;
		}

		$state->result = $outcome->data;

		// The write reported success. That is the handler's own opinion --
		// now prove it against real WordPress state before the step counts
		// as done. See verify() for why a missing verifier is also a
		// failure rather than a pass.
		$verification = $this->verify( $record, $action, $state, $userId );

		if ( ! $verification ) {
			$state->status = ActionState::FAILED;
			$state->error  = sprintf(
				/* translators: %s: verification failure detail */
				__( 'The write succeeded, but verifying WordPress’s state failed: %s', 'dosieci-ai-operator' ),
				(string) $state->verificationDetail
			);

			$record->status        = PlanStatus::FAILED;
			$record->failureReason = sprintf(
				/* translators: 1: step sequence number, 2: internal tool name, 3: failure message */
				__( 'Step %1$d (%2$s): %3$s', 'dosieci-ai-operator' ),
				$action->sequence,
				$action->toolName,
				$state->error
			);

			$this->plans->save( $record );

			return false;
		}

		$state->status = ActionState::SUCCEEDED;

		// Stamp ownership only now: after the write succeeded AND its
		// effect was verified. Marking earlier would claim a resource that
		// may not exist, and a later run would then refuse to create it.
		if ( null !== $this->recorder && '' !== $action->managedResourceKey ) {
			$this->recorder->record( $action, $record->plan->projectId, $state->result ?? array() );
		}

		$this->plans->save( $record );

		return true;
	}

	/**
	 * Fills in arguments that could not be known when the plan was written
	 * -- ids of objects earlier steps created.
	 *
	 * The ONLY source is a prior step's own recorded result, and the wiring
	 * (which step, which field) is fixed in the approved, hashed plan. So
	 * this cannot introduce a value the human did not authorise the shape
	 * of, and nothing from the current request reaches it. The substituted
	 * value still goes through ToolDispatcher's schema validation like any
	 * other argument. See PlanAction's docblock.
	 *
	 * @return array<string, mixed>
	 */
	private function resolveArguments( PlanRecord $record, PlanAction $action ): array {
		if ( array() === $action->resolvers ) {
			return $action->arguments;
		}

		$arguments = $action->arguments;

		foreach ( $action->resolvers as $argumentName => $spec ) {
			if ( ! is_string( $argumentName ) || ! is_array( $spec ) ) {
				continue;
			}

			// A list-shaped resolver: build menu items from several prior
			// page-creation steps. Needed because a menu entry must carry
			// the post id of a page that did not exist at plan time -- and
			// WriteToolFactory::createMenu silently skips an item that has
			// neither a page_id nor a url, which is how a "successful"
			// create_menu can produce an empty menu.
			if ( 'menu_items' === ( $spec['kind'] ?? '' ) ) {
				$arguments[ $argumentName ] = $this->resolveMenuItems( $record, $action, $spec );

				continue;
			}

			// A product's category ids exist only once the category steps
			// have run. The ROLES are hash-covered -- the human approved
			// which categories this product belongs to -- and the ids are
			// read back from those steps' own recorded results.
			if ( 'product_categories' === ( $spec['kind'] ?? '' ) ) {
				$arguments[ $argumentName ] = $this->resolveProductCategories( $record, $action, $spec );

				continue;
			}

			$sourceId = (string) ( $spec['from_action'] ?? '' );
			$field    = (string) ( $spec['field'] ?? '' );

			if ( '' === $sourceId || '' === $field ) {
				continue;
			}

			// Only a step this plan declared a dependency on may be read
			// from -- otherwise a resolver could reach a step that has not
			// run yet and silently substitute null.
			if ( ! in_array( $sourceId, $action->dependsOn, true ) ) {
				continue;
			}

			$source = $record->state( $sourceId );

			if ( null === $source || ActionState::SUCCEEDED !== $source->status || null === $source->result ) {
				continue;
			}

			if ( array_key_exists( $field, $source->result ) ) {
				$arguments[ $argumentName ] = $source->result[ $field ];
			}
		}

		return $arguments;
	}

	/**
	 * Builds a nav-menu item list from the results of the page-creation
	 * steps this action depends on.
	 *
	 * Same constraints as scalar resolution: only steps named in the
	 * approved plan's dependsOn are readable, only their own recorded
	 * results are used, and the titles come from the approved plan rather
	 * than from anything supplied at run time.
	 *
	 * @param array<string, mixed> $spec
	 *
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * Term ids for the category roles a product declares.
	 *
	 * ONE ordered list, each entry either naming the step that will create
	 * its category (id known only at run time) or already knowing the id
	 * (the category was reused or is a conflict the product still links
	 * to). Exactly the pattern `resolveMenuItems()` uses for pages, for the
	 * same reason: a category reused on a recovery run has no step of its
	 * own in this plan, so a resolver that only scanned `dependsOn` for a
	 * matching step found nothing -- and the product silently landed in
	 * WooCommerce's own "Uncategorized" instead of the category the human
	 * approved. Found on a real partial-failure recovery run.
	 *
	 * @param array<string, mixed> $spec
	 *
	 * @return int[]
	 */
	private function resolveProductCategories( PlanRecord $record, PlanAction $action, array $spec ): array {
		$ids = array();

		foreach ( (array) ( $spec['items'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$termId   = 0;
			$sourceId = (string) ( $entry['from_action'] ?? '' );

			if ( '' !== $sourceId ) {
				if ( ! in_array( $sourceId, $action->dependsOn, true ) ) {
					continue;
				}

				$source = $record->state( $sourceId );

				if ( null === $source || ActionState::SUCCEEDED !== $source->status || null === $source->result ) {
					continue;
				}

				$termId = (int) ( $source->result['term_id'] ?? 0 );
			} elseif ( isset( $entry['term_id'] ) ) {
				$termId = (int) $entry['term_id'];
			}

			if ( $termId > 0 ) {
				$ids[] = $termId;
			}
		}

		return $ids;
	}

	private function resolveMenuItems( PlanRecord $record, PlanAction $action, array $spec ): array {
		$items = array();

		// ONE ordered list, in blueprint order. Each entry either names a
		// step whose result carries the id (a page this run creates or
		// updates) or already knows the id (a page reused, or left alone in
		// a conflict). An earlier version kept those in two lists and merged
		// them, which silently reordered the menu on any run that reused a
		// page.
		foreach ( (array) ( $spec['items'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$title  = (string) ( $entry['title'] ?? '' );
			$postId = 0;

			$sourceId = (string) ( $entry['from_action'] ?? '' );

			if ( '' !== $sourceId ) {
				if ( ! in_array( $sourceId, $action->dependsOn, true ) ) {
					continue;
				}

				$source = $record->state( $sourceId );

				if ( null === $source || ActionState::SUCCEEDED !== $source->status || null === $source->result ) {
					continue;
				}

				$postId = (int) ( $source->result['post_id'] ?? 0 );
			} elseif ( isset( $entry['page_id'] ) ) {
				// A literal id, fixed in the approved plan.
				$postId = (int) $entry['page_id'];
			}

			if ( $postId <= 0 ) {
				continue;
			}

			$items[] = array(
				'title'   => $title,
				'page_id' => $postId,
			);
		}

		return $items;
	}

	/**
	 * Independently re-reads WordPress state to confirm the write landed.
	 *
	 * A failed verification FAILS the step and stops the plan. That is a
	 * deliberate reversal of the first cut, which merely recorded the
	 * result: the real-WordPress build proved the case for it, when
	 * create_menu returned success while producing an empty menu. If a
	 * step's observable effect is absent, later steps that depend on it
	 * cannot meaningfully run, so continuing would build on a lie.
	 *
	 * A MISSING verifier is also a failure, not a pass. In Site Builder
	 * mode a write nobody can check must not be reported green -- that is
	 * the fail-safe direction. The planner only emits steps whose tools
	 * have verifiers (asserted by its own tests), so this branch means a
	 * genuine wiring gap, and it should be loud.
	 *
	 * @return bool whether the step may be considered successfully completed
	 */
	private function verify( PlanRecord $record, PlanAction $action, ActionState $state, int $userId ): bool {
		$state->verifiedAt = ( $this->clock )();

		if ( null === $this->verifier ) {
			// No verifier wired at all: the domain executor is being used
			// outside Site Builder mode (unit tests, single-action paths),
			// where per-step verification is not part of the contract.
			$state->verificationStatus = null;
			$state->verifiedAt         = null;

			return true;
		}

		if ( null === $action->verification ) {
			$state->verificationStatus = 'unverifiable';
			$state->verificationDetail = sprintf(
				/* translators: %s: internal tool name */
				__( 'Step “%s” does not declare a verification, so its effect cannot be confirmed.', 'dosieci-ai-operator' ),
				$action->toolName
			);

			return false;
		}

		$result = $this->verifier->verify( $action, $state, $userId );

		$state->verificationStatus = $result->passed ? 'passed' : 'failed';
		$state->verificationDetail = $result->detail;
		$state->verificationExpected = $result->expected;
		$state->verificationActual   = $result->actual;

		return $result->passed;
	}

	/**
	 * Settles a finished run.
	 *
	 * SUCCEEDED requires two independent things to be true: every step
	 * succeeded, AND the final audit finds the site the blueprint
	 * described. The second is not redundant -- a later step can undo an
	 * earlier one, and per-step checks would still all be green. See
	 * SiteBuildAuditorInterface.
	 */
	private function finish( PlanRecord $record ): void {
		$progress = $record->progress();

		if ( $progress['succeeded'] !== $progress['total'] ) {
			$record->status = PlanStatus::FAILED;

			return;
		}

		if ( null !== $this->auditor ) {
			$report              = $this->auditor->audit( $record->plan->blueprint, ( $this->clock )() );
			$record->auditReport = $report->toArray();

			if ( ! $report->passed ) {
				// Reported, never auto-rolled-back: undoing a mostly-correct
				// site because one expectation was missed is far more
				// destructive than telling the user what does not match.
				$record->status        = PlanStatus::FAILED;
				$record->failureReason = $report->summary();

				return;
			}
		}

		$record->status = PlanStatus::SUCCEEDED;
	}

	/** @throws PlanStateException */
	public function pause( string $planId, int $userId ): PlanRecord {
		$record = $this->loadOwnedPlan( $planId, $userId );

		PlanStatus::assertTransition( $record->status, PlanStatus::PAUSED );
		$record->status = PlanStatus::PAUSED;
		$this->plans->save( $record );

		return $record;
	}

	/** @throws PlanStateException */
	public function resume( string $planId, int $userId ): PlanRecord {
		$record = $this->loadOwnedPlan( $planId, $userId );

		PlanStatus::assertTransition( $record->status, PlanStatus::RUNNING );
		$record->status = PlanStatus::RUNNING;
		$this->plans->save( $record );

		return $record;
	}

	/**
	 * Stops the run without undoing anything.
	 *
	 * Cancel deliberately does NOT auto-rollback. The user asked to stop,
	 * not to destroy six steps of work they may want to keep -- rollback is
	 * a separate, explicitly chosen action (see PlanRollbackService).
	 *
	 * @throws PlanStateException
	 */
	public function cancel( string $planId, int $userId ): PlanRecord {
		$record = $this->loadOwnedPlan( $planId, $userId );

		PlanStatus::assertTransition( $record->status, PlanStatus::CANCELLED );
		$record->status      = PlanStatus::CANCELLED;
		$record->cancelledAt = ( $this->clock )();
		$this->plans->save( $record );

		return $record;
	}

	/** @throws PlanStateException */
	private function loadOwnedPlan( string $planId, int $userId ): PlanRecord {
		$record = $this->plans->findForUser( $planId, $userId );

		if ( null === $record ) {
			throw new PlanStateException( 'No such plan for this user.' );
		}

		return $record;
	}

	/**
	 * A stable, unique id per step so the audit log can tie a dispatch back
	 * to the exact plan action that caused it.
	 */
	private function stepRequestId( PlanRecord $record, PlanAction $action ): string {
		return sprintf( 'plan_%s_%s', $record->plan->planId, $action->actionId );
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintGenerationException;
use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintValidationException;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanRecord;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanStateException;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanStatus;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanValidationException;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteAuditReport;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteBlueprint;
use DoSieci\AiOperator\Plugin;

/**
 * The Site Builder endpoints: propose a plan, approve it, run it step by
 * step, and pause/cancel/roll back.
 *
 * Same three gates as 1.1's chat controller, in the same order, for the
 * same reasons: nonce (CSRF), capability (a nonce proves intent, not
 * authorisation), then the operation itself. On top of those, every plan
 * operation is scoped to the calling user through the repository, so a
 * valid nonce from user B cannot touch user A's plan.
 *
 * What the client is allowed to send is deliberately minimal. For approval
 * and execution it is a plan id and nothing else -- never the steps, never
 * the arguments. The server already has those; accepting them from the
 * browser is precisely how "approve this harmless plan" becomes "execute a
 * different one". This mirrors AjaxController::handleConfirm()'s existing
 * discipline for single-action confirmation.
 *
 * The one exception is approval, which additionally requires the plan hash
 * the user was SHOWN. That is not trusted as authorisation -- it is
 * compared against the server's own hash so that approving a plan the
 * screen has since re-generated is refused rather than silently applied to
 * the newer one.
 */
final class SiteBuilderAjaxController {

	public const NONCE_ACTION = 'dosieci_ai_site_builder';

	public const ACTION_DESCRIBE = 'dosieci_ai_sb_describe';
	public const ACTION_PROPOSE  = 'dosieci_ai_sb_propose';
	public const ACTION_APPROVE  = 'dosieci_ai_sb_approve';
	public const ACTION_STEP     = 'dosieci_ai_sb_step';
	public const ACTION_STATUS   = 'dosieci_ai_sb_status';
	public const ACTION_PAUSE    = 'dosieci_ai_sb_pause';
	public const ACTION_RESUME   = 'dosieci_ai_sb_resume';
	public const ACTION_CANCEL   = 'dosieci_ai_sb_cancel';
	public const ACTION_ROLLBACK = 'dosieci_ai_sb_rollback';

	public function __construct( private Plugin $plugin ) {
	}

	public function register(): void {
		$map = array(
			self::ACTION_DESCRIBE => 'handleDescribe',
			self::ACTION_PROPOSE  => 'handlePropose',
			self::ACTION_APPROVE  => 'handleApprove',
			self::ACTION_STEP     => 'handleStep',
			self::ACTION_STATUS   => 'handleStatus',
			self::ACTION_PAUSE    => 'handlePause',
			self::ACTION_RESUME   => 'handleResume',
			self::ACTION_CANCEL   => 'handleCancel',
			self::ACTION_ROLLBACK => 'handleRollback',
		);

		foreach ( $map as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Turns a free-text description into a blueprint CANDIDATE for review.
	 *
	 * Writes nothing. No plan is created, no WordPress state is touched --
	 * this endpoint's entire output is a validated blueprint the human then
	 * edits or accepts. The provider never sees a tool and never produces
	 * one; translating a blueprint into executable steps is the local
	 * planner's job (see BlueprintGeneratorInterface's docblock).
	 */
	public function handleDescribe(): void {
		$this->assertAllowed();

		$request = isset( $_POST['request'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['request'] ) ) : '';

		if ( '' === $request ) {
			wp_send_json_error( array( 'message' => __( 'Opisz witrynę, którą chcesz zbudować.', 'dosieci-ai-operator' ) ), 400 );
		}

		try {
			$blueprint = $this->plugin->blueprintGenerator()->generate( $request, $this->plugin->siteContext() );
		} catch ( BlueprintGenerationException $e ) {
			// Reported as data the screen renders inline, with retryable
			// distinguished from terminal so the UI can offer the right
			// next step. A previously reviewed blueprint is NOT discarded --
			// this endpoint never mutates stored state.
			wp_send_json_error(
				array(
					'message'   => $e->getMessage(),
					'code'      => $e->errorCode,
					'retryable' => $e->retryable,
				),
				200
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => __( 'Nie udało się przygotować opisu witryny.', 'dosieci-ai-operator' ), 'code' => 'unexpected' ), 200 );
		}

		wp_send_json_success( array( 'blueprint' => $blueprint->toArray() ) );
	}

	/**
	 * Builds a blueprint and a plan, and returns both for review. Nothing
	 * is executed and nothing is approved here -- this endpoint only ever
	 * writes a DRAFT plan row.
	 */
	public function handlePropose(): void {
		$this->assertAllowed();

		$raw = isset( $_POST['blueprint'] ) ? wp_unslash( (string) $_POST['blueprint'] ) : '';
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Nieprawidłowy opis witryny.', 'dosieci-ai-operator' ) ), 400 );
		}

		try {
			$blueprint = SiteBlueprint::fromArray( $decoded );
		} catch ( BlueprintValidationException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage(), 'code' => 'invalid_blueprint' ), 400 );
		}

		if ( ! $this->plugin->writesEnabled() ) {
			// Generating a plan that could never run would waste the user's
			// time and misrepresent what the plugin will do.
			wp_send_json_error(
				array(
					'message' => __( 'Tryb budowania witryny wymaga włączenia narzędzi zapisu w Ustawieniach.', 'dosieci-ai-operator' ),
					'code'    => 'writes_disabled',
				),
				409
			);
		}

		$userId = get_current_user_id();

		try {
			$plan = $this->plugin->blueprintPlanner()->plan(
				$blueprint,
				$this->newPlanId(),
				(string) ( $_POST['conversation_id'] ?? '' ),
				$userId,
				time(),
				// Real site facts the planner needs to choose a strategy: a
				// block theme needs a wp_navigation post, a classic one does
				// not, and a menu that suits one is invisible on the other.
				$this->plugin->siteContext(),
				// Scopes managed-resource identity so a second run reconciles
				// with the first instead of duplicating the site.
				$this->plugin->projectId()
			);
		} catch ( PlanValidationException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage(), 'code' => 'invalid_plan' ), 400 );
		}

		$record         = new PlanRecord( $plan );
		$record->status = PlanStatus::AWAITING_APPROVAL;

		$this->plugin->planRepository()->save( $record );

		wp_send_json_success( $this->present( $record ) );
	}

	public function handleApprove(): void {
		$this->assertAllowed();

		$record = $this->loadPlanOr404();
		$shown  = isset( $_POST['plan_hash'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['plan_hash'] ) ) : '';

		// The user approves the plan they were SHOWN. If the stored plan has
		// since been regenerated, refuse rather than approving the new one
		// on the strength of a click aimed at the old.
		if ( '' === $shown || ! hash_equals( $record->plan->planHash, $shown ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Ten plan jest nieaktualny. Odśwież stronę i przejrzyj go ponownie.', 'dosieci-ai-operator' ),
					'code'    => 'stale_plan',
				),
				409
			);
		}

		$this->run(
			function () use ( $record ): PlanRecord {
				$record->approve( get_current_user_id(), time() );
				$this->plugin->planRepository()->save( $record );

				return $record;
			}
		);
	}

	public function handleStep(): void {
		$this->assertAllowed();

		$planId = $this->planIdFromRequest();

		$this->run(
			fn (): PlanRecord => $this->plugin->planExecutor()->executeNext( $planId, get_current_user_id() )
		);
	}

	public function handleStatus(): void {
		$this->assertAllowed();

		// Progress survives a page reload because it lives here, not in the
		// browser.
		wp_send_json_success( $this->present( $this->loadPlanOr404() ) );
	}

	public function handlePause(): void {
		$this->assertAllowed();
		$planId = $this->planIdFromRequest();

		$this->run( fn (): PlanRecord => $this->plugin->planExecutor()->pause( $planId, get_current_user_id() ) );
	}

	public function handleResume(): void {
		$this->assertAllowed();
		$planId = $this->planIdFromRequest();

		$this->run( fn (): PlanRecord => $this->plugin->planExecutor()->resume( $planId, get_current_user_id() ) );
	}

	public function handleCancel(): void {
		$this->assertAllowed();
		$planId = $this->planIdFromRequest();

		$this->run( fn (): PlanRecord => $this->plugin->planExecutor()->cancel( $planId, get_current_user_id() ) );
	}

	public function handleRollback(): void {
		$this->assertAllowed();
		$planId = $this->planIdFromRequest();

		$this->run( fn (): PlanRecord => $this->plugin->planRollbackService()->rollback( $planId, get_current_user_id() ) );
	}

	/** @param callable():PlanRecord $operation */
	private function run( callable $operation ): void {
		try {
			$record = $operation();
		} catch ( PlanStateException $e ) {
			// A refused state transition is an expected, explainable outcome
			// (cancelled, expired, not yours, already finished) -- reported
			// as data the UI can render, not as a fatal.
			wp_send_json_error( array( 'message' => $e->getMessage(), 'code' => 'plan_state' ), 409 );
		}

		wp_send_json_success( $this->present( $record ) );
	}

	private function planIdFromRequest(): string {
		$planId = isset( $_POST['plan_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['plan_id'] ) ) : '';

		if ( '' === $planId ) {
			wp_send_json_error( array( 'message' => __( 'Brak identyfikatora planu.', 'dosieci-ai-operator' ) ), 400 );
		}

		return $planId;
	}

	private function loadPlanOr404(): PlanRecord {
		$record = $this->plugin->planRepository()->findForUser( $this->planIdFromRequest(), get_current_user_id() );

		if ( null === $record ) {
			// Same response for "no such plan" and "not yours" -- a probe
			// must not be able to enumerate other users' plan ids.
			wp_send_json_error( array( 'message' => __( 'Nie znaleziono planu.', 'dosieci-ai-operator' ) ), 404 );
		}

		return $record;
	}

	/**
	 * The JSON shape the Site Builder screen renders. Deliberately excludes
	 * rollback snapshots: they can contain a page's full previous content,
	 * which the progress view has no use for.
	 *
	 * @return array<string, mixed>
	 */
	private function present( PlanRecord $record ): array {
		$steps = array();

		foreach ( $record->plan->actions as $action ) {
			$state = $record->state( $action->actionId );

			$steps[] = array(
				'action_id'   => $action->actionId,
				'sequence'    => $action->sequence,
				'tool'        => $action->toolName,
				'description' => $action->description,
				'risk'        => $action->riskLevel,
				'expected'    => $action->expectedResult,
				'reversible'  => $action->isReversible(),
				'status'      => null === $state ? 'pending' : $state->status,
				'error'       => null === $state ? null : $state->error,
				'verified'    => null === $state ? null : $state->verificationStatus,
			);
		}

		return array(
			'plan_id'        => $record->plan->planId,
			'plan_hash'      => $record->plan->planHash,
			'plan_version'   => $record->plan->planVersion,
			'status'         => $record->status,
			'blueprint'      => $record->plan->blueprint->toArray(),
			'steps'          => $steps,
			'progress'       => $record->progress(),
			// Shown BEFORE approval: the human is agreeing to a plan that
			// deliberately leaves these resources untouched.
			'conflicts'      => $record->plan->conflicts,
			// Why the build was called finished, or why it was not. Without
			// this the screen could say "audit failed" and nothing else,
			// leaving the user to guess which of a dozen checks went wrong.
			'audit'          => $this->presentAudit( $record ),
			'failure_reason' => $record->failureReason,
			'can_rollback'   => PlanStatus::canTransition( $record->status, PlanStatus::ROLLING_BACK )
				&& array() !== $record->rollbackCandidates(),
		);
	}

	/**
	 * The final audit as three plain fields per check.
	 *
	 * Rebuilt field by field rather than passed through, so that whatever
	 * a future auditor decides to record alongside a check -- a raw option
	 * value, a file path, an actual-versus-expected dump -- cannot reach
	 * the browser just because somebody added it server-side. The screen
	 * needs a label, a verdict and a sentence; it gets exactly those.
	 *
	 * @return array<string, mixed>|null
	 */
	private function presentAudit( PlanRecord $record ): ?array {
		if ( null === $record->auditReport ) {
			return null;
		}

		$report = SiteAuditReport::fromArray( $record->auditReport );

		$checks = array();

		foreach ( $report->checks as $check ) {
			$checks[] = array(
				'check'  => (string) ( $check['check'] ?? '' ),
				'passed' => true === ( $check['passed'] ?? false ),
				'detail' => (string) ( $check['detail'] ?? '' ),
			);
		}

		return array(
			'passed'     => $report->passed,
			'audited_at' => $report->auditedAt,
			'checks'     => $checks,
			// Composed server-side. The browser must not re-derive a verdict
			// from the rows: two implementations of "did this pass" is one
			// too many, and the backend is the one that decides.
			'summary'    => $report->summary(),
		);
	}

	private function assertAllowed(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'dosieci-ai-operator' ) ), 403 );
		}
	}

	private function newPlanId(): string {
		return 'plan_' . bin2hex( random_bytes( 8 ) );
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\ActionState;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanExecutor;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanStatus;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanVerifierInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\RollbackDataCollectorInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteAuditReport;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteBlueprint;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteBuildAuditorInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\VerificationResult;
use DoSieci\AiOperator\Domain\Tools\ArgumentsValidator;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolDispatcher;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use DoSieci\AiOperator\Tests\Support\FakeCapabilityChecker;
use DoSieci\AiOperator\Tests\Support\InMemoryAuditLog;
use DoSieci\AiOperator\Tests\Support\InMemoryPlanRepository;
use DoSieci\AiOperator\Tests\Support\SiteBuilderFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The property this whole milestone exists for: a write handler saying
 * "success" is not evidence, and a step whose effect cannot be confirmed
 * against real state must not be reported green.
 */
final class PlanVerificationTest extends TestCase {

	private InMemoryPlanRepository $plans;

	protected function setUp(): void {
		$this->plans = new InMemoryPlanRepository();
	}

	private function collector(): RollbackDataCollectorInterface {
		return new class implements RollbackDataCollectorInterface {
			public function capture( PlanAction $action, array $resolvedArguments = array() ): ?array {
				return null;
			}
		};
	}

	private function registry(): ToolRegistry {
		$registry = new ToolRegistry();
		$registry->register(
			new ToolDefinition(
				'create_post',
				'test',
				array( 'type' => 'object', 'properties' => array( 'title' => array( 'type' => 'string' ) ) ),
				'publish_pages',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				5,
				// Always claims success -- the point of these tests.
				static fn(): array => array( 'created' => true, 'post_id' => 42 )
			)
		);

		return $registry;
	}

	private function executor( ?PlanVerifierInterface $verifier, ?SiteBuildAuditorInterface $auditor = null ): PlanExecutor {
		return new PlanExecutor(
			$this->plans,
			new ToolDispatcher(
				$this->registry(),
				new ArgumentsValidator(),
				FakeCapabilityChecker::allowingEverything(),
				new InMemoryAuditLog(),
				ToolDefinition::RISK_DESTRUCTIVE
			),
			$this->collector(),
			$verifier,
			$auditor,
			null,
			static fn(): int => SiteBuilderFixtures::NOW + 100
		);
	}

	private function verifier( bool $passes, string $detail = 'x' ): PlanVerifierInterface {
		return new class( $passes, $detail ) implements PlanVerifierInterface {
			public function __construct( private bool $passes, private string $detail ) {
			}

			public function verify( PlanAction $action, ActionState $state, int $userId ): VerificationResult {
				return $this->passes
					? VerificationResult::passed( $this->detail, array( 'want' => 1 ), array( 'got' => 1 ) )
					: VerificationResult::failed( $this->detail, array( 'want' => 4 ), array( 'got' => 0 ) );
			}
		};
	}

	public function test_a_step_whose_verification_fails_is_marked_failed_even_though_the_handler_succeeded(): void {
		// The exact real-world shape: create_menu returned success while
		// producing an empty menu.
		$record = SiteBuilderFixtures::approvedRecord( array( SiteBuilderFixtures::action( 'a1', 1 ) ) );
		$this->plans->save( $record );

		$after = $this->executor( $this->verifier( false, 'menu ma 0 pozycji, oczekiwano 4' ) )
			->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( ActionState::FAILED, $after->state( 'a1' )->status );
		$this->assertSame( PlanStatus::FAILED, $after->status );
		$this->assertStringContainsString( 'oczekiwano 4', (string) $after->state( 'a1' )->error );
	}

	public function test_a_failed_verification_stops_the_plan_and_later_steps_never_run(): void {
		$record = SiteBuilderFixtures::approvedRecord(
			array(
				SiteBuilderFixtures::action( 'a1', 1 ),
				SiteBuilderFixtures::action( 'a2', 2 ),
			)
		);
		$this->plans->save( $record );

		$after = $this->executor( $this->verifier( false ) )->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( ActionState::PENDING, $after->state( 'a2' )->status );
		$this->assertSame( 0, $after->progress()['succeeded'] );
	}

	public function test_verification_evidence_is_recorded_for_audit(): void {
		$record = SiteBuilderFixtures::approvedRecord( array( SiteBuilderFixtures::action( 'a1', 1 ) ) );
		$this->plans->save( $record );

		$after = $this->executor( $this->verifier( false, 'brak pozycji' ) )
			->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$state = $after->state( 'a1' );

		$this->assertSame( 'failed', $state->verificationStatus );
		$this->assertSame( array( 'want' => 4 ), $state->verificationExpected );
		$this->assertSame( array( 'got' => 0 ), $state->verificationActual );
		$this->assertNotNull( $state->verifiedAt );
	}

	public function test_a_passing_verification_lets_the_step_succeed(): void {
		$record = SiteBuilderFixtures::approvedRecord( array( SiteBuilderFixtures::action( 'a1', 1 ) ) );
		$this->plans->save( $record );

		$after = $this->executor( $this->verifier( true ) )->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( ActionState::SUCCEEDED, $after->state( 'a1' )->status );
		$this->assertSame( 'passed', $after->state( 'a1' )->verificationStatus );
	}

	public function test_a_step_declaring_no_verification_cannot_succeed_in_site_builder_mode(): void {
		// Fail-safe direction: a write nobody can check must not be green.
		$record = SiteBuilderFixtures::approvedRecord(
			array( SiteBuilderFixtures::action( 'a1', 1, array( 'verification' => null ) ) )
		);
		$this->plans->save( $record );

		$after = $this->executor( $this->verifier( true ) )->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( ActionState::FAILED, $after->state( 'a1' )->status );
		$this->assertSame( 'unverifiable', $after->state( 'a1' )->verificationStatus );
	}

	public function test_with_no_verifier_wired_at_all_the_executor_behaves_as_before(): void {
		// The domain executor is also used outside Site Builder mode, where
		// per-step verification is not part of the contract.
		$record = SiteBuilderFixtures::approvedRecord( array( SiteBuilderFixtures::action( 'a1', 1 ) ) );
		$this->plans->save( $record );

		$after = $this->executor( null )->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( ActionState::SUCCEEDED, $after->state( 'a1' )->status );
		$this->assertNull( $after->state( 'a1' )->verificationStatus );
	}

	// --- final audit ---------------------------------------------------

	private function auditor( bool $passes ): SiteBuildAuditorInterface {
		return new class( $passes ) implements SiteBuildAuditorInterface {
			public function __construct( private bool $passes ) {
			}

			public function audit( SiteBlueprint $blueprint, int $now ): SiteAuditReport {
				return SiteAuditReport::fromChecks(
					array(
						array( 'check' => 'pages_exist', 'passed' => $this->passes, 'detail' => 'brak strony Kontakt' ),
					),
					$now
				);
			}
		};
	}

	public function test_a_plan_whose_steps_all_passed_still_fails_if_the_final_audit_fails(): void {
		// Every step green is necessary but not sufficient: a later step can
		// undo an earlier one without any step reporting a problem.
		$record = SiteBuilderFixtures::approvedRecord( array( SiteBuilderFixtures::action( 'a1', 1 ) ) );
		$this->plans->save( $record );

		$after = $this->executor( $this->verifier( true ), $this->auditor( false ) )
			->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( ActionState::SUCCEEDED, $after->state( 'a1' )->status );
		$this->assertSame( PlanStatus::FAILED, $after->status );
		$this->assertStringContainsString( 'Kontakt', (string) $after->failureReason );
	}

	public function test_a_passing_audit_allows_the_plan_to_succeed_and_is_persisted(): void {
		$record = SiteBuilderFixtures::approvedRecord( array( SiteBuilderFixtures::action( 'a1', 1 ) ) );
		$this->plans->save( $record );

		$after = $this->executor( $this->verifier( true ), $this->auditor( true ) )
			->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( PlanStatus::SUCCEEDED, $after->status );
		$this->assertIsArray( $after->auditReport );
		$this->assertTrue( $after->auditReport['passed'] );
	}

	public function test_a_failed_audit_does_not_roll_anything_back(): void {
		// Undoing a mostly-correct site because one expectation was missed
		// would be more destructive than reporting the mismatch.
		$record = SiteBuilderFixtures::approvedRecord( array( SiteBuilderFixtures::action( 'a1', 1 ) ) );
		$this->plans->save( $record );

		$after = $this->executor( $this->verifier( true ), $this->auditor( false ) )
			->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( 0, $after->progress()['rolled_back'] );
		$this->assertSame( ActionState::SUCCEEDED, $after->state( 'a1' )->status );
	}

	public function test_the_audit_report_summarises_failures_usefully(): void {
		$report = SiteAuditReport::fromChecks(
			array(
				array( 'check' => 'pages_exist', 'passed' => true, 'detail' => 'ok' ),
				array( 'check' => 'homepage', 'passed' => false, 'detail' => 'brak strony głównej' ),
			),
			SiteBuilderFixtures::NOW
		);

		$this->assertFalse( $report->passed );
		$this->assertCount( 1, $report->failures() );
		$this->assertStringContainsString( 'brak strony głównej', $report->summary() );
	}
}

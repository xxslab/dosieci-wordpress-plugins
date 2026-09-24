<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\ActionState;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanExecutor;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanRollbackService;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanStateException;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanStatus;
use DoSieci\AiOperator\Domain\SiteBuilder\RollbackDataCollectorInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\RollbackExecutorInterface;
use DoSieci\AiOperator\Domain\Tools\ArgumentsValidator;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolDispatcher;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use DoSieci\AiOperator\Tests\Support\FakeCapabilityChecker;
use DoSieci\AiOperator\Tests\Support\InMemoryAuditLog;
use DoSieci\AiOperator\Tests\Support\InMemoryPlanRepository;
use DoSieci\AiOperator\Tests\Support\SiteBuilderFixtures;
use PHPUnit\Framework\TestCase;

final class PlanExecutorTest extends TestCase {

	private InMemoryPlanRepository $plans;
	private InMemoryAuditLog $audit;

	/** @var array<int, array{tool:string, args:array, confirmed:bool}> */
	private array $dispatched = array();

	/** @var array<string, callable> */
	private array $handlers = array();

	protected function setUp(): void {
		$this->plans      = new InMemoryPlanRepository();
		$this->audit      = new InMemoryAuditLog();
		$this->dispatched = array();
		$this->handlers   = array();
	}

	private function registry(): ToolRegistry {
		$registry = new ToolRegistry();

		foreach ( array( 'create_post', 'install_plugin', 'trash_post' ) as $name ) {
			$registry->register(
				new ToolDefinition(
					$name,
					'test tool',
					array( 'type' => 'object', 'properties' => array( 'title' => array( 'type' => 'string' ) ) ),
					'publish_pages',
					ToolDefinition::RISK_REVERSIBLE_WRITE,
					5,
					function ( array $args ) use ( $name ): array {
						$this->dispatched[] = array( 'tool' => $name, 'args' => $args );

						$handler = $this->handlers[ $name ] ?? null;

						return null !== $handler ? $handler( $args ) : array( 'created' => true, 'post_id' => 42 );
					}
				)
			);
		}

		return $registry;
	}

	private function executor(
		?RollbackDataCollectorInterface $collector = null,
		?\DoSieci\AiOperator\Domain\SiteBuilder\PlanVerifierInterface $verifier = null,
		?\DoSieci\AiOperator\Domain\SiteBuilder\SiteBuildAuditorInterface $auditor = null
	): PlanExecutor {
		$collector ??= new class implements RollbackDataCollectorInterface {
			public function capture( PlanAction $action, array $resolvedArguments = array() ): ?array {
				return array( 'captured_for' => $action->actionId );
			}
		};

		return new PlanExecutor(
			$this->plans,
			new ToolDispatcher(
				$this->registry(),
				new ArgumentsValidator(),
				FakeCapabilityChecker::allowingEverything(),
				$this->audit,
				ToolDefinition::RISK_DESTRUCTIVE
			),
			$collector,
			$verifier,
			$auditor,
			null,
			static fn(): int => SiteBuilderFixtures::NOW + 100
		);
	}

	public function test_one_call_executes_one_step_and_leaves_the_rest_pending(): void {
		// Resumability is the whole design: a forty-step plan must not try
		// to run inside one PHP request.
		$record = SiteBuilderFixtures::approvedRecord();
		$this->plans->save( $record );

		$after = $this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertCount( 1, $this->dispatched );
		$this->assertSame( ActionState::SUCCEEDED, $after->state( 'a1' )->status );
		$this->assertSame( ActionState::PENDING, $after->state( 'a2' )->status );
		$this->assertSame( PlanStatus::RUNNING, $after->status );
	}

	public function test_successive_calls_resume_where_the_previous_one_stopped(): void {
		$record = SiteBuilderFixtures::approvedRecord();
		$this->plans->save( $record );

		$executor = $this->executor();
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
		$after = $executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertCount( 2, $this->dispatched );
		$this->assertSame( ActionState::SUCCEEDED, $after->state( 'a2' )->status );
	}

	public function test_the_plan_succeeds_only_once_every_step_has_run(): void {
		$record = SiteBuilderFixtures::approvedRecord();
		$this->plans->save( $record );

		$executor = $this->executor();
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
		$after = $executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		// The plan settles in the same call that ran its final step.
		$this->assertSame( PlanStatus::SUCCEEDED, $after->status );
		$this->assertCount( 2, $this->dispatched );

		// And a finished plan refuses to run again -- re-posting the
		// execute request must not duplicate its writes.
		$this->expectException( PlanStateException::class );
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
	}

	public function test_each_step_is_dispatched_with_exactly_the_approved_arguments(): void {
		$record = SiteBuilderFixtures::approvedRecord();
		$this->plans->save( $record );

		$executor = $this->executor();
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( array( 'title' => 'Start' ), $this->dispatched[0]['args'] );
		$this->assertSame( array( 'title' => 'Oferta' ), $this->dispatched[1]['args'] );
	}

	public function test_a_failing_step_stops_the_run_and_leaves_later_steps_untouched(): void {
		$this->handlers['create_post'] = static function ( array $args ): array {
			if ( 'Oferta' === ( $args['title'] ?? '' ) ) {
				throw new \RuntimeException( 'wp_insert_post failed' );
			}

			return array( 'created' => true );
		};

		$record = SiteBuilderFixtures::approvedRecord(
			array(
				SiteBuilderFixtures::action( 'a1', 1 ),
				SiteBuilderFixtures::action( 'a2', 2, array( 'arguments' => array( 'title' => 'Oferta' ) ) ),
				SiteBuilderFixtures::action( 'a3', 3, array( 'arguments' => array( 'title' => 'Kontakt' ) ) ),
			)
		);
		$this->plans->save( $record );

		$executor = $this->executor();
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
		$after = $executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( PlanStatus::FAILED, $after->status );
		$this->assertSame( ActionState::SUCCEEDED, $after->state( 'a1' )->status );
		$this->assertSame( ActionState::FAILED, $after->state( 'a2' )->status );
		// The critical property: step 3 was never attempted.
		$this->assertSame( ActionState::PENDING, $after->state( 'a3' )->status );
		$this->assertSame( 3, $after->progress()['total'] );
		$this->assertSame( 1, $after->progress()['succeeded'] );
	}

	public function test_a_handler_returning_success_false_is_treated_as_a_failed_step(): void {
		// WriteToolFactory returns array('success' => false, ...) for a
		// predictable refusal rather than throwing. A plan that marched past
		// those would report green while having changed nothing.
		$this->handlers['create_post'] = static fn(): array => array(
			'success' => false,
			'error'   => 'Strona główna musi być opublikowana.',
		);

		$record = SiteBuilderFixtures::approvedRecord( array( SiteBuilderFixtures::action( 'a1', 1 ) ) );
		$this->plans->save( $record );

		$after = $this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( PlanStatus::FAILED, $after->status );
		$this->assertSame( ActionState::FAILED, $after->state( 'a1' )->status );
		$this->assertStringContainsString( 'opublikowana', (string) $after->state( 'a1' )->error );
	}

	public function test_rollback_data_is_captured_before_the_step_runs(): void {
		$record = SiteBuilderFixtures::approvedRecord( array( SiteBuilderFixtures::action( 'a1', 1 ) ) );
		$this->plans->save( $record );

		$after = $this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( array( 'captured_for' => 'a1' ), $after->state( 'a1' )->rollbackData );
	}

	public function test_another_user_cannot_execute_the_plan(): void {
		$this->plans->save( SiteBuilderFixtures::approvedRecord() );

		$this->expectException( PlanStateException::class );
		$this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID + 1 );
	}

	public function test_an_unapproved_plan_cannot_be_executed(): void {
		$record         = new \DoSieci\AiOperator\Domain\SiteBuilder\PlanRecord( SiteBuilderFixtures::plan() );
		$record->status = PlanStatus::AWAITING_APPROVAL;
		$this->plans->save( $record );

		$this->expectException( PlanStateException::class );
		$this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
	}

	public function test_a_cancelled_plan_stops_executing_and_reports_partial_progress(): void {
		$record = SiteBuilderFixtures::approvedRecord();
		$this->plans->save( $record );

		$executor = $this->executor();
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
		$cancelled = $executor->cancel( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( PlanStatus::CANCELLED, $cancelled->status );
		$this->assertSame( 1, $cancelled->progress()['succeeded'] );
		$this->assertSame( 1, $cancelled->progress()['pending'] );

		$this->expectException( PlanStateException::class );
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
	}

	public function test_cancel_does_not_undo_anything_by_itself(): void {
		// Stopping is not the same as destroying six steps of work the user
		// may want to keep.
		$record = SiteBuilderFixtures::approvedRecord();
		$this->plans->save( $record );

		$executor = $this->executor();
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
		$cancelled = $executor->cancel( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( ActionState::SUCCEEDED, $cancelled->state( 'a1' )->status );
		$this->assertSame( 0, $cancelled->progress()['rolled_back'] );
	}

	public function test_pause_stops_execution_until_resume(): void {
		$record = SiteBuilderFixtures::approvedRecord();
		$this->plans->save( $record );

		$executor = $this->executor();
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
		$executor->pause( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		try {
			$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
			$this->fail( 'A paused plan must not execute.' );
		} catch ( PlanStateException ) {
			$this->assertCount( 1, $this->dispatched );
		}

		$executor->resume( 'plan-1', SiteBuilderFixtures::OWNER_ID );
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertCount( 2, $this->dispatched );
	}

	public function test_rollback_undoes_succeeded_steps_newest_first(): void {
		$record = SiteBuilderFixtures::approvedRecord();
		$this->plans->save( $record );

		$executor = $this->executor();
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$undone   = array();
		$rollback = new PlanRollbackService(
			$this->plans,
			new class( $undone ) implements RollbackExecutorInterface {
				/** @param array<int, string> $undone */
				public function __construct( private array &$undone ) {
				}

				public function undo( PlanAction $action, ActionState $state, int $userId ): bool {
					$this->undone[] = $action->actionId;

					return true;
				}
			}
		);

		$after = $rollback->rollback( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( PlanStatus::ROLLED_BACK, $after->status );
		$this->assertSame( array( 'a2', 'a1' ), $undone );
		$this->assertSame( ActionState::ROLLED_BACK, $after->state( 'a1' )->status );
	}

	public function test_a_partly_failing_rollback_reports_partially_rolled_back(): void {
		$record = SiteBuilderFixtures::approvedRecord();
		$this->plans->save( $record );

		$executor = $this->executor();
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );
		$executor->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$rollback = new PlanRollbackService(
			$this->plans,
			new class implements RollbackExecutorInterface {
				public function undo( PlanAction $action, ActionState $state, int $userId ): bool {
					return 'a1' !== $action->actionId;
				}
			}
		);

		$after = $rollback->rollback( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( PlanStatus::PARTIALLY_ROLLED_BACK, $after->status );
		$this->assertSame( ActionState::ROLLED_BACK, $after->state( 'a2' )->status );
		$this->assertSame( ActionState::ROLLBACK_FAILED, $after->state( 'a1' )->status );
	}

	public function test_another_user_cannot_roll_back_someone_elses_plan(): void {
		$record         = SiteBuilderFixtures::approvedRecord();
		$record->status = PlanStatus::SUCCEEDED;
		$this->plans->save( $record );

		$rollback = new PlanRollbackService(
			$this->plans,
			new class implements RollbackExecutorInterface {
				public function undo( PlanAction $action, ActionState $state, int $userId ): bool {
					return true;
				}
			}
		);

		$this->expectException( PlanStateException::class );
		$rollback->rollback( 'plan-1', SiteBuilderFixtures::OWNER_ID + 1 );
	}

	public function test_a_non_reversible_step_is_never_rolled_back(): void {
		$record = SiteBuilderFixtures::approvedRecord(
			array(
				SiteBuilderFixtures::action( 'a1', 1, array( 'rollback_strategy' => PlanAction::ROLLBACK_NONE ) ),
			)
		);
		$this->plans->save( $record );

		$this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$attempted = 0;
		$rollback  = new PlanRollbackService(
			$this->plans,
			new class( $attempted ) implements RollbackExecutorInterface {
				public function __construct( private int &$attempted ) {
				}

				public function undo( PlanAction $action, ActionState $state, int $userId ): bool {
					++$this->attempted;

					return true;
				}
			}
		);

		$after = $rollback->rollback( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$this->assertSame( 0, $attempted );
		$this->assertSame( PlanStatus::ROLLED_BACK, $after->status );
	}
}

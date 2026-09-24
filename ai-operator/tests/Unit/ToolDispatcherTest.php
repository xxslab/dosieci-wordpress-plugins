<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\Audit\AuditEntry;
use DoSieci\AiOperator\Domain\Tools\ArgumentsValidator;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolDispatcher;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use DoSieci\AiOperator\Tests\Support\FakeCapabilityChecker;
use DoSieci\AiOperator\Tests\Support\InMemoryAuditLog;
use PHPUnit\Framework\TestCase;

/**
 * The security gate order in ToolDispatcher is the single most important
 * piece of logic in this plugin, so each gate gets its own test AND each
 * denial is asserted to have produced an audit record.
 */
final class ToolDispatcherTest extends TestCase {

	private InMemoryAuditLog $audit;

	protected function setUp(): void {
		$this->audit = new InMemoryAuditLog();
	}

	private function registryWith( ToolDefinition ...$tools ): ToolRegistry {
		$registry = new ToolRegistry();
		foreach ( $tools as $tool ) {
			$registry->register( $tool );
		}

		return $registry;
	}

	private function readOnlyTool( string $name = 'get_site_info', string $capability = 'manage_options', ?callable $handler = null ): ToolDefinition {
		return new ToolDefinition(
			$name,
			'test tool',
			array( 'type' => 'object', 'properties' => array( 'limit' => array( 'type' => 'integer' ) ) ),
			$capability,
			ToolDefinition::RISK_READ_ONLY,
			5,
			$handler ?? static fn( array $args ): array => array( 'ok' => true, 'args' => $args )
		);
	}

	private function dispatcher( ToolRegistry $registry, FakeCapabilityChecker $capabilities, string $maxRisk = ToolDefinition::RISK_READ_ONLY ): ToolDispatcher {
		return new ToolDispatcher( $registry, new ArgumentsValidator(), $capabilities, $this->audit, $maxRisk );
	}

	public function test_a_permitted_read_only_tool_runs_and_is_audited_as_allowed(): void {
		$dispatcher = $this->dispatcher(
			$this->registryWith( $this->readOnlyTool() ),
			new FakeCapabilityChecker( array( 'manage_options' ) )
		);

		$outcome = $dispatcher->dispatch( 'get_site_info', array( 'limit' => 5 ), 7, 'req-1' );

		$this->assertFalse( $outcome->isError );
		$this->assertSame( array( 'ok' => true, 'args' => array( 'limit' => 5 ) ), $outcome->data );
		$this->assertSame( AuditEntry::OUTCOME_ALLOWED, $this->audit->last()->outcome );
		$this->assertSame( 7, $this->audit->last()->userId );
		$this->assertSame( 'req-1', $this->audit->last()->requestId );
	}

	public function test_an_unregistered_tool_is_refused(): void {
		$dispatcher = $this->dispatcher( $this->registryWith(), FakeCapabilityChecker::allowingEverything() );

		$outcome = $dispatcher->dispatch( 'execute_php', array(), 1, 'req-2' );

		$this->assertTrue( $outcome->isError );
		$this->assertSame( 'unknown_tool', $outcome->reasonCode );
		$this->assertSame( AuditEntry::OUTCOME_DENIED, $this->audit->last()->outcome );
	}

	public function test_arguments_that_do_not_match_the_schema_are_refused_before_the_handler_runs(): void {
		$handlerRan = false;
		$tool       = $this->readOnlyTool(
			'get_site_info',
			'manage_options',
			static function ( array $args ) use ( &$handlerRan ): array {
				$handlerRan = true;

				return array();
			}
		);

		$dispatcher = $this->dispatcher( $this->registryWith( $tool ), FakeCapabilityChecker::allowingEverything() );

		$outcome = $dispatcher->dispatch( 'get_site_info', array( 'limit' => 'not-an-integer' ), 1, 'req-3' );

		$this->assertTrue( $outcome->isError );
		$this->assertSame( 'invalid_arguments', $outcome->reasonCode );
		$this->assertFalse( $handlerRan, 'The handler must never see arguments that failed validation.' );
	}

	public function test_an_unknown_argument_is_refused_rather_than_silently_dropped(): void {
		$dispatcher = $this->dispatcher(
			$this->registryWith( $this->readOnlyTool() ),
			FakeCapabilityChecker::allowingEverything()
		);

		$outcome = $dispatcher->dispatch( 'get_site_info', array( 'sneaky' => 'value' ), 1, 'req-4' );

		$this->assertTrue( $outcome->isError );
		$this->assertSame( 'invalid_arguments', $outcome->reasonCode );
	}

	public function test_a_user_without_the_required_capability_is_refused(): void {
		$dispatcher = $this->dispatcher(
			$this->registryWith( $this->readOnlyTool( 'inspect_plugins', 'activate_plugins' ) ),
			new FakeCapabilityChecker( array( 'read' ) )
		);

		$outcome = $dispatcher->dispatch( 'inspect_plugins', array(), 3, 'req-5' );

		$this->assertTrue( $outcome->isError );
		$this->assertSame( 'missing_capability', $outcome->reasonCode );
		$this->assertSame( AuditEntry::OUTCOME_DENIED, $this->audit->last()->outcome );
	}

	public function test_a_tool_above_the_installations_max_risk_level_is_refused(): void {
		$writeTool = new ToolDefinition(
			'update_post',
			'writes',
			array( 'type' => 'object', 'properties' => array() ),
			'edit_posts',
			ToolDefinition::RISK_REVERSIBLE_WRITE,
			5,
			static fn(): array => array( 'written' => true )
		);

		$dispatcher = $this->dispatcher( $this->registryWith( $writeTool ), FakeCapabilityChecker::allowingEverything() );

		$outcome = $dispatcher->dispatch( 'update_post', array(), 1, 'req-6' );

		$this->assertTrue( $outcome->isError );
		$this->assertSame( 'risk_level_not_enabled', $outcome->reasonCode );
	}

	public function test_a_write_tool_still_requires_per_action_confirmation_even_when_its_risk_level_is_enabled(): void {
		$writeTool = new ToolDefinition(
			'update_post',
			'writes',
			array( 'type' => 'object', 'properties' => array() ),
			'edit_posts',
			ToolDefinition::RISK_REVERSIBLE_WRITE,
			5,
			static fn(): array => array( 'written' => true )
		);

		$dispatcher = $this->dispatcher(
			$this->registryWith( $writeTool ),
			FakeCapabilityChecker::allowingEverything(),
			ToolDefinition::RISK_REVERSIBLE_WRITE
		);

		$unconfirmed = $dispatcher->dispatch( 'update_post', array(), 1, 'req-7' );
		$this->assertTrue( $unconfirmed->isError );
		$this->assertSame( 'confirmation_required', $unconfirmed->reasonCode );

		$confirmed = $dispatcher->dispatch( 'update_post', array(), 1, 'req-8', true );
		$this->assertFalse( $confirmed->isError );
	}

	public function test_a_handler_that_throws_produces_a_visible_audited_failure(): void {
		$tool = $this->readOnlyTool(
			'get_site_info',
			'manage_options',
			static function (): array {
				throw new \RuntimeException( 'database exploded' );
			}
		);

		$dispatcher = $this->dispatcher( $this->registryWith( $tool ), FakeCapabilityChecker::allowingEverything() );

		$outcome = $dispatcher->dispatch( 'get_site_info', array(), 1, 'req-9' );

		$this->assertTrue( $outcome->isError );
		$this->assertSame( 'tool_failed', $outcome->reasonCode );
		$this->assertSame( AuditEntry::OUTCOME_FAILED, $this->audit->last()->outcome );
	}

	public function test_every_dispatch_produces_exactly_one_audit_record(): void {
		$dispatcher = $this->dispatcher(
			$this->registryWith( $this->readOnlyTool() ),
			new FakeCapabilityChecker( array( 'manage_options' ) )
		);

		$dispatcher->dispatch( 'get_site_info', array(), 1, 'a' );
		$dispatcher->dispatch( 'nope', array(), 1, 'b' );
		$dispatcher->dispatch( 'get_site_info', array( 'bad' => 1 ), 1, 'c' );

		$this->assertCount( 3, $this->audit->entries );
	}

	public function test_error_outcomes_serialise_to_a_machine_readable_tool_result(): void {
		$dispatcher = $this->dispatcher( $this->registryWith(), FakeCapabilityChecker::allowingEverything() );

		$outcome = $dispatcher->dispatch( 'shell_exec', array(), 1, 'req-10' );
		$decoded = json_decode( $outcome->toResultContent(), true );

		$this->assertSame( 'unknown_tool', $decoded['error'] );
	}
}

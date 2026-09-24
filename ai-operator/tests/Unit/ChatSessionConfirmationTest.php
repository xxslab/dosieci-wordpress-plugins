<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\ChatSession;
use DoSieci\AiOperator\Domain\Connection;
use DoSieci\AiOperator\Domain\Gateway\HubGateway;
use DoSieci\AiOperator\Domain\HubClient;
use DoSieci\AiOperator\Domain\Signing\RequestSigner;
use DoSieci\AiOperator\Domain\Tools\ArgumentsValidator;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolDispatcher;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use DoSieci\AiOperator\Tests\Support\FakeCapabilityChecker;
use DoSieci\AiOperator\Tests\Support\FakeTransport;
use DoSieci\AiOperator\Tests\Support\InMemoryAuditLog;
use PHPUnit\Framework\TestCase;

/**
 * The human-in-the-loop gate for write tools.
 *
 * The property under test is that a write NEVER runs as a side effect of
 * the model asking for it. The loop must stop, hand control back, and only
 * touch the site after a separate, deliberate approval carrying the id of
 * the exact action the human was shown.
 */
final class ChatSessionConfirmationTest extends TestCase {

	private InMemoryAuditLog $audit;

	/** @var array<int, array<string, mixed>> */
	private array $executed = array();

	protected function setUp(): void {
		$this->audit    = new InMemoryAuditLog();
		$this->executed = array();
	}

	private function registry(): ToolRegistry {
		$registry = new ToolRegistry();

		$registry->register(
			new ToolDefinition(
				'get_site_info',
				'Read-only.',
				array( 'type' => 'object', 'properties' => array() ),
				'manage_options',
				ToolDefinition::RISK_READ_ONLY,
				5,
				static fn(): array => array( 'name' => 'Test Shop' )
			)
		);

		$registry->register(
			new ToolDefinition(
				'create_post',
				'Tworzy stronę.',
				array(
					'type'       => 'object',
					'properties' => array( 'title' => array( 'type' => 'string' ) ),
					'required'   => array( 'title' ),
				),
				'publish_pages',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				5,
				function ( array $args ): array {
					$this->executed[] = $args;

					return array( 'created' => true, 'post_id' => 7 );
				}
			)
		);

		return $registry;
	}

	private function session( FakeTransport $transport ): ChatSession {
		$registry = $this->registry();

		return new ChatSession(
			new HubGateway(
				new HubClient( $transport, new RequestSigner() ),
				new Connection( 'https://license.dosieci.pl', 'site-1', 'lic_key', 'secret', 123 )
			),
			new ToolDispatcher(
				$registry,
				new ArgumentsValidator(),
				FakeCapabilityChecker::allowingEverything(),
				$this->audit,
				ToolDefinition::RISK_DESTRUCTIVE
			),
			$registry
		);
	}

	private static function response( array $payload, int $status = 200 ): array {
		return array( 'status' => $status, 'body' => (string) json_encode( $payload ) );
	}

	private static function writeRequest(): array {
		return self::response(
			array(
				'type'        => 'tool_call',
				'tool_name'   => 'create_post',
				'tool_use_id' => 'toolu_write',
				'arguments'   => array( 'title' => 'O nas' ),
			)
		);
	}

	public function test_a_write_pauses_the_turn_instead_of_running(): void {
		$transport = new FakeTransport( array( self::writeRequest() ) );

		$result = $this->session( $transport )->send( 'zrób stronę o nas', array(), 1 );

		$this->assertSame( 'pending_confirmation', $result['status'] );
		$this->assertSame( 'create_post', $result['pending']['tool_name'] );
		$this->assertSame( 'toolu_write', $result['pending']['tool_use_id'] );
		$this->assertSame( array( 'title' => 'O nas' ), $result['pending']['arguments'] );
		$this->assertSame( array(), $this->executed, 'The handler must not have run.' );
	}

	/**
	 * The paused conversation carries the model's tool_use turn but no
	 * result yet -- that is what lets resume() complete it in either
	 * direction without the provider rejecting the next call.
	 */
	public function test_the_paused_conversation_ends_with_an_unanswered_tool_use(): void {
		$transport = new FakeTransport( array( self::writeRequest() ) );

		$result = $this->session( $transport )->send( 'zrób stronę', array(), 1 );

		$last = end( $result['conversation'] );

		$this->assertSame( 'assistant_tool_use', $last['role'] );
		$this->assertSame( 'toolu_write', $last['tool_use_id'] );
	}

	public function test_a_read_only_tool_never_pauses(): void {
		$transport = new FakeTransport(
			array(
				self::response(
					array(
						'type'        => 'tool_call',
						'tool_name'   => 'get_site_info',
						'tool_use_id' => 'toolu_read',
						'arguments'   => array(),
					)
				),
				self::response( array( 'type' => 'final_answer', 'answer' => 'Test Shop.' ) ),
			)
		);

		$result = $this->session( $transport )->send( 'jak się nazywa witryna?', array(), 1 );

		$this->assertSame( 'answer', $result['status'] );
	}

	public function test_approving_runs_the_tool_and_continues_the_turn(): void {
		$paused = $this->session( new FakeTransport( array( self::writeRequest() ) ) )
			->send( 'zrób stronę', array(), 1 );

		$resumeTransport = new FakeTransport(
			array( self::response( array( 'type' => 'final_answer', 'answer' => 'Utworzyłem stronę.' ) ) )
		);

		$result = $this->session( $resumeTransport )->resume(
			$paused['conversation'],
			'create_post',
			'toolu_write',
			array( 'title' => 'O nas' ),
			true,
			1
		);

		$this->assertSame( 'answer', $result['status'] );
		$this->assertSame( 'Utworzyłem stronę.', $result['answer'] );
		$this->assertCount( 1, $this->executed );
		$this->assertSame( array( 'title' => 'O nas' ), $this->executed[0] );
	}

	public function test_rejecting_does_not_run_the_tool_but_still_tells_the_model(): void {
		$paused = $this->session( new FakeTransport( array( self::writeRequest() ) ) )
			->send( 'zrób stronę', array(), 1 );

		$resumeTransport = new FakeTransport(
			array( self::response( array( 'type' => 'final_answer', 'answer' => 'Rozumiem, nie zmieniam nic.' ) ) )
		);

		$result = $this->session( $resumeTransport )->resume(
			$paused['conversation'],
			'create_post',
			'toolu_write',
			array( 'title' => 'O nas' ),
			false,
			1
		);

		$this->assertSame( 'answer', $result['status'] );
		$this->assertSame( array(), $this->executed );

		// The model must be told, or the dangling tool_use makes the next
		// provider call invalid.
		$sent         = json_decode( $resumeTransport->requests[0]['body'], true );
		$conversation = $sent['conversation'];
		$lastTurn     = $conversation[ count( $conversation ) - 1 ];

		$this->assertSame( 'tool_result', $lastTurn['role'] );
		$this->assertSame( 'toolu_write', $lastTurn['tool_use_id'] );
		$this->assertTrue( $lastTurn['is_error'] );
		$this->assertStringContainsString( 'rejected_by_user', $lastTurn['content'] );
	}

	/**
	 * The confirmation flag is scoped to the single dispatch it was given
	 * for. If it leaked into the rest of the turn, one approval would
	 * silently authorise every subsequent write the model asked for.
	 */
	public function test_an_approval_does_not_authorise_a_later_write_in_the_same_turn(): void {
		$paused = $this->session( new FakeTransport( array( self::writeRequest() ) ) )
			->send( 'zrób stronę', array(), 1 );

		$resumeTransport = new FakeTransport(
			array(
				self::response(
					array(
						'type'        => 'tool_call',
						'tool_name'   => 'create_post',
						'tool_use_id' => 'toolu_second',
						'arguments'   => array( 'title' => 'Kontakt' ),
					)
				),
			)
		);

		$result = $this->session( $resumeTransport )->resume(
			$paused['conversation'],
			'create_post',
			'toolu_write',
			array( 'title' => 'O nas' ),
			true,
			1
		);

		$this->assertSame( 'pending_confirmation', $result['status'] );
		$this->assertSame( 'toolu_second', $result['pending']['tool_use_id'] );
		$this->assertCount( 1, $this->executed, 'Only the approved write ran.' );
	}

	/**
	 * An unknown tool must reach the dispatcher, which is the single place
	 * that refuses it AND audits the refusal. Short-circuiting it into a
	 * confirmation prompt would ask the user to approve something that does
	 * not exist, and would leave no audit record.
	 */
	public function test_an_unknown_tool_is_refused_and_audited_rather_than_queued_for_confirmation(): void {
		$transport = new FakeTransport(
			array(
				self::response(
					array(
						'type'        => 'tool_call',
						'tool_name'   => 'drop_database',
						'tool_use_id' => 'toolu_evil',
						'arguments'   => array(),
					)
				),
				self::response( array( 'type' => 'final_answer', 'answer' => 'Nie mam takiego narzędzia.' ) ),
			)
		);

		$result = $this->session( $transport )->send( 'usuń bazę', array(), 1 );

		$this->assertSame( 'answer', $result['status'] );
		$this->assertSame( 'unknown_tool', $result['tool_calls'][0]['outcome'] );
		$this->assertNotSame( array(), $this->audit->entries );
	}
}

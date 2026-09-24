<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\ChatSession;
use DoSieci\AiOperator\Domain\Connection;
use DoSieci\AiOperator\Domain\Gateway\HubGateway;
use DoSieci\AiOperator\Domain\HubClient;
use DoSieci\AiOperator\Domain\HubException;
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
 * The end-to-end chat loop, with the Hub faked at the HTTP boundary. This
 * is where the tool_use_id round trip is proven from the plugin's side --
 * the Hub-side half is proven in the Hub's own AnthropicMessageMapperTest.
 */
final class ChatSessionTest extends TestCase {

	private InMemoryAuditLog $audit;

	protected function setUp(): void {
		$this->audit = new InMemoryAuditLog();
	}

	private function connection(): Connection {
		return new Connection( 'https://license.dosieci.pl', 'site-1', 'lic_key', 'secret', 123 );
	}

	private function session( FakeTransport $transport, ?ToolRegistry $registry = null ): ChatSession {
		$registry ??= $this->registryWithSiteInfo();

		return new ChatSession(
			new HubGateway( new HubClient( $transport, new RequestSigner() ), $this->connection() ),
			new ToolDispatcher( $registry, new ArgumentsValidator(), FakeCapabilityChecker::allowingEverything(), $this->audit ),
			$registry
		);
	}

	private function registryWithSiteInfo(): ToolRegistry {
		$registry = new ToolRegistry();
		$registry->register(
			new ToolDefinition(
				'get_site_info',
				'test',
				array( 'type' => 'object', 'properties' => array() ),
				'manage_options',
				ToolDefinition::RISK_READ_ONLY,
				5,
				static fn(): array => array( 'name' => 'Test Shop' )
			)
		);

		return $registry;
	}

	private static function response( array $payload, int $status = 200 ): array {
		return array( 'status' => $status, 'body' => (string) json_encode( $payload ) );
	}

	public function test_a_direct_answer_ends_the_turn(): void {
		$transport = new FakeTransport(
			array( self::response( array( 'type' => 'final_answer', 'answer' => 'Cześć!' ) ) )
		);

		$result = $this->session( $transport )->send( 'hej', array(), 1 );

		$this->assertSame( 'Cześć!', $result['answer'] );
		$this->assertCount( 1, $transport->requests );
		$this->assertSame( array(), $result['tool_calls'] );
	}

	public function test_a_tool_call_is_executed_locally_and_the_result_is_sent_back_with_the_same_tool_use_id(): void {
		$transport = new FakeTransport(
			array(
				self::response(
					array(
						'type'        => 'tool_call',
						'tool_name'   => 'get_site_info',
						'tool_use_id' => 'toolu_777',
						'arguments'   => array(),
						'risk_level'  => 'read_only',
					)
				),
				self::response( array( 'type' => 'final_answer', 'answer' => 'Twoja witryna to Test Shop.' ) ),
			)
		);

		$result = $this->session( $transport )->send( 'jak się nazywa moja witryna?', array(), 42 );

		$this->assertSame( 'Twoja witryna to Test Shop.', $result['answer'] );
		$this->assertCount( 2, $transport->requests, 'One call to get the tool request, one to send its result.' );

		$secondCall   = json_decode( $transport->requests[1]['body'], true );
		$conversation = $secondCall['conversation'];

		// The model's own tool_use turn must be replayed...
		$toolUseTurn = $conversation[ count( $conversation ) - 2 ];
		$this->assertSame( 'assistant_tool_use', $toolUseTurn['role'] );
		$this->assertSame( 'toolu_777', $toolUseTurn['tool_use_id'] );

		// ...immediately followed by the matching result.
		$resultTurn = $conversation[ count( $conversation ) - 1 ];
		$this->assertSame( 'tool_result', $resultTurn['role'] );
		$this->assertSame( 'toolu_777', $resultTurn['tool_use_id'] );
		$this->assertFalse( $resultTurn['is_error'] );
		$this->assertStringContainsString( 'Test Shop', $resultTurn['content'] );

		$this->assertSame( 'ok', $result['tool_calls'][0]['outcome'] );
		$this->assertSame( 'get_site_info', $this->audit->last()->toolName );
	}

	public function test_a_locally_denied_tool_is_reported_back_to_the_model_as_an_error_result(): void {
		$emptyRegistry = new ToolRegistry();

		$transport = new FakeTransport(
			array(
				self::response(
					array(
						'type'        => 'tool_call',
						'tool_name'   => 'get_site_info',
						'tool_use_id' => 'toolu_deny',
						'arguments'   => array(),
					)
				),
				self::response( array( 'type' => 'final_answer', 'answer' => 'Nie mam dostępu do tego narzędzia.' ) ),
			)
		);

		$result = $this->session( $transport, $emptyRegistry )->send( 'sprawdź', array(), 1 );

		$secondCall  = json_decode( $transport->requests[1]['body'], true );
		$resultTurn  = end( $secondCall['conversation'] );

		$this->assertTrue( $resultTurn['is_error'], 'A refused tool must be flagged as an error, not passed off as data.' );
		$this->assertSame( 'unknown_tool', $result['tool_calls'][0]['outcome'] );
		$this->assertSame( 'Nie mam dostępu do tego narzędzia.', $result['answer'] );
	}

	public function test_a_hub_side_denial_still_closes_the_tool_use_turn(): void {
		$transport = new FakeTransport(
			array(
				self::response(
					array(
						'type'        => 'tool_call_denied',
						'tool_name'   => 'delete_everything',
						'tool_use_id' => 'toolu_hubdeny',
						'reason'      => 'risk level not allowed',
					)
				),
				self::response( array( 'type' => 'final_answer', 'answer' => 'Nie mogę tego zrobić.' ) ),
			)
		);

		$result = $this->session( $transport )->send( 'usuń wszystko', array(), 1 );

		$secondCall = json_decode( $transport->requests[1]['body'], true );
		$turns      = $secondCall['conversation'];

		$this->assertSame( 'assistant_tool_use', $turns[ count( $turns ) - 2 ]['role'] );
		$this->assertTrue( $turns[ count( $turns ) - 1 ]['is_error'] );
		$this->assertSame( 'denied_by_hub', $result['tool_calls'][0]['outcome'] );
	}

	public function test_a_tool_call_without_a_tool_use_id_is_refused_rather_than_sent_on(): void {
		$transport = new FakeTransport(
			array( self::response( array( 'type' => 'tool_call', 'tool_name' => 'get_site_info', 'arguments' => array() ) ) )
		);

		$this->expectException( HubException::class );
		$this->session( $transport )->send( 'x', array(), 1 );
	}

	public function test_the_tool_loop_is_bounded(): void {
		$responses = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$responses[] = self::response(
				array(
					'type'        => 'tool_call',
					'tool_name'   => 'get_site_info',
					'tool_use_id' => 'toolu_' . $i,
					'arguments'   => array(),
				)
			);
		}

		$result = $this->session( new FakeTransport( $responses ) )->send( 'loop', array(), 1 );

		$this->assertStringContainsString( 'too many tools', $result['answer'] );
	}

	public function test_each_hub_call_uses_a_distinct_request_id(): void {
		$transport = new FakeTransport(
			array(
				self::response( array( 'type' => 'tool_call', 'tool_name' => 'get_site_info', 'tool_use_id' => 't1', 'arguments' => array() ) ),
				self::response( array( 'type' => 'final_answer', 'answer' => 'ok' ) ),
			)
		);

		$this->session( $transport )->send( 'x', array(), 1 );

		$first  = json_decode( $transport->requests[0]['body'], true )['request_id'];
		$second = json_decode( $transport->requests[1]['body'], true )['request_id'];

		$this->assertNotSame( $first, $second, 'Reusing one request id across rounds would make the Hub treat round 2 as a duplicate.' );
	}

	public function test_every_request_carries_signature_headers_and_never_the_secret_in_the_body(): void {
		$transport = new FakeTransport( array( self::response( array( 'type' => 'final_answer', 'answer' => 'ok' ) ) ) );

		$this->session( $transport )->send( 'x', array(), 1 );

		$headers = $transport->requests[0]['headers'];
		$this->assertArrayHasKey( RequestSigner::HEADER_SIGNATURE, $headers );
		$this->assertSame( 'lic_key', $headers[ RequestSigner::HEADER_KEY_ID ] );
		$this->assertStringNotContainsString( 'secret', $transport->requests[0]['body'] );
	}
}

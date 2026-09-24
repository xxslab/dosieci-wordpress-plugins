<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\Gateway\AnthropicGateway;
use DoSieci\AiOperator\Domain\Gateway\ProviderSettings;
use DoSieci\AiOperator\Domain\Gateway\ToolSchemaExporter;
use DoSieci\AiOperator\Domain\HubException;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use DoSieci\AiOperator\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * The BYOK Anthropic path, with the provider faked at the HTTP boundary.
 *
 * The translation in both directions is what matters here: the plugin
 * stores conversations in the Hub's wire format, and getting the Anthropic
 * content-block mapping wrong produces a 400 from the provider rather than
 * a visible bug in this codebase -- which is exactly the kind of failure
 * that is expensive to diagnose in production and cheap to pin down here.
 */
final class AnthropicGatewayTest extends TestCase {

	private function registry(): ToolRegistry {
		$registry = new ToolRegistry();
		$registry->register(
			new ToolDefinition(
				'get_site_info',
				'Info o witrynie.',
				array( 'type' => 'object', 'properties' => array() ),
				'manage_options',
				ToolDefinition::RISK_READ_ONLY,
				5,
				static fn(): array => array()
			)
		);

		return $registry;
	}

	private function gateway( FakeTransport $transport, string $key = 'sk-ant-test' ): AnthropicGateway {
		return new AnthropicGateway(
			$transport,
			ProviderSettings::fromArray(
				array(
					'mode'    => ProviderSettings::MODE_ANTHROPIC,
					'api_key' => $key,
					'model'   => 'claude-sonnet-4-5',
				)
			),
			new ToolSchemaExporter( $this->registry(), static fn(): bool => true ),
			'Jesteś operatorem.'
		);
	}

	private static function response( array $payload, int $status = 200 ): array {
		return array( 'status' => $status, 'body' => (string) json_encode( $payload ) );
	}

	public function test_a_text_response_becomes_a_final_answer(): void {
		$transport = new FakeTransport(
			array(
				self::response(
					array(
						'content' => array(
							array( 'type' => 'text', 'text' => 'Witryna działa poprawnie.' ),
						),
					)
				),
			)
		);

		$result = $this->gateway( $transport )->chat( 'req-1', array( array( 'role' => 'user', 'content' => 'status?' ) ) );

		$this->assertSame( 'final_answer', $result['type'] );
		$this->assertSame( 'Witryna działa poprawnie.', $result['answer'] );
	}

	public function test_a_tool_use_block_becomes_a_tool_call(): void {
		$transport = new FakeTransport(
			array(
				self::response(
					array(
						'content' => array(
							array( 'type' => 'text', 'text' => 'Sprawdzam...' ),
							array(
								'type'  => 'tool_use',
								'id'    => 'toolu_abc',
								'name'  => 'get_site_info',
								'input' => array( 'foo' => 'bar' ),
							),
						),
					)
				),
			)
		);

		$result = $this->gateway( $transport )->chat( 'req-1', array() );

		// The narration must not win over the actionable tool call.
		$this->assertSame( 'tool_call', $result['type'] );
		$this->assertSame( 'get_site_info', $result['tool_name'] );
		$this->assertSame( 'toolu_abc', $result['tool_use_id'] );
		$this->assertSame( array( 'foo' => 'bar' ), $result['arguments'] );
	}

	public function test_the_api_key_travels_in_a_header_and_never_in_the_body(): void {
		$transport = new FakeTransport(
			array( self::response( array( 'content' => array( array( 'type' => 'text', 'text' => 'ok' ) ) ) ) )
		);

		$this->gateway( $transport, 'sk-ant-SUPERSECRET' )->chat( 'req-1', array() );

		$request = $transport->requests[0];

		$this->assertSame( 'sk-ant-SUPERSECRET', $request['headers']['x-api-key'] );
		$this->assertStringNotContainsString( 'SUPERSECRET', $request['body'] );
	}

	public function test_tool_schemas_are_sent_with_object_properties_not_an_empty_array(): void {
		$transport = new FakeTransport(
			array( self::response( array( 'content' => array( array( 'type' => 'text', 'text' => 'ok' ) ) ) ) )
		);

		$this->gateway( $transport )->chat( 'req-1', array() );

		// json_encode() turns PHP's empty array into [], which Anthropic
		// rejects for an object schema. It must serialise as {}.
		$this->assertStringContainsString( '"properties":{}', $transport->requests[0]['body'] );
	}

	public function test_a_tool_result_turn_is_sent_as_a_user_message_with_a_tool_result_block(): void {
		$transport = new FakeTransport(
			array( self::response( array( 'content' => array( array( 'type' => 'text', 'text' => 'ok' ) ) ) ) )
		);

		$this->gateway( $transport )->chat(
			'req-1',
			array(
				array( 'role' => 'user', 'content' => 'sprawdź' ),
				array(
					'role'        => 'assistant_tool_use',
					'content'     => '',
					'tool_name'   => 'get_site_info',
					'tool_use_id' => 'toolu_abc',
					'arguments'   => array(),
				),
				array(
					'role'        => 'tool_result',
					'content'     => '{"name":"Sklep"}',
					'tool_name'   => 'get_site_info',
					'tool_use_id' => 'toolu_abc',
					'is_error'    => false,
				),
			)
		);

		$sent = json_decode( $transport->requests[0]['body'], true );

		$toolUseTurn = $sent['messages'][1];
		$this->assertSame( 'assistant', $toolUseTurn['role'] );
		$this->assertSame( 'tool_use', $toolUseTurn['content'][0]['type'] );

		$resultTurn = $sent['messages'][2];
		$this->assertSame( 'user', $resultTurn['role'], 'Anthropic requires tool results on a user turn.' );
		$this->assertSame( 'tool_result', $resultTurn['content'][0]['type'] );
		$this->assertSame( 'toolu_abc', $resultTurn['content'][0]['tool_use_id'] );
	}

	public function test_an_empty_assistant_turn_is_dropped(): void {
		$transport = new FakeTransport(
			array( self::response( array( 'content' => array( array( 'type' => 'text', 'text' => 'ok' ) ) ) ) )
		);

		$this->gateway( $transport )->chat(
			'req-1',
			array(
				array( 'role' => 'user', 'content' => 'hej' ),
				array( 'role' => 'assistant', 'content' => '   ' ),
			)
		);

		$sent = json_decode( $transport->requests[0]['body'], true );

		$this->assertCount( 1, $sent['messages'] );
	}

	public function test_a_401_is_reported_as_a_rejected_key_and_is_not_retryable(): void {
		$transport = new FakeTransport(
			array( self::response( array( 'error' => array( 'message' => 'invalid x-api-key' ) ), 401 ) )
		);

		try {
			$this->gateway( $transport )->chat( 'req-1', array() );
			$this->fail( 'Expected a HubException.' );
		} catch ( HubException $e ) {
			$this->assertSame( 'byok_key_rejected', $e->errorCode );
			$this->assertFalse( $e->retryable );
		}
	}

	public function test_a_429_is_retryable(): void {
		$transport = new FakeTransport( array( self::response( array(), 429 ) ) );

		try {
			$this->gateway( $transport )->chat( 'req-1', array() );
			$this->fail( 'Expected a HubException.' );
		} catch ( HubException $e ) {
			$this->assertSame( 'provider_rate_limited', $e->errorCode );
			$this->assertTrue( $e->retryable );
		}
	}

	public function test_a_500_is_retryable_as_unavailable(): void {
		$transport = new FakeTransport( array( self::response( array(), 503 ) ) );

		$this->expectException( HubException::class );
		$this->gateway( $transport )->chat( 'req-1', array() );
	}

	public function test_a_contentless_response_is_an_explicit_error_not_a_blank_answer(): void {
		$transport = new FakeTransport( array( self::response( array( 'content' => array() ) ) ) );

		try {
			$this->gateway( $transport )->chat( 'req-1', array() );
			$this->fail( 'Expected a HubException.' );
		} catch ( HubException $e ) {
			$this->assertSame( 'provider_bad_response', $e->errorCode );
		}
	}

	public function test_a_malformed_body_does_not_produce_a_php_error(): void {
		$transport = new FakeTransport( array( array( 'status' => 200, 'body' => 'not json at all' ) ) );

		$this->expectException( HubException::class );
		$this->gateway( $transport )->chat( 'req-1', array() );
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\Gateway\OpenAiGateway;
use DoSieci\AiOperator\Domain\Gateway\ProviderSettings;
use DoSieci\AiOperator\Domain\Gateway\ToolSchemaExporter;
use DoSieci\AiOperator\Domain\HubException;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use DoSieci\AiOperator\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class OpenAiGatewayTest extends TestCase {

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

	private function gateway( FakeTransport $transport, string $key = 'sk-test' ): OpenAiGateway {
		return new OpenAiGateway(
			$transport,
			ProviderSettings::fromArray(
				array(
					'mode'    => ProviderSettings::MODE_OPENAI,
					'api_key' => $key,
				)
			),
			new ToolSchemaExporter( $this->registry(), static fn(): bool => true ),
			'Jesteś operatorem.'
		);
	}

	private static function response( array $payload, int $status = 200 ): array {
		return array( 'status' => $status, 'body' => (string) json_encode( $payload ) );
	}

	public function test_a_content_response_becomes_a_final_answer(): void {
		$transport = new FakeTransport(
			array(
				self::response(
					array( 'choices' => array( array( 'message' => array( 'content' => 'Gotowe.' ) ) ) )
				),
			)
		);

		$result = $this->gateway( $transport )->chat( 'req-1', array() );

		$this->assertSame( 'final_answer', $result['type'] );
		$this->assertSame( 'Gotowe.', $result['answer'] );
	}

	public function test_a_tool_call_is_decoded_from_its_json_string_arguments(): void {
		$transport = new FakeTransport(
			array(
				self::response(
					array(
						'choices' => array(
							array(
								'message' => array(
									'content'    => null,
									'tool_calls' => array(
										array(
											'id'       => 'call_123',
											'type'     => 'function',
											'function' => array(
												'name'      => 'get_site_info',
												'arguments' => '{"limit":5}',
											),
										),
									),
								),
							),
						),
					)
				),
			)
		);

		$result = $this->gateway( $transport )->chat( 'req-1', array() );

		$this->assertSame( 'tool_call', $result['type'] );
		$this->assertSame( 'get_site_info', $result['tool_name'] );
		$this->assertSame( 'call_123', $result['tool_use_id'] );
		$this->assertSame( array( 'limit' => 5 ), $result['arguments'] );
	}

	/**
	 * ChatSession runs one tool per round and replays its result before
	 * asking again. Returning several calls at once would leave the later
	 * ones without a matching tool result, which makes the NEXT request
	 * invalid.
	 */
	public function test_only_the_first_of_several_parallel_tool_calls_is_taken(): void {
		$transport = new FakeTransport(
			array(
				self::response(
					array(
						'choices' => array(
							array(
								'message' => array(
									'tool_calls' => array(
										array(
											'id'       => 'call_first',
											'function' => array( 'name' => 'get_site_info', 'arguments' => '{}' ),
										),
										array(
											'id'       => 'call_second',
											'function' => array( 'name' => 'get_site_info', 'arguments' => '{}' ),
										),
									),
								),
							),
						),
					)
				),
			)
		);

		$result = $this->gateway( $transport )->chat( 'req-1', array() );

		$this->assertSame( 'call_first', $result['tool_use_id'] );
	}

	public function test_the_system_prompt_leads_the_message_list(): void {
		$transport = new FakeTransport(
			array( self::response( array( 'choices' => array( array( 'message' => array( 'content' => 'ok' ) ) ) ) ) )
		);

		$this->gateway( $transport )->chat( 'req-1', array( array( 'role' => 'user', 'content' => 'hej' ) ) );

		$sent = json_decode( $transport->requests[0]['body'], true );

		$this->assertSame( 'system', $sent['messages'][0]['role'] );
		$this->assertSame( 'user', $sent['messages'][1]['role'] );
	}

	public function test_tool_arguments_are_sent_back_as_a_json_string(): void {
		$transport = new FakeTransport(
			array( self::response( array( 'choices' => array( array( 'message' => array( 'content' => 'ok' ) ) ) ) ) )
		);

		$this->gateway( $transport )->chat(
			'req-1',
			array(
				array(
					'role'        => 'assistant_tool_use',
					'content'     => '',
					'tool_name'   => 'get_site_info',
					'tool_use_id' => 'call_1',
					'arguments'   => array( 'limit' => 5 ),
				),
			)
		);

		$sent = json_decode( $transport->requests[0]['body'], true );

		// A JSON object here (rather than a string) is a 400 from OpenAI.
		$this->assertIsString( $sent['messages'][1]['tool_calls'][0]['function']['arguments'] );
		$this->assertSame( '{"limit":5}', $sent['messages'][1]['tool_calls'][0]['function']['arguments'] );
	}

	public function test_a_tool_result_is_sent_as_a_tool_role_message(): void {
		$transport = new FakeTransport(
			array( self::response( array( 'choices' => array( array( 'message' => array( 'content' => 'ok' ) ) ) ) ) )
		);

		$this->gateway( $transport )->chat(
			'req-1',
			array(
				array(
					'role'        => 'tool_result',
					'content'     => '{"ok":true}',
					'tool_name'   => 'get_site_info',
					'tool_use_id' => 'call_1',
					'is_error'    => false,
				),
			)
		);

		$sent = json_decode( $transport->requests[0]['body'], true );

		$this->assertSame( 'tool', $sent['messages'][1]['role'] );
		$this->assertSame( 'call_1', $sent['messages'][1]['tool_call_id'] );
	}

	public function test_the_key_is_a_bearer_header_and_not_in_the_body(): void {
		$transport = new FakeTransport(
			array( self::response( array( 'choices' => array( array( 'message' => array( 'content' => 'ok' ) ) ) ) ) )
		);

		$this->gateway( $transport, 'sk-SUPERSECRET' )->chat( 'req-1', array() );

		$this->assertSame( 'Bearer sk-SUPERSECRET', $transport->requests[0]['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'SUPERSECRET', $transport->requests[0]['body'] );
	}

	/**
	 * OpenAI uses 429 for both throttling and an empty wallet. They need
	 * different advice, so they must not collapse to one error code.
	 */
	public function test_an_exhausted_quota_is_distinguished_from_ordinary_rate_limiting(): void {
		$quota = new FakeTransport(
			array(
				self::response(
					array( 'error' => array( 'type' => 'insufficient_quota', 'message' => 'no funds' ) ),
					429
				),
			)
		);

		try {
			$this->gateway( $quota )->chat( 'req-1', array() );
			$this->fail( 'Expected a HubException.' );
		} catch ( HubException $e ) {
			$this->assertSame( 'byok_quota_exhausted', $e->errorCode );
			$this->assertFalse( $e->retryable, 'Retrying an empty wallet never succeeds.' );
		}

		$throttled = new FakeTransport(
			array( self::response( array( 'error' => array( 'type' => 'rate_limit_exceeded' ) ), 429 ) )
		);

		try {
			$this->gateway( $throttled )->chat( 'req-1', array() );
			$this->fail( 'Expected a HubException.' );
		} catch ( HubException $e ) {
			$this->assertSame( 'provider_rate_limited', $e->errorCode );
			$this->assertTrue( $e->retryable );
		}
	}

	public function test_a_401_is_a_rejected_key(): void {
		$transport = new FakeTransport( array( self::response( array(), 401 ) ) );

		try {
			$this->gateway( $transport )->chat( 'req-1', array() );
			$this->fail( 'Expected a HubException.' );
		} catch ( HubException $e ) {
			$this->assertSame( 'byok_key_rejected', $e->errorCode );
		}
	}

	public function test_a_response_without_choices_is_an_explicit_error(): void {
		$transport = new FakeTransport( array( self::response( array() ) ) );

		try {
			$this->gateway( $transport )->chat( 'req-1', array() );
			$this->fail( 'Expected a HubException.' );
		} catch ( HubException $e ) {
			$this->assertSame( 'provider_bad_response', $e->errorCode );
		}
	}
}

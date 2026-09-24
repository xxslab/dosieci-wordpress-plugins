<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Gateway;

use DoSieci\AiOperator\Domain\HttpTransportInterface;
use DoSieci\AiOperator\Domain\HubException;

/**
 * BYOK path for OpenAI, against the Chat Completions API.
 *
 * Mirrors AnthropicGateway's contract exactly -- same wire format in, same
 * wire format out, same stable error codes -- so ChatSession and the chat
 * UI cannot tell which provider answered. The differences are entirely in
 * the translation layer, and they are real: OpenAI carries tool calls in a
 * `tool_calls` array on the assistant message with the arguments as a JSON
 * *string*, and expects results back as a dedicated `tool` role keyed by
 * tool_call_id, where Anthropic uses typed content blocks inside user and
 * assistant turns.
 */
final class OpenAiGateway implements ChatGatewayInterface {

	private const API_URL = 'https://api.openai.com/v1/chat/completions';

	public function __construct(
		private HttpTransportInterface $transport,
		private ProviderSettings $settings,
		private ToolSchemaExporter $tools,
		private string $systemPrompt
	) {
	}

	public function label(): string {
		/* translators: %s: model identifier, e.g. gpt-5 */
		return sprintf( __( 'OpenAI, your own API key (%s)', 'dosieci-ai-operator' ), $this->settings->effectiveModel() );
	}

	public function chat( string $requestId, array $conversation ): array {
		$payload = array(
			'model'                 => $this->settings->effectiveModel(),
			// Not max_tokens: reasoning models (the gpt-5 and o families)
			// reject it with a 400, and max_completion_tokens works on
			// every current chat model.
			'max_completion_tokens' => $this->settings->maxTokens,
			'messages'              => $this->toOpenAiMessages( $conversation ),
		);

		$toolSchemas = $this->tools->forOpenAi();
		if ( array() !== $toolSchemas ) {
			$payload['tools'] = $toolSchemas;

			// One tool call per response -- see toWireFormat() for why a
			// parallel batch cannot be honoured.
			$payload['parallel_tool_calls'] = false;
		}

		$response = $this->transport->post(
			self::API_URL,
			array(
				'Authorization' => 'Bearer ' . $this->settings->apiKey,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			(string) json_encode( $payload ),
			$this->settings->timeoutSeconds
		);

		$decoded = json_decode( $response['body'], true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			$this->throwProviderError( $response['status'], $decoded );
		}

		return $this->toWireFormat( $decoded );
	}

	/**
	 * @param array<int, array<string, mixed>> $conversation
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function toOpenAiMessages( array $conversation ): array {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->systemPrompt,
			),
		);

		foreach ( $conversation as $entry ) {
			$role    = isset( $entry['role'] ) ? (string) $entry['role'] : '';
			$content = isset( $entry['content'] ) ? (string) $entry['content'] : '';

			switch ( $role ) {
				case 'user':
					$messages[] = array(
						'role'    => 'user',
						'content' => $content,
					);
					break;

				case 'assistant':
					if ( '' !== trim( $content ) ) {
						$messages[] = array(
							'role'    => 'assistant',
							'content' => $content,
						);
					}
					break;

				case 'assistant_tool_use':
					$messages[] = array(
						'role'       => 'assistant',
						'content'    => null,
						'tool_calls' => array(
							array(
								'id'       => isset( $entry['tool_use_id'] ) ? (string) $entry['tool_use_id'] : '',
								'type'     => 'function',
								'function' => array(
									'name' => isset( $entry['tool_name'] ) ? (string) $entry['tool_name'] : '',
									// OpenAI expects the arguments as a JSON
									// string, not an object -- sending an
									// object here is a 400.
									'arguments' => (string) json_encode(
										isset( $entry['arguments'] ) && is_array( $entry['arguments'] ) ? $entry['arguments'] : array()
									),
								),
							),
						),
					);
					break;

				case 'tool_result':
					$messages[] = array(
						'role'         => 'tool',
						'tool_call_id' => isset( $entry['tool_use_id'] ) ? (string) $entry['tool_use_id'] : '',
						'content'      => $content,
					);
					break;
			}
		}

		return $messages;
	}

	/**
	 * @param array<string, mixed> $decoded
	 *
	 * @return array<string, mixed>
	 */
	private function toWireFormat( array $decoded ): array {
		$message = $decoded['choices'][0]['message'] ?? null;

		if ( ! is_array( $message ) ) {
			throw new HubException(
				'OpenAI returned a response with no message.',
				502,
				'provider_bad_response',
				false
			);
		}

		$toolCalls = isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ? $message['tool_calls'] : array();

		// Only the first tool call is taken. ChatSession runs one tool per
		// round and replays the result before asking again, so a parallel
		// batch would leave the later calls with no matching tool_result
		// and make the NEXT request invalid. One at a time is slower and
		// correct.
		foreach ( $toolCalls as $call ) {
			if ( ! is_array( $call ) ) {
				continue;
			}

			$name = $call['function']['name'] ?? '';
			$raw  = $call['function']['arguments'] ?? '{}';

			$arguments = json_decode( is_string( $raw ) ? $raw : '{}', true );

			return array(
				'type'        => 'tool_call',
				'tool_name'   => (string) $name,
				'tool_use_id' => isset( $call['id'] ) ? (string) $call['id'] : '',
				'arguments'   => is_array( $arguments ) ? $arguments : array(),
			);
		}

		$content = isset( $message['content'] ) ? (string) $message['content'] : '';

		if ( '' === trim( $content ) ) {
			// A reasoning model can spend the whole token budget thinking
			// and return nothing. That needs different advice from a
			// genuinely broken response.
			if ( 'length' === ( $decoded['choices'][0]['finish_reason'] ?? '' ) ) {
				throw new HubException(
					'OpenAI used up the token limit before answering.',
					502,
					'provider_output_truncated',
					false
				);
			}

			throw new HubException(
				'OpenAI returned an empty answer.',
				502,
				'provider_bad_response',
				false
			);
		}

		return array(
			'type'   => 'final_answer',
			'answer' => $content,
		);
	}

	/**
	 * @param array<string, mixed> $decoded
	 *
	 * @throws HubException always
	 */
	private function throwProviderError( int $status, array $decoded ): never {
		$providerMessage = '';
		if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
			$providerMessage = $decoded['error']['message'];
		}

		// OpenAI returns 429 both for rate limiting and for an exhausted
		// billing quota. They need different advice ("wait" vs "top up"),
		// and the only signal distinguishing them is the error type.
		$type = isset( $decoded['error']['type'] ) ? (string) $decoded['error']['type'] : '';

		[ $code, $retryable ] = match ( true ) {
			401 === $status || 403 === $status         => array( 'byok_key_rejected', false ),
			429 === $status && 'insufficient_quota' === $type => array( 'byok_quota_exhausted', false ),
			429 === $status                            => array( 'provider_rate_limited', true ),
			$status >= 500                             => array( 'provider_unavailable', true ),
			400 === $status                            => array( 'provider_bad_request', false ),
			default                                    => array( 'provider_bad_response', false ),
		};

		throw new HubException(
			sprintf( 'OpenAI HTTP %d: %s', (int) $status, esc_html( $providerMessage ) ),
			(int) $status,
			esc_html( $code ),
			(bool) $retryable
		);
	}
}

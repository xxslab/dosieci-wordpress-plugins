<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Gateway;

use DoSieci\AiOperator\Domain\HttpTransportInterface;
use DoSieci\AiOperator\Domain\HubException;

/**
 * BYOK path for Anthropic: the site calls api.anthropic.com directly with
 * the owner's own key. No DoSieci credits are consumed and the Hub is not
 * involved in the chat turn at all.
 *
 * Responsibilities beyond "send an HTTP request":
 *
 *  - Translate the Hub wire format both ways. The stored conversation
 *    format is the Hub's (so a site can switch between hosted and BYOK
 *    mid-conversation without its history becoming unreadable), and
 *    Anthropic's content-block format is different enough that this
 *    mapping is the bulk of the class.
 *
 *  - Map every documented failure to a HubException with a stable error
 *    code, so AjaxController's existing humanise() switch keeps working
 *    unchanged in BYOK mode. A 401 from Anthropic must not surface to the
 *    user as a generic "communication error" -- it means their key is
 *    wrong, and only this class knows that.
 *
 * The API key is sent in a header and never logged, never returned in any
 * response shape, and never written into the conversation history.
 */
final class AnthropicGateway implements ChatGatewayInterface {

	private const API_URL = 'https://api.anthropic.com/v1/messages';
	private const VERSION = '2023-06-01';

	public function __construct(
		private HttpTransportInterface $transport,
		private ProviderSettings $settings,
		private ToolSchemaExporter $tools,
		private string $systemPrompt
	) {
	}

	public function label(): string {
		/* translators: %s: model identifier, e.g. claude-sonnet-5 */
		return sprintf( __( 'Anthropic, your own API key (%s)', 'dosieci-ai-operator' ), $this->settings->effectiveModel() );
	}

	public function chat( string $requestId, array $conversation ): array {
		$payload = array(
			'model'      => $this->settings->effectiveModel(),
			'max_tokens' => $this->settings->maxTokens,
			'system'     => $this->systemPrompt,
			'messages'   => $this->toAnthropicMessages( $conversation ),
		);

		$toolSchemas = $this->tools->forAnthropic();
		if ( array() !== $toolSchemas ) {
			$payload['tools'] = $toolSchemas;

			// One tool per response: ChatSession runs a single tool per
			// round and replays its result, so a second tool_use block in
			// the same response would be dropped (see toWireFormat()).
			$payload['tool_choice'] = array(
				'type'                      => 'auto',
				'disable_parallel_tool_use' => true,
			);
		}

		$response = $this->transport->post(
			self::API_URL,
			array(
				'x-api-key'         => $this->settings->apiKey,
				'anthropic-version' => self::VERSION,
				'Content-Type'      => 'application/json',
				'Accept'            => 'application/json',
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
	private function toAnthropicMessages( array $conversation ): array {
		$messages = array();

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
					// An empty assistant turn is dropped rather than sent:
					// Anthropic rejects a message with empty content, and a
					// blank final answer carries no information anyway.
					if ( '' !== trim( $content ) ) {
						$messages[] = array(
							'role'    => 'assistant',
							'content' => $content,
						);
					}
					break;

				case 'assistant_tool_use':
					$messages[] = array(
						'role'    => 'assistant',
						'content' => array(
							array(
								'type'  => 'tool_use',
								'id'    => isset( $entry['tool_use_id'] ) ? (string) $entry['tool_use_id'] : '',
								'name'  => isset( $entry['tool_name'] ) ? (string) $entry['tool_name'] : '',
								'input' => isset( $entry['arguments'] ) && is_array( $entry['arguments'] )
									? (object) $entry['arguments']
									: new \stdClass(),
							),
						),
					);
					break;

				case 'tool_result':
					// Anthropic requires tool results to arrive as a USER
					// turn carrying tool_result blocks -- not as an
					// assistant turn, and not as prose.
					$messages[] = array(
						'role'    => 'user',
						'content' => array(
							array(
								'type'        => 'tool_result',
								'tool_use_id' => isset( $entry['tool_use_id'] ) ? (string) $entry['tool_use_id'] : '',
								'content'     => $content,
								'is_error'    => (bool) ( $entry['is_error'] ?? false ),
							),
						),
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
		$blocks = isset( $decoded['content'] ) && is_array( $decoded['content'] ) ? $decoded['content'] : array();

		// A tool_use block wins over any accompanying text: the model may
		// narrate ("let me check that for you") in the same response, but
		// the actionable instruction is the tool call, and returning the
		// narration as a final answer would silently drop the tool.
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || 'tool_use' !== ( $block['type'] ?? '' ) ) {
				continue;
			}

			return array(
				'type'        => 'tool_call',
				'tool_name'   => isset( $block['name'] ) ? (string) $block['name'] : '',
				'tool_use_id' => isset( $block['id'] ) ? (string) $block['id'] : '',
				'arguments'   => isset( $block['input'] ) && is_array( $block['input'] ) ? $block['input'] : array(),
			);
		}

		$text = '';
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}

		if ( '' === trim( $text ) ) {
			throw new HubException(
				'Anthropic returned a response with no text.',
				502,
				'provider_bad_response',
				false
			);
		}

		return array(
			'type'   => 'final_answer',
			'answer' => $text,
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

		[ $code, $retryable ] = match ( true ) {
			401 === $status || 403 === $status => array( 'byok_key_rejected', false ),
			429 === $status                    => array( 'provider_rate_limited', true ),
			$status >= 500                     => array( 'provider_unavailable', true ),
			400 === $status                    => array( 'provider_bad_request', false ),
			default                            => array( 'provider_bad_response', false ),
		};

		throw new HubException(
			sprintf( 'Anthropic HTTP %d: %s', (int) $status, esc_html( $providerMessage ) ),
			(int) $status,
			esc_html( $code ),
			(bool) $retryable
		);
	}
}

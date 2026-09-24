<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress;

use DoSieci\AiOperator\Domain\Gateway\ChatGatewayInterface;
use DoSieci\AiOperator\Domain\Gateway\ToolSchemaExporter;
use DoSieci\AiOperator\Domain\HubException;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * BYOK through the AI client built into WordPress 7.0+.
 *
 * The site owner connects a provider once, in Settings > Connectors
 * (OpenAI, Anthropic, Google or any provider plugin), and WordPress stores
 * the key. This gateway never sees that key: it hands the conversation and
 * the tool declarations to wp_ai_client_prompt() and translates the result
 * back into the Hub wire format ChatSession speaks, exactly like the direct
 * Anthropic and OpenAI gateways do.
 *
 * The SDK classes used here exist only on WordPress 7.0+. The class is
 * only ever constructed after isAvailable() said yes (see Plugin::gateway()),
 * so loading this file on an older WordPress is harmless.
 */
final class WpAiClientGateway implements ChatGatewayInterface {

	public function __construct(
		private ToolSchemaExporter $tools,
		private string $systemPrompt,
		private string $modelPreference = '',
		private int $maxTokens = 8192
	) {
	}

	/**
	 * WordPress has the AI client and has not switched AI features off.
	 */
	public static function isAvailable(): bool {
		return function_exists( 'wp_ai_client_prompt' )
			&& function_exists( 'wp_supports_ai' )
			&& wp_supports_ai();
	}

	/**
	 * AI connectors that have both their provider plugin installed and a
	 * key (from the database, a constant or an environment variable) --
	 * the same sources WordPress itself reads.
	 *
	 * @return array<string, string> connector id => display name
	 */
	public static function connectedProviders(): array {
		if ( ! self::isAvailable() || ! function_exists( 'wp_get_connectors' ) || ! class_exists( AiClient::class ) ) {
			return array();
		}

		$registry  = AiClient::defaultRegistry();
		$connected = array();

		foreach ( wp_get_connectors() as $id => $connector ) {
			if ( 'ai_provider' !== ( $connector['type'] ?? '' ) || ! $registry->hasProvider( (string) $id ) ) {
				continue;
			}

			$auth = is_array( $connector['authentication'] ?? null ) ? $connector['authentication'] : array();

			if ( 'none' === ( $auth['method'] ?? '' ) || self::hasKey( $auth ) ) {
				$connected[ (string) $id ] = (string) ( $connector['name'] ?? $id );
			}
		}

		return $connected;
	}

	public static function isConfigured(): bool {
		return array() !== self::connectedProviders();
	}

	/**
	 * @param array<string, mixed> $auth
	 */
	private static function hasKey( array $auth ): bool {
		$envVar = (string) ( $auth['env_var_name'] ?? '' );
		if ( '' !== $envVar && '' !== (string) getenv( $envVar ) ) {
			return true;
		}

		$constant = (string) ( $auth['constant_name'] ?? '' );
		if ( '' !== $constant && defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return true;
		}

		$setting = (string) ( $auth['setting_name'] ?? '' );

		return '' !== $setting && '' !== (string) get_option( $setting, '' );
	}

	public function label(): string {
		return __( 'WordPress AI connectors', 'dosieci-ai-operator' );
	}

	public function chat( string $requestId, array $conversation ): array {
		$builder = wp_ai_client_prompt( $this->toMessages( $conversation ) )
			->using_system_instruction( $this->systemPrompt )
			->using_max_tokens( $this->maxTokens );

		$declarations = array_map(
			static fn( array $tool ): FunctionDeclaration => new FunctionDeclaration( $tool['name'], $tool['description'], $tool['parameters'] ),
			$this->tools->forFunctionDeclarations()
		);

		if ( array() !== $declarations ) {
			$builder = $builder->using_function_declarations( ...$declarations );
		}

		if ( '' !== $this->modelPreference ) {
			$builder = $builder->using_model_preference( $this->modelPreference );
		}

		$result = $builder->generate_text_result();

		if ( is_wp_error( $result ) ) {
			$this->throwFromWpError( $result );
		}

		return $this->toWireFormat( $result );
	}

	/**
	 * @param array<int, array<string, mixed>> $conversation
	 *
	 * @return list<Message>
	 */
	private function toMessages( array $conversation ): array {
		$messages = array();

		foreach ( $conversation as $entry ) {
			$role    = isset( $entry['role'] ) ? (string) $entry['role'] : '';
			$content = isset( $entry['content'] ) ? (string) $entry['content'] : '';

			switch ( $role ) {
				case 'user':
					$messages[] = new UserMessage( array( new MessagePart( $content ) ) );
					break;

				case 'assistant':
					// Providers reject empty turns; a blank answer carries
					// nothing anyway.
					if ( '' !== trim( $content ) ) {
						$messages[] = new ModelMessage( array( new MessagePart( $content ) ) );
					}
					break;

				case 'assistant_tool_use':
					$call = new FunctionCall(
						isset( $entry['tool_use_id'] ) ? (string) $entry['tool_use_id'] : null,
						isset( $entry['tool_name'] ) ? (string) $entry['tool_name'] : null,
						isset( $entry['arguments'] ) && is_array( $entry['arguments'] ) ? $entry['arguments'] : array()
					);

					$signature = $entry['provider_state']['thought_signature'] ?? null;

					$messages[] = new ModelMessage(
						array( new MessagePart( $call, null, is_string( $signature ) ? $signature : null ) )
					);
					break;

				case 'tool_result':
					$decoded = json_decode( $content, true );

					$messages[] = new UserMessage(
						array(
							new MessagePart(
								new FunctionResponse(
									isset( $entry['tool_use_id'] ) ? (string) $entry['tool_use_id'] : null,
									isset( $entry['tool_name'] ) ? (string) $entry['tool_name'] : null,
									is_array( $decoded ) ? $decoded : array( 'result' => $content )
								)
							),
						)
					);
					break;
			}
		}

		return $messages;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toWireFormat( GenerativeAiResult $result ): array {
		$candidates = $result->getCandidates();

		if ( array() === $candidates ) {
			throw new HubException( 'The WordPress AI client returned no answer.', 502, 'provider_bad_response', false );
		}

		// Providers differ in how they split one reply: the OpenAI provider
		// turns every output item (a message, each function call) into its
		// own candidate. So look across all of them.
		$parts = array();
		foreach ( $candidates as $candidate ) {
			foreach ( $candidate->getMessage()->getParts() as $part ) {
				$parts[] = $part;
			}
		}

		// A function call wins over any accompanying text, and only the
		// first one is taken -- ChatSession runs one tool per round.
		foreach ( $parts as $part ) {
			if ( ! $part->getType()->isFunctionCall() || null === $part->getFunctionCall() ) {
				continue;
			}

			$call      = $part->getFunctionCall();
			$arguments = $call->getArgs();

			if ( is_string( $arguments ) ) {
				$arguments = json_decode( $arguments, true );
			}

			// Some providers (Gemini) match results by name and send no id;
			// ChatSession still needs one to pair the result with the call.
			$id = (string) $call->getId();
			if ( '' === $id ) {
				$id = 'call_' . bin2hex( random_bytes( 8 ) );
			}

			$wire = array(
				'type'        => 'tool_call',
				'tool_name'   => (string) $call->getName(),
				'tool_use_id' => $id,
				'arguments'   => is_array( $arguments ) ? $arguments : array(),
			);

			if ( null !== $part->getThoughtSignature() ) {
				$wire['provider_state'] = array( 'thought_signature' => $part->getThoughtSignature() );
			}

			return $wire;
		}

		$text = '';
		foreach ( $parts as $part ) {
			if ( $part->getChannel()->isContent() && null !== $part->getText() ) {
				$text .= $part->getText();
			}
		}

		if ( '' === trim( $text ) ) {
			if ( $candidates[0]->getFinishReason()->isLength() ) {
				throw new HubException( 'The model used up the token limit before answering.', 502, 'provider_output_truncated', false );
			}

			throw new HubException( 'The WordPress AI client returned an empty answer.', 502, 'provider_bad_response', false );
		}

		return array(
			'type'   => 'final_answer',
			'answer' => $text,
		);
	}

	/**
	 * Maps the AI client's WP_Error onto the error codes the chat screen
	 * already knows how to explain.
	 *
	 * @throws HubException always
	 */
	private function throwFromWpError( \WP_Error $error ): never {
		$code    = (string) $error->get_error_code();
		$message = $error->get_error_message();
		$data    = $error->get_error_data();
		$status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		[ $mapped, $retryable ] = match ( true ) {
			'prompt_prevented' === $code                                                       => array( 'wp_ai_unavailable', false ),
			'prompt_invalid_argument' === $code && str_starts_with( $message, 'No models found' ) => array( 'wp_ai_no_model', false ),
			'prompt_network_error' === $code || 'prompt_upstream_server_error' === $code       => array( 'provider_unavailable', true ),
			401 === $status || 403 === $status                                                 => array( 'byok_key_rejected', false ),
			429 === $status                                                                    => array( 'provider_rate_limited', true ),
			'prompt_token_limit_reached' === $code                                             => array( 'provider_output_truncated', false ),
			'prompt_client_error' === $code || 'prompt_invalid_argument' === $code             => array( 'provider_bad_request', false ),
			default                                                                            => array( 'provider_bad_response', false ),
		};

		throw new HubException(
			sprintf( 'WordPress AI client (%s): %s', esc_html( $code ), esc_html( $message ) ),
			(int) $status,
			esc_html( $mapped ),
			(bool) $retryable
		);
	}
}

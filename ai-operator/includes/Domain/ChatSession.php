<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain;

use DoSieci\AiOperator\Domain\Gateway\ChatGatewayInterface;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolDispatcher;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use DoSieci\AiOperator\Domain\Tools\UnknownToolException;

/**
 * Drives one user message to completion: send to the gateway, run any tool
 * the model asks for locally, send the result back, repeat until the model
 * gives a final answer -- or until it asks for something that needs a
 * human's explicit go-ahead.
 *
 * The plugin always initiates. Nothing reaches into this WordPress site
 * from outside (AI_OPERATOR_ARCHITECTURE.md section 10): the site pulls the
 * instruction, executes it under its own local gates, and pushes the result
 * back. That is true whether the gateway is the DoSieci Hub or a direct
 * BYOK provider call -- ChatGatewayInterface exists precisely so this loop
 * does not have to care which.
 *
 * Two ways a turn can end:
 *
 *  - 'answer'  -- the model produced a final answer.
 *  - 'pending_confirmation' -- the model asked for a WRITE, which stops the
 *    loop and hands control back to the human. The conversation is returned
 *    with the model's tool_use turn already appended but NO tool_result, so
 *    resume() can complete it in either direction. This is the one place
 *    the loop deliberately does not run to completion.
 *
 * The loop is bounded by $maxToolRounds. A model that keeps asking for
 * tools forever would otherwise hold an admin request open indefinitely and
 * burn the account's credits; hitting the bound ends the turn with an
 * honest message rather than silently truncating.
 */
final class ChatSession {

	public function __construct(
		private ChatGatewayInterface $gateway,
		private ToolDispatcher $dispatcher,
		private ToolRegistry $registry,
		private int $maxToolRounds = 8
	) {
	}

	/**
	 * @param array<int, array<string, mixed>> $priorConversation conversation so far, in wire format
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HubException|TransportException
	 */
	public function send( string $userMessage, array $priorConversation, int $userId ): array {
		$conversation   = $priorConversation;
		$conversation[] = array(
			'role'    => 'user',
			'content' => $userMessage,
		);

		return $this->runLoop( $conversation, $userId, array() );
	}

	/**
	 * Continues a turn that stopped at a confirmation prompt.
	 *
	 * $approved false is NOT the same as doing nothing: the model is told
	 * its request was refused, via a tool_result carrying an error. Leaving
	 * the tool_use turn dangling without a result would make the next
	 * provider call invalid, and silently dropping it would leave the model
	 * believing the action succeeded.
	 *
	 * @param array<int, array<string, mixed>> $conversation
	 * @param array<string, mixed>             $arguments
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HubException|TransportException
	 */
	public function resume(
		array $conversation,
		string $toolName,
		string $toolUseId,
		array $arguments,
		bool $approved,
		int $userId
	): array {
		$requestId = self::generateRequestId();

		if ( ! $approved ) {
			$conversation[] = array(
				'role'        => 'tool_result',
				'content'     => (string) json_encode(
					array(
						'error'   => 'rejected_by_user',
						'message' => 'The user rejected this change.',
					)
				),
				'tool_name'   => $toolName,
				'tool_use_id' => $toolUseId,
				'is_error'    => true,
			);

			return $this->runLoop(
				$conversation,
				$userId,
				array(
					array(
						'tool'    => $toolName,
						'outcome' => 'rejected_by_user',
						'reason'  => null,
					),
				)
			);
		}

		// The human said yes to THIS call, with THESE arguments. The
		// confirmation flag is passed for one dispatch only -- it is never
		// stored, so it cannot leak into a later tool call the user never
		// saw.
		$outcome = $this->dispatcher->dispatch( $toolName, $arguments, $userId, $requestId, true );

		$conversation[] = array(
			'role'        => 'tool_result',
			'content'     => $outcome->toResultContent(),
			'tool_name'   => $toolName,
			'tool_use_id' => $toolUseId,
			'is_error'    => $outcome->isError,
		);

		return $this->runLoop(
			$conversation,
			$userId,
			array(
				array(
					'tool'    => $toolName,
					'outcome' => $outcome->isError ? ( $outcome->reasonCode ?? 'error' ) : 'ok',
					'reason'  => $outcome->message,
				),
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $conversation
	 * @param array<int, array<string, mixed>> $toolCalls
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HubException|TransportException
	 */
	private function runLoop( array $conversation, int $userId, array $toolCalls ): array {
		for ( $round = 0; $round <= $this->maxToolRounds; $round++ ) {
			// A fresh request id per gateway call: each call is separately
			// metered, so reusing one id across rounds would make the Hub
			// treat round 2 as a duplicate of round 1.
			$requestId = self::generateRequestId();
			$response  = $this->gateway->chat( $requestId, $conversation );

			$type = isset( $response['type'] ) ? (string) $response['type'] : '';

			if ( 'final_answer' === $type ) {
				$answer         = isset( $response['answer'] ) ? (string) $response['answer'] : '';
				$conversation[] = array(
					'role'    => 'assistant',
					'content' => $answer,
				);

				return array(
					'status'       => 'answer',
					'conversation' => $conversation,
					'answer'       => $answer,
					'tool_calls'   => $toolCalls,
				);
			}

			if ( 'tool_call' !== $type && 'tool_call_denied' !== $type ) {
				throw new HubException( sprintf( 'Unexpected response type from the gateway: “%s”.', esc_html( $type ) ) );
			}

			$toolName  = isset( $response['tool_name'] ) ? (string) $response['tool_name'] : '';
			$toolUseId = isset( $response['tool_use_id'] ) ? (string) $response['tool_use_id'] : '';
			$arguments = isset( $response['arguments'] ) && is_array( $response['arguments'] ) ? $response['arguments'] : array();

			if ( '' === $toolUseId ) {
				// Without the correlation id the provider cannot pair our
				// result to its request, so continuing would produce a
				// protocol error on the next call. Fail loudly instead.
				throw new HubException( sprintf( 'The gateway asked for tool “%s” without a tool_use_id.', esc_html( $toolName ) ) );
			}

			// Replay the model's own tool_use turn, then our result -- both
			// are required for the provider to accept the next call.
			$toolUse = array(
				'role'        => 'assistant_tool_use',
				'content'     => '',
				'tool_name'   => $toolName,
				'tool_use_id' => $toolUseId,
				'arguments'   => $arguments,
			);

			// Opaque per-provider data a gateway needs back on the next
			// call (e.g. a Gemini thought signature). Carried, never read.
			if ( isset( $response['provider_state'] ) && is_array( $response['provider_state'] ) && array() !== $response['provider_state'] ) {
				$toolUse['provider_state'] = $response['provider_state'];
			}

			$conversation[] = $toolUse;

			if ( 'tool_call_denied' === $type ) {
				// The HUB refused it. We still have to tell the model,
				// otherwise the conversation is left with a dangling
				// tool_use and the next call is invalid.
				$reason         = isset( $response['reason'] ) ? (string) $response['reason'] : 'denied';
				$conversation[] = array(
					'role'        => 'tool_result',
					'content'     => (string) json_encode(
						array(
							'error'   => 'denied_by_hub',
							'message' => $reason,
						)
					),
					'tool_name'   => $toolName,
					'tool_use_id' => $toolUseId,
					'is_error'    => true,
				);

				$toolCalls[] = array(
					'tool'    => $toolName,
					'outcome' => 'denied_by_hub',
					'reason'  => $reason,
				);

				continue;
			}

			// A write stops the loop and asks the human. Returning here
			// rather than dispatching is what makes confirmation real: the
			// handler is never reached without a separate, deliberate
			// round trip through the UI.
			if ( $this->needsConfirmation( $toolName ) ) {
				return array(
					'status'       => 'pending_confirmation',
					'conversation' => $conversation,
					'answer'       => '',
					'tool_calls'   => $toolCalls,
					'pending'      => $this->describePending( $toolName, $toolUseId, $arguments ),
				);
			}

			$outcome = $this->dispatcher->dispatch( $toolName, $arguments, $userId, $requestId );

			$conversation[] = array(
				'role'        => 'tool_result',
				'content'     => $outcome->toResultContent(),
				'tool_name'   => $toolName,
				'tool_use_id' => $toolUseId,
				'is_error'    => $outcome->isError,
			);

			$toolCalls[] = array(
				'tool'    => $toolName,
				'outcome' => $outcome->isError ? ( $outcome->reasonCode ?? 'error' ) : 'ok',
				'reason'  => $outcome->message,
			);
		}

		$message        = __( 'Stopped: the model asked for too many tools in a row in one turn.', 'dosieci-ai-operator' );
		$conversation[] = array(
			'role'    => 'assistant',
			'content' => $message,
		);

		return array(
			'status'       => 'answer',
			'conversation' => $conversation,
			'answer'       => $message,
			'tool_calls'   => $toolCalls,
		);
	}

	/**
	 * An unknown tool is deliberately NOT treated as needing confirmation:
	 * it must fall through to the dispatcher, which is the single place
	 * that refuses unknown tools and audits the refusal. Short-circuiting
	 * here would produce a confirmation prompt for a tool that does not
	 * exist, and no audit record.
	 */
	private function needsConfirmation( string $toolName ): bool {
		try {
			$tool = $this->registry->get( $toolName );
		} catch ( UnknownToolException ) {
			return false;
		}

		return ToolDefinition::RISK_READ_ONLY !== $tool->riskLevel;
	}

	/**
	 * @param array<string, mixed> $arguments
	 *
	 * @return array<string, mixed>
	 */
	private function describePending( string $toolName, string $toolUseId, array $arguments ): array {
		$description = '';
		$risk        = ToolDefinition::RISK_REVERSIBLE_WRITE;

		try {
			$tool        = $this->registry->get( $toolName );
			$description = $tool->description;
			$risk        = $tool->riskLevel;
		} catch ( UnknownToolException ) {
			$description = '';
		}

		return array(
			'tool_name'   => $toolName,
			'tool_use_id' => $toolUseId,
			'arguments'   => $arguments,
			'description' => $description,
			'risk_level'  => $risk,
		);
	}

	private static function generateRequestId(): string {
		return 'wpreq_' . bin2hex( random_bytes( 12 ) );
	}
}

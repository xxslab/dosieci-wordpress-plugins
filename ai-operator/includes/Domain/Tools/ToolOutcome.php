<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Tools;

/**
 * What happened when a tool call was dispatched. Deliberately a value
 * object rather than "return the data or throw": a denied or failed tool
 * is a normal, expected outcome that must be reported back to the model as
 * a tool_result with is_error set, not an exception that aborts the chat
 * turn -- the model needs to learn that its request was refused so it can
 * try something else or explain the refusal to the user.
 */
final class ToolOutcome {

	/**
	 * @param array<string, mixed> $data
	 */
	private function __construct(
		public readonly string $toolName,
		public readonly bool $isError,
		public readonly array $data,
		public readonly ?string $reasonCode,
		public readonly ?string $message
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function success( string $toolName, array $data ): self {
		return new self( $toolName, false, $data, null, null );
	}

	public static function denied( string $toolName, string $reasonCode, string $message ): self {
		return new self( $toolName, true, array(), $reasonCode, $message );
	}

	public static function failed( string $toolName, string $message ): self {
		return new self( $toolName, true, array(), 'tool_failed', $message );
	}

	/**
	 * The string sent back to the model as the tool_result content.
	 */
	public function toResultContent(): string {
		if ( $this->isError ) {
			return (string) json_encode(
				array(
					'error'   => $this->reasonCode,
					'message' => $this->message,
				)
			);
		}

		return (string) json_encode( $this->data );
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Tools;

/**
 * A tool this WordPress installation is willing to run, with every gate
 * AI_OPERATOR_SECURITY.md section 3 requires declared up front.
 *
 * $handler is the callable that actually does the work. It receives the
 * validated arguments array and returns an array (serialised to JSON as the
 * tool result). It is only ever invoked by ToolDispatcher, and only after
 * every gate below has passed.
 */
final class ToolDefinition {

	public const RISK_READ_ONLY        = 'read_only';
	public const RISK_REVERSIBLE_WRITE = 'reversible_write';
	public const RISK_DESTRUCTIVE      = 'destructive';

	/**
	 * @param array<string, mixed> $argumentsSchema
	 * @param callable(array<string, mixed>):array<string, mixed> $handler
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly array $argumentsSchema,
		public readonly string $requiredCapability,
		public readonly string $riskLevel,
		public readonly int $timeoutSeconds,
		public readonly mixed $handler
	) {
		if ( ! in_array( $riskLevel, array( self::RISK_READ_ONLY, self::RISK_REVERSIBLE_WRITE, self::RISK_DESTRUCTIVE ), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown risk level "%s" for tool "%s".', $riskLevel, $name ) );
		}

		if ( ! is_callable( $handler ) ) {
			throw new \InvalidArgumentException( sprintf( 'Tool "%s" has no callable handler.', $name ) );
		}
	}
}

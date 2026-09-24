<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * The provider could not produce a usable blueprint.
 *
 * Carries a machine-readable code so the UI can distinguish "try again in
 * a moment" (timeout, rate limit) from "this will not work until something
 * changes" (no credentials, malformed output).
 */
final class BlueprintGenerationException extends \RuntimeException {

	public function __construct(
		string $message,
		public readonly string $errorCode = 'blueprint_generation_failed',
		public readonly bool $retryable = false
	) {
		parent::__construct( $message );
	}
}

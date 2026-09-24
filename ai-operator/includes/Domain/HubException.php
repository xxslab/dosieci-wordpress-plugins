<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain;

/**
 * The Hub answered with a non-2xx status. $errorCode is the Hub's own
 * machine-readable code (e.g. 'insufficient_credits', 'site_not_active',
 * 'provider_rate_limited') so the UI can branch on it instead of parsing
 * prose; $retryable mirrors the Hub's own hint about whether backing off
 * and retrying with the same request id could succeed.
 */
final class HubException extends \RuntimeException {

	public function __construct(
		string $message,
		public readonly int $statusCode = 0,
		public readonly string $errorCode = '',
		public readonly bool $retryable = false
	) {
		parent::__construct( $message );
	}
}

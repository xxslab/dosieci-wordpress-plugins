<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Audit;

/**
 * One immutable local audit record, written for EVERY tool-invocation
 * attempt -- allowed or denied. A denied attempt is at least as important
 * to keep as an allowed one: it is the evidence that the gates worked.
 *
 * $context is caller-supplied and MUST NOT contain the pairing secret, an
 * AI provider key, or an Authorization/X-DoSieci-Signature header value.
 * AuditLogInterface implementations additionally scrub known-sensitive
 * keys as defence in depth, but the primary rule is that call sites never
 * put a credential in here in the first place.
 */
final class AuditEntry {

	public const OUTCOME_ALLOWED = 'allowed';
	public const OUTCOME_DENIED  = 'denied';
	public const OUTCOME_FAILED  = 'failed';

	/**
	 * @param array<string, mixed> $context
	 */
	public function __construct(
		public readonly int $userId,
		public readonly string $toolName,
		public readonly string $outcome,
		public readonly ?string $reason,
		public readonly array $context,
		public readonly string $requestId,
		public readonly int $occurredAt
	) {
	}
}

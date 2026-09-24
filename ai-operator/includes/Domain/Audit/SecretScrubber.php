<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Audit;

/**
 * Last line of defence before anything is written to the audit log.
 *
 * The primary rule is that call sites never put a credential into an
 * AuditEntry's context. This exists because "never do X" enforced only by
 * convention eventually fails -- and the specific failure it guards
 * against is documented, not hypothetical: the legacy wpAIseoGen audit
 * (audit/wpAIseoGen-AUDIT.md) found the plugin writing full request and
 * response payloads, API key included, to error_log().
 *
 * Matching is on the key NAME, recursively, and is intentionally broad
 * (substring, case-insensitive): a false positive redacts a harmless field,
 * a false negative writes a credential to the database forever.
 */
final class SecretScrubber {

	private const SENSITIVE_KEY_FRAGMENTS = array(
		'secret',
		'password',
		'passwd',
		'api_key',
		'apikey',
		'token',
		'authorization',
		'signature',
		'x-dosieci-signature',
		'private_key',
		'credential',
	);

	public const REDACTED = '[redacted]';

	/**
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	public function scrub( array $context ): array {
		$scrubbed = array();

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && $this->isSensitiveKey( $key ) ) {
				$scrubbed[ $key ] = self::REDACTED;
				continue;
			}

			$scrubbed[ $key ] = is_array( $value ) ? $this->scrub( $value ) : $value;
		}

		return $scrubbed;
	}

	private function isSensitiveKey( string $key ): bool {
		$lower = strtolower( $key );

		foreach ( self::SENSITIVE_KEY_FRAGMENTS as $fragment ) {
			if ( str_contains( $lower, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}

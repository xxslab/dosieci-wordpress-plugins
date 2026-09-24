<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Signing;

/**
 * Byte-for-byte the same canonical string the Hub's verifier builds
 * (packages/request-signing's CanonicalRequest, docs/adr/0008). Duplicated
 * here rather than depended on because a WordPress plugin must not carry a
 * Composer dependency on a Hub-side package -- ADR-0001's dependency
 * isolation. The duplication is deliberate and must stay in lockstep;
 * tests/Unit/SigningInteropTest.php asserts this implementation produces
 * exactly what the Hub's implementation produces for the same inputs.
 *
 * Fields are newline-separated (not concatenated) so that e.g. nonce "AB" +
 * timestamp "12" can never collide with nonce "A" + timestamp "B12".
 */
final class CanonicalRequest {

	private function __construct() {
	}

	public static function build( string $method, string $path, string $timestamp, string $nonce, string $body ): string {
		return implode(
			"\n",
			array(
				strtoupper( $method ),
				$path,
				$timestamp,
				$nonce,
				hash( 'sha256', $body ),
			)
		);
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\Signing\CanonicalRequest;
use DoSieci\AiOperator\Domain\Signing\RequestSigner;
use PHPUnit\Framework\TestCase;

/**
 * This plugin deliberately re-implements the signing protocol rather than
 * depending on packages/request-signing (ADR-0001 -- a WordPress plugin
 * must not carry a Hub-side Composer dependency). The risk of that choice
 * is drift: if either side changes the canonical string, signatures start
 * failing in production with an unhelpful 401.
 *
 * These tests pin the exact protocol with hardcoded expected values, so a
 * change on this side fails here, and the identical assertions exist on the
 * Hub side in packages/request-signing's own suite.
 */
final class SigningInteropTest extends TestCase {

	public function test_canonical_request_is_newline_separated_with_a_hashed_body(): void {
		$canonical = CanonicalRequest::build( 'post', '/api/v1/ai/chat', '1700000000', 'abc123', '{"a":1}' );

		$this->assertSame(
			"POST\n/api/v1/ai/chat\n1700000000\nabc123\n" . hash( 'sha256', '{"a":1}' ),
			$canonical
		);
	}

	public function test_method_is_uppercased_but_path_is_not_touched(): void {
		$canonical = CanonicalRequest::build( 'post', '/API/v1/Chat', '1', 'n', '' );

		$this->assertStringStartsWith( "POST\n/API/v1/Chat\n", $canonical );
	}

	public function test_field_separation_prevents_boundary_collisions(): void {
		// Without the newline separator, nonce "AB" + timestamp "12" and
		// nonce "A" + timestamp "B12" would hash identically.
		$a = CanonicalRequest::build( 'GET', '/x', '12', 'AB', '' );
		$b = CanonicalRequest::build( 'GET', '/x', 'B12', 'A', '' );

		$this->assertNotSame( $a, $b );
	}

	public function test_signature_is_hmac_sha256_of_the_canonical_string(): void {
		$signer = new RequestSigner(
			static fn(): string => 'fixed-nonce',
			static fn(): int => 1700000000
		);

		$headers = $signer->sign( 'POST', '/api/v1/ai/chat', '{"a":1}', 'key-1', 'secret-1' );

		$expected = hash_hmac(
			'sha256',
			CanonicalRequest::build( 'POST', '/api/v1/ai/chat', '1700000000', 'fixed-nonce', '{"a":1}' ),
			'secret-1'
		);

		$this->assertSame( $expected, $headers[ RequestSigner::HEADER_SIGNATURE ] );
		$this->assertSame( 'key-1', $headers[ RequestSigner::HEADER_KEY_ID ] );
		$this->assertSame( '1700000000', $headers[ RequestSigner::HEADER_TIMESTAMP ] );
		$this->assertSame( 'fixed-nonce', $headers[ RequestSigner::HEADER_NONCE ] );
		$this->assertSame( 'v1', $headers[ RequestSigner::HEADER_SIGNATURE_VERSION ] );
	}

	public function test_header_names_match_the_hub_protocol_exactly(): void {
		// A typo here is a production 401 that is very hard to diagnose.
		$this->assertSame( 'X-DoSieci-Key-Id', RequestSigner::HEADER_KEY_ID );
		$this->assertSame( 'X-DoSieci-Timestamp', RequestSigner::HEADER_TIMESTAMP );
		$this->assertSame( 'X-DoSieci-Nonce', RequestSigner::HEADER_NONCE );
		$this->assertSame( 'X-DoSieci-Signature', RequestSigner::HEADER_SIGNATURE );
		$this->assertSame( 'X-DoSieci-Signature-Version', RequestSigner::HEADER_SIGNATURE_VERSION );
	}

	public function test_each_signature_uses_a_fresh_nonce(): void {
		$signer = new RequestSigner();

		$first  = $signer->sign( 'POST', '/x', '', 'k', 's' );
		$second = $signer->sign( 'POST', '/x', '', 'k', 's' );

		$this->assertNotSame(
			$first[ RequestSigner::HEADER_NONCE ],
			$second[ RequestSigner::HEADER_NONCE ],
			'Reusing a nonce would be rejected by the Hub as a replay.'
		);
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Signing;

/**
 * Produces the X-DoSieci-* headers for an outbound request to the Hub,
 * matching the protocol the Hub already verifies (docs/adr/0008).
 *
 * The nonce generator is injectable purely so tests can be deterministic;
 * the default uses random_bytes(), never mt_rand()/uniqid(), because a
 * predictable nonce would let an attacker who can observe one request
 * pre-burn nonces the plugin is about to use.
 */
final class RequestSigner {

	public const HEADER_KEY_ID            = 'X-DoSieci-Key-Id';
	public const HEADER_TIMESTAMP         = 'X-DoSieci-Timestamp';
	public const HEADER_NONCE             = 'X-DoSieci-Nonce';
	public const HEADER_SIGNATURE         = 'X-DoSieci-Signature';
	public const HEADER_SIGNATURE_VERSION = 'X-DoSieci-Signature-Version';

	public const SIGNATURE_VERSION = 'v1';

	/** @var callable():string */
	private $nonceGenerator;

	/** @var callable():int */
	private $clock;

	public function __construct( ?callable $nonceGenerator = null, ?callable $clock = null ) {
		$this->nonceGenerator = $nonceGenerator ?? static function (): string {
			return bin2hex( random_bytes( 16 ) );
		};
		$this->clock          = $clock ?? static function (): int {
			return time();
		};
	}

	/**
	 * @return array<string, string> header name => value
	 */
	public function sign( string $method, string $path, string $body, string $keyId, string $secret ): array {
		$timestamp = (string) ( $this->clock )();
		$nonce     = ( $this->nonceGenerator )();
		$canonical = CanonicalRequest::build( $method, $path, $timestamp, $nonce, $body );

		return array(
			self::HEADER_KEY_ID            => $keyId,
			self::HEADER_TIMESTAMP         => $timestamp,
			self::HEADER_NONCE             => $nonce,
			self::HEADER_SIGNATURE         => hash_hmac( 'sha256', $canonical, $secret ),
			self::HEADER_SIGNATURE_VERSION => self::SIGNATURE_VERSION,
		);
	}
}

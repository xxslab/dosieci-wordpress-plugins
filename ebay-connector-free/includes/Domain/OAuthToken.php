<?php

declare(strict_types=1);

namespace DoSieci\Ebay\Connector\Domain;

/**
 * An eBay application access token with its expiry.
 *
 * Tokens are cached and reused until shortly before they expire. The safety
 * margin matters: a token that expires between our expiry check and eBay
 * receiving the request produces a confusing 401 on an otherwise correct
 * call, so anything within the margin is treated as already expired.
 */
final class OAuthToken {

	public const EXPIRY_SAFETY_MARGIN_SECONDS = 60;

	public function __construct(
		public readonly string $accessToken,
		public readonly int $expiresAt
	) {
	}

	public function isValidAt( int $now ): bool {
		return '' !== $this->accessToken && $this->expiresAt - self::EXPIRY_SAFETY_MARGIN_SECONDS > $now;
	}

	/**
	 * @param array<string, mixed> $payload eBay's token response
	 *
	 * @throws EbayException
	 */
	public static function fromResponse( array $payload, int $now ): self {
		$token = $payload['access_token'] ?? null;

		if ( ! is_string( $token ) || '' === $token ) {
			throw new EbayException( 'Odpowiedź eBay nie zawiera tokenu dostępu.' );
		}

		$expiresIn = isset( $payload['expires_in'] ) && is_numeric( $payload['expires_in'] )
			? (int) $payload['expires_in']
			: 7200;

		return new self( $token, $now + $expiresIn );
	}
}

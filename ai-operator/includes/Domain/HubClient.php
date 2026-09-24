<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain;

use DoSieci\AiOperator\Domain\Signing\RequestSigner;

/**
 * Everything this plugin sends to the Hub goes through here.
 *
 * Two kinds of call, deliberately different:
 *  - completePairing() is UNSIGNED, because at that moment the plugin has
 *    no credentials yet -- the one-time pairing token IS the credential
 *    (mirrors the Hub's PairingController::complete() reasoning).
 *  - everything else is SIGNED with the per-installation secret obtained
 *    from that pairing.
 *
 * The Hub base URL is validated to be https:// before any request: sending
 * a signed request (or a pairing token) over plain http would expose the
 * credential on the wire.
 */
final class HubClient {

	private const PAIRING_PATH = '/api/v1/pairing/complete';
	private const CHAT_PATH    = '/api/v1/ai/chat';

	public function __construct(
		private HttpTransportInterface $transport,
		private RequestSigner $signer,
		private int $timeoutSeconds = 30
	) {
	}

	/**
	 * Exchanges a one-time pairing token for this installation's permanent
	 * identity. The returned secret is the ONLY time the Hub ever sends it.
	 *
	 * @throws HubException|TransportException
	 */
	public function completePairing( string $hubUrl, string $token, string $siteUrl ): Connection {
		$hubUrl = self::normaliseHubUrl( $hubUrl );

		$body     = (string) json_encode(
			array(
				'token'    => $token,
				'site_url' => $siteUrl,
			)
		);
		$response = $this->request( $hubUrl . self::PAIRING_PATH, array( 'Content-Type' => 'application/json' ), $body );

		foreach ( array( 'site_id', 'key_id', 'secret' ) as $required ) {
			if ( ! isset( $response[ $required ] ) || ! is_string( $response[ $required ] ) || '' === $response[ $required ] ) {
				throw new HubException( 'Hub pairing response is missing "' . $required . '".' );
			}
		}

		return new Connection(
			$hubUrl,
			$response['site_id'],
			$response['key_id'],
			$response['secret'],
			time()
		);
	}

	/**
	 * One chat turn. Returns the Hub's decoded response, which is one of:
	 *  {type: final_answer, answer}
	 *  {type: tool_call, tool_name, tool_use_id, arguments, required_capability, risk_level}
	 *  {type: tool_call_denied, tool_name, tool_use_id, reason}
	 *
	 * $requestId is this HTTP call's idempotency key: a retry after a
	 * dropped response MUST reuse the same value so the Hub's usage ledger
	 * does not double-charge.
	 *
	 * $capabilities, when given, tells the Hub what THIS installation can
	 * actually execute -- operator version, whether write tools are turned
	 * on locally, and which tool names it has registered -- so the Hub can
	 * narrow what it offers the provider to tools this site can really run.
	 * Omitting it (null) is what an AI Operator 1.0 site does; the Hub
	 * treats that identically to an explicit read-only declaration. See
	 * HubGateway::capabilities() for how this is built.
	 *
	 * @param array<int, array<string, mixed>> $conversation
	 * @param array<string, mixed>|null        $capabilities
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HubException|TransportException
	 */
	public function chat( Connection $connection, string $requestId, array $conversation, ?array $capabilities = null ): array {
		$payload = array(
			'request_id'   => $requestId,
			'conversation' => $conversation,
		);

		if ( null !== $capabilities ) {
			$payload['capabilities'] = $capabilities;
		}

		$body = (string) json_encode( $payload );

		$headers = $this->signer->sign( 'POST', self::CHAT_PATH, $body, $connection->keyId, $connection->secret );
		$headers['Content-Type'] = 'application/json';

		return $this->request( $connection->hubUrl . self::CHAT_PATH, $headers, $body );
	}

	/**
	 * @param array<string, string> $headers
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HubException|TransportException
	 */
	private function request( string $url, array $headers, string $body ): array {
		$headers['Accept'] = 'application/json';

		$response = $this->transport->post( $url, $headers, $body, $this->timeoutSeconds );

		$decoded = json_decode( $response['body'], true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			$errorCode = isset( $decoded['error'] ) && is_string( $decoded['error'] ) ? $decoded['error'] : 'hub_error';

			throw new HubException(
				sprintf( 'Hub returned HTTP %d (%s).', $response['status'], $errorCode ),
				$response['status'],
				$errorCode,
				(bool) ( $decoded['retryable'] ?? false )
			);
		}

		return $decoded;
	}

	private static function normaliseHubUrl( string $hubUrl ): string {
		$hubUrl = rtrim( trim( $hubUrl ), '/' );

		if ( 0 !== stripos( $hubUrl, 'https://' ) ) {
			// A pairing token or a signed request sent over plain http is a
			// credential leak, so this is refused rather than silently
			// upgraded -- an operator who typed http:// deserves to know.
			throw new HubException( 'The Hub URL must start with https://.' );
		}

		return $hubUrl;
	}
}

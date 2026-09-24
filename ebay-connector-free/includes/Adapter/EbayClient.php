<?php

declare(strict_types=1);

namespace DoSieci\Ebay\Connector\Adapter;

use DoSieci\Ebay\Connector\Domain\EbayEnvironment;
use DoSieci\Ebay\Connector\Domain\EbayException;
use DoSieci\Ebay\Connector\Domain\EbayMarketplace;
use DoSieci\Ebay\Connector\Domain\ListingMapper;
use DoSieci\Ebay\Connector\Domain\OAuthToken;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * eBay client using the site owner's own App ID and Cert ID, called directly
 * from this site. Nothing goes through DoSieci.
 *
 * Read-only by construction: it can obtain an application token and search
 * the Browse API, and has no method that creates listings, writes orders or
 * pushes inventory. "It cannot accidentally publish to your eBay account" is
 * therefore a structural guarantee rather than a promise.
 *
 * The token is cached in a transient so a page refresh does not burn a new
 * one against the account's rate limit.
 */
final class EbayClient {

	public const TOKEN_TRANSIENT = 'dosieci_ebay_app_token';

	public function __construct(
		private string $clientId,
		private string $clientSecret,
		private EbayEnvironment $environment,
		private string $marketplace = EbayMarketplace::DEFAULT,
		private int $timeoutSeconds = 20
	) {
	}

	/**
	 * @throws EbayException With a message safe to show to the operator.
	 */
	public function accessToken(): string {
		$cached = get_transient( self::TOKEN_TRANSIENT . '_' . $this->environment->name );

		if ( is_array( $cached ) && isset( $cached['token'], $cached['expires_at'] ) ) {
			$token = new OAuthToken( (string) $cached['token'], (int) $cached['expires_at'] );

			if ( $token->isValidAt( time() ) ) {
				return $token->accessToken;
			}
		}

		$response = wp_remote_post(
			$this->environment->oauthUrl(),
			array(
				'headers' => array(
					// Client-credentials grant with HTTP Basic authentication:
					// the application authenticates as itself, which is all the
					// read-only Browse API needs.
					'Authorization' => 'Basic ' . base64_encode( $this->clientId . ':' . $this->clientSecret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication header.
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type' => 'client_credentials',
					'scope'      => 'https://api.ebay.com/oauth/api_scope',
				),
				'timeout' => $this->timeoutSeconds,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new EbayException( esc_html( $this->redact( $response->get_error_message() ) ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 400 === $status || 401 === $status ) {
			throw new EbayException(
				esc_html__( 'eBay rejected the application keys. Check the App ID (Client ID) and Cert ID (Client Secret), and that they belong to the selected environment (Sandbox or Production).', 'dosieci-ebay-connector' ),
				(int) $status
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new EbayException(
				/* translators: %d: HTTP status code */
				esc_html( sprintf( __( 'eBay returned HTTP error %d while issuing the access token.', 'dosieci-ebay-connector' ), $status ) ),
				(int) $status
			);
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$token   = OAuthToken::fromResponse( is_array( $payload ) ? $payload : array(), time() );

		set_transient(
			self::TOKEN_TRANSIENT . '_' . $this->environment->name,
			array(
				'token'      => $token->accessToken,
				'expires_at' => $token->expiresAt,
			),
			max( 60, $token->expiresAt - time() - OAuthToken::EXPIRY_SAFETY_MARGIN_SECONDS )
		);

		return $token->accessToken;
	}

	/**
	 * Read-only Browse API search on the selected marketplace.
	 *
	 * @return array{total:int, items:array<int, array<string, string|null>>}
	 *
	 * @throws EbayException With a message safe to show to the operator.
	 */
	public function search( string $query, int $limit = 10 ): array {
		$limit = max( 1, min( 50, $limit ) );

		$response = wp_remote_get(
			add_query_arg(
				array(
					'q'     => rawurlencode( $query ),
					'limit' => $limit,
				),
				$this->environment->browseUrl() . '/item_summary/search'
			),
			array(
				'headers' => array(
					'Authorization'           => 'Bearer ' . $this->accessToken(),
					'Accept'                  => 'application/json',
					'X-EBAY-C-MARKETPLACE-ID' => $this->marketplace,
				),
				'timeout' => $this->timeoutSeconds,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new EbayException( esc_html( $this->redact( $response->get_error_message() ) ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			throw new EbayException(
				/* translators: %d: HTTP status code */
				esc_html( sprintf( __( 'eBay returned HTTP error %d.', 'dosieci-ebay-connector' ), $status ) ),
				(int) $status
			);
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$payload = is_array( $payload ) ? $payload : array();

		return array(
			'total' => ListingMapper::totalFromSearchResponse( $payload ),
			'items' => ListingMapper::fromSearchResponse( $payload ),
		);
	}

	private function redact( string $message ): string {
		return str_replace( array_filter( array( $this->clientSecret, $this->clientId ) ), '[redacted]', $message );
	}
}

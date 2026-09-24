<?php

declare(strict_types=1);

namespace DoSieci\Ebay\Connector\Adapter;

use DoSieci\Ebay\Connector\Domain\EbayEnvironment;
use DoSieci\Ebay\Connector\Domain\EbayException;
use DoSieci\Ebay\Connector\Domain\ListingMapper;
use DoSieci\Ebay\Connector\Domain\OAuthToken;

/**
 * BYOK eBay client: the user's own App ID / Cert ID, called directly from
 * this site. Nothing goes through DoSieci.
 *
 * Free tier is READ-ONLY -- this class can request an application token and
 * search the Browse API. There is no listing-creation, no order-writing and
 * no inventory-pushing method here at all, which is what makes "the free
 * build cannot accidentally publish to your live eBay account" a structural
 * guarantee rather than a promise.
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
		private int $timeoutSeconds = 20
	) {
	}

	/**
	 * @throws EbayException
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
				'headers'   => array(
					// Client-credentials grant: the app authenticates as
					// itself, no per-user consent involved, which is all the
					// read-only Browse API needs.
					'Authorization' => 'Basic ' . base64_encode( $this->clientId . ':' . $this->clientSecret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'      => array(
					'grant_type' => 'client_credentials',
					'scope'      => 'https://api.ebay.com/oauth/api_scope',
				),
				'timeout'   => $this->timeoutSeconds,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new EbayException( $this->redact( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 400 === $status || 401 === $status ) {
			throw new EbayException(
				__( 'eBay odrzucił dane aplikacji. Sprawdź App ID (Client ID) i Cert ID (Client Secret) oraz to, czy pasują do wybranego środowiska.', 'dosieci-ebay-connector' ),
				$status
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new EbayException(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'eBay zwrócił błąd HTTP %d podczas pobierania tokenu.', 'dosieci-ebay-connector' ),
					$status
				),
				$status
			);
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$token   = OAuthToken::fromResponse( is_array( $payload ) ? $payload : array(), time() );

		set_transient(
			self::TOKEN_TRANSIENT . '_' . $this->environment->name,
			array( 'token' => $token->accessToken, 'expires_at' => $token->expiresAt ),
			max( 60, $token->expiresAt - time() - OAuthToken::EXPIRY_SAFETY_MARGIN_SECONDS )
		);

		return $token->accessToken;
	}

	/**
	 * Read-only Browse API search.
	 *
	 * @return array{total:int, items:array<int, array<string, string|null>>}
	 *
	 * @throws EbayException
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
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->accessToken(),
					'Accept'        => 'application/json',
				),
				'timeout'   => $this->timeoutSeconds,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new EbayException( $this->redact( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			throw new EbayException(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'eBay zwrócił błąd HTTP %d.', 'dosieci-ebay-connector' ),
					$status
				),
				$status
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
		return str_replace( array( $this->clientSecret, $this->clientId ), '[redacted]', $message );
	}
}

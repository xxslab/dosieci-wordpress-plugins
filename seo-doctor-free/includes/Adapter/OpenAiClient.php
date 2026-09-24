<?php

declare(strict_types=1);

namespace DoSieci\SEO\Doctor\Adapter;

use DoSieci\SEO\Doctor\Domain\ByokKeyStore;

/**
 * BYOK call to the user's own OpenAI-compatible endpoint.
 *
 * The request goes WordPress -> provider directly. It is never routed
 * through DoSieci, which is the defining difference between this free
 * plugin and DoSieci AI Operator (see readme.txt).
 *
 * Nothing here logs the request or response body. The legacy plugin this
 * replaces did exactly that and leaked the API key into error_log
 * (audit/wpAIseoGen-AUDIT.md); any provider error text is passed through
 * ByokKeyStore::redactFrom() before it can reach a screen or a log.
 */
final class OpenAiClient {

	private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	public function __construct(
		private string $apiKey,
		private string $model = 'gpt-4o-mini',
		private int $timeoutSeconds = 30
	) {
	}

	/**
	 * @throws \RuntimeException with a message safe to show to the operator
	 */
	public function complete( string $prompt ): string {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'      => (string) wp_json_encode(
					array(
						'model'       => $this->model,
						'temperature' => 0.4,
						'messages'    => array(
							array( 'role' => 'user', 'content' => $prompt ),
						),
					)
				),
				'timeout'   => $this->timeoutSeconds,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				ByokKeyStore::redactFrom( $response->get_error_message(), $this->apiKey )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( 401 === $status || 403 === $status ) {
			throw new \RuntimeException( __( 'Dostawca odrzucił klucz API. Sprawdź klucz w ustawieniach.', 'dosieci-seo-doctor' ) );
		}

		if ( 429 === $status ) {
			throw new \RuntimeException( __( 'Przekroczono limit zapytań u dostawcy. Spróbuj ponownie za chwilę.', 'dosieci-seo-doctor' ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Dostawca zwrócił błąd HTTP %d.', 'dosieci-seo-doctor' ),
					$status
				)
			);
		}

		$decoded = json_decode( $body, true );
		$content = $decoded['choices'][0]['message']['content'] ?? null;

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			throw new \RuntimeException( __( 'Dostawca zwrócił pustą odpowiedź.', 'dosieci-seo-doctor' ) );
		}

		return $content;
	}
}

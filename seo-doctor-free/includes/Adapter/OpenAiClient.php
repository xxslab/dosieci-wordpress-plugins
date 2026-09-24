<?php

declare(strict_types=1);

namespace DoSieci\SEO\Doctor\Adapter;

use DoSieci\SEO\Doctor\Domain\ByokKeyStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Direct call to the OpenAI API with the site owner's own key.
 *
 * Used only when WordPress itself has no AI provider configured (WordPress
 * before 7.0, or no connector set up). The request goes from this site
 * straight to OpenAI, never through DoSieci. Nothing here logs the request
 * or the response, and any provider error text is passed through
 * ByokKeyStore::redactFrom() before it can reach a screen.
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
	 * @throws \RuntimeException With a message safe to show to the operator.
	 */
	public function complete( string $prompt ): string {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) wp_json_encode(
					// No temperature or response_format: several current models
					// reject non-default values, and the model name is up to the
					// site owner. The prompt itself asks for JSON.
					array(
						'model'    => $this->model,
						'messages' => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
					)
				),
				'timeout' => $this->timeoutSeconds,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( ByokKeyStore::redactFrom( $response->get_error_message(), $this->apiKey ) ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( 401 === $status || 403 === $status ) {
			throw new \RuntimeException( esc_html__( 'OpenAI rejected the API key. Check the key in the settings below.', 'dosieci-seo-doctor' ) );
		}

		if ( 404 === $status ) {
			throw new \RuntimeException( esc_html__( 'OpenAI does not recognise this model name, or your key has no access to it. Check the model in the settings below.', 'dosieci-seo-doctor' ) );
		}

		if ( 429 === $status ) {
			throw new \RuntimeException( esc_html__( 'OpenAI rate limit or quota reached. Try again in a moment, or check the billing of your OpenAI account.', 'dosieci-seo-doctor' ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException(
				/* translators: %d: HTTP status code */
				esc_html( sprintf( __( 'OpenAI returned HTTP error %d.', 'dosieci-seo-doctor' ), $status ) )
			);
		}

		$decoded = json_decode( $body, true );
		$content = $decoded['choices'][0]['message']['content'] ?? null;

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			throw new \RuntimeException( esc_html__( 'OpenAI returned an empty answer.', 'dosieci-seo-doctor' ) );
		}

		return $content;
	}
}

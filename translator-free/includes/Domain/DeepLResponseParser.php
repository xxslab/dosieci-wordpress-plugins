<?php

declare(strict_types=1);

namespace DoSieci\Translator\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses DeepL's response, and maps its documented error statuses to
 * messages an operator can act on.
 *
 * Kept apart from the HTTP client so every branch is testable without a
 * network or an API key, including the quota-exceeded case (456), which is
 * the one a real user is most likely to hit.
 */
final class DeepLResponseParser {

	/**
	 * @throws TranslationException When the status is not a success.
	 */
	public static function assertOk( int $status, string $body ): void {
		if ( $status >= 200 && $status < 300 ) {
			return;
		}

		$message = match ( true ) {
			403 === $status => __( 'DeepL rejected the API key. Check the key; keys ending in ":fx" are DeepL API Free keys.', 'dosieci-translator' ),
			429 === $status => __( 'Too many requests to DeepL. Wait a moment and try again.', 'dosieci-translator' ),
			456 === $status => __( 'The character limit of your DeepL plan is used up for this billing period.', 'dosieci-translator' ),
			413 === $status => __( 'The text is too long for a single DeepL request. Split the content into smaller parts.', 'dosieci-translator' ),
			$status >= 500  => __( 'DeepL is temporarily unavailable. Try again in a moment.', 'dosieci-translator' ),
			/* translators: %d: HTTP status code */
			default         => sprintf( __( 'DeepL returned HTTP error %d.', 'dosieci-translator' ), $status ),
		};

		throw new TranslationException( esc_html( $message ), (int) $status );
	}

	/**
	 * @throws TranslationException When the body holds no translation.
	 */
	public static function extractTranslation( string $body ): string {
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['translations'][0]['text'] ) ) {
			throw new TranslationException( esc_html__( 'The DeepL response contains no translation.', 'dosieci-translator' ) );
		}

		$text = $decoded['translations'][0]['text'];

		if ( ! is_string( $text ) ) {
			throw new TranslationException( esc_html__( 'The DeepL response has an unexpected format.', 'dosieci-translator' ) );
		}

		return $text;
	}

	public static function detectedSourceLanguage( string $body ): ?string {
		$decoded = json_decode( $body, true );
		$lang    = is_array( $decoded ) ? ( $decoded['translations'][0]['detected_source_language'] ?? null ) : null;

		return is_string( $lang ) ? $lang : null;
	}
}

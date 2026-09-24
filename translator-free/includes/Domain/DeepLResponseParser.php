<?php

declare(strict_types=1);

namespace DoSieci\Translator\Domain;

/**
 * Parses DeepL's response shape, and maps its documented error statuses to
 * messages an operator can act on.
 *
 * Kept separate from the HTTP client so every one of these branches is
 * testable without a network or an API key -- including the quota-exceeded
 * case (456), which is the one a real user is most likely to hit and the
 * one that is most confusing if it surfaces as a generic failure.
 */
final class DeepLResponseParser {

	/**
	 * @throws TranslationException
	 */
	public static function assertOk( int $status, string $body ): void {
		if ( $status >= 200 && $status < 300 ) {
			return;
		}

		throw new TranslationException(
			match ( true ) {
				403 === $status => 'DeepL odrzucił klucz API. Sprawdź klucz i to, czy używasz właściwego endpointu (Free vs Pro).',
				429 === $status => 'Zbyt wiele zapytań do DeepL. Odczekaj chwilę i spróbuj ponownie.',
				456 === $status => 'Wyczerpano limit znaków w Twoim planie DeepL na ten okres rozliczeniowy.',
				413 === $status => 'Tekst jest za długi dla jednego zapytania DeepL. Podziel treść na mniejsze części.',
				$status >= 500  => 'DeepL jest chwilowo niedostępny. Spróbuj ponownie za chwilę.',
				default         => sprintf( 'DeepL zwrócił błąd HTTP %d.', $status ),
			},
			$status
		);
	}

	/**
	 * @throws TranslationException
	 */
	public static function extractTranslation( string $body ): string {
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['translations'][0]['text'] ) ) {
			throw new TranslationException( 'Odpowiedź DeepL nie zawiera tłumaczenia.' );
		}

		$text = $decoded['translations'][0]['text'];

		if ( ! is_string( $text ) ) {
			throw new TranslationException( 'Odpowiedź DeepL ma nieoczekiwany format.' );
		}

		return $text;
	}

	public static function detectedSourceLanguage( string $body ): ?string {
		$decoded = json_decode( $body, true );
		$lang    = $decoded['translations'][0]['detected_source_language'] ?? null;

		return is_string( $lang ) ? $lang : null;
	}
}

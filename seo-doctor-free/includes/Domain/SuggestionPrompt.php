<?php

declare(strict_types=1);

namespace DoSieci\SEO\Doctor\Domain;

/**
 * Builds the prompt sent to the user's own AI provider, and parses what
 * comes back.
 *
 * Two rules are baked into the prompt text rather than left to chance,
 * because both are explicit product constraints (PRODUCT_SCOPE.md's
 * "Zakazy" for this product):
 *  - never invent product attributes that are not in the supplied content
 *  - return only the requested fields, so the output is parseable and the
 *    plugin never has to guess which part of a chatty answer is the title
 *
 * Nothing here applies a suggestion. The free tier proposes; the human
 * copies what they want. There is no "apply to post" path in this build.
 */
final class SuggestionPrompt {

	public const MAX_CONTENT_CHARS = 4000;

	public static function build( string $title, string $contentText ): string {
		$content = mb_substr( trim( $contentText ), 0, self::MAX_CONTENT_CHARS );

		return implode(
			"\n",
			array(
				'Jesteś specjalistą SEO. Na podstawie poniższej treści zaproponuj metadane po polsku.',
				'',
				'ZASADY:',
				'- Nie wymyślaj cech produktu, których nie ma w treści.',
				'- Tytuł: 30-60 znaków.',
				'- Opis: 70-160 znaków.',
				'- Odpowiedz WYŁĄCZNIE poprawnym JSON-em o kluczach: title, description, keyphrase.',
				'- Bez komentarza przed ani po JSON-ie.',
				'',
				'AKTUALNY TYTUŁ:',
				$title,
				'',
				'TREŚĆ:',
				$content,
			)
		);
	}

	/**
	 * @return array{title:string, description:string, keyphrase:string}
	 *
	 * @throws \RuntimeException when the model did not return usable JSON
	 */
	public static function parse( string $raw ): array {
		$raw = trim( $raw );

		// Models frequently wrap JSON in a ```json fence despite being told
		// not to; tolerate that rather than failing the whole request.
		if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/s', $raw, $matches ) ) {
			$raw = $matches[1];
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Model nie zwrócił poprawnego JSON-a.' );
		}

		foreach ( array( 'title', 'description' ) as $required ) {
			if ( ! isset( $decoded[ $required ] ) || ! is_string( $decoded[ $required ] ) || '' === trim( $decoded[ $required ] ) ) {
				throw new \RuntimeException( sprintf( 'W odpowiedzi modelu brakuje pola „%s”.', $required ) );
			}
		}

		return array(
			'title'       => trim( $decoded['title'] ),
			'description' => trim( $decoded['description'] ),
			'keyphrase'   => isset( $decoded['keyphrase'] ) && is_string( $decoded['keyphrase'] ) ? trim( $decoded['keyphrase'] ) : '',
		);
	}
}

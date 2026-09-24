<?php

declare(strict_types=1);

namespace DoSieci\SEO\Doctor\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the prompt sent to the AI provider, and parses what comes back.
 *
 * Two rules are written into the prompt rather than left to chance:
 *  - never invent product attributes that are not in the supplied content;
 *  - return only the requested fields as JSON, so the output is parseable
 *    and the plugin never guesses which part of a chatty answer is the title.
 *
 * Nothing here applies a suggestion: the human copies what they want.
 */
final class SuggestionPrompt {

	public const MAX_CONTENT_CHARS = 4000;

	/**
	 * JSON schema of the answer, for providers that support structured output.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'keyphrase'   => array( 'type' => 'string' ),
			),
			'required'             => array( 'title', 'description', 'keyphrase' ),
			'additionalProperties' => false,
		);
	}

	public static function build( string $title, string $contentText ): string {
		$content = mb_substr( trim( $contentText ), 0, self::MAX_CONTENT_CHARS );

		return implode(
			"\n",
			array(
				'You are an SEO specialist. Based on the content below, suggest search metadata.',
				'',
				'RULES:',
				'- Write in the same language as the content.',
				'- Do not invent product features that are not in the content.',
				'- Title: 30-60 characters.',
				'- Description: 70-160 characters.',
				'- Answer ONLY with valid JSON with the keys: title, description, keyphrase.',
				'- No comments before or after the JSON.',
				'',
				'CURRENT TITLE:',
				$title,
				'',
				'CONTENT:',
				$content,
			)
		);
	}

	/**
	 * @return array{title:string, description:string, keyphrase:string}
	 *
	 * @throws \RuntimeException When the model did not return usable JSON.
	 */
	public static function parse( string $raw ): array {
		$raw = trim( $raw );

		// Models often wrap JSON in a ```json fence despite being told not
		// to; tolerate that rather than failing the whole request.
		if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/s', $raw, $matches ) ) {
			$raw = $matches[1];
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( esc_html__( 'The AI model did not return valid JSON. Try again.', 'dosieci-seo-doctor' ) );
		}

		foreach ( array( 'title', 'description' ) as $required ) {
			if ( ! isset( $decoded[ $required ] ) || ! is_string( $decoded[ $required ] ) || '' === trim( $decoded[ $required ] ) ) {
				throw new \RuntimeException(
					/* translators: %s: name of the missing field */
					esc_html( sprintf( __( 'The AI answer is missing the "%s" field. Try again.', 'dosieci-seo-doctor' ), $required ) )
				);
			}
		}

		return array(
			'title'       => trim( $decoded['title'] ),
			'description' => trim( $decoded['description'] ),
			'keyphrase'   => isset( $decoded['keyphrase'] ) && is_string( $decoded['keyphrase'] ) ? trim( $decoded['keyphrase'] ) : '',
		);
	}
}

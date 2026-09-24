<?php

declare(strict_types=1);

namespace DoSieci\Instant\Search\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A normalised, safe-to-execute search term.
 *
 * The normalisation bounds the length (a 5 KB "search term" is an attack, not
 * a query), strips SQL LIKE wildcards and the escape character so a visitor
 * cannot widen the match, and splits the term into a few word tokens.
 */
final class SearchQuery {

	public const MIN_LENGTH = 2;
	public const MAX_LENGTH = 100;
	public const MAX_TOKENS = 5;

	/** @param string[] $tokens */
	private function __construct(
		public readonly string $raw,
		public readonly string $normalised,
		public readonly array $tokens
	) {
	}

	public static function fromString( string $input ): self {
		$raw = trim( $input );

		// Collapse whitespace, drop LIKE wildcards, the LIKE escape character
		// and control characters.
		$normalised = preg_replace( '/[\p{C}]+/u', '', $raw ) ?? '';
		$normalised = str_replace( array( '%', '_', '\\' ), ' ', $normalised );
		$normalised = preg_replace( '/\s+/u', ' ', $normalised ) ?? '';
		$normalised = trim( mb_substr( $normalised, 0, self::MAX_LENGTH ) );

		$tokens = array_slice(
			array_values( array_unique( array_filter( explode( ' ', $normalised ), static fn( string $token ): bool => '' !== $token ) ) ),
			0,
			self::MAX_TOKENS
		);

		return new self( $raw, $normalised, $tokens );
	}

	public function isSearchable(): bool {
		return mb_strlen( $this->normalised ) >= self::MIN_LENGTH;
	}

	/**
	 * LIKE patterns for "this token starts a word in the title": at the very
	 * start, after a space, or after a hyphen ("shirt" finds "Blue T-shirt").
	 * Every token must match, in any order, so "shoes black" finds "Black
	 * running shoes".
	 *
	 * @return array<int, array{0:string, 1:string, 2:string}>
	 */
	public function wordStartPatterns(): array {
		return array_map(
			static fn( string $token ): array => array( $token . '%', '% ' . $token . '%', '%-' . $token . '%' ),
			$this->tokens
		);
	}

	/**
	 * Used only for the SKU lookup, where a fragment in the middle ("-XL") is
	 * a realistic thing to type.
	 */
	public function containsPattern(): string {
		return '%' . $this->normalised . '%';
	}
}

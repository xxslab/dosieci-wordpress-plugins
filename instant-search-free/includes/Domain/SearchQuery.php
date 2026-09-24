<?php

declare(strict_types=1);

namespace DoSieci\Instant\Search\Domain;

/**
 * A normalised, safe-to-execute search term.
 *
 * The normalisation here is what makes the search fast AND safe: it bounds
 * the length (a 5 KB "search term" is an attack, not a query), strips
 * SQL LIKE wildcards so a user cannot turn `%` into a full table scan, and
 * splits into tokens so the adapter can build a prefix query instead of the
 * `LIKE %term%` pattern PRODUCT_SCOPE.md explicitly forbids for this
 * product ("bez ciężkiego LIKE %term% na każdy znak").
 */
final class SearchQuery {

	public const MIN_LENGTH = 2;
	public const MAX_LENGTH = 100;

	/** @param string[] $tokens */
	private function __construct(
		public readonly string $raw,
		public readonly string $normalised,
		public readonly array $tokens
	) {
	}

	public static function fromString( string $input ): self {
		$raw = trim( $input );

		// Collapse whitespace, drop LIKE wildcards and control characters.
		$normalised = preg_replace( '/[\p{C}]+/u', '', $raw ) ?? '';
		$normalised = str_replace( array( '%', '_' ), ' ', $normalised );
		$normalised = preg_replace( '/\s+/u', ' ', $normalised ) ?? '';
		$normalised = trim( mb_substr( $normalised, 0, self::MAX_LENGTH ) );

		$tokens = array_values(
			array_filter(
				explode( ' ', $normalised ),
				static fn( string $token ): bool => mb_strlen( $token ) >= 1
			)
		);

		return new self( $raw, $normalised, $tokens );
	}

	public function isSearchable(): bool {
		return mb_strlen( $this->normalised ) >= self::MIN_LENGTH;
	}

	/**
	 * The token used for a prefix match, i.e. `term%` rather than
	 * `%term%` -- a leading wildcard prevents the database from using an
	 * index at all, which is the difference between a 10 ms and a 4 s
	 * search on a 100k-product catalogue.
	 */
	public function prefixPattern(): string {
		return $this->normalised . '%';
	}

	/**
	 * Used only for the SKU/short-description fallback, where a contained
	 * match is genuinely wanted. Kept separate and used sparingly, so the
	 * expensive pattern is a deliberate choice per field, never the default.
	 */
	public function containsPattern(): string {
		return '%' . $this->normalised . '%';
	}
}

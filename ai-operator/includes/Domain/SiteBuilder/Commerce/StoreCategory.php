<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Commerce;

use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintValidationException;

/** One product category the blueprint asks for. */
final class StoreCategory {

	private function __construct(
		public readonly string $role,
		public readonly string $name,
		public readonly string $description
	) {
	}

	/**
	 * Accepts either a bare name ("Koszulki") or an object, because a
	 * model asked for "categories" in prose overwhelmingly produces the
	 * former and rejecting it would fail the common case for no benefit.
	 *
	 * @throws BlueprintValidationException
	 */
	public static function fromMixed( mixed $raw ): self {
		if ( is_string( $raw ) ) {
			$raw = array( 'name' => $raw );
		}

		if ( ! is_array( $raw ) ) {
			throw new BlueprintValidationException( 'Kategoria musi być nazwą albo obiektem.' );
		}

		$name = trim( (string) ( $raw['name'] ?? '' ) );

		if ( '' === $name ) {
			throw new BlueprintValidationException( 'Kategoria musi mieć nazwę.' );
		}

		if ( mb_strlen( $name ) > 100 ) {
			throw new BlueprintValidationException( 'Nazwa kategorii jest zbyt długa.' );
		}

		return new self(
			trim( (string) ( $raw['logical_role'] ?? $name ) ),
			$name,
			trim( (string) ( $raw['description'] ?? '' ) )
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'logical_role' => $this->role,
			'name'         => $this->name,
			'description'  => $this->description,
		);
	}
}

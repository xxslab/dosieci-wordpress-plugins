<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Commerce;

use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintValidationException;

/**
 * One product the builder may create, as described by a blueprint.
 *
 * ## Price is the field that makes this different from a page
 *
 * Getting a heading wrong is embarrassing. Getting a price wrong is a
 * commercial fact: a shop that publishes 7.90 where it meant 79.00 sells
 * at a tenth of cost until somebody notices. So the price is validated
 * into a canonical decimal string here, rendered into the step
 * description the human approves, and covered by the plan hash -- it
 * cannot be altered between approval and execution.
 *
 * ## Why there is no `status` field
 *
 * Because there is no choice to express. A product this builder creates
 * is a DRAFT, always. Publishing a product is a commercial decision with
 * a price attached, and a blueprint that could ask for `status: publish`
 * would make that decision reachable from a sentence of prose.
 */
final class StoreProduct {

	private const MAX_PRICE = 1_000_000.0;

	private function __construct(
		public readonly string $role,
		public readonly string $name,
		public readonly string $description,
		public readonly string $shortDescription,
		/** Canonical decimal string, e.g. "79.00". Never a float. */
		public readonly string $regularPrice,
		/** @var string[] roles of the categories this belongs to */
		public readonly array $categoryRoles,
		public readonly string $imageStrategy
	) {
	}

	public const IMAGE_NONE    = 'none';
	public const IMAGE_LIBRARY = 'library';

	/**
	 * @param array<string, mixed> $raw
	 *
	 * @throws BlueprintValidationException
	 */
	public static function fromArray( array $raw ): self {
		$name = trim( (string) ( $raw['name'] ?? '' ) );

		if ( '' === $name ) {
			throw new BlueprintValidationException( esc_html__( 'A product must have a name.', 'dosieci-ai-operator' ) );
		}

		if ( mb_strlen( $name ) > 120 ) {
			throw new BlueprintValidationException( esc_html__( 'The product name is too long.', 'dosieci-ai-operator' ) );
		}

		$role = trim( (string) ( $raw['logical_role'] ?? $name ) );

		$imageStrategy = (string) ( $raw['image_strategy'] ?? self::IMAGE_LIBRARY );

		// Rejected, not defaulted. An unknown strategy means the model asked
		// for something this build cannot do, and silently substituting a
		// different one is how "no images please" becomes an image.
		if ( ! in_array( $imageStrategy, array( self::IMAGE_NONE, self::IMAGE_LIBRARY ), true ) ) {
			throw new BlueprintValidationException(
				sprintf(
					/* translators: %s: the unrecognised image strategy */
					esc_html__( 'Unknown product image strategy: “%s”.', 'dosieci-ai-operator' ),
					esc_html( $imageStrategy )
				)
			);
		}

		$categoryRoles = array();
		foreach ( (array) ( $raw['category_roles'] ?? array() ) as $categoryRole ) {
			$categoryRole = trim( (string) $categoryRole );

			if ( '' !== $categoryRole ) {
				$categoryRoles[] = $categoryRole;
			}
		}

		return new self(
			$role,
			$name,
			trim( (string) ( $raw['description'] ?? '' ) ),
			trim( (string) ( $raw['short_description'] ?? '' ) ),
			self::price( $raw['regular_price'] ?? null ),
			$categoryRoles,
			$imageStrategy
		);
	}

	/**
	 * Normalises a price into a canonical two-decimal string.
	 *
	 * Accepts the comma decimal separator a Polish-language model will
	 * produce ("79,00"), because rejecting it would mean the feature fails
	 * on its primary market. Everything else is refused rather than coerced:
	 * "about 80 PLN" has no defensible numeric reading, and guessing one
	 * puts an invented number on a real shop.
	 *
	 * @throws BlueprintValidationException
	 */
	public static function price( mixed $raw ): string {
		if ( null === $raw || '' === $raw ) {
			throw new BlueprintValidationException( esc_html__( 'A product must have a price.', 'dosieci-ai-operator' ) );
		}

		if ( is_int( $raw ) || is_float( $raw ) ) {
			$value = (float) $raw;
		} else {
			$text = str_replace( array( ' ', "\u{a0}" ), '', (string) $raw );
			$text = str_replace( ',', '.', $text );

			if ( 1 !== preg_match( '/^\d{1,7}(\.\d{1,2})?$/', $text ) ) {
				throw new BlueprintValidationException(
					sprintf(
						/* translators: %s: the invalid price value */
						esc_html__( 'The price “%s” is not a valid number.', 'dosieci-ai-operator' ),
						esc_html( (string) $raw )
					)
				);
			}

			$value = (float) $text;
		}

		if ( $value < 0 ) {
			throw new BlueprintValidationException( esc_html__( 'The price cannot be negative.', 'dosieci-ai-operator' ) );
		}

		if ( $value > self::MAX_PRICE ) {
			throw new BlueprintValidationException( esc_html__( 'The price exceeds the allowed limit.', 'dosieci-ai-operator' ) );
		}

		return number_format( $value, 2, '.', '' );
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'logical_role'      => $this->role,
			'name'              => $this->name,
			'description'       => $this->description,
			'short_description' => $this->shortDescription,
			'regular_price'     => $this->regularPrice,
			'category_roles'    => $this->categoryRoles,
			'image_strategy'    => $this->imageStrategy,
		);
	}
}

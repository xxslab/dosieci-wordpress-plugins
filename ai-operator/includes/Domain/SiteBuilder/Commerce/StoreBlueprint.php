<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Commerce;

use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintValidationException;

/**
 * The store half of a SiteBlueprint: what shop the user asked for.
 *
 * Optional. A blueprint without one describes a site with no commerce,
 * and nothing in the planner, the tools or the audit engages -- which is
 * what keeps AI Operator working normally on the overwhelming majority
 * of sites that will never install WooCommerce.
 *
 * Like SiteBlueprint, this describes an OUTCOME. It cannot name a tool,
 * a capability, an option name or a post id. Every field below is either
 * free text the user will see, or a value from a closed enum -- which is
 * what makes "the model wrote this" uninteresting: there is no field in
 * which a tool call, a setting name or a URL could be expressed.
 */
final class StoreBlueprint {

	/** @var string[] */
	public const WEIGHT_UNITS = array( 'kg', 'g', 'lbs', 'oz' );

	/** @var string[] */
	public const DIMENSION_UNITS = array( 'm', 'cm', 'mm', 'in', 'yd' );

	private const MAX_CATEGORIES = 20;
	private const MAX_PRODUCTS   = 20;

	private function __construct(
		public readonly string $country,
		public readonly string $currency,
		public readonly string $weightUnit,
		public readonly string $dimensionUnit,
		/** @var StoreCategory[] */
		public readonly array $categories,
		/** @var StoreProduct[] */
		public readonly array $products
	) {
	}

	/**
	 * @param array<string, mixed> $raw
	 *
	 * @throws BlueprintValidationException
	 */
	public static function fromArray( array $raw ): self {
		// ISO 3166-1 alpha-2, optionally with a WooCommerce state suffix.
		// Validated by SHAPE here; the adapter checks it against
		// WooCommerce's own country list, because that is the only
		// authority on which codes the installed version accepts.
		$country = strtoupper( trim( (string) ( $raw['store_country'] ?? '' ) ) );

		if ( 1 !== preg_match( '/^[A-Z]{2}(:[A-Z0-9]{1,6})?$/', $country ) ) {
			throw new BlueprintValidationException(
				sprintf(
					/* translators: %s: the invalid store country code */
					esc_html__( 'The store country code “%s” is invalid.', 'dosieci-ai-operator' ),
					esc_html( $country )
				)
			);
		}

		$currency = strtoupper( trim( (string) ( $raw['currency'] ?? '' ) ) );

		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new BlueprintValidationException(
				sprintf(
					/* translators: %s: the invalid currency code */
					esc_html__( 'The currency code “%s” is invalid.', 'dosieci-ai-operator' ),
					esc_html( $currency )
				)
			);
		}

		$weightUnit    = strtolower( trim( (string) ( $raw['weight_unit'] ?? 'kg' ) ) );
		$dimensionUnit = strtolower( trim( (string) ( $raw['dimension_unit'] ?? 'cm' ) ) );

		if ( ! in_array( $weightUnit, self::WEIGHT_UNITS, true ) ) {
			throw new BlueprintValidationException(
				sprintf(
					/* translators: %s: the unrecognised weight unit */
					esc_html__( 'Unknown weight unit “%s”.', 'dosieci-ai-operator' ),
					esc_html( $weightUnit )
				)
			);
		}

		if ( ! in_array( $dimensionUnit, self::DIMENSION_UNITS, true ) ) {
			throw new BlueprintValidationException(
				sprintf(
					/* translators: %s: the unrecognised dimension unit */
					esc_html__( 'Unknown dimension unit “%s”.', 'dosieci-ai-operator' ),
					esc_html( $dimensionUnit )
				)
			);
		}

		$categories = array();
		foreach ( (array) ( $raw['categories'] ?? array() ) as $entry ) {
			$categories[] = StoreCategory::fromMixed( $entry );
		}

		if ( count( $categories ) > self::MAX_CATEGORIES ) {
			throw new BlueprintValidationException( esc_html__( 'Too many categories in the store description.', 'dosieci-ai-operator' ) );
		}

		$products = array();
		foreach ( (array) ( $raw['initial_products'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				throw new BlueprintValidationException( esc_html__( 'A product description must be an object.', 'dosieci-ai-operator' ) );
			}

			$products[] = StoreProduct::fromArray( $entry );
		}

		if ( count( $products ) > self::MAX_PRODUCTS ) {
			throw new BlueprintValidationException( esc_html__( 'Too many products in the store description.', 'dosieci-ai-operator' ) );
		}

		self::assertRolesAreUnique( $categories, $products );
		self::assertProductCategoriesExist( $categories, $products );

		return new self( $country, $currency, $weightUnit, $dimensionUnit, $categories, $products );
	}

	/**
	 * @param StoreCategory[] $categories
	 * @param StoreProduct[]  $products
	 *
	 * @throws BlueprintValidationException
	 */
	private static function assertRolesAreUnique( array $categories, array $products ): void {
		// Two resources sharing a logical role would map to one managed
		// identity, so the second would be treated as an update of the
		// first and one of them would silently vanish on the next run.
		foreach ( array( 'categories' => $categories, 'products' => $products ) as $label => $set ) {
			$seen = array();

			foreach ( $set as $item ) {
				$key = ManagedCommerceRole::normalise( $item->role );

				if ( isset( $seen[ $key ] ) ) {
					throw new BlueprintValidationException(
						sprintf(
							/* translators: 1: section name ("categories" or "products"), 2: duplicated name */
							esc_html__( 'Duplicate name in the %1$s section: “%2$s”.', 'dosieci-ai-operator' ),
							esc_html( $label ),
							esc_html( $item->role )
						)
					);
				}

				$seen[ $key ] = true;
			}
		}
	}

	/**
	 * @param StoreCategory[] $categories
	 * @param StoreProduct[]  $products
	 *
	 * @throws BlueprintValidationException
	 */
	private static function assertProductCategoriesExist( array $categories, array $products ): void {
		$known = array();
		foreach ( $categories as $category ) {
			$known[ ManagedCommerceRole::normalise( $category->role ) ] = true;
		}

		foreach ( $products as $product ) {
			foreach ( $product->categoryRoles as $role ) {
				if ( ! isset( $known[ ManagedCommerceRole::normalise( $role ) ] ) ) {
					// Refused rather than dropped: a product silently landing
					// in no category is a shop the merchant has to repair by
					// hand, and they would have no idea why.
					throw new BlueprintValidationException(
						sprintf(
							/* translators: 1: product name, 2: category role that does not exist */
							esc_html__( 'Product “%1$s” references a category that does not exist: “%2$s”.', 'dosieci-ai-operator' ),
							esc_html( $product->name ),
							esc_html( $role )
						)
					);
				}
			}
		}
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'store_country'    => $this->country,
			'currency'         => $this->currency,
			'weight_unit'      => $this->weightUnit,
			'dimension_unit'   => $this->dimensionUnit,
			'categories'       => array_map( static fn( StoreCategory $c ): array => $c->toArray(), $this->categories ),
			'initial_products' => array_map( static fn( StoreProduct $p ): array => $p->toArray(), $this->products ),
		);
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\StoreBlueprint;

/**
 * What the user wants built, as structured data rather than a paragraph of
 * prose the planner has to re-interpret on every turn.
 *
 * This exists so the human reviews the AI's UNDERSTANDING before reviewing
 * its PLAN. "Build me a plumbing company site" underspecifies a dozen
 * decisions (how many pages? shop or no shop? which language?), and a
 * planner that guesses silently produces a twenty-step plan whose first
 * wrong assumption invalidates the other nineteen. Showing the blueprint
 * first makes those guesses correctable while correcting them is still
 * cheap.
 *
 * Deliberately a validated value object, not an associative array: every
 * field a downstream planner or Gutenberg composer reads is guaranteed
 * present and of the right shape by the time it gets here, so those layers
 * contain no defensive re-checking of model output.
 *
 * Note what is NOT here: anything security-relevant. A blueprint cannot
 * grant a capability, name a tool, or widen a risk ceiling -- it only
 * describes a desired outcome. Authorisation lives entirely in the plan/
 * execution layers (ToolDispatcher's gates, unchanged from 1.1).
 */
final class SiteBlueprint {

	public const TYPE_SERVICE_BUSINESS = 'service_business';
	public const TYPE_LANDING_PAGE     = 'landing_page';
	public const TYPE_PORTFOLIO        = 'portfolio';
	public const TYPE_RESTAURANT       = 'restaurant';
	public const TYPE_BLOG             = 'blog';
	public const TYPE_STORE            = 'store';

	public const BUILDER_GUTENBERG = 'gutenberg';
	public const BUILDER_ELEMENTOR = 'elementor';

	private const SITE_TYPES = array(
		self::TYPE_SERVICE_BUSINESS,
		self::TYPE_LANDING_PAGE,
		self::TYPE_PORTFOLIO,
		self::TYPE_RESTAURANT,
		self::TYPE_BLOG,
		self::TYPE_STORE,
	);

	private const PAGE_BUILDERS = array( self::BUILDER_GUTENBERG, self::BUILDER_ELEMENTOR );

	/** Bounded so one blueprint cannot become a thousand-step plan. */
	private const MAX_PAGES    = 20;
	private const MAX_FEATURES = 20;

	/**
	 * @param string[]                    $pages
	 * @param string[]                    $features
	 * @param array<string, string>       $brandColors
	 * @param array<string, mixed>        $store
	 */
	private function __construct(
		public readonly string $siteType,
		public readonly string $businessName,
		public readonly string $language,
		public readonly string $description,
		public readonly string $brandStyle,
		public readonly array $brandColors,
		public readonly array $pages,
		public readonly array $features,
		public readonly bool $woocommerce,
		public readonly string $pageBuilder,
		public readonly array $store,
		/**
		 * The validated store, or null when this is not a shop.
		 *
		 * Null is the normal case and must stay cheap: the overwhelming
		 * majority of sites this builds will never install WooCommerce,
		 * and nothing in the planner, the tools or the audit engages
		 * unless this is set.
		 */
		public readonly ?StoreBlueprint $storeBlueprint = null
	) {
	}

	public function hasStore(): bool {
		return null !== $this->storeBlueprint;
	}

	/**
	 * @param array<string, mixed> $raw model- or UI-supplied, untrusted
	 *
	 * @throws BlueprintValidationException
	 */
	public static function fromArray( array $raw ): self {
		$siteType = self::requireEnum( $raw, 'site_type', self::SITE_TYPES );
		$name     = self::requireString( $raw, 'business_name', 120 );

		// Everything below is optional with an honest default, because a
		// blueprint the user is about to review does not need to be complete
		// to be reviewable -- it needs to be accurate about what it does and
		// does not yet know.
		$language    = self::optionalString( $raw, 'language', 10, 'pl' );
		$description = self::optionalString( $raw, 'description', 2000, '' );
		$brandStyle  = self::optionalString( $raw, 'brand_style', 60, 'modern_professional' );
		$pageBuilder = isset( $raw['page_builder'] )
			? self::requireEnum( $raw, 'page_builder', self::PAGE_BUILDERS )
			: self::BUILDER_GUTENBERG;

		$pages    = self::stringList( $raw, 'pages', self::MAX_PAGES, 120 );
		$features = self::stringList( $raw, 'features', self::MAX_FEATURES, 60 );

		if ( array() === $pages ) {
			throw new BlueprintValidationException( 'Blueprint must define at least one page.' );
		}

		// A store is validated STRICTLY, here, at the same moment as the
		// rest of the blueprint. Deferring it would mean the human reviews
		// a shop description whose prices have not been checked yet.
		$rawStore = is_array( $raw['store'] ?? null ) ? $raw['store'] : array();
		$store    = array() === $rawStore ? null : StoreBlueprint::fromArray( $rawStore );

		// A store site with no store data is not a smaller version of a
		// shop -- it is the UI having lost the shop entirely. Silently
		// falling back to a generic, non-commerce plan here is exactly the
		// failure mode this guards against: better an explicit error the
		// human can act on than eighteen steps that quietly forgot
		// WooCommerce.
		if ( self::TYPE_STORE === $siteType && null === $store ) {
			throw new BlueprintValidationException(
				'Rodzaj witryny „Sklep” wymaga danych sklepu: kraju, waluty, przynajmniej jednej kategorii i produktu.'
			);
		}

		return new self(
			$siteType,
			$name,
			$language,
			$description,
			$brandStyle,
			self::colors( $raw['brand_colors'] ?? array() ),
			$pages,
			$features,
			(bool) ( $raw['woocommerce'] ?? false ) || null !== $store,
			$pageBuilder,
			// The CANONICAL form feeds the hash, so "79" and "79.00" cannot
			// produce two different approvals of the same shop.
			null === $store ? array() : $store->toArray(),
			$store
		);
	}

	public function hasFeature( string $feature ): bool {
		return in_array( $feature, $this->features, true );
	}

	/**
	 * Canonical array form. Key order is fixed and explicit rather than
	 * insertion-dependent, because this feeds the plan hash -- see
	 * ActionPlan::canonicalHash().
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'brand_colors'  => $this->brandColors,
			'brand_style'   => $this->brandStyle,
			'business_name' => $this->businessName,
			'description'   => $this->description,
			'features'      => $this->features,
			'language'      => $this->language,
			'page_builder'  => $this->pageBuilder,
			'pages'         => $this->pages,
			'site_type'     => $this->siteType,
			'store'         => $this->store,
			'woocommerce'   => $this->woocommerce,
		);
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param string[]             $allowed
	 *
	 * @throws BlueprintValidationException
	 */
	private static function requireEnum( array $raw, string $key, array $allowed ): string {
		$value = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';

		if ( ! in_array( $value, $allowed, true ) ) {
			throw new BlueprintValidationException(
				sprintf( '"%s" must be one of: %s.', $key, implode( ', ', $allowed ) )
			);
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $raw
	 *
	 * @throws BlueprintValidationException
	 */
	private static function requireString( array $raw, string $key, int $max ): string {
		$value = trim( isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '' );

		if ( '' === $value ) {
			throw new BlueprintValidationException( sprintf( '"%s" is required.', $key ) );
		}

		if ( mb_strlen( $value ) > $max ) {
			throw new BlueprintValidationException( sprintf( '"%s" exceeds %d characters.', $key, $max ) );
		}

		return $value;
	}

	/** @param array<string, mixed> $raw */
	private static function optionalString( array $raw, string $key, int $max, string $default ): string {
		$value = trim( isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '' );

		if ( '' === $value ) {
			return $default;
		}

		return mb_substr( $value, 0, $max );
	}

	/**
	 * @param array<string, mixed> $raw
	 *
	 * @return string[]
	 *
	 * @throws BlueprintValidationException
	 */
	private static function stringList( array $raw, string $key, int $maxItems, int $maxLength ): array {
		if ( ! isset( $raw[ $key ] ) ) {
			return array();
		}

		if ( ! is_array( $raw[ $key ] ) ) {
			throw new BlueprintValidationException( sprintf( '"%s" must be a list.', $key ) );
		}

		$out = array();
		foreach ( $raw[ $key ] as $item ) {
			if ( ! is_string( $item ) ) {
				throw new BlueprintValidationException( sprintf( '"%s" must contain only strings.', $key ) );
			}

			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}

			$item = mb_substr( $item, 0, $maxLength );

			// De-duplicated here rather than later: two identically-named
			// pages produce two create_post steps and a menu with a
			// duplicate entry, which reads as a bug to the user.
			if ( ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
		}

		if ( count( $out ) > $maxItems ) {
			throw new BlueprintValidationException( sprintf( '"%s" is limited to %d entries.', $key, $maxItems ) );
		}

		return $out;
	}

	/**
	 * Colours are constrained to hex literals. They end up in generated
	 * block markup and inline style attributes, so anything that is not
	 * provably a colour is dropped rather than escaped-and-hoped.
	 *
	 * @return array<string, string>
	 */
	private static function colors( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $role => $value ) {
			if ( ! is_string( $role ) || ! is_string( $value ) ) {
				continue;
			}

			if ( 1 === preg_match( '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value ) ) {
				$out[ strtolower( $role ) ] = strtolower( $value );
			}
		}

		ksort( $out );

		return $out;
	}
}

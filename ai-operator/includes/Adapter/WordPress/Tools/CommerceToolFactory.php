<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\Tools;

use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooCommerceAdapter;
use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\StoreBlueprint;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;

/**
 * The complete set of commerce writes AI Operator can perform. There are
 * five, and the list is the security model.
 *
 * ## What is absent, and why absence is the mechanism
 *
 * There is no tool for payment credentials, REST API keys, webhooks,
 * orders, order status, refunds, customers, coupons, or arbitrary
 * WooCommerce options. Not "guarded", not "discouraged in the prompt" --
 * absent. A model that asks to set a Stripe secret is not refused by a
 * check; there is simply nothing in the registry that could carry the
 * request, and the dispatcher rejects unknown tool names outright.
 *
 * The same reasoning kills the obvious convenience:
 * `set_woocommerce_option( name, value )` would be one tool instead of
 * four fields, and it would also be a route to every setting WooCommerce
 * has, payment gateways included. `set_store_basics` therefore takes four
 * named fields validated against WooCommerce's own lists, and cannot
 * express anything else.
 *
 * ## Products are always drafts
 *
 * No tool here accepts a status. A generated product is a draft, full
 * stop -- publishing a priced item is a commercial decision, and it must
 * not be reachable from a sentence of prose.
 */
final class CommerceToolFactory {

	private const DEFAULT_TIMEOUT = 60;

	public function __construct( private WooCommerceAdapter $woo = new WooCommerceAdapter() ) {
	}

	public function register( ToolRegistry $registry ): void {
		foreach ( $this->definitions() as $definition ) {
			$registry->register( $definition );
		}
	}

	/** The tools this factory owns, for the planner/verifier parity test. @var string[] */
	public const TOOLS = array(
		'ensure_store_pages',
		'set_store_basics',
		'create_product_category',
		'update_product_category',
		'create_product_draft',
		'update_product_draft',
	);

	/** @return ToolDefinition[] */
	private function definitions(): array {
		$categorySchema = array(
			'type'       => 'object',
			'properties' => array(
				'name'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'term_id'     => array( 'type' => 'integer' ),
				'resource_key' => array( 'type' => 'string' ),
				'project_id'   => array( 'type' => 'string' ),
			),
			'required'   => array( 'name' ),
		);

		$productSchema = array(
			'type'       => 'object',
			'properties' => array(
				'name'              => array( 'type' => 'string' ),
				'description'       => array( 'type' => 'string' ),
				'short_description' => array( 'type' => 'string' ),
				'regular_price'     => array( 'type' => 'string' ),
				'category_ids'      => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'image_id'          => array( 'type' => 'integer' ),
				'product_id'        => array( 'type' => 'integer' ),
			),
			'required'   => array( 'name', 'regular_price' ),
		);

		return array(
			new ToolDefinition(
				'ensure_store_pages',
				'Sprawdza strony sklepu (Sklep, Koszyk, Zamówienie, Moje konto) i odtwarza tylko te, których faktycznie brakuje.',
				array( 'type' => 'object', 'properties' => array(), 'required' => array() ),
				'manage_options',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'ensureStorePages' )
			),
			new ToolDefinition(
				'set_store_basics',
				'Ustawia kraj, walutę i jednostki sklepu. Nie może zmienić żadnego innego ustawienia WooCommerce.',
				array(
					'type'       => 'object',
					'properties' => array(
						'country'        => array( 'type' => 'string' ),
						'currency'       => array( 'type' => 'string' ),
						'weight_unit'    => array( 'type' => 'string' ),
						'dimension_unit' => array( 'type' => 'string' ),
					),
					'required'   => array( 'country', 'currency' ),
				),
				'manage_woocommerce',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'setStoreBasics' )
			),
			new ToolDefinition(
				'create_product_category',
				'Tworzy kategorię produktów.',
				$categorySchema,
				'manage_product_terms',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'createProductCategory' )
			),
			new ToolDefinition(
				'update_product_category',
				'Aktualizuje kategorię produktów utworzoną wcześniej przez kreator.',
				$categorySchema,
				'manage_product_terms',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'updateProductCategory' )
			),
			new ToolDefinition(
				'create_product_draft',
				'Tworzy produkt jako SZKIC. Nie publikuje go.',
				$productSchema,
				'publish_products',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'createProductDraft' )
			),
			new ToolDefinition(
				'update_product_draft',
				'Aktualizuje szkic produktu utworzony wcześniej przez kreator. Nie publikuje go.',
				$productSchema,
				'edit_products',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'updateProductDraft' )
			),
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function ensureStorePages( array $args ): array {
		if ( ! $this->woo->isReady() ) {
			// Refused rather than attempted. A request that just activated
			// WooCommerce has not registered its post types, and writing
			// through that half-built state produces objects WordPress
			// cannot read back. Failing here stops the plan cleanly and the
			// user resumes into a request where the shop is really up.
			return array( 'success' => false, 'error' => 'WooCommerce nie jest jeszcze w pełni załadowany w tym żądaniu.' );
		}

		return $this->woo->ensureCorePages();
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function setStoreBasics( array $args ): array {
		if ( ! $this->woo->isReady() ) {
			// Refused rather than attempted. A request that just activated
			// WooCommerce has not registered its post types, and writing
			// through that half-built state produces objects WordPress
			// cannot read back. Failing here stops the plan cleanly and the
			// user resumes into a request where the shop is really up.
			return array( 'success' => false, 'error' => 'WooCommerce nie jest jeszcze w pełni załadowany w tym żądaniu.' );
		}

		$country  = strtoupper( trim( (string) ( $args['country'] ?? '' ) ) );
		$currency = strtoupper( trim( (string) ( $args['currency'] ?? '' ) ) );

		// Checked against the lists the INSTALLED WooCommerce publishes.
		// The blueprint validated shape; only the running plugin knows
		// which codes it actually accepts.
		if ( ! $this->woo->supportsCountry( $country ) ) {
			return array( 'success' => false, 'error' => sprintf( 'WooCommerce nie zna kraju "%s".', $country ) );
		}

		if ( ! $this->woo->supportsCurrency( $currency ) ) {
			return array( 'success' => false, 'error' => sprintf( 'WooCommerce nie zna waluty "%s".', $currency ) );
		}

		$weight    = strtolower( trim( (string) ( $args['weight_unit'] ?? 'kg' ) ) );
		$dimension = strtolower( trim( (string) ( $args['dimension_unit'] ?? 'cm' ) ) );

		if ( ! in_array( $weight, StoreBlueprint::WEIGHT_UNITS, true )
			|| ! in_array( $dimension, StoreBlueprint::DIMENSION_UNITS, true ) ) {
			return array( 'success' => false, 'error' => 'Nieobsługiwana jednostka.' );
		}

		return $this->woo->applyStoreSettings(
			array(
				'country'        => $country,
				'currency'       => $currency,
				'weight_unit'    => $weight,
				'dimension_unit' => $dimension,
			)
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function createProductCategory( array $args ): array {
		if ( ! $this->woo->isReady() ) {
			// Refused rather than attempted. A request that just activated
			// WooCommerce has not registered its post types, and writing
			// through that half-built state produces objects WordPress
			// cannot read back. Failing here stops the plan cleanly and the
			// user resumes into a request where the shop is really up.
			return array( 'success' => false, 'error' => 'WooCommerce nie jest jeszcze w pełni załadowany w tym żądaniu.' );
		}

		return $this->woo->createCategory(
			sanitize_text_field( (string) $args['name'] ),
			sanitize_textarea_field( (string) ( $args['description'] ?? '' ) ),
			// Planner-supplied and hash-covered, never model-supplied: this
			// is which resource the human approved this step to own.
			(string) ( $args['resource_key'] ?? '' ),
			(string) ( $args['project_id'] ?? '' )
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function updateProductCategory( array $args ): array {
		if ( ! $this->woo->isReady() ) {
			// Refused rather than attempted. A request that just activated
			// WooCommerce has not registered its post types, and writing
			// through that half-built state produces objects WordPress
			// cannot read back. Failing here stops the plan cleanly and the
			// user resumes into a request where the shop is really up.
			return array( 'success' => false, 'error' => 'WooCommerce nie jest jeszcze w pełni załadowany w tym żądaniu.' );
		}

		$termId = (int) ( $args['term_id'] ?? 0 );

		if ( $termId <= 0 ) {
			return array( 'success' => false, 'error' => 'Brak identyfikatora kategorii.' );
		}

		return $this->woo->updateCategory(
			$termId,
			sanitize_text_field( (string) $args['name'] ),
			sanitize_textarea_field( (string) ( $args['description'] ?? '' ) )
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function createProductDraft( array $args ): array {
		if ( ! $this->woo->isReady() ) {
			// Refused rather than attempted. A request that just activated
			// WooCommerce has not registered its post types, and writing
			// through that half-built state produces objects WordPress
			// cannot read back. Failing here stops the plan cleanly and the
			// user resumes into a request where the shop is really up.
			return array( 'success' => false, 'error' => 'WooCommerce nie jest jeszcze w pełni załadowany w tym żądaniu.' );
		}

		$price = $this->price( $args );

		if ( null === $price ) {
			return array( 'success' => false, 'error' => 'Nieprawidłowa cena produktu.' );
		}

		return $this->woo->createDraftProduct(
			sanitize_text_field( (string) $args['name'] ),
			wp_kses_post( (string) ( $args['description'] ?? '' ) ),
			wp_kses_post( (string) ( $args['short_description'] ?? '' ) ),
			$price,
			$this->categoryIds( $args ),
			$this->imageId( $args )
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function updateProductDraft( array $args ): array {
		if ( ! $this->woo->isReady() ) {
			// Refused rather than attempted. A request that just activated
			// WooCommerce has not registered its post types, and writing
			// through that half-built state produces objects WordPress
			// cannot read back. Failing here stops the plan cleanly and the
			// user resumes into a request where the shop is really up.
			return array( 'success' => false, 'error' => 'WooCommerce nie jest jeszcze w pełni załadowany w tym żądaniu.' );
		}

		$productId = (int) ( $args['product_id'] ?? 0 );
		$price     = $this->price( $args );

		if ( $productId <= 0 ) {
			return array( 'success' => false, 'error' => 'Brak identyfikatora produktu.' );
		}

		if ( null === $price ) {
			return array( 'success' => false, 'error' => 'Nieprawidłowa cena produktu.' );
		}

		return $this->woo->updateDraftProduct(
			$productId,
			sanitize_text_field( (string) $args['name'] ),
			wp_kses_post( (string) ( $args['description'] ?? '' ) ),
			wp_kses_post( (string) ( $args['short_description'] ?? '' ) ),
			$price,
			$this->categoryIds( $args ),
			$this->imageId( $args )
		);
	}

	/**
	 * Revalidated at dispatch, not trusted from the plan.
	 *
	 * The planner already canonicalised this, and the hash covers it --
	 * but a price is the one argument where a defect anywhere upstream
	 * has a direct commercial cost, so it is checked again against the
	 * same canonical form rather than cast to a float and hoped over.
	 *
	 * @param array<string, mixed> $args
	 */
	private function price( array $args ): ?string {
		$raw = (string) ( $args['regular_price'] ?? '' );

		if ( 1 !== preg_match( '/^\d{1,7}\.\d{2}$/', $raw ) ) {
			return null;
		}

		return $raw;
	}

	/** @param array<string, mixed> $args @return int[] */
	private function categoryIds( array $args ): array {
		$ids = array();

		foreach ( (array) ( $args['category_ids'] ?? array() ) as $id ) {
			$id = (int) $id;

			if ( $id > 0 && term_exists( $id, WooCommerceAdapter::TAXONOMY ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Only a raster image that already exists in the library. There is no
	 * URL argument anywhere in this factory: a product image cannot come
	 * from outside the site.
	 *
	 * @param array<string, mixed> $args
	 */
	private function imageId( array $args ): int {
		$id = (int) ( $args['image_id'] ?? 0 );

		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return 0;
		}

		$mime = (string) get_post_mime_type( $id );

		return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ), true )
			? $id
			: 0;
	}
}

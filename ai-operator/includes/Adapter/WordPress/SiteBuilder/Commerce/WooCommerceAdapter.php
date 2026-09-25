<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce;

use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\WpManagedResourceResolver;
use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\CommerceAdapterInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\ManagedCommerceRole;
use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\StoreProduct;
use DoSieci\AiOperator\Domain\SiteBuilder\ManagedResource;
use DoSieci\AiOperator\Domain\SiteBuilder\ResourceResolution;

/**
 * Every WooCommerce call the builder makes, in one class.
 *
 * Written against the API surface verified in
 * `docs/architecture/WOOCOMMERCE_API_NOTES.md` on WooCommerce 11.0.1 --
 * not from documentation, and not from memory. Two findings recorded
 * there shape this file directly:
 *
 * - `wc_create_page()` does not exist in 11.0.1. Code written from memory
 *   against it fatals on first use. Core pages come from
 *   `WC_Install::create_pages()`, which is public, static and idempotent.
 * - After a Woo page is deleted, `wc_get_page_id()` keeps returning the
 *   stale id, because the option is not cleared. A non-zero id is
 *   therefore NOT evidence a page exists -- the same class of mistake as
 *   trusting a write handler's `success => true`.
 *
 * ## Products are not pages
 *
 * A product's meaningful state is spread across post fields and meta:
 * the price is not in `post_content`. So the generic post reconciliation
 * cannot fingerprint one correctly, and product resolution lives here
 * where WC_Product knowledge belongs. The identity markers are the same
 * meta keys everything else uses, so a product is managed by exactly the
 * mechanism a page is.
 */
final class WooCommerceAdapter implements CommerceAdapterInterface {

	/** @var string[] Woo core pages the builder cares about, by their option-name suffix. */
	public const CORE_PAGE_SLUGS = array( 'shop', 'cart', 'checkout', 'myaccount' );

	/**
	 * Human-readable labels for the core pages, for check messages. Not a
	 * class constant: constant expressions cannot call __().
	 *
	 * @return array<string, string>
	 */
	public static function coreLabels(): array {
		return array(
			'shop'      => __( 'Shop', 'dosieci-ai-operator' ),
			'cart'      => __( 'Cart', 'dosieci-ai-operator' ),
			'checkout'  => __( 'Checkout', 'dosieci-ai-operator' ),
			'myaccount' => __( 'My account', 'dosieci-ai-operator' ),
		);
	}

	public function __construct( private WpManagedResourceResolver $resources = new WpManagedResourceResolver() ) {
	}

	public function isAvailable(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_id' );
	}

	/**
	 * Whether WooCommerce has finished booting in THIS request.
	 *
	 * Loaded is not the same as ready. A request that activates
	 * WooCommerce does so after `init` has already run, so its post types
	 * and taxonomies are never registered and its data stores are half
	 * built. Writing a product in that state produced a product WordPress
	 * could not then read back -- found on the first real store build,
	 * where `create_product_draft` reported success and verification said
	 * "Product 320 does not exist".
	 *
	 * Commerce tools therefore refuse rather than write. The plan stops,
	 * the user resumes, and the next request has a fully booted shop --
	 * which is what resumable execution is for.
	 */
	public function isReady(): bool {
		return $this->isAvailable()
			&& did_action( 'woocommerce_after_register_post_type' ) > 0
			&& post_type_exists( 'product' );
	}

	public function label(): string {
		return 'WooCommerce';
	}

	public function version(): string {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
	}

	// -----------------------------------------------------------------
	// Capability lists -- the installed plugin is the only authority
	// -----------------------------------------------------------------

	public function supportsCurrency( string $code ): bool {
		if ( ! function_exists( 'get_woocommerce_currencies' ) ) {
			return false;
		}

		return array_key_exists( strtoupper( $code ), (array) get_woocommerce_currencies() );
	}

	public function supportsCountry( string $code ): bool {
		$countries = $this->countries();

		if ( null === $countries ) {
			return false;
		}

		// "PL" and "US:CA" are both valid; only the country half is a code.
		$country = strtoupper( explode( ':', $code )[0] );

		return array_key_exists( $country, (array) $countries->get_countries() );
	}

	/**
	 * WooCommerce's country list, however it can be reached.
	 *
	 * `WC()->countries` is populated during WooCommerce's own `init`, so it
	 * is NULL in any request that activated the plugin later than that --
	 * which is precisely the request a store build activates it in. Found
	 * on the first real store run, where `set_store_basics` died on
	 * "Call to a member function get_countries() on null".
	 *
	 * Constructing WC_Countries directly is the documented public class and
	 * costs nothing, so the fallback is simply always correct.
	 */
	private function countries(): ?\WC_Countries {
		if ( ! $this->isAvailable() ) {
			return null;
		}

		if ( function_exists( 'WC' ) && WC()->countries instanceof \WC_Countries ) {
			return WC()->countries;
		}

		return class_exists( 'WC_Countries' ) ? new \WC_Countries() : null;
	}

	// -----------------------------------------------------------------
	// Core pages
	// -----------------------------------------------------------------

	/**
	 * What Woo believes about each core page, and whether that belief is
	 * true.
	 *
	 * @return array<string, array{page_id:int, exists:bool, status:string, title:string}>
	 */
	public function corePageState(): array {
		$state = array();

		foreach ( self::CORE_PAGE_SLUGS as $slug ) {
			$id   = (int) get_option( 'woocommerce_' . $slug . '_page_id', 0 );
			$post = $id > 0 ? get_post( $id ) : null;

			// A live page means: the post is really there, it is a page, and
			// it is not in the trash. The stale-id bug makes every one of
			// those three worth checking separately.
			$exists = $post instanceof \WP_Post
				&& 'page' === $post->post_type
				&& 'trash' !== $post->post_status;

			$state[ $slug ] = array(
				'page_id' => $id,
				'exists'  => $exists,
				'status'  => $post instanceof \WP_Post ? (string) $post->post_status : '',
				'title'   => $post instanceof \WP_Post ? (string) $post->post_title : '',
			);
		}

		return $state;
	}

	/** The core pages Woo currently has no live page for. @return string[] */
	public function missingCorePages(): array {
		$missing = array();

		foreach ( $this->corePageState() as $slug => $state ) {
			if ( true !== $state['exists'] ) {
				$missing[] = $slug;
			}
		}

		return $missing;
	}

	/**
	 * Repairs whatever is missing, and only that.
	 *
	 * WooCommerce creates these itself on activation, so the normal
	 * outcome here is that nothing happens. `WC_Install::create_pages()`
	 * checks each page before creating it, so calling it when one page is
	 * gone recreates exactly that one -- verified: deleting Cart and
	 * calling it left precisely one page titled "Cart", not two.
	 *
	 * @return array<string, mixed>
	 */
	public function ensureCorePages(): array {
		$before = $this->missingCorePages();

		if ( array() !== $before ) {
			if ( ! class_exists( 'WC_Install' ) ) {
				return array( 'success' => false, 'error' => __( 'WooCommerce is not fully loaded.', 'dosieci-ai-operator' ) );
			}

			// Clear the stale ids first. create_pages() skips a page whose
			// option is set, and after a delete that option still points at
			// the dead post -- so without this the repair silently does
			// nothing and the page never comes back.
			foreach ( $before as $slug ) {
				delete_option( 'woocommerce_' . $slug . '_page_id' );
			}

			\WC_Install::create_pages();
		}

		$after = $this->missingCorePages();

		return array(
			'repaired'     => $before,
			'still_missing' => $after,
			'pages'        => $this->corePageState(),
		);
	}

	// -----------------------------------------------------------------
	// Store settings
	// -----------------------------------------------------------------

	/**
	 * The four settings this builder is allowed to touch, and nothing
	 * else. There is deliberately no "set any option" primitive: an
	 * option name accepted as an argument is a way to reach every setting
	 * WooCommerce has, including the payment ones.
	 *
	 * @var array<string, string>
	 */
	public const SETTING_OPTIONS = array(
		'country'        => 'woocommerce_default_country',
		'currency'       => 'woocommerce_currency',
		'weight_unit'    => 'woocommerce_weight_unit',
		'dimension_unit' => 'woocommerce_dimension_unit',
	);

	/** @return array<string, string> */
	public function storeSettings(): array {
		$out = array();

		foreach ( self::SETTING_OPTIONS as $field => $option ) {
			$out[ $field ] = (string) get_option( $option, '' );
		}

		return $out;
	}

	/**
	 * @param array<string, string> $settings keyed by SETTING_OPTIONS field
	 *
	 * @return array<string, mixed>
	 */
	public function applyStoreSettings( array $settings ): array {
		$applied = array();

		foreach ( self::SETTING_OPTIONS as $field => $option ) {
			if ( ! isset( $settings[ $field ] ) ) {
				continue;
			}

			update_option( $option, (string) $settings[ $field ] );
			$applied[ $field ] = (string) $settings[ $field ];
		}

		return array( 'applied' => $applied, 'settings' => $this->storeSettings() );
	}

	/**
	 * Read back through WooCommerce's own accessors rather than
	 * get_option, because those are what the store actually behaves
	 * according to.
	 *
	 * @return array<string, string>
	 */
	public function effectiveSettings(): array {
		$base = function_exists( 'wc_get_base_location' ) ? (array) wc_get_base_location() : array();
		$country = (string) ( $base['country'] ?? '' );
		$state   = (string) ( $base['state'] ?? '' );

		return array(
			'country'        => '' !== $state ? $country . ':' . $state : $country,
			'currency'       => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'weight_unit'    => (string) get_option( 'woocommerce_weight_unit', '' ),
			'dimension_unit' => (string) get_option( 'woocommerce_dimension_unit', '' ),
		);
	}

	// -----------------------------------------------------------------
	// Categories
	// -----------------------------------------------------------------

	public const TAXONOMY = 'product_cat';

	/**
	 * @return array<string, mixed>
	 */
	/**
	 * Creates the category, or recognises one we already own.
	 *
	 * ## Why this cannot just call wp_insert_term
	 *
	 * Reconciliation normally happens at PLAN time, so the human approves
	 * "create" or "update" as a visible decision. A store build cannot do
	 * that for its own categories: WooCommerce is installed by step 13 of
	 * the same plan, so at plan time `product_cat` is not a registered
	 * taxonomy and there is literally nothing to reconcile against. Every
	 * category is therefore planned as a CREATE.
	 *
	 * That is correct on a fresh site and wrong on a retry, where the
	 * category from the failed run is still there. Found exactly that way:
	 * a recovery run died on "A term with the name provided already
	 * exists".
	 *
	 * So ownership is re-checked here, at execution:
	 *
	 * - Ours, same project → update it. Idempotent, no duplicate.
	 * - Somebody else's, same name → REFUSED. Adopting it would be
	 *   name-based idempotency, which is the one thing managed identity
	 *   exists to prevent. The next plan is generated with WooCommerce
	 *   active and will show the conflict properly.
	 *
	 * @return array<string, mixed>
	 */
	public function createCategory( string $name, string $description, string $resourceKey = '', string $projectId = '' ): array {
		if ( '' !== $resourceKey && ManagedResource::isValidKey( $resourceKey ) ) {
			$owned = $this->resources->findManagedTerm(
				ManagedResource::fromKey( $resourceKey ),
				$projectId,
				self::TAXONOMY
			);

			if ( null !== $owned ) {
				return $this->updateCategory( $owned, $name, $description );
			}
		}

		$term = wp_insert_term( $name, self::TAXONOMY, array( 'description' => $description ) );

		if ( is_wp_error( $term ) ) {
			if ( 'term_exists' === $term->get_error_code() ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: category name */
						__( 'A category called “%s” already exists and the builder did not create it. Regenerate the plan to see the conflict.', 'dosieci-ai-operator' ),
						$name
					),
				);
			}

			return array( 'success' => false, 'error' => $term->get_error_message() );
		}

		return array( 'term_id' => (int) $term['term_id'], 'name' => $name, 'created' => true );
	}

	/** @return array<string, mixed> */
	public function updateCategory( int $termId, string $name, string $description ): array {
		$updated = wp_update_term( $termId, self::TAXONOMY, array( 'name' => $name, 'description' => $description ) );

		if ( is_wp_error( $updated ) ) {
			return array( 'success' => false, 'error' => $updated->get_error_message() );
		}

		return array( 'term_id' => $termId, 'name' => $name, 'created' => false );
	}

	/** @return array<string, mixed>|null */
	public function readCategory( int $termId ): ?array {
		$term = get_term( $termId, self::TAXONOMY );

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return array(
			'term_id'     => (int) $term->term_id,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'count'       => (int) $term->count,
		);
	}

	/** Term ids for a set of blueprint category roles, in the given order. @return int[] */
	public function categoryIdsForRoles( array $roles, string $projectId ): array {
		$ids = array();

		foreach ( $roles as $role ) {
			$found = $this->resources->findManagedTerm(
				ManagedCommerceRole::category( (string) $role ),
				$projectId,
				self::TAXONOMY
			);

			if ( null !== $found ) {
				$ids[] = $found;
			}
		}

		return $ids;
	}

	// -----------------------------------------------------------------
	// Products
	// -----------------------------------------------------------------

	/**
	 * Creates a DRAFT product. There is no status argument, here or
	 * anywhere above this line: publishing is a commercial decision with
	 * a price attached and is out of scope for 1.2.
	 *
	 * @param int[] $categoryIds
	 *
	 * @return array<string, mixed>
	 */
	public function createDraftProduct(
		string $name,
		string $description,
		string $shortDescription,
		string $regularPrice,
		array $categoryIds,
		int $imageId = 0
	): array {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return array( 'success' => false, 'error' => __( 'WooCommerce is not fully loaded.', 'dosieci-ai-operator' ) );
		}

		$product = new \WC_Product_Simple();

		return $this->writeProduct( $product, $name, $description, $shortDescription, $regularPrice, $categoryIds, $imageId, true );
	}

	/**
	 * @param int[] $categoryIds
	 *
	 * @return array<string, mixed>
	 */
	public function updateDraftProduct(
		int $productId,
		string $name,
		string $description,
		string $shortDescription,
		string $regularPrice,
		array $categoryIds,
		int $imageId = 0
	): array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $productId ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %d: product ID */
					__( 'Product %d does not exist.', 'dosieci-ai-operator' ),
					$productId
				),
			);
		}

		return $this->writeProduct( $product, $name, $description, $shortDescription, $regularPrice, $categoryIds, $imageId, false );
	}

	/**
	 * @param int[] $categoryIds
	 *
	 * @return array<string, mixed>
	 */
	private function writeProduct(
		\WC_Product $product,
		string $name,
		string $description,
		string $shortDescription,
		string $regularPrice,
		array $categoryIds,
		int $imageId,
		bool $created
	): array {
		$product->set_name( $name );
		$product->set_description( $description );
		$product->set_short_description( $shortDescription );
		$product->set_regular_price( $regularPrice );
		$product->set_status( 'draft' );

		if ( array() !== $categoryIds ) {
			$product->set_category_ids( array_map( 'intval', $categoryIds ) );
		}

		if ( $imageId > 0 ) {
			$product->set_image_id( $imageId );
		}

		$id = $product->save();

		if ( ! is_int( $id ) || $id <= 0 ) {
			return array( 'success' => false, 'error' => __( 'WooCommerce did not save the product.', 'dosieci-ai-operator' ) );
		}

		return array(
			'product_id' => $id,
			// The recorder recognises post_id; a product IS a post, and
			// reusing that field keeps ownership stamping uniform.
			'post_id'    => $id,
			'created'    => $created,
			'status'     => (string) $product->get_status(),
			'price'      => (string) $product->get_regular_price(),
		);
	}

	/** @return array<string, mixed>|null */
	public function readProduct( int $productId ): ?array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $productId ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		return array(
			'product_id'   => (int) $product->get_id(),
			'name'         => (string) $product->get_name(),
			'status'       => (string) $product->get_status(),
			'price'        => (string) $product->get_regular_price(),
			'categories'   => array_map( 'intval', (array) $product->get_category_ids() ),
			'image_id'     => (int) $product->get_image_id(),
			'description'  => (string) $product->get_description(),
			'short'        => (string) $product->get_short_description(),
		);
	}

	// -----------------------------------------------------------------
	// Product reconciliation
	// -----------------------------------------------------------------

	/**
	 * The canonical string a product's fingerprint is taken over.
	 *
	 * Price is in it, which is the point: a merchant repricing a draft
	 * from 79 to 89 is a commercial decision, and the next run must see a
	 * conflict rather than quietly restoring 79. Categories and image are
	 * in it for the same reason.
	 *
	 * Both sides of the comparison come from here, so there is only ever
	 * one spelling of "what this product is".
	 *
	 * @param int[] $categoryIds
	 */
	public static function productContent(
		string $name,
		string $description,
		string $shortDescription,
		string $price,
		array $categoryIds,
		int $imageId
	): string {
		$categories = array_map( 'intval', $categoryIds );
		sort( $categories );

		return implode(
			"\n",
			array(
				trim( $name ),
				trim( $description ),
				trim( $shortDescription ),
				$price,
				implode( ',', $categories ),
				(string) $imageId,
			)
		);
	}

	public function resolveProduct( StoreProduct $product, string $projectId, int $imageId ): ResourceResolution {
		$resource = ManagedCommerceRole::product( $product->role );
		$key      = $resource->key();

		if ( ! $this->isAvailable() ) {
			return ResourceResolution::create( $key, __( 'WooCommerce is not active yet.', 'dosieci-ai-operator' ) );
		}

		$existing = $this->resources->findManaged( $resource, $projectId );

		if ( null === $existing ) {
			// Unlike pages and categories there is no name-match conflict
			// check here: a merchant's own product with a similar name is
			// not the same resource, and refusing to build because a name
			// collides would block the common case of a real catalogue.
			// Managed identity is what decides ownership.
			return ResourceResolution::create( $key, __( 'No existing product.', 'dosieci-ai-operator' ) );
		}

		$current = $this->readProduct( $existing );

		if ( null === $current || 'trash' === $current['status'] ) {
			return ResourceResolution::create( $key, __( 'The previous product was deleted.', 'dosieci-ai-operator' ) );
		}

		$recorded    = (string) get_post_meta( $existing, WpManagedResourceResolver::META_FINGERPRINT, true );
		$currentHash = ManagedResource::fingerprint(
			self::productContent(
				$current['name'],
				$current['description'],
				$current['short'],
				$current['price'],
				$current['categories'],
				$current['image_id']
			)
		);

		if ( '' !== $recorded && ! hash_equals( $recorded, $currentHash ) ) {
			return ResourceResolution::conflict(
				$key,
				$existing,
				__( 'The product was edited by hand after the builder’s last change.', 'dosieci-ai-operator' )
			);
		}

		$desired = ManagedResource::fingerprint(
			self::productContent(
				$product->name,
				$product->description,
				$product->shortDescription,
				$product->regularPrice,
				$this->categoryIdsForRoles( $product->categoryRoles, $projectId ),
				$imageId
			)
		);

		$authored = (string) get_post_meta( $existing, WpManagedResourceResolver::META_AUTHORED, true );
		$baseline = '' !== $authored ? $authored : $currentHash;

		if ( hash_equals( $baseline, $desired ) ) {
			return ResourceResolution::reuse( $key, $existing, __( 'The product already matches the plan.', 'dosieci-ai-operator' ), true );
		}

		return ResourceResolution::updateManaged( $key, $existing, __( 'Product created by the builder, with no manual changes.', 'dosieci-ai-operator' ) );
	}
}

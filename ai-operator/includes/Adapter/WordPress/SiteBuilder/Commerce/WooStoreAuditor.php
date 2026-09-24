<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce;

use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\WpManagedResourceResolver;
use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\ManagedCommerceRole;
use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\StoreBlueprint;

/**
 * The independent read-only pass over a shop, run after every step of a
 * store plan has individually passed.
 *
 * Per-step verification answers "did step 9 create that product?". This
 * asks the question a merchant would: "is the shop I described actually
 * there, and is anything priced live that should not be?"
 *
 * The two diverge. A later step can undo an earlier one, another plugin
 * can react to a save, and a product created as a draft can be published
 * by something else entirely between step 9 and the end of the run. The
 * draft check below exists precisely because "it was a draft when we
 * wrote it" is not the same claim as "it is a draft now".
 */
final class WooStoreAuditor {

	public function __construct(
		private WooCommerceAdapter $woo = new WooCommerceAdapter(),
		private WpManagedResourceResolver $resources = new WpManagedResourceResolver()
	) {
	}

	/**
	 * @return array<int, array{check:string, passed:bool, detail:string}>
	 */
	public function audit( StoreBlueprint $store, string $projectId ): array {
		if ( ! $this->woo->isAvailable() ) {
			return array( $this->row( 'woocommerce_active', false, 'WooCommerce nie jest aktywny.' ) );
		}

		$checks = array(
			$this->row( 'woocommerce_active', true, sprintf( 'WooCommerce %s jest aktywny.', $this->woo->version() ) ),
		);

		$checks = array_merge( $checks, $this->corePages(), $this->settings( $store ) );
		$checks = array_merge( $checks, $this->categories( $store, $projectId ) );

		return array_merge( $checks, $this->products( $store, $projectId ) );
	}

	/** @return array<int, array{check:string, passed:bool, detail:string}> */
	private function corePages(): array {
		$rows = array();

		foreach ( $this->woo->corePageState() as $slug => $page ) {
			$label = WooCommerceAdapter::CORE_PAGES[ $slug ] ?? $slug;

			// Checks the live post, not the option. WooCommerce keeps a page
			// id after the page is deleted, so a non-zero option proves
			// nothing -- verified on 11.0.1.
			$rows[] = $this->row(
				'store_page_' . $slug,
				true === $page['exists'],
				true === $page['exists']
					? sprintf( 'Strona „%s” działa (id %d).', $label, $page['page_id'] )
					: sprintf( 'Strona „%s” nie istnieje lub nie jest przypisana.', $label )
			);
		}

		return $rows;
	}

	/** @return array<int, array{check:string, passed:bool, detail:string}> */
	private function settings( StoreBlueprint $store ): array {
		$actual = $this->woo->effectiveSettings();

		$expected = array(
			'store_country'        => array( 'country', $store->country ),
			'store_currency'       => array( 'currency', $store->currency ),
			'store_weight_unit'    => array( 'weight_unit', $store->weightUnit ),
			'store_dimension_unit' => array( 'dimension_unit', $store->dimensionUnit ),
		);

		$rows = array();

		foreach ( $expected as $check => [$field, $want] ) {
			$have   = (string) ( $actual[ $field ] ?? '' );
			$passed = 0 === strcasecmp( $have, (string) $want );

			$rows[] = $this->row(
				$check,
				$passed,
				$passed
					? sprintf( '%s: %s.', $field, $have )
					: sprintf( '%s to „%s”, oczekiwano „%s”.', $field, $have, (string) $want )
			);
		}

		return $rows;
	}

	/** @return array<int, array{check:string, passed:bool, detail:string}> */
	private function categories( StoreBlueprint $store, string $projectId ): array {
		$rows = array();

		foreach ( $store->categories as $category ) {
			$resource = ManagedCommerceRole::category( $category->role );
			$termId   = $this->resources->findManagedTerm( $resource, $projectId, WooCommerceAdapter::TAXONOMY );
			$term     = null === $termId ? null : $this->woo->readCategory( $termId );

			$rows[] = $this->row(
				'store_category_' . $resource->slug,
				null !== $term,
				null !== $term
					? sprintf( 'Kategoria „%s” istnieje (id %d).', $term['name'], $term['term_id'] )
					: sprintf( 'Brak kategorii „%s”.', $category->name )
			);
		}

		return $rows;
	}

	/** @return array<int, array{check:string, passed:bool, detail:string}> */
	private function products( StoreBlueprint $store, string $projectId ): array {
		$rows = array();

		foreach ( $store->products as $product ) {
			$resource  = ManagedCommerceRole::product( $product->role );
			$productId = $this->resources->findManaged( $resource, $projectId );
			$live      = null === $productId ? null : $this->woo->readProduct( $productId );

			if ( null === $live ) {
				$rows[] = $this->row(
					'store_product_' . $resource->slug,
					false,
					sprintf( 'Brak produktu „%s”.', $product->name )
				);

				continue;
			}

			// The invariant worth auditing separately from the price: nothing
			// this builder generated may be live in the shop.
			if ( 'draft' !== $live['status'] ) {
				$rows[] = $this->row(
					'store_product_' . $resource->slug,
					false,
					sprintf( 'Produkt „%s” ma status „%s”, a musi pozostać szkicem.', $live['name'], $live['status'] )
				);

				continue;
			}

			if ( $this->canonicalPrice( $live['price'] ) !== $this->canonicalPrice( $product->regularPrice ) ) {
				$rows[] = $this->row(
					'store_product_' . $resource->slug,
					false,
					sprintf(
						'Produkt „%s”: oczekiwano %s, jest %s.',
						$live['name'],
						$product->regularPrice,
						$live['price']
					)
				);

				continue;
			}

			$expectedCategories = $this->woo->categoryIdsForRoles( $product->categoryRoles, $projectId );
			$actualCategories   = $live['categories'];
			sort( $expectedCategories );
			sort( $actualCategories );

			if ( array() !== $expectedCategories && $expectedCategories !== $actualCategories ) {
				$rows[] = $this->row(
					'store_product_' . $resource->slug,
					false,
					sprintf( 'Produkt „%s” jest w innych kategoriach niż zaplanowano.', $live['name'] )
				);

				continue;
			}

			$rows[] = $this->row(
				'store_product_' . $resource->slug,
				true,
				sprintf( 'Szkic „%s” — %s.', $live['name'], $live['price'] )
			);
		}

		return $rows;
	}

	private function canonicalPrice( string $price ): string {
		$price = trim( $price );

		return 1 === preg_match( '/^\d{1,9}(\.\d{1,2})?$/', $price )
			? number_format( (float) $price, 2, '.', '' )
			: $price;
	}

	/** @return array{check:string, passed:bool, detail:string} */
	private function row( string $check, bool $passed, string $detail ): array {
		return array( 'check' => $check, 'passed' => $passed, 'detail' => $detail );
	}
}

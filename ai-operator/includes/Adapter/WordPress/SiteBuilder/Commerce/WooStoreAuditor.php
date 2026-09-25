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
			return array( $this->row( 'woocommerce_active', false, __( 'WooCommerce is not active.', 'dosieci-ai-operator' ) ) );
		}

		$checks = array(
			$this->row(
				'woocommerce_active',
				true,
				sprintf(
					/* translators: %s: WooCommerce version number */
					__( 'WooCommerce %s is active.', 'dosieci-ai-operator' ),
					$this->woo->version()
				)
			),
		);

		$checks = array_merge( $checks, $this->corePages(), $this->settings( $store ) );
		$checks = array_merge( $checks, $this->categories( $store, $projectId ) );

		return array_merge( $checks, $this->products( $store, $projectId ) );
	}

	/** @return array<int, array{check:string, passed:bool, detail:string}> */
	private function corePages(): array {
		$rows   = array();
		$labels = WooCommerceAdapter::coreLabels();

		foreach ( $this->woo->corePageState() as $slug => $page ) {
			$label = $labels[ $slug ] ?? $slug;

			// Checks the live post, not the option. WooCommerce keeps a page
			// id after the page is deleted, so a non-zero option proves
			// nothing -- verified on 11.0.1.
			$rows[] = $this->row(
				'store_page_' . $slug,
				true === $page['exists'],
				true === $page['exists']
					? sprintf(
						/* translators: 1: store page label, 2: post ID */
						__( 'Page “%1$s” works (id %2$d).', 'dosieci-ai-operator' ),
						$label,
						$page['page_id']
					)
					: sprintf(
						/* translators: %s: store page label */
						__( 'Page “%s” does not exist or is not assigned.', 'dosieci-ai-operator' ),
						$label
					)
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
					? sprintf(
						/* translators: 1: setting field name, 2: setting value */
						__( '%1$s: %2$s.', 'dosieci-ai-operator' ),
						$field,
						$have
					)
					: sprintf(
						/* translators: 1: setting field name, 2: actual value, 3: expected value */
						__( '%1$s is “%2$s”, expected “%3$s”.', 'dosieci-ai-operator' ),
						$field,
						$have,
						(string) $want
					)
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
					? sprintf(
						/* translators: 1: category name, 2: term ID */
						__( 'Category “%1$s” exists (id %2$d).', 'dosieci-ai-operator' ),
						$term['name'],
						$term['term_id']
					)
					: sprintf(
						/* translators: %s: category name */
						__( 'Category “%s” is missing.', 'dosieci-ai-operator' ),
						$category->name
					)
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
					sprintf(
						/* translators: %s: product name */
						__( 'Product “%s” is missing.', 'dosieci-ai-operator' ),
						$product->name
					)
				);

				continue;
			}

			// The invariant worth auditing separately from the price: nothing
			// this builder generated may be live in the shop.
			if ( 'draft' !== $live['status'] ) {
				$rows[] = $this->row(
					'store_product_' . $resource->slug,
					false,
					sprintf(
						/* translators: 1: product name, 2: actual product status */
						__( 'Product “%1$s” has status “%2$s”, but must remain a draft.', 'dosieci-ai-operator' ),
						$live['name'],
						$live['status']
					)
				);

				continue;
			}

			if ( $this->canonicalPrice( $live['price'] ) !== $this->canonicalPrice( $product->regularPrice ) ) {
				$rows[] = $this->row(
					'store_product_' . $resource->slug,
					false,
					sprintf(
						/* translators: 1: product name, 2: expected price, 3: actual price */
						__( 'Product “%1$s”: expected %2$s, is %3$s.', 'dosieci-ai-operator' ),
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
					sprintf(
						/* translators: %s: product name */
						__( 'Product “%s” is filed under different categories than planned.', 'dosieci-ai-operator' ),
						$live['name']
					)
				);

				continue;
			}

			$rows[] = $this->row(
				'store_product_' . $resource->slug,
				true,
				sprintf(
					/* translators: 1: product name, 2: product price */
					__( 'Draft “%1$s” — %2$s.', 'dosieci-ai-operator' ),
					$live['name'],
					$live['price']
				)
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

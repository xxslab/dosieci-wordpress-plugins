<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintValidationException;
use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\ManagedCommerceRole;
use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\StoreBlueprint;
use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\StoreProduct;
use DoSieci\AiOperator\Domain\SiteBuilder\ManagedResource;
use PHPUnit\Framework\TestCase;

/**
 * A blueprint describing a shop carries the one kind of field where a
 * wrong value is not embarrassing but expensive: the price. These tests
 * pin the validation, and pin that the model cannot express anything the
 * builder would act on beyond an outcome.
 */
final class StoreBlueprintTest extends TestCase {

	/** @param array<string, mixed> $overrides */
	private function store( array $overrides = array() ): StoreBlueprint {
		return StoreBlueprint::fromArray(
			array_merge(
				array(
					'store_country'    => 'PL',
					'currency'         => 'PLN',
					'weight_unit'      => 'kg',
					'dimension_unit'   => 'cm',
					'categories'       => array( 'Koszulki', 'Bluzy' ),
					'initial_products' => array(
						array(
							'name'           => 'Koszulka Classic',
							'regular_price'  => '79.00',
							'category_roles' => array( 'Koszulki' ),
						),
					),
				),
				$overrides
			)
		);
	}

	// --- shape ------------------------------------------------------------

	public function test_a_plain_store_description_validates(): void {
		$store = $this->store();

		$this->assertSame( 'PL', $store->country );
		$this->assertSame( 'PLN', $store->currency );
		$this->assertCount( 2, $store->categories );
		$this->assertSame( 'Koszulki', $store->categories[0]->name );
	}

	public function test_a_category_may_be_a_bare_name_or_an_object(): void {
		// A model asked for "kategorie Koszulki i Bluzy" produces strings.
		// Refusing that would fail the common case for no benefit.
		$store = $this->store(
			array( 'categories' => array( 'Koszulki', array( 'name' => 'Bluzy', 'description' => 'Ciepłe.' ) ) )
		);

		$this->assertSame( 'Koszulki', $store->categories[0]->name );
		$this->assertSame( 'Ciepłe.', $store->categories[1]->description );
	}

	public function test_a_country_with_a_state_suffix_is_accepted(): void {
		$this->assertSame( 'US:CA', $this->store( array( 'store_country' => 'us:ca' ) )->country );
	}

	public function test_a_malformed_country_or_currency_is_rejected_not_defaulted(): void {
		// Substituting a default would quietly set up a shop in the wrong
		// country, which is a tax question, not a cosmetic one.
		$this->expectException( BlueprintValidationException::class );

		$this->store( array( 'store_country' => 'Polska' ) );
	}

	public function test_an_unknown_currency_shape_is_rejected(): void {
		$this->expectException( BlueprintValidationException::class );

		$this->store( array( 'currency' => 'złoty' ) );
	}

	public function test_an_unknown_unit_is_rejected(): void {
		$this->expectException( BlueprintValidationException::class );

		$this->store( array( 'weight_unit' => 'stones' ) );
	}

	// --- price ------------------------------------------------------------

	public function test_prices_normalise_to_a_canonical_decimal_string(): void {
		$this->assertSame( '79.00', StoreProduct::price( '79' ) );
		$this->assertSame( '79.00', StoreProduct::price( 79 ) );
		$this->assertSame( '79.90', StoreProduct::price( 79.9 ) );
		// A Polish-language model writes the comma separator, and this
		// feature's primary market is Poland.
		$this->assertSame( '79.90', StoreProduct::price( '79,90' ) );
		$this->assertSame( '1299.00', StoreProduct::price( '1 299' ) );
	}

	public function test_a_price_that_is_not_a_number_is_refused_never_guessed(): void {
		// "about 80 PLN" has no defensible numeric reading, and guessing
		// one puts an invented number on a real shop.
		$this->expectException( BlueprintValidationException::class );

		StoreProduct::price( 'około 80 zł' );
	}

	public function test_a_missing_price_is_refused(): void {
		$this->expectException( BlueprintValidationException::class );

		StoreProduct::price( null );
	}

	public function test_a_negative_or_absurd_price_is_refused(): void {
		$caught = 0;

		foreach ( array( '-5', '99999999' ) as $bad ) {
			try {
				StoreProduct::price( $bad );
			} catch ( BlueprintValidationException ) {
				++$caught;
			}
		}

		$this->assertSame( 2, $caught );
	}

	public function test_a_price_keeps_at_most_two_decimals(): void {
		$this->expectException( BlueprintValidationException::class );

		StoreProduct::price( '79.999' );
	}

	// --- what the model cannot express ------------------------------------

	public function test_a_product_has_no_way_to_ask_to_be_published(): void {
		// Publishing is a commercial decision with a price attached. There
		// is deliberately no field in which a blueprint could request it.
		$product = StoreProduct::fromArray(
			array( 'name' => 'X', 'regular_price' => '1', 'status' => 'publish', 'post_status' => 'publish' )
		);

		$this->assertFalse( property_exists( $product, 'status' ) );
		$this->assertArrayNotHasKey( 'status', $product->toArray() );
	}

	public function test_an_unknown_image_strategy_is_rejected_not_defaulted(): void {
		// Silently substituting a different strategy is how "no images
		// please" turns into an image.
		$this->expectException( BlueprintValidationException::class );

		StoreProduct::fromArray(
			array( 'name' => 'X', 'regular_price' => '1', 'image_strategy' => 'download_from_url' )
		);
	}

	public function test_a_product_cannot_reference_a_category_the_blueprint_never_declared(): void {
		// Dropping it instead would put the product in no category, and the
		// merchant would have no idea why.
		$this->expectException( BlueprintValidationException::class );

		$this->store(
			array(
				'initial_products' => array(
					array( 'name' => 'X', 'regular_price' => '1', 'category_roles' => array( 'Buty' ) ),
				),
			)
		);
	}

	public function test_two_entries_meaning_the_same_resource_are_rejected(): void {
		// They would map to one managed identity, so the second would be
		// treated as an update of the first and one would silently vanish.
		$this->expectException( BlueprintValidationException::class );

		$this->store( array( 'categories' => array( 'Koszulki', 'koszulki' ) ) );
	}

	// --- identity ---------------------------------------------------------

	public function test_store_resources_use_the_same_managed_identity_as_pages(): void {
		$this->assertSame( 'product_category:koszulki', ManagedCommerceRole::category( 'Koszulki' )->key() );
		$this->assertSame( 'product:koszulka-classic', ManagedCommerceRole::product( 'Koszulka Classic' )->key() );

		$this->assertTrue( ManagedResource::isValidKey( 'product_category:koszulki' ) );
		$this->assertTrue( ManagedResource::isValidKey( 'product:koszulka-classic' ) );
	}

	public function test_a_category_is_a_term_and_a_product_is_not(): void {
		// The resolver looks these up in different places; a type landing in
		// the wrong family would find nothing and duplicate on every run.
		$this->assertTrue( ManagedCommerceRole::category( 'Koszulki' )->isTerm() );
		$this->assertFalse( ManagedCommerceRole::product( 'Koszulka' )->isTerm() );
		$this->assertFalse( ManagedResource::forRole( ManagedResource::TYPE_PAGE, 'Start' )->isTerm() );
	}

	public function test_a_renamed_category_keeps_its_identity_through_its_role(): void {
		// A merchant renaming the display name must not earn a second
		// category on the next run.
		$store = $this->store(
			array( 'categories' => array( array( 'logical_role' => 'Koszulki', 'name' => 'T-shirty' ) ) )
		);

		$this->assertSame( 'T-shirty', $store->categories[0]->name );
		$this->assertSame( 'product_category:koszulki', ManagedCommerceRole::category( $store->categories[0]->role )->key() );
	}

	public function test_the_description_round_trips(): void {
		$store = $this->store();

		$this->assertSame( $store->toArray(), StoreBlueprint::fromArray( $store->toArray() )->toArray() );
	}
}

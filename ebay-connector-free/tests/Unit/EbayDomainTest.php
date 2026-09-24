<?php

declare(strict_types=1);

namespace DoSieci\Ebay\Connector\Tests\Unit;

use DoSieci\Ebay\Connector\Domain\EbayEnvironment;
use DoSieci\Ebay\Connector\Domain\EbayException;
use DoSieci\Ebay\Connector\Domain\EbayMarketplace;
use DoSieci\Ebay\Connector\Domain\ListingMapper;
use DoSieci\Ebay\Connector\Domain\OAuthToken;
use PHPUnit\Framework\TestCase;

final class EbayDomainTest extends TestCase {

	public function test_sandbox_is_the_default_environment(): void {
		$this->assertSame( EbayEnvironment::SANDBOX, ( new EbayEnvironment() )->name );
		$this->assertFalse( ( new EbayEnvironment() )->isProduction() );
	}

	public function test_sandbox_and_production_use_different_hosts(): void {
		$sandbox    = EbayEnvironment::sandbox();
		$production = new EbayEnvironment( EbayEnvironment::PRODUCTION );

		$this->assertStringContainsString( 'sandbox.ebay.com', $sandbox->oauthUrl() );
		$this->assertStringNotContainsString( 'sandbox', $production->oauthUrl() );
		$this->assertStringContainsString( 'api.ebay.com', $production->browseUrl() );
	}

	public function test_an_unknown_environment_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new EbayEnvironment( 'staging' );
	}

	public function test_a_token_response_is_parsed_with_its_expiry(): void {
		$token = OAuthToken::fromResponse( array( 'access_token' => 'v^1.1#abc', 'expires_in' => 7200 ), 1000 );

		$this->assertSame( 'v^1.1#abc', $token->accessToken );
		$this->assertSame( 8200, $token->expiresAt );
	}

	public function test_a_token_response_without_a_token_is_rejected(): void {
		$this->expectException( EbayException::class );
		OAuthToken::fromResponse( array( 'expires_in' => 7200 ), 1000 );
	}

	public function test_a_token_without_expires_in_gets_a_conservative_default(): void {
		$this->assertSame( 8200, OAuthToken::fromResponse( array( 'access_token' => 'x' ), 1000 )->expiresAt );
	}

	public function test_a_token_is_treated_as_expired_inside_the_safety_margin(): void {
		$token = new OAuthToken( 'x', 1000 );

		$this->assertTrue( $token->isValidAt( 900 ) );
		// 30 s before expiry is inside the 60 s margin: already unusable.
		$this->assertFalse( $token->isValidAt( 970 ) );
		$this->assertFalse( $token->isValidAt( 1001 ) );
	}

	public function test_an_empty_token_is_never_valid(): void {
		$this->assertFalse( ( new OAuthToken( '', PHP_INT_MAX ) )->isValidAt( 0 ) );
	}

	public function test_search_results_are_mapped(): void {
		$payload = array(
			'total'         => 2,
			'itemSummaries' => array(
				array(
					'itemId'     => 'v1|123|0',
					'title'      => 'Buty trekkingowe',
					'price'      => array( 'value' => '199.00', 'currency' => 'PLN' ),
					'condition'  => 'New',
					'itemWebUrl' => 'https://ebay.test/itm/123',
					'image'      => array( 'imageUrl' => 'https://img.test/1.jpg' ),
					'seller'     => array( 'username' => 'sklep' ),
				),
			),
		);

		$items = ListingMapper::fromSearchResponse( $payload );

		$this->assertSame( 2, ListingMapper::totalFromSearchResponse( $payload ) );
		$this->assertCount( 1, $items );
		$this->assertSame( '199.00 PLN', $items[0]['price'] );
		$this->assertSame( 'sklep', $items[0]['seller'] );
	}

	public function test_missing_optional_fields_map_to_null_rather_than_crashing(): void {
		$items = ListingMapper::fromSearchResponse(
			array( 'itemSummaries' => array( array( 'itemId' => 'v1|1|0' ) ) )
		);

		$this->assertSame( '(no title)', $items[0]['title'] );
		$this->assertNull( $items[0]['price'] );
		$this->assertNull( $items[0]['image'] );
	}

	public function test_a_response_with_no_results_maps_to_an_empty_list(): void {
		$this->assertSame( array(), ListingMapper::fromSearchResponse( array( 'total' => 0 ) ) );
		$this->assertSame( 0, ListingMapper::totalFromSearchResponse( array() ) );
	}

	public function test_non_array_entries_in_the_result_set_are_skipped(): void {
		$items = ListingMapper::fromSearchResponse(
			array( 'itemSummaries' => array( 'unexpected string', array( 'itemId' => 'v1|2|0' ) ) )
		);

		$this->assertCount( 1, $items );
	}

	public function test_the_marketplace_follows_the_site_locale_and_falls_back_to_ebay_com(): void {
		$this->assertSame( 'EBAY_PL', EbayMarketplace::forLocale( 'pl_PL' ) );
		$this->assertSame( 'EBAY_DE', EbayMarketplace::forLocale( 'de_DE' ) );
		$this->assertSame( 'EBAY_GB', EbayMarketplace::forLocale( 'en_GB' ) );
		$this->assertSame( 'EBAY_US', EbayMarketplace::forLocale( 'en_US' ) );
		$this->assertSame( 'EBAY_US', EbayMarketplace::forLocale( 'uk' ) );
	}

	public function test_marketplaces_are_an_allowlist(): void {
		$this->assertTrue( EbayMarketplace::isValid( 'EBAY_PL' ) );
		$this->assertFalse( EbayMarketplace::isValid( 'ebay_pl' ), 'The header value is case-sensitive.' );
		$this->assertFalse( EbayMarketplace::isValid( "EBAY_US\r\nX-Injected: 1" ) );
	}
}

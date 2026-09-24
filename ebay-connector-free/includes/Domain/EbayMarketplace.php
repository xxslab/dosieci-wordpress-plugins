<?php

declare(strict_types=1);

namespace DoSieci\Ebay\Connector\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The eBay site a search runs on, sent as X-EBAY-C-MARKETPLACE-ID.
 *
 * Without the header eBay searches ebay.com, so a Polish shop would browse
 * American listings priced in dollars. The list is an allowlist because the
 * value goes into an outbound request header.
 */
final class EbayMarketplace {

	public const DEFAULT = 'EBAY_US';

	/** marketplace id => site host */
	private const SITES = array(
		'EBAY_US' => 'ebay.com',
		'EBAY_GB' => 'ebay.co.uk',
		'EBAY_DE' => 'ebay.de',
		'EBAY_AT' => 'ebay.at',
		'EBAY_CH' => 'ebay.ch',
		'EBAY_FR' => 'ebay.fr',
		'EBAY_IT' => 'ebay.it',
		'EBAY_ES' => 'ebay.es',
		'EBAY_NL' => 'ebay.nl',
		'EBAY_BE' => 'ebay.be',
		'EBAY_IE' => 'ebay.ie',
		'EBAY_PL' => 'ebay.pl',
		'EBAY_AU' => 'ebay.com.au',
		'EBAY_CA' => 'ebay.ca',
	);

	/** WordPress locale => marketplace, for the first-run default */
	private const LOCALES = array(
		'pl_PL'          => 'EBAY_PL',
		'de_DE'          => 'EBAY_DE',
		'de_DE_formal'   => 'EBAY_DE',
		'de_AT'          => 'EBAY_AT',
		'de_CH'          => 'EBAY_CH',
		'de_CH_informal' => 'EBAY_CH',
		'en_GB'          => 'EBAY_GB',
		'fr_FR'          => 'EBAY_FR',
		'fr_BE'          => 'EBAY_BE',
		'nl_BE'          => 'EBAY_BE',
		'it_IT'          => 'EBAY_IT',
		'es_ES'          => 'EBAY_ES',
		'nl_NL'          => 'EBAY_NL',
		'nl_NL_formal'   => 'EBAY_NL',
		'en_AU'          => 'EBAY_AU',
		'en_CA'          => 'EBAY_CA',
		'fr_CA'          => 'EBAY_CA',
	);

	/** @return array<string, string> marketplace id => site host */
	public static function all(): array {
		return self::SITES;
	}

	public static function isValid( string $id ): bool {
		return isset( self::SITES[ $id ] );
	}

	public static function forLocale( string $locale ): string {
		return self::LOCALES[ $locale ] ?? self::DEFAULT;
	}
}

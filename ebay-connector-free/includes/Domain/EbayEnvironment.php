<?php

declare(strict_types=1);

namespace DoSieci\Ebay\Connector\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sandbox vs Production, as an explicit, validated choice.
 *
 * Sandbox is the default everywhere in this plugin, and switching to
 * Production is a deliberate, separate action. Defaulting a marketplace
 * integration to the live environment is how test calls end up on a real
 * account.
 */
final class EbayEnvironment {

	public const SANDBOX    = 'sandbox';
	public const PRODUCTION = 'production';

	private const HOSTS = array(
		self::SANDBOX    => array(
			'oauth'  => 'https://api.sandbox.ebay.com/identity/v1/oauth2/token',
			'browse' => 'https://api.sandbox.ebay.com/buy/browse/v1',
		),
		self::PRODUCTION => array(
			'oauth'  => 'https://api.ebay.com/identity/v1/oauth2/token',
			'browse' => 'https://api.ebay.com/buy/browse/v1',
		),
	);

	public function __construct( public readonly string $name = self::SANDBOX ) {
		if ( ! self::isValid( $name ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown eBay environment "%s".', $name ) ) );
		}
	}

	public static function isValid( string $name ): bool {
		return isset( self::HOSTS[ $name ] );
	}

	public static function sandbox(): self {
		return new self( self::SANDBOX );
	}

	public function isProduction(): bool {
		return self::PRODUCTION === $this->name;
	}

	public function oauthUrl(): string {
		return self::HOSTS[ $this->name ]['oauth'];
	}

	public function browseUrl(): string {
		return self::HOSTS[ $this->name ]['browse'];
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\Clean\Urls\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * old path -> new path, with two guarantees: no redirect chains, and no
 * loops.
 *
 * Chains cost a request per hop and dilute the signal search engines pass
 * through; loops take the URL offline. Both are prevented at insert time: if
 * A->B exists and B->C is added, A is rewritten to point straight at C.
 *
 * Old paths are matched in a canonical form -- percent-decoded, lower-cased,
 * without a trailing slash -- because the same old address arrives as
 * "/za%c5%bc%c3%b3%c5%82k/" from a link WordPress printed,
 * "/za%C5%BC%C3%B3%C5%82k" from a browser that encoded it itself, and
 * "/Zażółk/" from someone who typed it. Targets keep the exact shape of the
 * permalink they were recorded from, so the redirect lands on the canonical
 * URL without an extra hop.
 */
final class RedirectMap {

	/** @var array<string, string> canonical old path => decoded target path */
	private array $map = array();

	/**
	 * @param array<string, string> $initial
	 */
	public function __construct( array $initial = array() ) {
		foreach ( $initial as $from => $to ) {
			$this->add( (string) $from, (string) $to );
		}
	}

	public function add( string $from, string $to ): void {
		$fromKey = self::canonical( $from );
		$to      = self::decodedPath( $to );

		if ( '/' === $fromKey || self::canonical( $to ) === $fromKey ) {
			// Never hijack the home page; a self-redirect is the simplest loop.
			return;
		}

		// If the destination itself redirects somewhere, skip the hop.
		$final    = $this->resolve( $to );
		$finalKey = self::canonical( $final );

		if ( $finalKey === $fromKey ) {
			// Adding this would create a cycle; refuse rather than build one.
			return;
		}

		$this->map[ $fromKey ] = $final;

		// Re-point anything that pointed at $from so no chain survives.
		foreach ( $this->map as $existingFrom => $existingTo ) {
			if ( self::canonical( $existingTo ) === $fromKey && $existingFrom !== $finalKey ) {
				$this->map[ $existingFrom ] = $final;
			}
		}
	}

	/**
	 * Follows the map from $path to its final destination (decoded path).
	 */
	public function resolve( string $path ): string {
		$path = self::decodedPath( $path );
		$key  = self::canonical( $path );
		$seen = array();

		while ( isset( $this->map[ $key ] ) && ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = true;
			$path         = $this->map[ $key ];
			$key          = self::canonical( $path );
		}

		return $path;
	}

	public function has( string $from ): bool {
		return isset( $this->map[ self::canonical( $from ) ] );
	}

	/**
	 * The target of $from as a percent-encoded URL path ready for a Location
	 * header, or null when $from is not redirected.
	 */
	public function target( string $from ): ?string {
		$target = $this->map[ self::canonical( $from ) ] ?? null;

		return null === $target ? null : implode( '/', array_map( 'rawurlencode', explode( '/', $target ) ) );
	}

	/** @return array<string, string> */
	public function all(): array {
		return $this->map;
	}

	public function count(): int {
		return count( $this->map );
	}

	public static function canonical( string $path ): string {
		$path = rtrim( mb_strtolower( self::decodedPath( $path ), 'UTF-8' ), '/' );

		return '' === $path ? '/' : $path;
	}

	private static function decodedPath( string $path ): string {
		$parts = wp_parse_url( trim( $path ) );

		if ( ! is_array( $parts ) ) {
			return '/';
		}

		// A full URL without a path ("https://example.test") is the home page.
		$path = $parts['path'] ?? ( isset( $parts['host'] ) ? '/' : '' );

		return '/' . ltrim( rawurldecode( $path ), '/' );
	}
}

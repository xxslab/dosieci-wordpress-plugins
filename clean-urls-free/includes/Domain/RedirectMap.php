<?php

declare(strict_types=1);

namespace DoSieci\Clean\Urls\Domain;

/**
 * old path -> new path, with two guarantees the Safe Migration Engine
 * requires (PRODUCT_SCOPE.md): no redirect chains, and no loops.
 *
 * Chains matter because each hop costs a request and dilutes the signal
 * search engines pass through; loops matter because they take the URL
 * permanently offline. Both are prevented at insert time rather than
 * detected later: if A->B already exists and B->C is added, A is rewritten
 * to point straight at C.
 */
final class RedirectMap {

	/** @var array<string, string> */
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
		$from = self::normalisePath( $from );
		$to   = self::normalisePath( $to );

		if ( '' === $from || '' === $to || $from === $to ) {
			// A self-redirect is the simplest possible loop.
			return;
		}

		// If the destination itself redirects somewhere, skip the hop.
		$finalTarget = $this->resolve( $to );

		if ( $finalTarget === $from ) {
			// Adding this would create a cycle; refuse rather than build one.
			return;
		}

		$this->map[ $from ] = $finalTarget;

		// Re-point anything that pointed at $from so no chain survives.
		foreach ( $this->map as $existingFrom => $existingTo ) {
			if ( $existingTo === $from && $existingFrom !== $finalTarget ) {
				$this->map[ $existingFrom ] = $finalTarget;
			}
		}
	}

	public function resolve( string $path ): string {
		$path = self::normalisePath( $path );
		$seen = array();

		while ( isset( $this->map[ $path ] ) && ! isset( $seen[ $path ] ) ) {
			$seen[ $path ] = true;
			$path          = $this->map[ $path ];
		}

		return $path;
	}

	public function has( string $from ): bool {
		return isset( $this->map[ self::normalisePath( $from ) ] );
	}

	public function target( string $from ): ?string {
		return $this->map[ self::normalisePath( $from ) ] ?? null;
	}

	/** @return array<string, string> */
	public function all(): array {
		return $this->map;
	}

	public function count(): int {
		return count( $this->map );
	}

	private static function normalisePath( string $path ): string {
		$path = trim( $path );
		$path = parse_url( $path, PHP_URL_PATH ) ?: $path;
		$path = '/' . ltrim( $path, '/' );

		return rtrim( $path, '/' ) ?: '/';
	}
}

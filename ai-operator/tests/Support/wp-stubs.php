<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for the handful of WordPress functions used by Adapter
 * classes that are otherwise pure logic.
 *
 * Scope, deliberately narrow: this exists so the security-critical
 * OptionAllowlist can be unit-tested without a WordPress install. It is NOT
 * an attempt to emulate WordPress. Anything whose behaviour depends on real
 * WordPress state -- posts, options, users, capabilities, the upgrader --
 * is not stubbed here and is not covered by these tests; that layer is
 * exercised by the real ZIP-install smoke test instead.
 *
 * Each stub below matches the real function closely enough for the property
 * being tested, and no closer. Where the real function is stricter than the
 * stub, the test is therefore conservative rather than optimistic.
 */

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Real behaviour: strips tags, removes invalid UTF-8, strips octets,
	 * collapses whitespace, trims. The parts that matter for the allowlist
	 * are tag stripping and trimming.
	 */
	function sanitize_text_field( string $str ): string {
		$filtered = strip_tags( $str );
		$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );

		return trim( (string) $filtered );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

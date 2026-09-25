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

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Used by RemoteImagePolicy (Domain code, so it may not call WordPress
	 * functions directly except the handful stubbed in this file) instead of
	 * PHP's own parse_url(), which WordPress.org's Plugin Check flags.
	 *
	 * Real behaviour: core's wp_parse_url() is parse_url() plus fixes for
	 * PHP parse_url() quirks (e.g. a scheme-less "//host/path"). Every URL
	 * RemoteImagePolicy is ever asked about already carries an explicit
	 * scheme (it refuses anything that does not), so a plain delegation
	 * matches core closely enough for what this class checks.
	 *
	 * @return array<string, int|string>|false
	 */
	function wp_parse_url( string $url ): array|false {
		return parse_url( $url );
	}
}

/*
 * Translation and escaping. User-facing messages are written as
 * __( 'English', 'dosieci-ai-operator' ) even in Domain classes, and
 * exception messages are escaped where they are built (a WordPress.org
 * review requirement). With no translations loaded, WordPress returns the
 * English source text, and these stubs do the same, so tests assert on the
 * English strings.
 */

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Real behaviour: encodes &, <, >, " and ' without double-encoding
	 * existing entities -- the same as this.
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\SEO\Doctor\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BYOK key handling rules, as pure functions so they can be tested.
 *
 * The BYOK model here is the OPPOSITE of DoSieci AI Operator's: the user
 * supplies their own provider key, and the request goes straight from this
 * WordPress site to the provider. Nothing is proxied through DoSieci, and
 * DoSieci never sees the key.
 *
 * The rules this class enforces exist because of a specific, audited
 * failure in the legacy plugin this product replaces
 * (audit/wpAIseoGen-AUDIT.md): it logged full request and response bodies,
 * API key included, to error_log(). Hence: keys are never returned to a
 * template in full, never logged, and any value that reaches a UI is
 * masked.
 */
final class ByokKeyStore {

	/**
	 * Masks a key for display: enough to recognise which key is configured,
	 * never enough to use it.
	 */
	public static function mask( string $key ): string {
		$key = trim( $key );

		if ( '' === $key ) {
			return '';
		}

		$visible = 4;
		if ( mb_strlen( $key ) <= $visible * 2 ) {
			return str_repeat( '•', mb_strlen( $key ) );
		}

		return mb_substr( $key, 0, $visible ) . str_repeat( '•', 8 ) . mb_substr( $key, -$visible );
	}

	/**
	 * Basic shape validation, so an obviously-wrong paste (a whole curl
	 * command, a URL, an empty string) fails immediately with a clear
	 * message instead of producing a confusing 401 from the provider later.
	 */
	public static function looksPlausible( string $key ): bool {
		$key = trim( $key );

		if ( mb_strlen( $key ) < 16 || mb_strlen( $key ) > 256 ) {
			return false;
		}

		// Keys are opaque tokens: no whitespace, no URL, no shell syntax.
		return 1 === preg_match( '/^[A-Za-z0-9_\-\.:]+$/', $key );
	}

	/**
	 * Redacts any occurrence of the key from an arbitrary string before it
	 * is shown or stored -- used on provider error messages, which
	 * sometimes echo the credential back.
	 */
	public static function redactFrom( string $text, string $key ): string {
		$key = trim( $key );

		return '' === $key ? $text : str_replace( $key, '[redacted]', $text );
	}
}

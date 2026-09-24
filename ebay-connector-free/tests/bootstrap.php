<?php

declare(strict_types=1);

/**
 * Bootstrap for this plugin's Domain unit tests. No WordPress is loaded:
 * includes/Domain only uses WordPress's translation and escaping functions,
 * which are replaced below by pass-through stubs. The WordPress-dependent
 * layers are exercised on a real WordPress install instead.
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- stub of the WordPress wrapper.
	}
}

spl_autoload_register(
	static function ( string $class ): void {
		$map = array(
			'DoSieci\\Ebay\\Connector\\Tests\\' => __DIR__ . '/',
			'DoSieci\\Ebay\\Connector\\' => __DIR__ . '/../includes/',
		);

		foreach ( $map as $prefix => $baseDir ) {
			if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
				continue;
			}

			$path = $baseDir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;

				return;
			}
		}
	}
);

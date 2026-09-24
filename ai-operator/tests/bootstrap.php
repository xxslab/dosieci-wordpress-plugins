<?php

declare(strict_types=1);

/**
 * Bootstrap for this plugin's Domain unit tests.
 *
 * These tests run with NO WordPress loaded, which is the whole point of
 * keeping includes/Domain free of WordPress calls: the security-critical
 * logic (signing, argument validation, the tool-dispatch gate order, secret
 * scrubbing, the chat loop) is testable in isolation and fast.
 *
 * The WordPress-dependent layer (includes/Adapter, includes/UI) is NOT
 * covered here -- it is exercised by the real ZIP-install smoke test
 * documented in the release audit, not by mocking WordPress.
 *
 * The one deliberate exception is OptionAllowlist: it lives in Adapter
 * because it names WordPress options, but it is pure decision logic and it
 * is the boundary that stops an option write from becoming a site
 * takeover. Support/wp-stubs.php provides the two string helpers it needs
 * so that boundary can be tested here rather than only by inspection. See
 * that file for the limits of what it does and does not emulate.
 */

require_once __DIR__ . '/Support/wp-stubs.php';

spl_autoload_register(
	static function ( string $class ): void {
		$map = array(
			'DoSieci\\AiOperator\\Tests\\' => __DIR__ . '/',
			'DoSieci\\AiOperator\\'        => __DIR__ . '/../includes/',
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

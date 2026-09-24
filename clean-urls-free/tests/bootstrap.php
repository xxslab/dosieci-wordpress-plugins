<?php

declare(strict_types=1);

/**
 * Bootstrap for this plugin's Domain unit tests. No WordPress is loaded --
 * includes/Domain is deliberately free of WordPress calls so the logic that
 * matters can be tested fast and in isolation. The WordPress-dependent
 * layers (includes/Adapter, includes/Plugin.php) are covered by the real
 * ZIP-install smoke test instead, not by mocking WordPress.
 */

spl_autoload_register(
	static function ( string $class ): void {
		$map = array(
			'DoSieci\Clean\Urls\\Tests\\' => __DIR__ . '/',
			'DoSieci\Clean\Urls\\'        => __DIR__ . '/../includes/',
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

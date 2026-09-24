<?php

declare(strict_types=1);

namespace DoSieci\WP\Doctor\Domain;

/**
 * Everything the checks need to know about a site, collected once by the
 * WordPress adapter and then handed to pure functions.
 *
 * This split is what makes the diagnostics testable: the checks themselves
 * never call a WordPress function, so a "what does WP Doctor say about a
 * site running PHP 7.4 with 3 MB of autoloaded options" test is a plain
 * unit test with no WordPress, no database and no fixtures.
 */
final class SiteFacts {

	/**
	 * @param array<int, array{name:string, version:string, active:bool}> $plugins
	 * @param array<int, array{hook:string, timestamp:int}>               $cronEvents
	 */
	public function __construct(
		public readonly string $phpVersion,
		public readonly string $wordPressVersion,
		public readonly bool $isHttps,
		public readonly bool $debugEnabled,
		public readonly bool $debugDisplayEnabled,
		public readonly bool $searchEngineDiscouraged,
		public readonly string $permalinkStructure,
		public readonly int $autoloadedBytes,
		public readonly int $revisionCount,
		public readonly int $postCount,
		public readonly int $transientCount,
		public readonly array $plugins,
		public readonly array $cronEvents,
		public readonly int $memoryLimitBytes,
		public readonly int $now
	) {
	}
}

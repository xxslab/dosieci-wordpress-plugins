<?php

declare(strict_types=1);

namespace DoSieci\WP\Doctor\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The free tier's diagnostic engine: pure, read-only analysis of SiteFacts.
 *
 * There is no "fix" method anywhere in this class, and that is the whole
 * design. The legacy plugin this product replaces shipped an Autofix that ran
 * unconditional DELETEs and OPTIMIZE TABLE in a loop from a single AJAX call,
 * with no preflight, dry-run or backup. This build reports and explains;
 * anything that writes belongs to a separate, gated flow and is deliberately
 * not part of this tier.
 */
final class DiagnosticEngine {

	/**
	 * PHP branch => [end of active support, end of security support], as
	 * published on php.net/supported-versions.php. Dated rather than a fixed
	 * "minimum version" so the verdict stays correct as branches age out.
	 * Branches newer than the last entry are treated as supported.
	 */
	public const PHP_SUPPORT = array(
		'7.4' => array( '2021-11-28', '2022-11-28' ),
		'8.0' => array( '2022-11-26', '2023-11-26' ),
		'8.1' => array( '2023-11-25', '2025-12-31' ),
		'8.2' => array( '2024-12-31', '2026-12-31' ),
		'8.3' => array( '2025-12-31', '2027-12-31' ),
		'8.4' => array( '2026-12-31', '2028-12-31' ),
		'8.5' => array( '2027-12-31', '2029-12-31' ),
	);

	public const AUTOLOAD_WARNING_BYTES  = 800 * 1024;
	public const AUTOLOAD_CRITICAL_BYTES = 2 * 1024 * 1024;
	public const REVISION_WARNING_RATIO  = 10;
	public const TRANSIENT_WARNING_COUNT = 2000;
	public const MEMORY_WARNING_BYTES    = 128 * 1024 * 1024;
	public const CRON_OVERDUE_SECONDS    = 3600;

	/** @return CheckResult[] */
	public function run( SiteFacts $facts ): array {
		return array(
			$this->checkPhpVersion( $facts ),
			$this->checkHttps( $facts ),
			$this->checkDebugSettings( $facts ),
			$this->checkSearchEngineVisibility( $facts ),
			$this->checkPermalinks( $facts ),
			$this->checkAutoloadedOptions( $facts ),
			$this->checkRevisions( $facts ),
			$this->checkTransients( $facts ),
			$this->checkMemoryLimit( $facts ),
			$this->checkCronBacklog( $facts ),
			$this->checkInactivePlugins( $facts ),
		);
	}

	/**
	 * @param CheckResult[] $results
	 *
	 * @return array{good:int, warning:int, critical:int, info:int, score:int}
	 */
	public function summarise( array $results ): array {
		$counts = array(
			'good'     => 0,
			'warning'  => 0,
			'critical' => 0,
			'info'     => 0,
		);

		foreach ( $results as $result ) {
			++$counts[ $result->status ];
		}

		$scored = $counts['good'] + $counts['warning'] + $counts['critical'];

		// A deliberately blunt score: it shows the direction of travel between
		// scans and must never be advertised as "your site is X% secure".
		$counts['score'] = $scored > 0
			? (int) round( ( ( $counts['good'] + $counts['warning'] * 0.5 ) / $scored ) * 100 )
			: 100;

		return $counts;
	}

	private function checkPhpVersion( SiteFacts $facts ): CheckResult {
		$label   = __( 'PHP version', 'dosieci-wp-doctor' );
		$branch  = implode( '.', array_slice( explode( '.', $facts->phpVersion ), 0, 2 ) );
		$details = array( 'current' => $facts->phpVersion );
		$today   = gmdate( 'Y-m-d', $facts->now );
		$newest  = array_key_last( self::PHP_SUPPORT );

		if ( ! isset( self::PHP_SUPPORT[ $branch ] ) ) {
			if ( version_compare( $branch, $newest, '>' ) ) {
				return new CheckResult(
					'php_version',
					$label,
					CheckResult::STATUS_GOOD,
					/* translators: %s: PHP version, e.g. 8.6.1 */
					sprintf( __( 'PHP %s is a current release.', 'dosieci-wp-doctor' ), $facts->phpVersion ),
					'',
					$details
				);
			}

			return $this->phpEndOfLife( $label, $facts->phpVersion, $details );
		}

		[ $activeUntil, $securityUntil ] = self::PHP_SUPPORT[ $branch ];

		if ( $today > $securityUntil ) {
			return $this->phpEndOfLife( $label, $facts->phpVersion, $details );
		}

		if ( $today > $activeUntil ) {
			return new CheckResult(
				'php_version',
				$label,
				CheckResult::STATUS_WARNING,
				sprintf(
					/* translators: 1: PHP version, 2: date (YYYY-MM-DD) */
					__( 'PHP %1$s only receives security fixes, until %2$s.', 'dosieci-wp-doctor' ),
					$facts->phpVersion,
					$securityUntil
				),
				__( 'Plan the upgrade to a newer PHP version with your host before that date.', 'dosieci-wp-doctor' ),
				$details
			);
		}

		return new CheckResult(
			'php_version',
			$label,
			CheckResult::STATUS_GOOD,
			/* translators: %s: PHP version */
			sprintf( __( 'PHP %s is actively supported.', 'dosieci-wp-doctor' ), $facts->phpVersion ),
			'',
			$details
		);
	}

	/**
	 * @param array<string, mixed> $details
	 */
	private function phpEndOfLife( string $label, string $version, array $details ): CheckResult {
		return new CheckResult(
			'php_version',
			$label,
			CheckResult::STATUS_CRITICAL,
			/* translators: %s: PHP version */
			sprintf( __( 'PHP %s no longer receives security fixes.', 'dosieci-wp-doctor' ), $version ),
			__( 'Ask your host to move the site to a supported PHP version and test the site after the switch.', 'dosieci-wp-doctor' ),
			$details
		);
	}

	private function checkHttps( SiteFacts $facts ): CheckResult {
		$label = __( 'HTTPS', 'dosieci-wp-doctor' );

		return $facts->isHttps
			? new CheckResult( 'https', $label, CheckResult::STATUS_GOOD, __( 'The site is served over HTTPS.', 'dosieci-wp-doctor' ) )
			: new CheckResult(
				'https',
				$label,
				CheckResult::STATUS_CRITICAL,
				__( 'The site does not use HTTPS.', 'dosieci-wp-doctor' ),
				__( 'Enable an SSL certificate (for example Let\'s Encrypt) and change the site address to https://.', 'dosieci-wp-doctor' )
			);
	}

	private function checkDebugSettings( SiteFacts $facts ): CheckResult {
		$label = __( 'Debug mode', 'dosieci-wp-doctor' );

		if ( $facts->debugEnabled && $facts->debugDisplayEnabled ) {
			return new CheckResult(
				'debug',
				$label,
				CheckResult::STATUS_CRITICAL,
				__( 'WP_DEBUG_DISPLAY is on, so error messages can be shown to visitors.', 'dosieci-wp-doctor' ),
				__( 'Set WP_DEBUG_DISPLAY to false in wp-config.php. Log errors to a file, not to the screen.', 'dosieci-wp-doctor' )
			);
		}

		if ( $facts->debugEnabled ) {
			return new CheckResult(
				'debug',
				$label,
				CheckResult::STATUS_WARNING,
				__( 'WP_DEBUG is on (without displaying errors on screen).', 'dosieci-wp-doctor' ),
				__( 'On a production site it should usually be off.', 'dosieci-wp-doctor' )
			);
		}

		return new CheckResult( 'debug', $label, CheckResult::STATUS_GOOD, __( 'Debugging is off.', 'dosieci-wp-doctor' ) );
	}

	private function checkSearchEngineVisibility( SiteFacts $facts ): CheckResult {
		$label = __( 'Search engine visibility', 'dosieci-wp-doctor' );

		return $facts->searchEngineDiscouraged
			? new CheckResult(
				'search_visibility',
				$label,
				CheckResult::STATUS_CRITICAL,
				__( 'The site asks search engines not to index it.', 'dosieci-wp-doctor' ),
				__( 'Untick "Discourage search engines from indexing this site" in Settings > Reading, unless this is intentional (for example on a staging site).', 'dosieci-wp-doctor' )
			)
			: new CheckResult( 'search_visibility', $label, CheckResult::STATUS_GOOD, __( 'The site can be indexed.', 'dosieci-wp-doctor' ) );
	}

	private function checkPermalinks( SiteFacts $facts ): CheckResult {
		$label = __( 'Permalink structure', 'dosieci-wp-doctor' );

		return '' === $facts->permalinkStructure
			? new CheckResult(
				'permalinks',
				$label,
				CheckResult::STATUS_WARNING,
				__( 'Plain permalinks (?p=123) are in use.', 'dosieci-wp-doctor' ),
				__( 'Switch to a structure based on the post name in Settings > Permalinks.', 'dosieci-wp-doctor' )
			)
			: new CheckResult( 'permalinks', $label, CheckResult::STATUS_GOOD, __( 'A readable permalink structure is in use.', 'dosieci-wp-doctor' ) );
	}

	private function checkAutoloadedOptions( SiteFacts $facts ): CheckResult {
		$label   = __( 'Autoloaded options', 'dosieci-wp-doctor' );
		$kb      = (int) round( $facts->autoloadedBytes / 1024 );
		$details = array( 'bytes' => $facts->autoloadedBytes );

		/* translators: %d: size in kilobytes */
		$loaded = sprintf( __( '%d KB of options is loaded on every request.', 'dosieci-wp-doctor' ), $kb );

		if ( $facts->autoloadedBytes >= self::AUTOLOAD_CRITICAL_BYTES ) {
			return new CheckResult(
				'autoload',
				$label,
				CheckResult::STATUS_CRITICAL,
				$loaded,
				__( 'Find the largest autoloaded entries and turn autoload off where it is not needed. Unused plugins and abandoned transients are the usual cause.', 'dosieci-wp-doctor' ),
				$details
			);
		}

		if ( $facts->autoloadedBytes >= self::AUTOLOAD_WARNING_BYTES ) {
			return new CheckResult(
				'autoload',
				$label,
				CheckResult::STATUS_WARNING,
				$loaded,
				__( 'The recommended limit is about 800 KB. Review the largest entries.', 'dosieci-wp-doctor' ),
				$details
			);
		}

		return new CheckResult(
			'autoload',
			$label,
			CheckResult::STATUS_GOOD,
			/* translators: %d: size in kilobytes */
			sprintf( __( '%d KB, below the recommended threshold.', 'dosieci-wp-doctor' ), $kb ),
			'',
			$details
		);
	}

	private function checkRevisions( SiteFacts $facts ): CheckResult {
		$label = __( 'Post revisions', 'dosieci-wp-doctor' );

		if ( 0 === $facts->postCount ) {
			return new CheckResult( 'revisions', $label, CheckResult::STATUS_INFO, __( 'There are no posts to assess.', 'dosieci-wp-doctor' ) );
		}

		$ratio = $facts->revisionCount / max( 1, $facts->postCount );

		if ( $ratio >= self::REVISION_WARNING_RATIO ) {
			return new CheckResult(
				'revisions',
				$label,
				CheckResult::STATUS_WARNING,
				sprintf(
					/* translators: 1: number of revisions, 2: number of posts, 3: average revisions per post */
					__( 'Revisions: %1$d, posts: %2$d (%3$.1f revisions per post on average).', 'dosieci-wp-doctor' ),
					$facts->revisionCount,
					$facts->postCount,
					$ratio
				),
				__( 'Consider limiting WP_POST_REVISIONS. Do not bulk-delete revisions without a backup: it cannot be undone.', 'dosieci-wp-doctor' ),
				array(
					'revisions' => $facts->revisionCount,
					'posts'     => $facts->postCount,
				)
			);
		}

		return new CheckResult(
			'revisions',
			$label,
			CheckResult::STATUS_GOOD,
			sprintf(
				/* translators: 1: number of revisions, 2: number of posts */
				__( 'Revisions: %1$d, posts: %2$d.', 'dosieci-wp-doctor' ),
				$facts->revisionCount,
				$facts->postCount
			)
		);
	}

	private function checkTransients( SiteFacts $facts ): CheckResult {
		$label = __( 'Transients', 'dosieci-wp-doctor' );

		return $facts->transientCount >= self::TRANSIENT_WARNING_COUNT
			? new CheckResult(
				'transients',
				$label,
				CheckResult::STATUS_WARNING,
				/* translators: %d: number of transients */
				sprintf( _n( 'The database holds %d transient.', 'The database holds %d transients.', $facts->transientCount, 'dosieci-wp-doctor' ), $facts->transientCount ),
				__( 'A large number of transients usually means there is no persistent object cache. Consider Redis or Memcached rather than clearing them by hand.', 'dosieci-wp-doctor' ),
				array( 'count' => $facts->transientCount )
			)
			: new CheckResult(
				'transients',
				$label,
				CheckResult::STATUS_GOOD,
				/* translators: %d: number of transients */
				sprintf( _n( '%d transient.', '%d transients.', $facts->transientCount, 'dosieci-wp-doctor' ), $facts->transientCount )
			);
	}

	private function checkMemoryLimit( SiteFacts $facts ): CheckResult {
		$label = __( 'PHP memory limit', 'dosieci-wp-doctor' );

		return $facts->memoryLimitBytes > 0 && $facts->memoryLimitBytes < self::MEMORY_WARNING_BYTES
			? new CheckResult(
				'memory_limit',
				$label,
				CheckResult::STATUS_WARNING,
				/* translators: %d: memory limit in megabytes */
				sprintf( __( 'The memory limit is %d MB.', 'dosieci-wp-doctor' ), (int) round( $facts->memoryLimitBytes / 1048576 ) ),
				__( 'WooCommerce needs at least 128 MB (often 256 MB).', 'dosieci-wp-doctor' ),
				array( 'bytes' => $facts->memoryLimitBytes )
			)
			: new CheckResult( 'memory_limit', $label, CheckResult::STATUS_GOOD, __( 'The memory limit is sufficient.', 'dosieci-wp-doctor' ) );
	}

	private function checkCronBacklog( SiteFacts $facts ): CheckResult {
		$label   = __( 'Scheduled tasks (cron)', 'dosieci-wp-doctor' );
		$overdue = array_filter(
			$facts->cronEvents,
			static fn( array $event ): bool => $event['timestamp'] < ( $facts->now - self::CRON_OVERDUE_SECONDS )
		);

		return array() !== $overdue
			? new CheckResult(
				'cron',
				$label,
				CheckResult::STATUS_WARNING,
				/* translators: %d: number of overdue scheduled tasks */
				sprintf( _n( '%d scheduled task is more than an hour overdue.', '%d scheduled tasks are more than an hour overdue.', count( $overdue ), 'dosieci-wp-doctor' ), count( $overdue ) ),
				__( 'WP-Cron only runs when the site gets traffic. On a low-traffic site, set up a system cron job that calls wp-cron.php.', 'dosieci-wp-doctor' ),
				array(
					'overdue' => count( $overdue ),
					'total'   => count( $facts->cronEvents ),
				)
			)
			: new CheckResult(
				'cron',
				$label,
				CheckResult::STATUS_GOOD,
				/* translators: %d: number of scheduled tasks */
				sprintf( _n( '%d scheduled task, none overdue.', '%d scheduled tasks, none overdue.', count( $facts->cronEvents ), 'dosieci-wp-doctor' ), count( $facts->cronEvents ) )
			);
	}

	private function checkInactivePlugins( SiteFacts $facts ): CheckResult {
		$label    = __( 'Inactive plugins', 'dosieci-wp-doctor' );
		$inactive = array_filter( $facts->plugins, static fn( array $plugin ): bool => ! $plugin['active'] );

		return count( $inactive ) >= 5
			? new CheckResult(
				'inactive_plugins',
				$label,
				CheckResult::STATUS_WARNING,
				/* translators: %d: number of inactive plugins */
				sprintf( _n( '%d inactive plugin is still installed.', '%d inactive plugins are still installed.', count( $inactive ), 'dosieci-wp-doctor' ), count( $inactive ) ),
				__( 'Inactive plugins can still contain vulnerable code reachable from the web. Delete the ones you do not plan to use.', 'dosieci-wp-doctor' ),
				array(
					'inactive' => count( $inactive ),
					'total'    => count( $facts->plugins ),
				)
			)
			: new CheckResult(
				'inactive_plugins',
				$label,
				CheckResult::STATUS_GOOD,
				/* translators: %d: number of inactive plugins */
				sprintf( _n( '%d inactive plugin.', '%d inactive plugins.', count( $inactive ), 'dosieci-wp-doctor' ), count( $inactive ) )
			);
	}
}

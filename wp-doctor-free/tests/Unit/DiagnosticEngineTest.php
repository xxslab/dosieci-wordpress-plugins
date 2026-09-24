<?php

declare(strict_types=1);

namespace DoSieci\WP\Doctor\Tests\Unit;

use DoSieci\WP\Doctor\Domain\CheckResult;
use DoSieci\WP\Doctor\Domain\DiagnosticEngine;
use DoSieci\WP\Doctor\Domain\SiteFacts;
use PHPUnit\Framework\TestCase;

final class DiagnosticEngineTest extends TestCase {

	private DiagnosticEngine $engine;

	protected function setUp(): void {
		$this->engine = new DiagnosticEngine();
	}

	/**
	 * A site with nothing wrong with it -- the baseline every test below
	 * mutates one fact away from, so each assertion isolates one check.
	 */
	private function healthySite( array $overrides = array() ): SiteFacts {
		$defaults = array(
			'phpVersion'              => '8.3.0',
			'wordPressVersion'        => '6.9',
			'isHttps'                 => true,
			'debugEnabled'            => false,
			'debugDisplayEnabled'     => false,
			'searchEngineDiscouraged' => false,
			'permalinkStructure'      => '/%postname%/',
			'autoloadedBytes'         => 200 * 1024,
			'revisionCount'           => 20,
			'postCount'               => 100,
			'transientCount'          => 50,
			'plugins'                 => array( array( 'name' => 'A', 'version' => '1', 'active' => true ) ),
			'cronEvents'              => array( array( 'hook' => 'wp_version_check', 'timestamp' => 2000 ) ),
			'memoryLimitBytes'        => 256 * 1024 * 1024,
			'now'                     => 1000,
		);

		$f = array_merge( $defaults, $overrides );

		return new SiteFacts(
			$f['phpVersion'], $f['wordPressVersion'], $f['isHttps'], $f['debugEnabled'],
			$f['debugDisplayEnabled'], $f['searchEngineDiscouraged'], $f['permalinkStructure'],
			$f['autoloadedBytes'], $f['revisionCount'], $f['postCount'], $f['transientCount'],
			$f['plugins'], $f['cronEvents'], $f['memoryLimitBytes'], $f['now']
		);
	}

	/** @return CheckResult */
	private function check( SiteFacts $facts, string $id ): CheckResult {
		foreach ( $this->engine->run( $facts ) as $result ) {
			if ( $id === $result->checkId ) {
				return $result;
			}
		}

		$this->fail( "Check {$id} was not produced by the engine." );
	}

	public function test_a_healthy_site_reports_no_problems(): void {
		foreach ( $this->engine->run( $this->healthySite() ) as $result ) {
			$this->assertFalse( $result->isProblem(), "Unexpected problem reported: {$result->checkId} — {$result->summary}" );
		}
	}

	public function test_end_of_life_php_is_critical(): void {
		$result = $this->check( $this->healthySite( array( 'phpVersion' => '7.4.33' ) ), 'php_version' );

		$this->assertSame( CheckResult::STATUS_CRITICAL, $result->status );
		$this->assertNotSame( '', $result->recommendation, 'Every problem must come with a recommendation.' );
	}

	public function test_supported_but_not_recommended_php_is_only_a_warning(): void {
		$this->assertSame(
			CheckResult::STATUS_WARNING,
			$this->check( $this->healthySite( array( 'phpVersion' => '8.1.20' ) ), 'php_version' )->status
		);
	}

	public function test_missing_https_is_critical(): void {
		$this->assertSame(
			CheckResult::STATUS_CRITICAL,
			$this->check( $this->healthySite( array( 'isHttps' => false ) ), 'https' )->status
		);
	}

	public function test_debug_display_on_is_critical_but_debug_alone_is_a_warning(): void {
		$this->assertSame(
			CheckResult::STATUS_CRITICAL,
			$this->check( $this->healthySite( array( 'debugEnabled' => true, 'debugDisplayEnabled' => true ) ), 'debug' )->status
		);

		$this->assertSame(
			CheckResult::STATUS_WARNING,
			$this->check( $this->healthySite( array( 'debugEnabled' => true ) ), 'debug' )->status
		);
	}

	public function test_discouraging_search_engines_is_critical(): void {
		$this->assertSame(
			CheckResult::STATUS_CRITICAL,
			$this->check( $this->healthySite( array( 'searchEngineDiscouraged' => true ) ), 'search_visibility' )->status
		);
	}

	public function test_plain_permalinks_are_a_warning(): void {
		$this->assertSame(
			CheckResult::STATUS_WARNING,
			$this->check( $this->healthySite( array( 'permalinkStructure' => '' ) ), 'permalinks' )->status
		);
	}

	public function test_autoload_thresholds(): void {
		$this->assertSame(
			CheckResult::STATUS_GOOD,
			$this->check( $this->healthySite( array( 'autoloadedBytes' => 700 * 1024 ) ), 'autoload' )->status
		);

		$this->assertSame(
			CheckResult::STATUS_WARNING,
			$this->check( $this->healthySite( array( 'autoloadedBytes' => 900 * 1024 ) ), 'autoload' )->status
		);

		$this->assertSame(
			CheckResult::STATUS_CRITICAL,
			$this->check( $this->healthySite( array( 'autoloadedBytes' => 3 * 1024 * 1024 ) ), 'autoload' )->status
		);
	}

	public function test_a_high_revision_ratio_is_a_warning_and_the_advice_does_not_suggest_bulk_deletion_without_a_backup(): void {
		$result = $this->check( $this->healthySite( array( 'revisionCount' => 2000, 'postCount' => 100 ) ), 'revisions' );

		$this->assertSame( CheckResult::STATUS_WARNING, $result->status );
		$this->assertStringContainsString( 'kopii zapasowej', $result->recommendation );
	}

	public function test_a_site_with_no_posts_does_not_divide_by_zero(): void {
		$this->assertSame(
			CheckResult::STATUS_INFO,
			$this->check( $this->healthySite( array( 'postCount' => 0, 'revisionCount' => 0 ) ), 'revisions' )->status
		);
	}

	public function test_overdue_cron_events_are_detected_relative_to_the_supplied_clock(): void {
		$facts = $this->healthySite(
			array(
				'now'        => 100000,
				'cronEvents' => array(
					array( 'hook' => 'old', 'timestamp' => 1000 ),
					array( 'hook' => 'soon', 'timestamp' => 100000 ),
				),
			)
		);

		$result = $this->check( $facts, 'cron' );

		$this->assertSame( CheckResult::STATUS_WARNING, $result->status );
		$this->assertSame( 1, $result->details['overdue'] );
	}

	public function test_a_low_memory_limit_is_a_warning_but_unlimited_is_not(): void {
		$this->assertSame(
			CheckResult::STATUS_WARNING,
			$this->check( $this->healthySite( array( 'memoryLimitBytes' => 64 * 1024 * 1024 ) ), 'memory_limit' )->status
		);

		// 0 means "-1 / unlimited" from the collector, which must not be
		// misread as "0 bytes of memory".
		$this->assertSame(
			CheckResult::STATUS_GOOD,
			$this->check( $this->healthySite( array( 'memoryLimitBytes' => 0 ) ), 'memory_limit' )->status
		);
	}

	public function test_many_inactive_plugins_are_flagged(): void {
		$plugins = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$plugins[] = array( 'name' => "P{$i}", 'version' => '1', 'active' => false );
		}

		$this->assertSame(
			CheckResult::STATUS_WARNING,
			$this->check( $this->healthySite( array( 'plugins' => $plugins ) ), 'inactive_plugins' )->status
		);
	}

	public function test_the_score_drops_as_problems_appear(): void {
		$healthy = $this->engine->summarise( $this->engine->run( $this->healthySite() ) );
		$broken  = $this->engine->summarise(
			$this->engine->run(
				$this->healthySite(
					array(
						'phpVersion'              => '7.4',
						'isHttps'                 => false,
						'searchEngineDiscouraged' => true,
						'autoloadedBytes'         => 3 * 1024 * 1024,
					)
				)
			)
		);

		$this->assertSame( 100, $healthy['score'] );
		$this->assertLessThan( 70, $broken['score'] );
		$this->assertSame( 4, $broken['critical'] );
	}

	public function test_every_problem_carries_an_actionable_recommendation(): void {
		$facts = $this->healthySite(
			array(
				'phpVersion'          => '7.4',
				'isHttps'             => false,
				'debugEnabled'        => true,
				'debugDisplayEnabled' => true,
				'permalinkStructure'  => '',
				'autoloadedBytes'     => 3 * 1024 * 1024,
				'transientCount'      => 5000,
				'memoryLimitBytes'    => 32 * 1024 * 1024,
			)
		);

		foreach ( $this->engine->run( $facts ) as $result ) {
			if ( $result->isProblem() ) {
				$this->assertNotSame( '', $result->recommendation, "Check {$result->checkId} reports a problem with no recommendation." );
			}
		}
	}
}

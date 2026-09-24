<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\SiteAuditReport;
use PHPUnit\Framework\TestCase;

/**
 * The audit is only useful if a human can read WHY the build was called
 * finished, or why it was not. "Audyt nie powiódł się" with no detail
 * leaves the user guessing which of a dozen checks went wrong.
 *
 * The verdict and the sentence are both composed here, server-side, so
 * the browser never has to derive either. Two implementations of "did
 * this pass" is one too many, and the one that gates the plan's status is
 * the one that must be shown.
 */
final class AuditPresentationTest extends TestCase {

	/** @param array<int, array{check:string, passed:bool, detail:string}> $checks */
	private function report( array $checks ): SiteAuditReport {
		return SiteAuditReport::fromChecks( $checks, 1_700_000_000 );
	}

	public function test_a_clean_audit_summarises_as_all_checks_passed(): void {
		$report = $this->report(
			array(
				array( 'check' => 'pages', 'passed' => true, 'detail' => '4 strony.' ),
				array( 'check' => 'navigation', 'passed' => true, 'detail' => 'Menu ma 4 pozycje.' ),
			)
		);

		$this->assertTrue( $report->passed );
		$this->assertSame( array(), $report->failures() );
		$this->assertStringContainsString( '2/2', $report->summary() );
	}

	public function test_a_failing_check_names_itself_in_the_summary(): void {
		// This is the whole point of the exercise: the sentence has to say
		// what is wrong, not that something is.
		$report = $this->report(
			array(
				array( 'check' => 'pages', 'passed' => true, 'detail' => '4 strony.' ),
				array( 'check' => 'currency', 'passed' => false, 'detail' => 'Waluta to EUR, oczekiwano PLN.' ),
			)
		);

		$this->assertFalse( $report->passed );
		$this->assertCount( 1, $report->failures() );
		$this->assertStringContainsString( 'EUR', $report->summary() );
		$this->assertStringContainsString( 'PLN', $report->summary() );
	}

	public function test_several_failures_are_all_reported_not_just_the_first(): void {
		// Fixing one problem only to discover the next on the following run
		// is a bad way to learn what is wrong with a site.
		$report = $this->report(
			array(
				array( 'check' => 'currency', 'passed' => false, 'detail' => 'Waluta to EUR.' ),
				array( 'check' => 'products', 'passed' => false, 'detail' => 'Brakuje 2 produktów.' ),
			)
		);

		$this->assertCount( 2, $report->failures() );
		$this->assertStringContainsString( 'Waluta', $report->summary() );
		$this->assertStringContainsString( 'produkt', $report->summary() );
	}

	public function test_a_check_missing_its_passed_flag_counts_as_a_failure(): void {
		// Fail closed. A malformed check must not read as a pass, because
		// the plan's SUCCEEDED status depends on this answer.
		$report = $this->report( array( array( 'check' => 'mystery', 'detail' => '' ) ) );

		$this->assertFalse( $report->passed );
	}

	public function test_the_report_round_trips_through_storage(): void {
		// It is persisted as JSON and read back on the next poll, so a
		// refresh mid-build must not lose the verdict.
		$report   = $this->report(
			array( array( 'check' => 'currency', 'passed' => false, 'detail' => 'Waluta to EUR.' ) )
		);
		$restored = SiteAuditReport::fromArray( $report->toArray() );

		$this->assertSame( $report->passed, $restored->passed );
		$this->assertSame( $report->checks, $restored->checks );
		$this->assertSame( $report->auditedAt, $restored->auditedAt );
		$this->assertSame( $report->summary(), $restored->summary() );
	}
}

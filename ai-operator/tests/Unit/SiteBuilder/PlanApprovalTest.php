<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\ActionPlan;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanRecord;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanStateException;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanStatus;
use DoSieci\AiOperator\Tests\Support\SiteBuilderFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Approval is the moment a human takes responsibility for a batch of
 * writes. Every test here is one way that responsibility could be forged,
 * bypassed, or stretched to cover something the human never saw.
 */
final class PlanApprovalTest extends TestCase {

	private function awaiting( ?array $actions = null, int $ownerId = SiteBuilderFixtures::OWNER_ID ): PlanRecord {
		$record         = new PlanRecord( SiteBuilderFixtures::plan( $actions, $ownerId ) );
		$record->status = PlanStatus::AWAITING_APPROVAL;

		return $record;
	}

	public function test_the_owner_can_approve_and_the_approved_hash_is_recorded(): void {
		$record = $this->awaiting();

		$record->approve( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + 5 );

		$this->assertSame( PlanStatus::APPROVED, $record->status );
		$this->assertSame( $record->plan->planHash, $record->approvedHash );
		$this->assertSame( SiteBuilderFixtures::OWNER_ID, $record->approvedBy );
	}

	public function test_a_different_user_cannot_approve_someone_elses_plan(): void {
		$record = $this->awaiting();

		$this->expectException( PlanStateException::class );
		$record->approve( SiteBuilderFixtures::OWNER_ID + 1, SiteBuilderFixtures::NOW + 5 );
	}

	public function test_an_expired_plan_cannot_be_approved(): void {
		$record = $this->awaiting();

		$this->expectException( PlanStateException::class );
		$record->approve( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + ActionPlan::DEFAULT_TTL_SECONDS + 1 );
	}

	public function test_a_plan_cannot_be_approved_twice(): void {
		// Replaying an approval request must not re-arm a plan that has
		// since run and finished.
		$record = $this->awaiting();
		$record->approve( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + 5 );

		$this->expectException( PlanStateException::class );
		$record->approve( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + 6 );
	}

	public function test_a_cancelled_plan_cannot_be_approved(): void {
		$record         = $this->awaiting();
		$record->status = PlanStatus::CANCELLED;

		$this->expectException( PlanStateException::class );
		$record->approve( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + 5 );
	}

	public function test_an_unapproved_plan_is_not_executable(): void {
		$record = $this->awaiting();

		$this->expectException( PlanStateException::class );
		$record->assertExecutable( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + 5 );
	}

	public function test_an_approved_plan_is_executable_by_its_owner(): void {
		$record = SiteBuilderFixtures::approvedRecord();

		$record->assertExecutable( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + 20 );

		$this->addToAssertionCount( 1 ); // Throws on failure; reaching here is the assertion.
	}

	public function test_another_user_cannot_execute_an_approved_plan(): void {
		$record = SiteBuilderFixtures::approvedRecord();

		$this->expectException( PlanStateException::class );
		$record->assertExecutable( SiteBuilderFixtures::OWNER_ID + 1, SiteBuilderFixtures::NOW + 20 );
	}

	public function test_a_plan_that_expires_mid_run_stops_being_executable(): void {
		$record = SiteBuilderFixtures::approvedRecord();

		$this->expectException( PlanStateException::class );
		$record->assertExecutable( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + ActionPlan::DEFAULT_TTL_SECONDS + 1 );
	}

	public function test_a_plan_whose_approved_hash_no_longer_matches_is_refused(): void {
		// Simulates the plan being altered in storage after the human
		// approved it -- the exact attack the hash exists to catch.
		$record = SiteBuilderFixtures::approvedRecord();
		$record->approvedHash = str_repeat( 'a', 64 );

		$this->expectException( PlanStateException::class );
		$record->assertExecutable( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + 20 );
	}

	public function test_a_cancelled_plan_stops_being_executable(): void {
		$record         = SiteBuilderFixtures::approvedRecord();
		$record->status = PlanStatus::CANCELLED;

		$this->expectException( PlanStateException::class );
		$record->assertExecutable( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + 20 );
	}

	public function test_a_paused_plan_is_not_executable_until_resumed(): void {
		// Pause must actually stop work, not just relabel a running plan.
		$record         = SiteBuilderFixtures::approvedRecord();
		$record->status = PlanStatus::PAUSED;

		$this->expectException( PlanStateException::class );
		$record->assertExecutable( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + 20 );
	}

	public function test_a_finished_plan_cannot_be_re_run(): void {
		$record         = SiteBuilderFixtures::approvedRecord();
		$record->status = PlanStatus::SUCCEEDED;

		$this->expectException( PlanStateException::class );
		$record->assertExecutable( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + 20 );
	}

	public function test_the_state_machine_refuses_undeclared_transitions(): void {
		$this->assertFalse( PlanStatus::canTransition( PlanStatus::DRAFT, PlanStatus::RUNNING ) );
		$this->assertFalse( PlanStatus::canTransition( PlanStatus::CANCELLED, PlanStatus::RUNNING ) );
		$this->assertFalse( PlanStatus::canTransition( PlanStatus::SUCCEEDED, PlanStatus::RUNNING ) );
		$this->assertFalse( PlanStatus::canTransition( PlanStatus::ROLLED_BACK, PlanStatus::ROLLING_BACK ) );
		$this->assertTrue( PlanStatus::canTransition( PlanStatus::APPROVED, PlanStatus::RUNNING ) );
		$this->assertTrue( PlanStatus::canTransition( PlanStatus::SUCCEEDED, PlanStatus::ROLLING_BACK ) );
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\ActionPlan;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanValidationException;
use DoSieci\AiOperator\Tests\Support\SiteBuilderFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The plan hash is what makes "the human approved exactly this" checkable,
 * so these tests are about the two ways it can be wrong: hashing the same
 * plan differently (breaks valid approvals) and hashing different plans the
 * same (defeats the entire mechanism).
 */
final class ActionPlanTest extends TestCase {

	public function test_the_same_plan_content_always_hashes_identically(): void {
		$one = SiteBuilderFixtures::plan();
		$two = SiteBuilderFixtures::plan();

		$this->assertSame( $one->planHash, $two->planHash );
	}

	public function test_key_order_in_arguments_does_not_change_the_hash(): void {
		// A plan that round-tripped through JSON must still validate. If key
		// order mattered, re-serialising a plan would silently invalidate a
		// legitimate approval.
		$a = SiteBuilderFixtures::plan(
			array( SiteBuilderFixtures::action( 'a1', 1, array( 'arguments' => array( 'title' => 'Start', 'status' => 'draft' ) ) ) )
		);
		$b = SiteBuilderFixtures::plan(
			array( SiteBuilderFixtures::action( 'a1', 1, array( 'arguments' => array( 'status' => 'draft', 'title' => 'Start' ) ) ) )
		);

		$this->assertSame( $a->planHash, $b->planHash );
	}

	public function test_changing_one_argument_changes_the_hash(): void {
		$original = SiteBuilderFixtures::plan(
			array( SiteBuilderFixtures::action( 'a1', 1, array( 'arguments' => array( 'title' => 'Kontakt' ) ) ) )
		);
		$tampered = SiteBuilderFixtures::plan(
			array( SiteBuilderFixtures::action( 'a1', 1, array( 'arguments' => array( 'title' => 'Admin' ) ) ) )
		);

		$this->assertNotSame( $original->planHash, $tampered->planHash );
	}

	public function test_changing_the_tool_name_changes_the_hash(): void {
		$original = SiteBuilderFixtures::plan( array( SiteBuilderFixtures::action( 'a1', 1 ) ) );
		$swapped  = SiteBuilderFixtures::plan(
			array( SiteBuilderFixtures::action( 'a1', 1, array( 'tool_name' => 'trash_post' ) ) )
		);

		$this->assertNotSame( $original->planHash, $swapped->planHash );
	}

	public function test_reordering_steps_changes_the_hash(): void {
		// Order is content: "create the page then add it to the menu" is not
		// the same plan as the reverse.
		$forward = SiteBuilderFixtures::plan(
			array( SiteBuilderFixtures::action( 'a1', 1 ), SiteBuilderFixtures::action( 'a2', 2 ) )
		);
		$reverse = SiteBuilderFixtures::plan(
			array( SiteBuilderFixtures::action( 'a2', 2 ), SiteBuilderFixtures::action( 'a1', 1 ) )
		);

		$this->assertNotSame( $forward->planHash, $reverse->planHash );
	}

	public function test_appending_a_step_changes_the_hash(): void {
		$two   = SiteBuilderFixtures::plan();
		$three = SiteBuilderFixtures::plan(
			array(
				SiteBuilderFixtures::action( 'a1', 1 ),
				SiteBuilderFixtures::action( 'a2', 2 ),
				SiteBuilderFixtures::action( 'a3', 3, array( 'tool_name' => 'install_plugin' ) ),
			)
		);

		$this->assertNotSame( $two->planHash, $three->planHash );
	}

	public function test_a_freshly_created_plan_matches_its_own_hash(): void {
		$this->assertTrue( SiteBuilderFixtures::plan()->hashMatches() );
	}

	public function test_revising_actions_produces_a_new_version_and_a_new_hash(): void {
		$original = SiteBuilderFixtures::plan();

		$revised = $original->withRevisedActions(
			array( SiteBuilderFixtures::action( 'a1', 1, array( 'arguments' => array( 'title' => 'Nowy' ) ) ) ),
			SiteBuilderFixtures::NOW + 60
		);

		$this->assertSame( 2, $revised->planVersion );
		$this->assertNotSame( $original->planHash, $revised->planHash );
		$this->assertSame( $original->planId, $revised->planId );
	}

	public function test_the_plan_version_itself_is_part_of_the_hash(): void {
		$blueprint = SiteBuilderFixtures::blueprint();
		$actions   = array( SiteBuilderFixtures::action( 'a1', 1 ) );

		$this->assertNotSame(
			ActionPlan::canonicalHash( $blueprint, $actions, 1 ),
			ActionPlan::canonicalHash( $blueprint, $actions, 2 )
		);
	}

	public function test_a_plan_with_no_actions_is_rejected(): void {
		$this->expectException( PlanValidationException::class );

		SiteBuilderFixtures::plan( array() );
	}

	public function test_duplicate_action_ids_are_rejected(): void {
		$this->expectException( PlanValidationException::class );

		SiteBuilderFixtures::plan(
			array( SiteBuilderFixtures::action( 'same', 1 ), SiteBuilderFixtures::action( 'same', 2 ) )
		);
	}

	public function test_a_dependency_on_an_unknown_action_is_rejected(): void {
		$this->expectException( PlanValidationException::class );

		SiteBuilderFixtures::plan(
			array( SiteBuilderFixtures::action( 'a1', 1, array( 'depends_on' => array( 'nope' ) ) ) )
		);
	}

	public function test_a_dependency_on_a_later_step_is_rejected(): void {
		// Would stall forever at run time; caught at construction instead.
		$this->expectException( PlanValidationException::class );

		SiteBuilderFixtures::plan(
			array(
				SiteBuilderFixtures::action( 'a1', 1, array( 'depends_on' => array( 'a2' ) ) ),
				SiteBuilderFixtures::action( 'a2', 2 ),
			)
		);
	}

	public function test_an_unknown_rollback_strategy_is_rejected(): void {
		$this->expectException( PlanValidationException::class );

		new PlanAction( 'a1', 1, 'create_post', array(), 'reversible_write', 'x', array(), '', null, 'nuke_everything' );
	}

	public function test_a_plan_expires(): void {
		$plan = SiteBuilderFixtures::plan();

		$this->assertFalse( $plan->isExpired( SiteBuilderFixtures::NOW + 10 ) );
		$this->assertTrue( $plan->isExpired( SiteBuilderFixtures::NOW + ActionPlan::DEFAULT_TTL_SECONDS ) );
	}
}

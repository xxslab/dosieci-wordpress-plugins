<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintPlanner;
use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintValidationException;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteBlueprint;
use DoSieci\AiOperator\Tests\Support\SiteBuilderFixtures;
use PHPUnit\Framework\TestCase;

final class BlueprintPlannerTest extends TestCase {

	private function plan( ?SiteBlueprint $blueprint = null ) {
		return ( new BlueprintPlanner() )->plan(
			$blueprint ?? SiteBuilderFixtures::blueprint(),
			'plan-x',
			'conv-x',
			SiteBuilderFixtures::OWNER_ID,
			SiteBuilderFixtures::NOW
		);
	}

	/** @return string[] */
	private function toolSequence( $plan ): array {
		return array_map( static fn( PlanAction $a ): string => $a->toolName, $plan->actions );
	}

	public function test_a_service_business_blueprint_produces_a_complete_build_sequence(): void {
		$tools = $this->toolSequence( $this->plan() );

		$this->assertContains( 'install_theme', $tools );
		$this->assertContains( 'install_plugin', $tools );
		$this->assertContains( 'create_post', $tools );
		$this->assertContains( 'create_menu', $tools );
		$this->assertContains( 'set_homepage', $tools );
		$this->assertContains( 'set_site_option', $tools );
	}

	public function test_one_create_post_step_is_generated_per_blueprint_page(): void {
		$plan  = $this->plan();
		$pages = array_filter(
			$plan->actions,
			static fn( PlanAction $a ): bool => 'create_post' === $a->toolName
		);

		$this->assertCount( 3, $pages ); // Start, Oferta, Kontakt
	}

	public function test_the_theme_is_installed_before_any_page_is_created(): void {
		$tools = $this->toolSequence( $this->plan() );

		$this->assertLessThan(
			array_search( 'create_post', $tools, true ),
			array_search( 'install_theme', $tools, true )
		);
	}

	public function test_the_menu_depends_on_every_page_step(): void {
		// Ordering alone is not enough -- an explicit dependency is what
		// stops a menu of dead links being built after a page failed.
		$plan = $this->plan();

		$menu = null;
		$pageIds = array();
		foreach ( $plan->actions as $action ) {
			if ( 'create_menu' === $action->toolName ) {
				$menu = $action;
			}
			if ( 'create_post' === $action->toolName ) {
				$pageIds[] = $action->actionId;
			}
		}

		$this->assertNotNull( $menu );
		foreach ( $pageIds as $pageId ) {
			$this->assertContains( $pageId, $menu->dependsOn );
		}
	}

	public function test_set_homepage_resolves_its_page_id_from_the_first_page_step(): void {
		// The id cannot exist at plan time; the resolver wiring is what
		// fills it in, and it must point at a step this action depends on.
		$plan = $this->plan();

		$homepage = null;
		foreach ( $plan->actions as $action ) {
			if ( 'set_homepage' === $action->toolName ) {
				$homepage = $action;
			}
		}

		$this->assertNotNull( $homepage );
		$this->assertArrayHasKey( 'page_id', $homepage->resolvers );
		$this->assertSame( 'post_id', $homepage->resolvers['page_id']['field'] );
		$this->assertContains( $homepage->resolvers['page_id']['from_action'], $homepage->dependsOn );
	}

	public function test_no_contact_form_plugin_step_when_the_blueprint_does_not_ask_for_one(): void {
		$blueprint = SiteBuilderFixtures::blueprint( array( 'features' => array() ) );

		$this->assertNotContains( 'install_plugin', $this->toolSequence( $this->plan( $blueprint ) ) );
	}

	public function test_every_step_declares_a_rollback_strategy_or_explicitly_none(): void {
		foreach ( $this->plan()->actions as $action ) {
			$this->assertContains( $action->rollbackStrategy, PlanAction::ROLLBACK_STRATEGIES );
		}
	}

	public function test_every_write_step_declares_a_verification_read(): void {
		// "The handler said success" is not proof; each step names a
		// read-only tool that can confirm it independently.
		foreach ( $this->plan()->actions as $action ) {
			$this->assertNotNull(
				$action->verification,
				sprintf( 'Step %s (%s) has no verification.', $action->actionId, $action->toolName )
			);
		}
	}

	public function test_the_generated_plan_is_internally_valid_and_hashes(): void {
		$plan = $this->plan();

		$this->assertTrue( $plan->hashMatches() );
		$this->assertGreaterThan( 5, $plan->actionCount() );
	}

	public function test_the_same_blueprint_always_produces_the_same_plan_hash(): void {
		// A planner that produced different output for identical input
		// would make an approved plan unreproducible.
		$this->assertSame( $this->plan()->planHash, $this->plan()->planHash );
	}

	public function test_pages_are_created_as_published_not_drafts(): void {
		// A menu pointing at drafts and a homepage that 404s for logged-out
		// visitors is not a finished site.
		foreach ( $this->plan()->actions as $action ) {
			if ( 'create_post' === $action->toolName ) {
				$this->assertSame( 'publish', $action->arguments['status'] );
			}
		}
	}

	public function test_generated_page_content_is_block_markup(): void {
		foreach ( $this->plan()->actions as $action ) {
			if ( 'create_post' === $action->toolName ) {
				$this->assertStringContainsString( '<!-- wp:', (string) $action->arguments['content'] );
			}
		}
	}

	public function test_menu_items_are_resolved_from_the_page_steps_not_left_id_less(): void {
		// Regression: the first real-WordPress build produced an EMPTY menu.
		// The plan passed item titles with no page_id and no url, and
		// WriteToolFactory::createMenu skips exactly that shape -- so
		// create_menu reported success while adding nothing. The items must
		// therefore be resolved from the page steps' real post ids.
		$plan = $this->plan();

		$menu = null;
		foreach ( $plan->actions as $action ) {
			if ( 'create_menu' === $action->toolName ) {
				$menu = $action;
			}
		}

		$this->assertNotNull( $menu );
		$this->assertSame( 'menu_items', $menu->resolvers['items']['kind'] );
		$this->assertCount( 3, $menu->resolvers['items']['items'] );

		foreach ( $menu->resolvers['items']['items'] as $entry ) {
			$this->assertNotSame( '', $entry['title'] );
			// Every item must name a page step this action depends on --
			// otherwise the resolver refuses it and we are back to an empty
			// menu.
			$this->assertContains( $entry['from_action'], $menu->dependsOn );
		}
	}

	public function test_a_blueprint_with_no_pages_is_rejected_before_planning(): void {
		$this->expectException( BlueprintValidationException::class );

		SiteBuilderFixtures::blueprint( array( 'pages' => array() ) );
	}
}

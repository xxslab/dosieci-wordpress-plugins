<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintPlanner;
use DoSieci\AiOperator\Domain\SiteBuilder\ManagedResource;
use DoSieci\AiOperator\Domain\SiteBuilder\ManagedResourceResolverInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\ResourceResolution;
use DoSieci\AiOperator\Tests\Support\SiteBuilderFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Re-running a build must not duplicate the site, and must not quietly
 * overwrite work a human has done since. These tests pin both directions.
 */
final class ResourceReconciliationTest extends TestCase {

	/** A resolver returning a fixed decision per resource key. */
	private function resolver( array $decisions ): ManagedResourceResolverInterface {
		return new class( $decisions ) implements ManagedResourceResolverInterface {
			public array $recorded = array();

			public function __construct( private array $decisions ) {
			}

			public function resolve( ManagedResource $resource, string $projectId, string $desiredContent ): ResourceResolution {
				$decision = $this->decisions[ $resource->key() ] ?? null;

				return $decision ?? ResourceResolution::create( $resource->key() );
			}

			public function markManaged( ManagedResource $resource, string $projectId, int $objectId, string $storedContent, ?string $authoredContent = null ): void {
				$this->recorded[ $resource->key() ] = $objectId;
			}
		};
	}

	private function plan( ?ManagedResourceResolverInterface $resolver = null ) {
		return ( new BlueprintPlanner( new \DoSieci\AiOperator\Domain\SiteBuilder\Gutenberg\PageContentFactory(
			new \DoSieci\AiOperator\Domain\SiteBuilder\Gutenberg\BlockComposer()
		), $resolver ) )->plan(
			SiteBuilderFixtures::blueprint(),
			'plan-r',
			'conv',
			SiteBuilderFixtures::OWNER_ID,
			SiteBuilderFixtures::NOW,
			array(),
			'proj-1'
		);
	}

	/** @return PlanAction[] */
	private function pageSteps( $plan ): array {
		return array_values(
			array_filter(
				$plan->actions,
				static fn( PlanAction $a ): bool => in_array( $a->toolName, array( 'create_post', 'update_post' ), true )
			)
		);
	}

	// --- identity ---------------------------------------------------------

	public function test_a_resource_key_is_stable_and_normalised(): void {
		// "O nas" and "o-nas" are the same logical page; a key that
		// distinguished them would create a duplicate on the second run.
		$this->assertSame(
			ManagedResource::forRole( ManagedResource::TYPE_PAGE, 'O nas' )->key(),
			ManagedResource::forRole( ManagedResource::TYPE_PAGE, 'o-nas' )->key()
		);
		$this->assertSame( 'page:o-nas', ManagedResource::forRole( ManagedResource::TYPE_PAGE, 'O nas' )->key() );
	}

	public function test_polish_characters_produce_clean_stable_keys(): void {
		$this->assertSame(
			'page:realizacje',
			ManagedResource::forRole( ManagedResource::TYPE_PAGE, 'Realizacje' )->key()
		);
		$this->assertSame(
			'page:czesc-zamowienia',
			ManagedResource::forRole( ManagedResource::TYPE_PAGE, 'Część zamówienia' )->key()
		);
	}

	public function test_an_empty_role_never_yields_an_empty_key(): void {
		// An empty key would collide with every other empty one, making
		// unrelated resources look like the same thing.
		$this->assertSame( 'page:resource', ManagedResource::forRole( ManagedResource::TYPE_PAGE, '   ' )->key() );
	}

	public function test_a_malformed_or_unknown_key_is_rejected(): void {
		$this->assertFalse( ManagedResource::isValidKey( 'page' ) );
		$this->assertFalse( ManagedResource::isValidKey( 'wat:something' ) );
		$this->assertFalse( ManagedResource::isValidKey( '' ) );
		$this->assertTrue( ManagedResource::isValidKey( 'page:kontakt' ) );
	}

	public function test_the_fingerprint_ignores_insignificant_whitespace(): void {
		// WordPress reformats on save; a trivial difference must not read as
		// a human edit and trigger a spurious conflict.
		$this->assertSame(
			ManagedResource::fingerprint( "<p>Hello</p>\n\n<p>World</p>" ),
			ManagedResource::fingerprint( '<p>Hello</p> <p>World</p>' )
		);
		$this->assertNotSame(
			ManagedResource::fingerprint( '<p>Hello</p>' ),
			ManagedResource::fingerprint( '<p>Goodbye</p>' )
		);
	}

	public function test_the_fingerprint_ignores_the_space_wordpress_adds_to_self_closing_tags(): void {
		// Found on a real re-run: WordPress balances <hr/> into <hr />, and
		// that one byte made every page containing a separator block differ
		// from itself, planning a pointless rewrite on every single run.
		$this->assertSame(
			ManagedResource::fingerprint( '<hr class="wp-block-separator"/>' ),
			ManagedResource::fingerprint( '<hr class="wp-block-separator" />' )
		);
		// Still not blind to a real change in the same tag.
		$this->assertNotSame(
			ManagedResource::fingerprint( '<hr class="wp-block-separator" />' ),
			ManagedResource::fingerprint( '<hr class="wp-block-spacer" />' )
		);
	}

	// --- planning ---------------------------------------------------------

	public function test_with_no_resolver_every_page_is_planned_as_a_create(): void {
		foreach ( $this->pageSteps( $this->plan() ) as $step ) {
			$this->assertSame( 'create_post', $step->toolName );
		}
	}

	public function test_every_page_step_carries_its_managed_resource_key(): void {
		// Without this the executor cannot stamp ownership, and the next run
		// duplicates everything.
		foreach ( $this->pageSteps( $this->plan() ) as $step ) {
			$this->assertNotSame( '', $step->managedResourceKey );
			$this->assertTrue( ManagedResource::isValidKey( $step->managedResourceKey ) );
		}
	}

	public function test_a_managed_page_needing_new_content_is_updated_not_duplicated(): void {
		$plan = $this->plan(
			$this->resolver(
				array( 'page:start' => ResourceResolution::updateManaged( 'page:start', 42 ) )
			)
		);

		$start = null;
		foreach ( $this->pageSteps( $plan ) as $step ) {
			if ( 'page:start' === $step->managedResourceKey ) {
				$start = $step;
			}
		}

		$this->assertNotNull( $start );
		$this->assertSame( 'update_post', $start->toolName );
		$this->assertSame( 42, $start->arguments['post_id'] );
		// Rolling back an update restores prior content; it must NOT trash a
		// page that existed before this run.
		$this->assertSame( PlanAction::ROLLBACK_RESTORE_POST, $start->rollbackStrategy );
	}

	public function test_a_page_already_matching_the_blueprint_produces_no_step_at_all(): void {
		// Planning a write that would change nothing is noise in an approval
		// list the human has to read.
		$plan = $this->plan(
			$this->resolver(
				array( 'page:start' => ResourceResolution::reuse( 'page:start', 42, 'identical', true ) )
			)
		);

		foreach ( $this->pageSteps( $plan ) as $step ) {
			$this->assertNotSame( 'page:start', $step->managedResourceKey );
		}
	}

	public function test_a_human_edited_page_is_never_rewritten_and_the_conflict_is_in_the_plan(): void {
		// The most destructive thing this plugin could do is silently revert
		// somebody's writing.
		$plan = $this->plan(
			$this->resolver(
				array(
					'page:kontakt' => ResourceResolution::conflict(
						'page:kontakt',
						77,
						'Treść została zmieniona ręcznie po ostatniej zmianie kreatora.'
					),
				)
			)
		);

		foreach ( $this->pageSteps( $plan ) as $step ) {
			$this->assertNotSame( 'page:kontakt', $step->managedResourceKey );
		}

		$this->assertArrayHasKey( 'page:kontakt', $plan->conflicts );
		$this->assertStringContainsString( 'ręcznie', $plan->conflicts['page:kontakt'] );
	}

	public function test_conflicts_are_part_of_the_plan_hash(): void {
		// "We will leave your page alone" is a promise the human approves,
		// so it must not be alterable after approval.
		$clean = $this->plan();
		$withConflict = $this->plan(
			$this->resolver(
				array( 'page:kontakt' => ResourceResolution::conflict( 'page:kontakt', 77, 'edited' ) )
			)
		);

		$this->assertNotSame( $clean->planHash, $withConflict->planHash );
	}

	public function test_a_conflicted_page_is_still_linked_in_the_menu(): void {
		// We decline to WRITE to it, but the site still needs to link to it.
		$plan = $this->plan(
			$this->resolver(
				array( 'page:kontakt' => ResourceResolution::conflict( 'page:kontakt', 77, 'edited' ) )
			)
		);

		$menu = null;
		foreach ( $plan->actions as $action ) {
			if ( 'create_menu' === $action->toolName ) {
				$menu = $action;
			}
		}

		$this->assertNotNull( $menu );

		// Carried as a literal id inside the ordered resolver list: the page
		// exists, we just decline to write to it.
		$literalIds = array();
		foreach ( $menu->resolvers['items']['items'] as $entry ) {
			if ( isset( $entry['page_id'] ) ) {
				$literalIds[] = (int) $entry['page_id'];
			}
		}

		$this->assertContains( 77, $literalIds );
	}

	public function test_a_reused_home_page_still_gets_a_set_homepage_step_with_its_real_id(): void {
		$plan = $this->plan(
			$this->resolver(
				array( 'page:start' => ResourceResolution::reuse( 'page:start', 42, 'identical', true ) )
			)
		);

		$homepage = null;
		foreach ( $plan->actions as $action ) {
			if ( 'set_homepage' === $action->toolName ) {
				$homepage = $action;
			}
		}

		$this->assertNotNull( $homepage );
		$this->assertSame( 42, $homepage->arguments['page_id'] );
		// No resolver needed: the id is already known.
		$this->assertSame( array(), $homepage->resolvers );
	}

	public function test_the_managed_key_is_part_of_the_plan_hash(): void {
		// Which resource a step owns is something the human approved; it
		// cannot be repointed after the fact.
		$a = PlanAction::fromArray(
			array( 'action_id' => 'a1', 'sequence' => 1, 'tool_name' => 'create_post', 'managed_resource' => 'page:start' )
		);
		$b = PlanAction::fromArray(
			array( 'action_id' => 'a1', 'sequence' => 1, 'tool_name' => 'create_post', 'managed_resource' => 'page:kontakt' )
		);

		$this->assertNotSame(
			\DoSieci\AiOperator\Domain\SiteBuilder\ActionPlan::canonicalHash( SiteBuilderFixtures::blueprint(), array( $a ), 1 ),
			\DoSieci\AiOperator\Domain\SiteBuilder\ActionPlan::canonicalHash( SiteBuilderFixtures::blueprint(), array( $b ), 1 )
		);
	}

	public function test_a_menu_mixes_literal_ids_for_reused_pages_with_resolvers_for_new_ones(): void {
		// Regression: the executor's menu resolver REPLACED the literal
		// items instead of merging them, so on a recovery run every reused
		// page silently vanished from the menu. The final audit caught it.
		$plan = $this->plan(
			$this->resolver(
				array(
					'page:oferta'  => ResourceResolution::reuse( 'page:oferta', 61, 'identical', true ),
					'page:kontakt' => ResourceResolution::reuse( 'page:kontakt', 62, 'identical', true ),
				)
			)
		);

		$menu = null;
		foreach ( $plan->actions as $action ) {
			if ( 'create_menu' === $action->toolName ) {
				$menu = $action;
			}
		}

		$this->assertNotNull( $menu );

		// One ordered list mixing both kinds, in BLUEPRINT order -- keeping
		// them in separate lists reordered the menu on any reuse.
		$entries = $menu->resolvers['items']['items'];
		$this->assertSame(
			array( 'Start', 'Oferta', 'Kontakt' ),
			array_map( static fn( array $e ): string => (string) $e['title'], $entries )
		);

		$literalIds = array();
		foreach ( $entries as $entry ) {
			if ( isset( $entry['page_id'] ) ) {
				$literalIds[] = (int) $entry['page_id'];
			}
		}
		sort( $literalIds );
		$this->assertSame( array( 61, 62 ), $literalIds );
	}

	public function test_the_project_id_scopes_the_plan(): void {
		$plan = $this->plan();

		$this->assertSame( 'proj-1', $plan->projectId );
	}
}

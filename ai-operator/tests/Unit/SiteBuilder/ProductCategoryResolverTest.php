<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\ActionState;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanExecutor;
use DoSieci\AiOperator\Domain\SiteBuilder\RollbackDataCollectorInterface;
use DoSieci\AiOperator\Domain\Tools\ArgumentsValidator;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolDispatcher;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use DoSieci\AiOperator\Tests\Support\FakeCapabilityChecker;
use DoSieci\AiOperator\Tests\Support\InMemoryAuditLog;
use DoSieci\AiOperator\Tests\Support\InMemoryPlanRepository;
use DoSieci\AiOperator\Tests\Support\SiteBuilderFixtures;
use PHPUnit\Framework\TestCase;

/**
 * A product's category list is resolved from an ORDERED list of entries,
 * each either naming the step that will create its category (id known
 * only at run time) or already knowing the id (the category was reused,
 * or is a conflict the product still links to). Exactly the pattern
 * PlanExecutor already used for menu items, applied here for the same
 * reason.
 *
 * ## Why this exists
 *
 * The planner's first cut only ever emitted `from_action` entries, on the
 * assumption that a product's categories are always created in the same
 * plan. That is true on a first build and false on a recovery plan: a
 * category reconciled as REUSE gets no step of its own, so a resolver
 * that only scanned this plan's steps found nothing for it. The product
 * still had to go somewhere, so WooCommerce filed it under its own
 * "Uncategorized" -- silently, with a step that reported success and a
 * verifier that had nothing to compare against because the plan's own
 * literal argument was the same wrong answer.
 *
 * Found on a real partial-failure recovery run against WooCommerce
 * 11.0.1: a category created in run 1 and reused in the recovery plan
 * left the recovery-created product in the wrong category.
 */
final class ProductCategoryResolverTest extends TestCase {

	private InMemoryPlanRepository $plans;
	private InMemoryAuditLog $audit;

	/** @var array<int, array{tool:string, args:array}> */
	private array $dispatched = array();

	protected function setUp(): void {
		$this->plans      = new InMemoryPlanRepository();
		$this->audit      = new InMemoryAuditLog();
		$this->dispatched = array();
	}

	private function registry(): ToolRegistry {
		$registry = new ToolRegistry();

		$registry->register(
			new ToolDefinition(
				'create_product_category',
				'test tool',
				array( 'type' => 'object', 'properties' => array( 'name' => array( 'type' => 'string' ) ) ),
				'manage_product_terms',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				5,
				function ( array $args ): array {
					$this->dispatched[] = array( 'tool' => 'create_product_category', 'args' => $args );

					return array( 'term_id' => 50, 'name' => (string) $args['name'], 'created' => true );
				}
			)
		);

		$registry->register(
			new ToolDefinition(
				'create_product_draft',
				'test tool',
				array(
					'type'       => 'object',
					'properties' => array(
						'name'          => array( 'type' => 'string' ),
						'regular_price' => array( 'type' => 'string' ),
						'category_ids'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					),
				),
				'publish_products',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				5,
				function ( array $args ): array {
					$this->dispatched[] = array( 'tool' => 'create_product_draft', 'args' => $args );

					return array( 'product_id' => 99, 'created' => true );
				}
			)
		);

		return $registry;
	}

	private function executor(): PlanExecutor {
		$collector = new class implements RollbackDataCollectorInterface {
			public function capture( PlanAction $action, array $resolvedArguments = array() ): ?array {
				return null;
			}
		};

		return new PlanExecutor(
			$this->plans,
			new ToolDispatcher(
				$this->registry(),
				new ArgumentsValidator(),
				FakeCapabilityChecker::allowingEverything(),
				$this->audit,
				ToolDefinition::RISK_DESTRUCTIVE
			),
			$collector,
			null,
			null,
			null,
			static fn(): int => SiteBuilderFixtures::NOW + 100
		);
	}

	/** @param PlanAction[] $actions */
	private function record( array $actions ) {
		$record = new \DoSieci\AiOperator\Domain\SiteBuilder\PlanRecord( SiteBuilderFixtures::plan( $actions ) );
		$record->status = \DoSieci\AiOperator\Domain\SiteBuilder\PlanStatus::AWAITING_APPROVAL;
		$record->approve( SiteBuilderFixtures::OWNER_ID, SiteBuilderFixtures::NOW + 10 );

		return $record;
	}

	private function categoryAction(): PlanAction {
		return SiteBuilderFixtures::action(
			'cat-1',
			1,
			array(
				'tool_name'        => 'create_product_category',
				'arguments'        => array( 'name' => 'Koszulki' ),
				'rollback_strategy' => PlanAction::ROLLBACK_DELETE_PRODUCT_CATEGORY,
				'managed_resource' => 'product_category:koszulki',
			)
		);
	}

	private function productAction( array $categoryEntries, array $dependsOn ): PlanAction {
		return SiteBuilderFixtures::action(
			'prod-1',
			2,
			array(
				'tool_name'        => 'create_product_draft',
				'arguments'        => array( 'name' => 'Koszulka', 'regular_price' => '79.00', 'category_ids' => array() ),
				'depends_on'       => $dependsOn,
				'rollback_strategy' => PlanAction::ROLLBACK_TRASH_PRODUCT,
				'resolvers'        => array(
					'category_ids' => array( 'kind' => 'product_categories', 'items' => $categoryEntries ),
				),
			)
		);
	}

	public function test_a_from_action_entry_resolves_the_id_the_category_step_just_created(): void {
		$record = $this->record(
			array(
				$this->categoryAction(),
				$this->productAction( array( array( 'from_action' => 'cat-1' ) ), array( 'cat-1' ) ),
			)
		);
		$this->plans->save( $record );

		$this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID ); // cat-1
		$this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID ); // prod-1

		$productCall = $this->dispatched[1];
		$this->assertSame( 'create_product_draft', $productCall['tool'] );
		$this->assertSame( array( 50 ), $productCall['args']['category_ids'] );
	}

	public function test_a_literal_term_id_entry_resolves_with_no_step_at_all(): void {
		// The case that was broken: the category was reused on a prior run,
		// so THIS plan contains no step for it -- only a term id already
		// known at plan time.
		$record = $this->record(
			array( $this->productAction( array( array( 'term_id' => 77 ) ), array() ) )
		);
		$this->plans->save( $record );

		$this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID );

		$productCall = $this->dispatched[0];
		$this->assertSame( array( 77 ), $productCall['args']['category_ids'] );
	}

	public function test_mixed_entries_resolve_in_order_one_new_one_reused(): void {
		// A product spanning a category created THIS run and a category
		// reused from a previous one -- the realistic recovery shape.
		$record = $this->record(
			array(
				$this->categoryAction(),
				$this->productAction(
					array( array( 'term_id' => 77 ), array( 'from_action' => 'cat-1' ) ),
					array( 'cat-1' )
				),
			)
		);
		$this->plans->save( $record );

		$this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID ); // cat-1
		$this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID ); // prod-1

		$this->assertSame( array( 77, 50 ), $this->dispatched[1]['args']['category_ids'] );
	}

	public function test_a_from_action_entry_not_in_dependson_is_refused(): void {
		// The same safety rule every other resolver honours: a step may
		// only read a prior step it explicitly declared a dependency on,
		// so a resolver cannot be pointed at a step the human never saw
		// this action wait for.
		$record = $this->record(
			array(
				$this->categoryAction(),
				$this->productAction( array( array( 'from_action' => 'cat-1' ) ), array() ),
			)
		);
		$this->plans->save( $record );

		$this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID ); // cat-1
		$this->executor()->executeNext( 'plan-1', SiteBuilderFixtures::OWNER_ID ); // prod-1

		$this->assertSame( array(), $this->dispatched[1]['args']['category_ids'] );
	}
}

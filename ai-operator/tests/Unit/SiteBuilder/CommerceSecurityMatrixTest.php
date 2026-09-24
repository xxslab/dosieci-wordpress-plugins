<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\ActionPlan;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use DoSieci\AiOperator\Domain\Tools\UnknownToolException;
use DoSieci\AiOperator\Tests\Support\SiteBuilderFixtures;
use PHPUnit\Framework\TestCase;

/**
 * The commerce security model is a closed tool list plus the same
 * generic hash/approval machinery every other Site Builder step already
 * uses. Nothing here is a NEW mechanism -- these tests prove the existing
 * mechanisms actually cover the commerce surface, because "the general
 * case is tested" is not evidence the specific dangerous case is caught.
 *
 * Structural absence is the security model, not a runtime check: a tool
 * for a payment secret, a webhook, an order mutation or an arbitrary
 * WooCommerce option does not exist anywhere the registry can find it.
 * ToolRegistry::has() and get() are the ground truth for "can this ever
 * be dispatched", so that is what these tests query -- not documentation,
 * not a comment, not a naming convention.
 */
final class CommerceSecurityMatrixTest extends TestCase {

	/** Every commerce write tool that may ever reach the registry. */
	private const KNOWN_COMMERCE_TOOLS = array(
		'ensure_store_pages',
		'set_store_basics',
		'create_product_category',
		'update_product_category',
		'create_product_draft',
		'update_product_draft',
	);

	/**
	 * Tool names a compromised or malicious blueprint might request.
	 * None of these may ever be registered -- checked by NAME, and by
	 * every plausible naming variant, because "we didn't build it" is
	 * only a security property if there is no route to it at all.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_TOOL_NAMES = array(
		'set_woocommerce_option',
		'set_option',
		'update_option',
		'set_stripe_secret',
		'set_payment_gateway',
		'configure_payment_gateway',
		'set_stripe_credentials',
		'set_paypal_credentials',
		'create_rest_api_key',
		'create_webhook',
		'set_webhook',
		'add_webhook',
		'process_refund',
		'refund_order',
		'update_order',
		'update_order_status',
		'set_order_status',
		'delete_customer',
		'remove_customer',
		'set_customer_password',
		'change_password',
		'create_coupon',
		'apply_coupon',
		'run_php',
		'eval_php',
		'execute_php',
		'run_sql',
		'execute_sql',
		'shell_exec',
		'run_shell',
		'download_image',
		'fetch_remote_image',
		'set_product_image_url',
		'publish_product',
		'set_product_status',
	);

	private function registry(): ToolRegistry {
		$registry = new ToolRegistry();
		( new \DoSieci\AiOperator\Adapter\WordPress\Tools\CommerceToolFactory() )->register( $registry );

		return $registry;
	}

	// --- structural absence -------------------------------------------

	public function test_the_registry_contains_exactly_the_known_commerce_tools(): void {
		$names = $this->registry()->names();
		sort( $names );

		$expected = self::KNOWN_COMMERCE_TOOLS;
		sort( $expected );

		$this->assertSame( $expected, $names );
	}

	public function test_no_forbidden_tool_name_is_ever_registered(): void {
		$registry = $this->registry();

		foreach ( self::FORBIDDEN_TOOL_NAMES as $name ) {
			$this->assertFalse(
				$registry->has( $name ),
				sprintf( 'Tool "%s" must never be registered -- there is no safe implementation of it.', $name )
			);
		}
	}

	public function test_dispatching_an_unknown_commerce_tool_name_throws(): void {
		$this->expectException( UnknownToolException::class );

		$this->registry()->get( 'set_woocommerce_option' );
	}

	// --- no generic option primitive -----------------------------------

	public function test_set_store_basics_accepts_only_the_four_named_fields(): void {
		// The schema itself is the security boundary: a property outside
		// this list cannot be validated through, whatever a model asks for.
		$definition = $this->registry()->get( 'set_store_basics' );
		$properties = $definition->argumentsSchema['properties'] ?? array();

		$this->assertSame(
			array( 'country', 'currency', 'weight_unit', 'dimension_unit' ),
			array_keys( $properties )
		);
	}

	public function test_no_commerce_tool_schema_declares_an_option_name_field(): void {
		// A generic "which setting" field is exactly how set_store_basics's
		// four fields would turn back into set_woocommerce_option(name, *).
		foreach ( $this->registry()->all() as $definition ) {
			$properties = array_keys( $definition->argumentsSchema['properties'] ?? array() );

			foreach ( array( 'option', 'option_name', 'key', 'setting' ) as $forbidden ) {
				$this->assertNotContains(
					$forbidden,
					$properties,
					sprintf( 'Tool "%s" must not accept a generic "%s" field.', $definition->name, $forbidden )
				);
			}
		}
	}

	public function test_no_product_tool_schema_accepts_a_status_field(): void {
		// There is deliberately no way to ask for anything but a draft.
		foreach ( array( 'create_product_draft', 'update_product_draft' ) as $tool ) {
			$definition = $this->registry()->get( $tool );
			$properties = array_keys( $definition->argumentsSchema['properties'] ?? array() );

			$this->assertNotContains( 'status', $properties );
			$this->assertNotContains( 'post_status', $properties );
		}
	}

	public function test_no_product_tool_schema_accepts_an_image_url_field(): void {
		// Product images come from the Media Library only. A url-shaped
		// argument would be a route to server-side request forgery.
		foreach ( array( 'create_product_draft', 'update_product_draft' ) as $tool ) {
			$definition = $this->registry()->get( $tool );
			$properties = array_keys( $definition->argumentsSchema['properties'] ?? array() );

			foreach ( array( 'image_url', 'url', 'remote_url', 'src' ) as $forbidden ) {
				$this->assertNotContains( $forbidden, $properties );
			}

			$this->assertContains( 'image_id', $properties );
		}
	}

	// --- plan hash covers every commercial value ------------------------

	private function storeAction( array $overrides = array() ): PlanAction {
		return SiteBuilderFixtures::action(
			'a1',
			1,
			array_merge(
				array(
					'tool_name'        => 'create_product_draft',
					'arguments'        => array(
						'name'          => 'Koszulka Classic',
						'regular_price' => '79.00',
						'category_ids'  => array( 5 ),
						'image_id'      => 12,
					),
					'rollback_strategy' => PlanAction::ROLLBACK_TRASH_PRODUCT,
				),
				$overrides
			)
		);
	}

	public function test_a_price_change_after_the_fact_changes_the_hash(): void {
		$original = $this->storeAction();
		$tampered = $this->storeAction( array( 'arguments' => array_merge( $original->arguments, array( 'regular_price' => '7.90' ) ) ) );

		$this->assertNotSame(
			ActionPlan::canonicalHash( SiteBuilderFixtures::blueprint(), array( $original ), 1 ),
			ActionPlan::canonicalHash( SiteBuilderFixtures::blueprint(), array( $tampered ), 1 )
		);
	}

	public function test_a_category_change_after_the_fact_changes_the_hash(): void {
		$original = $this->storeAction();
		$tampered = $this->storeAction( array( 'arguments' => array_merge( $original->arguments, array( 'category_ids' => array( 999 ) ) ) ) );

		$this->assertNotSame(
			ActionPlan::canonicalHash( SiteBuilderFixtures::blueprint(), array( $original ), 1 ),
			ActionPlan::canonicalHash( SiteBuilderFixtures::blueprint(), array( $tampered ), 1 )
		);
	}

	public function test_an_appended_woo_step_changes_the_hash(): void {
		$before = array( $this->storeAction() );
		$after  = array(
			$this->storeAction(),
			SiteBuilderFixtures::action(
				'a2',
				2,
				array( 'tool_name' => 'create_product_category', 'arguments' => array( 'name' => 'Injected' ) )
			),
		);

		$this->assertNotSame(
			ActionPlan::canonicalHash( SiteBuilderFixtures::blueprint(), $before, 1 ),
			ActionPlan::canonicalHash( SiteBuilderFixtures::blueprint(), $after, 1 )
		);
	}

	public function test_a_currency_change_in_the_store_settings_step_changes_the_hash(): void {
		$original = SiteBuilderFixtures::action(
			'a1',
			1,
			array( 'tool_name' => 'set_store_basics', 'arguments' => array( 'country' => 'PL', 'currency' => 'PLN' ) )
		);
		$tampered = SiteBuilderFixtures::action(
			'a1',
			1,
			array( 'tool_name' => 'set_store_basics', 'arguments' => array( 'country' => 'PL', 'currency' => 'EUR' ) )
		);

		$this->assertNotSame(
			ActionPlan::canonicalHash( SiteBuilderFixtures::blueprint(), array( $original ), 1 ),
			ActionPlan::canonicalHash( SiteBuilderFixtures::blueprint(), array( $tampered ), 1 )
		);
	}
}

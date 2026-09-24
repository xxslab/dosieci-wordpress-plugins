<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\Gateway\ToolSchemaExporter;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What the model is allowed to know about.
 *
 * The filtering here is not cosmetic. A tool advertised to the model but
 * then refused at the dispatch gate wastes a round trip and produces a
 * confusing "I tried and was refused" answer; a tool withheld from the
 * model can never be called at all. Both directions have to be right.
 */
final class ToolSchemaExporterTest extends TestCase {

	private function registry(): ToolRegistry {
		$registry = new ToolRegistry();

		$registry->register(
			new ToolDefinition(
				'get_site_info',
				'Read-only info.',
				array( 'type' => 'object', 'properties' => array() ),
				'manage_options',
				ToolDefinition::RISK_READ_ONLY,
				5,
				static fn(): array => array()
			)
		);

		$registry->register(
			new ToolDefinition(
				'search_products',
				'WooCommerce products.',
				array(
					'type'       => 'object',
					'properties' => array( 'query' => array( 'type' => 'string' ) ),
					'required'   => array( 'query' ),
				),
				'edit_products',
				ToolDefinition::RISK_READ_ONLY,
				5,
				static fn(): array => array()
			)
		);

		$registry->register(
			new ToolDefinition(
				'create_post',
				'Creates a page.',
				array(
					'type'       => 'object',
					'properties' => array( 'title' => array( 'type' => 'string' ) ),
					'required'   => array( 'title' ),
				),
				'publish_pages',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				5,
				static fn(): array => array()
			)
		);

		return $registry;
	}

	private static function names( array $exported ): array {
		return array_map( static fn( array $tool ): string => $tool['name'], $exported );
	}

	public function test_write_tools_are_withheld_when_the_installation_is_read_only(): void {
		$exporter = new ToolSchemaExporter(
			$this->registry(),
			static fn(): bool => true,
			ToolDefinition::RISK_READ_ONLY
		);

		$names = self::names( $exporter->forAnthropic() );

		$this->assertContains( 'get_site_info', $names );
		$this->assertNotContains( 'create_post', $names );
	}

	public function test_write_tools_are_offered_once_writes_are_enabled(): void {
		$exporter = new ToolSchemaExporter(
			$this->registry(),
			static fn(): bool => true,
			ToolDefinition::RISK_DESTRUCTIVE
		);

		$this->assertContains( 'create_post', self::names( $exporter->forAnthropic() ) );
	}

	public function test_tools_the_user_lacks_the_capability_for_are_withheld(): void {
		$exporter = new ToolSchemaExporter(
			$this->registry(),
			// A shop manager without edit_products.
			static fn( string $capability ): bool => 'manage_options' === $capability,
			ToolDefinition::RISK_READ_ONLY
		);

		$names = self::names( $exporter->forAnthropic() );

		$this->assertSame( array( 'get_site_info' ), $names );
	}

	public function test_the_anthropic_shape_uses_input_schema(): void {
		$exporter = new ToolSchemaExporter( $this->registry(), static fn(): bool => true );

		$tool = $exporter->forAnthropic()[0];

		$this->assertArrayHasKey( 'input_schema', $tool );
		$this->assertArrayHasKey( 'description', $tool );
	}

	public function test_the_openai_shape_wraps_the_tool_in_a_function_object(): void {
		$exporter = new ToolSchemaExporter( $this->registry(), static fn(): bool => true );

		$tool = $exporter->forOpenAi()[0];

		$this->assertSame( 'function', $tool['type'] );
		$this->assertArrayHasKey( 'parameters', $tool['function'] );
		$this->assertSame( 'get_site_info', $tool['function']['name'] );
	}

	/**
	 * PHP's empty array encodes as [], but both providers require an object
	 * for a schema's properties. Every no-argument tool would be rejected
	 * without this normalisation.
	 */
	public function test_an_empty_properties_map_serialises_as_an_object(): void {
		$exporter = new ToolSchemaExporter( $this->registry(), static fn(): bool => true );

		$json = (string) json_encode( $exporter->forAnthropic()[0]['input_schema'] );

		$this->assertStringContainsString( '"properties":{}', $json );
	}

	public function test_a_populated_schema_keeps_its_properties_and_required_list(): void {
		$exporter = new ToolSchemaExporter( $this->registry(), static fn(): bool => true );

		$productsTool = null;
		foreach ( $exporter->forAnthropic() as $tool ) {
			if ( 'search_products' === $tool['name'] ) {
				$productsTool = $tool;
			}
		}

		$this->assertNotNull( $productsTool );
		$this->assertArrayHasKey( 'query', $productsTool['input_schema']['properties'] );
		$this->assertSame( array( 'query' ), $productsTool['input_schema']['required'] );
	}

	/**
	 * Regression for the production fix in AnthropicMessageMapper
	 * (149ec61, "encode empty tool schemas as JSON objects"): a shallow,
	 * top-level-only version of this normalisation shipped first and was
	 * found insufficient against a real Anthropic response, because a
	 * write tool can nest an object schema with empty properties inside an
	 * array's `items` (exactly what create_menu's `items` parameter does).
	 * The fix must therefore recurse, not just fix the outermost object.
	 */
	public function test_a_nested_empty_object_inside_an_array_items_schema_is_normalised(): void {
		$registry = new ToolRegistry();
		$registry->register(
			new ToolDefinition(
				'create_menu',
				'Creates a navigation menu.',
				array(
					'type'       => 'object',
					'properties' => array(
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(),
							),
						),
					),
				),
				'edit_theme_options',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				5,
				static fn(): array => array()
			)
		);

		$exporter = new ToolSchemaExporter( $registry, static fn(): bool => true, ToolDefinition::RISK_REVERSIBLE_WRITE );

		$json = (string) json_encode( $exporter->forAnthropic()[0]['input_schema'] );

		// The nested items-schema's properties must serialise as {}, not
		// [] -- Anthropic rejects the latter for an object schema
		// regardless of how deep it is nested.
		$this->assertStringContainsString( '"items":{"type":"object","properties":{}}', $json );
	}
}

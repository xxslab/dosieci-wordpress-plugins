<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Gateway;

use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;

/**
 * Turns this installation's live tool registry into the tool schemas the
 * two supported providers expect.
 *
 * Only relevant in BYOK mode. When routing through the Hub, the Hub owns
 * the tool list it advertises to the model; here the plugin is talking to
 * the provider itself, so it must describe its own tools.
 *
 * Two properties matter and are enforced here rather than left to the
 * caller:
 *
 *  - The exported list is derived from the SAME registry ToolDispatcher
 *    dispatches against. A model cannot be told about a tool that would
 *    then be refused as unknown, and cannot be kept ignorant of one that
 *    is actually available.
 *
 *  - Tools the current user could not run anyway are filtered out. Telling
 *    a shop manager's model about a tool that will be denied at the
 *    capability gate wastes a round trip and produces a confusing "I tried
 *    but was refused" answer, when the honest behaviour is for the model
 *    to never see the tool at all.
 */
final class ToolSchemaExporter {

	/**
	 * @param callable(string):bool $canDo receives a capability, returns whether the current user has it
	 */
	public function __construct(
		private ToolRegistry $registry,
		private $canDo,
		private string $maxRiskLevel = ToolDefinition::RISK_READ_ONLY
	) {
	}

	private const RISK_ORDER = array(
		ToolDefinition::RISK_READ_ONLY        => 0,
		ToolDefinition::RISK_REVERSIBLE_WRITE => 1,
		ToolDefinition::RISK_DESTRUCTIVE      => 2,
	);

	/** @return ToolDefinition[] */
	public function available(): array {
		$maxRisk = self::RISK_ORDER[ $this->maxRiskLevel ] ?? 0;

		return array_values(
			array_filter(
				$this->registry->all(),
				function ( ToolDefinition $tool ) use ( $maxRisk ): bool {
					if ( ( self::RISK_ORDER[ $tool->riskLevel ] ?? 99 ) > $maxRisk ) {
						return false;
					}

					return (bool) ( $this->canDo )( $tool->requiredCapability );
				}
			)
		);
	}

	/**
	 * Anthropic Messages API tool format.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forAnthropic(): array {
		return array_map(
			static fn( ToolDefinition $tool ): array => array(
				'name'         => $tool->name,
				'description'  => $tool->description,
				'input_schema' => self::normaliseSchema( $tool->argumentsSchema ),
			),
			$this->available()
		);
	}

	/**
	 * OpenAI Chat Completions tool format.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forOpenAi(): array {
		return array_map(
			static fn( ToolDefinition $tool ): array => array(
				'type'     => 'function',
				'function' => array(
					'name'        => $tool->name,
					'description' => $tool->description,
					'parameters'  => self::normaliseSchema( $tool->argumentsSchema ),
				),
			),
			$this->available()
		);
	}

	/**
	 * Both providers reject a tool schema whose `properties` is a JSON
	 * array rather than an object, which is exactly what json_encode()
	 * produces for PHP's empty array -- the no-argument tools (the majority
	 * of the read-only set) would all fail schema validation at the
	 * provider without this.
	 *
	 * Applied recursively, not just at the top level: a write tool such as
	 * `create_menu` nests object schemas inside an array's `items`, and any
	 * one of those can independently have empty `properties`. This mirrors
	 * the Hub's own AnthropicMessageMapper::normalizeInputSchema() exactly
	 * -- that recursive shape is a verified fix from a real Anthropic
	 * response (a shallow, top-level-only version of this shipped first and
	 * was found insufficient), so it is ported here rather than
	 * re-derived, to keep both call paths to Anthropic behaving identically.
	 *
	 * @param array<string, mixed> $schema
	 *
	 * @return array<string, mixed>
	 */
	/**
	 * Provider-neutral shape for the WordPress AI client, which turns each
	 * entry into a FunctionDeclaration and maps it to whichever provider
	 * the site connected.
	 *
	 * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
	 */
	public function forFunctionDeclarations(): array {
		return array_map(
			static fn( ToolDefinition $tool ): array => array(
				'name'        => $tool->name,
				'description' => $tool->description,
				'parameters'  => self::normaliseSchema( $tool->argumentsSchema ),
			),
			$this->available()
		);
	}

	private static function normaliseSchema( array $schema ): array {
		if ( array() === $schema ) {
			return array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			);
		}

		if ( ! isset( $schema['type'] ) ) {
			$schema['type'] = 'object';
		}

		if ( 'object' === $schema['type'] ) {
			$properties = $schema['properties'] ?? array();

			if ( array() === $properties ) {
				$schema['properties'] = new \stdClass();
			} elseif ( is_array( $properties ) ) {
				foreach ( $properties as $name => $propertySchema ) {
					if ( is_array( $propertySchema ) ) {
						$properties[ $name ] = self::normaliseSchema( $propertySchema );
					}
				}
				$schema['properties'] = $properties;
			}
		}

		if ( 'array' === $schema['type'] && is_array( $schema['items'] ?? null ) ) {
			$schema['items'] = self::normaliseSchema( $schema['items'] );
		}

		return $schema;
	}
}

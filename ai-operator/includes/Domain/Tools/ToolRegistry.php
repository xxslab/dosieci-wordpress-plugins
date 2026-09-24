<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Tools;

/**
 * The allowlist of tool names this installation will run, by name.
 *
 * A tool the model asks for that is not registered here is refused --
 * there is no dynamic dispatch, no "call whatever method matches the
 * name" fallback, and therefore no way for a model (or anything that can
 * influence a model, e.g. prompt injection in scanned post content) to
 * reach a function that was never deliberately exposed.
 */
final class ToolRegistry {

	/** @var array<string, ToolDefinition> */
	private array $tools = array();

	public function register( ToolDefinition $tool ): void {
		if ( isset( $this->tools[ $tool->name ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Tool “%s” is already registered.', esc_html( $tool->name ) ) );
		}

		$this->tools[ $tool->name ] = $tool;
	}

	public function has( string $name ): bool {
		return isset( $this->tools[ $name ] );
	}

	/**
	 * @throws UnknownToolException
	 */
	public function get( string $name ): ToolDefinition {
		if ( ! isset( $this->tools[ $name ] ) ) {
			throw new UnknownToolException( sprintf( 'Unknown tool “%s”.', esc_html( $name ) ) );
		}

		return $this->tools[ $name ];
	}

	/** @return array<string, ToolDefinition> */
	public function all(): array {
		return $this->tools;
	}

	/** @return string[] */
	public function names(): array {
		return array_keys( $this->tools );
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Tools;

/**
 * Validates the arguments the MODEL produced against the tool's declared
 * schema, before the tool's handler is allowed to see them.
 *
 * This is not schema validation for tidiness -- the model's JSON output is
 * untrusted external input in exactly the same sense as a POST body
 * (AI_OPERATOR_SECURITY.md section 3), and this is the boundary that makes
 * it safe to pass to a handler that will use it in a WP_Query or a
 * get_post() call.
 *
 * Supported subset (deliberately small; matching the Hub-side
 * ArgumentsValidator so a tool schema means the same thing on both sides):
 * type object with properties, required, and per-property type of
 * string|integer|boolean|number|array|object plus optional enum. Unknown
 * properties are REJECTED rather than ignored -- silently dropping an
 * argument the model thought it was passing produces confusing,
 * hard-to-debug behaviour.
 *
 * array/object support was added alongside the write tools
 * (create_menu's `items` is an array of objects) -- read-only Phase 1
 * never needed them, which is why the Hub-side ArgumentsValidator already
 * declared array/object in its own docblock (packages/ai-operator-core)
 * while this class did not: it was carried over from Phase 1 and never
 * extended when the plugin gained write tools. Left unfixed, EVERY call
 * to a tool with an array argument would be rejected as "must be a
 * string" before the handler ever ran -- this was only caught by
 * exercising create_menu against a real WordPress install, not by any
 * mocked unit test, which is why one exists (test_array_arguments below).
 */
final class ArgumentsValidator {

	/**
	 * @param array<string, mixed> $schema
	 * @param array<string, mixed> $arguments
	 *
	 * @return array<string, mixed> the validated (and type-coerced) arguments
	 *
	 * @throws InvalidArgumentsException
	 */
	public function validate( string $toolName, array $schema, array $arguments ): array {
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();

		foreach ( $required as $name ) {
			if ( ! array_key_exists( $name, $arguments ) ) {
				throw new InvalidArgumentsException( sprintf( 'Tool “%s”: missing required argument “%s”.', esc_html( $toolName ), esc_html( (string) $name ) ) );
			}
		}

		$validated = array();

		foreach ( $arguments as $name => $value ) {
			if ( ! isset( $properties[ $name ] ) ) {
				throw new InvalidArgumentsException( sprintf( 'Tool “%s”: unknown argument “%s”.', esc_html( $toolName ), esc_html( (string) $name ) ) );
			}

			$validated[ $name ] = $this->coerce( $toolName, (string) $name, (array) $properties[ $name ], $value );
		}

		return $validated;
	}

	/**
	 * @param array<string, mixed> $property
	 */
	private function coerce( string $toolName, string $name, array $property, mixed $value ): mixed {
		$type = isset( $property['type'] ) ? (string) $property['type'] : 'string';

		$coerced = match ( $type ) {
			'integer' => is_int( $value ) || ( is_string( $value ) && ctype_digit( ltrim( $value, '-' ) ) )
				? (int) $value
				: throw new InvalidArgumentsException( sprintf( 'Tool “%s”: argument “%s” must be an integer.', esc_html( $toolName ), esc_html( $name ) ) ),
			'number'  => is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) )
				? (float) $value
				: throw new InvalidArgumentsException( sprintf( 'Tool “%s”: argument “%s” must be a number.', esc_html( $toolName ), esc_html( $name ) ) ),
			'boolean' => is_bool( $value )
				? $value
				: throw new InvalidArgumentsException( sprintf( 'Tool “%s”: argument “%s” must be a boolean.', esc_html( $toolName ), esc_html( $name ) ) ),
			'array'   => is_array( $value ) && array_is_list( $value )
				? $this->coerceList( $toolName, $name, $property, $value )
				: throw new InvalidArgumentsException( sprintf( 'Tool “%s”: argument “%s” must be an array.', esc_html( $toolName ), esc_html( $name ) ) ),
			'object'  => is_array( $value ) && ! array_is_list( $value )
				? $this->coerceObject( $toolName, $name, $property, $value )
				: throw new InvalidArgumentsException( sprintf( 'Tool “%s”: argument “%s” must be an object.', esc_html( $toolName ), esc_html( $name ) ) ),
			default   => is_scalar( $value )
				? (string) $value
				: throw new InvalidArgumentsException( sprintf( 'Tool “%s”: argument “%s” must be a string.', esc_html( $toolName ), esc_html( $name ) ) ),
		};

		if ( isset( $property['enum'] ) && is_array( $property['enum'] ) && ! in_array( $coerced, $property['enum'], true ) ) {
			throw new InvalidArgumentsException( sprintf( 'Tool “%s”: argument “%s” is not one of the allowed values.', esc_html( $toolName ), esc_html( $name ) ) );
		}

		return $coerced;
	}

	/**
	 * @param array<string, mixed> $property
	 * @param array<int, mixed>    $value
	 *
	 * @return array<int, mixed>
	 */
	private function coerceList( string $toolName, string $name, array $property, array $value ): array {
		$itemSchema = isset( $property['items'] ) && is_array( $property['items'] ) ? $property['items'] : null;

		// No item schema declared -- items pass through unvalidated. This
		// matches the Hub-side validator's permissiveness for an
		// under-specified schema rather than inventing a stricter rule
		// this class was never asked to enforce.
		if ( null === $itemSchema ) {
			return $value;
		}

		$coercedItems = array();
		foreach ( $value as $index => $item ) {
			$itemType = isset( $itemSchema['type'] ) ? (string) $itemSchema['type'] : 'string';

			// Object items go through the full property-by-property
			// validator (unknown keys rejected, each field's own type
			// enforced) -- exactly the same rigor a top-level argument
			// gets, because a menu item's page_id or url is exactly as
			// untrusted as any other model-supplied value.
			if ( 'object' === $itemType ) {
				if ( ! is_array( $item ) || array_is_list( $item ) ) {
					throw new InvalidArgumentsException( sprintf( 'Tool “%s”: argument “%s[%d]” must be an object.', esc_html( $toolName ), esc_html( $name ), (int) $index ) );
				}

				$coercedItems[] = $this->validateObjectProperties( $toolName, sprintf( '%s[%d]', $name, $index ), $itemSchema, $item );

				continue;
			}

			$coercedItems[] = $this->coerce( $toolName, sprintf( '%s[%d]', $name, $index ), $itemSchema, $item );
		}

		return $coercedItems;
	}

	/**
	 * @param array<string, mixed> $property
	 * @param array<string, mixed> $value
	 *
	 * @return array<string, mixed>
	 */
	private function coerceObject( string $toolName, string $name, array $property, array $value ): array {
		return $this->validateObjectProperties( $toolName, $name, $property, $value );
	}

	/**
	 * Shared by top-level `object` arguments and object-typed array items.
	 * Unknown keys are optional here (not required, unlike the top-level
	 * validate()) -- a menu item legitimately omits `page_id` when it
	 * carries `url` instead, and the tool's own handler is what enforces
	 * "at least one of page_id/url" business logic, not the schema layer.
	 *
	 * @param array<string, mixed> $schema
	 * @param array<string, mixed> $value
	 *
	 * @return array<string, mixed>
	 */
	private function validateObjectProperties( string $toolName, string $path, array $schema, array $value ): array {
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();

		foreach ( $required as $requiredName ) {
			if ( ! array_key_exists( $requiredName, $value ) ) {
				throw new InvalidArgumentsException( sprintf( 'Tool “%s”: “%s” is missing required field “%s”.', esc_html( $toolName ), esc_html( $path ), esc_html( (string) $requiredName ) ) );
			}
		}

		$validated = array();
		foreach ( $value as $key => $item ) {
			if ( ! isset( $properties[ $key ] ) ) {
				throw new InvalidArgumentsException( sprintf( 'Tool “%s”: “%s” has unknown field “%s”.', esc_html( $toolName ), esc_html( $path ), esc_html( (string) $key ) ) );
			}

			$validated[ $key ] = $this->coerce( $toolName, sprintf( '%s.%s', $path, $key ), (array) $properties[ $key ], $item );
		}

		return $validated;
	}
}

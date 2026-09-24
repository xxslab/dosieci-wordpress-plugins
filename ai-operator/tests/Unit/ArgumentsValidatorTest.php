<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\Tools\ArgumentsValidator;
use DoSieci\AiOperator\Domain\Tools\InvalidArgumentsException;
use PHPUnit\Framework\TestCase;

final class ArgumentsValidatorTest extends TestCase {

	private ArgumentsValidator $validator;

	private const SCHEMA = array(
		'type'       => 'object',
		'properties' => array(
			'query'  => array( 'type' => 'string' ),
			'limit'  => array( 'type' => 'integer' ),
			'status' => array( 'type' => 'string', 'enum' => array( 'publish', 'draft' ) ),
			'exact'  => array( 'type' => 'boolean' ),
		),
		'required'   => array( 'query' ),
	);

	protected function setUp(): void {
		$this->validator = new ArgumentsValidator();
	}

	public function test_valid_arguments_pass(): void {
		$validated = $this->validator->validate( 't', self::SCHEMA, array( 'query' => 'buty', 'limit' => 5 ) );

		$this->assertSame( array( 'query' => 'buty', 'limit' => 5 ), $validated );
	}

	public function test_a_missing_required_argument_is_rejected(): void {
		$this->expectException( InvalidArgumentsException::class );
		$this->validator->validate( 't', self::SCHEMA, array( 'limit' => 5 ) );
	}

	public function test_an_unknown_argument_is_rejected_not_ignored(): void {
		$this->expectException( InvalidArgumentsException::class );
		$this->validator->validate( 't', self::SCHEMA, array( 'query' => 'x', 'inject' => 'DROP TABLE' ) );
	}

	public function test_numeric_strings_are_coerced_to_integers(): void {
		// Models routinely emit "5" where the schema says integer.
		$validated = $this->validator->validate( 't', self::SCHEMA, array( 'query' => 'x', 'limit' => '5' ) );

		$this->assertSame( 5, $validated['limit'] );
	}

	public function test_a_non_numeric_string_is_not_accepted_as_an_integer(): void {
		$this->expectException( InvalidArgumentsException::class );
		$this->validator->validate( 't', self::SCHEMA, array( 'query' => 'x', 'limit' => 'lots' ) );
	}

	public function test_enum_values_are_enforced(): void {
		$this->assertSame(
			'draft',
			$this->validator->validate( 't', self::SCHEMA, array( 'query' => 'x', 'status' => 'draft' ) )['status']
		);

		$this->expectException( InvalidArgumentsException::class );
		$this->validator->validate( 't', self::SCHEMA, array( 'query' => 'x', 'status' => 'trash' ) );
	}

	public function test_booleans_must_actually_be_booleans(): void {
		$this->assertTrue( $this->validator->validate( 't', self::SCHEMA, array( 'query' => 'x', 'exact' => true ) )['exact'] );

		$this->expectException( InvalidArgumentsException::class );
		$this->validator->validate( 't', self::SCHEMA, array( 'query' => 'x', 'exact' => 'yes' ) );
	}

	public function test_an_array_is_not_accepted_where_a_string_is_declared(): void {
		$this->expectException( InvalidArgumentsException::class );
		$this->validator->validate( 't', self::SCHEMA, array( 'query' => array( 'nested' => 'payload' ) ) );
	}

	public function test_a_schema_with_no_properties_rejects_all_arguments(): void {
		$this->expectException( InvalidArgumentsException::class );
		$this->validator->validate( 't', array( 'type' => 'object', 'properties' => array() ), array( 'anything' => 1 ) );
	}

	/**
	 * Regression: this is the exact shape of WriteToolFactory's
	 * `create_menu` `items` argument. A version of this class that only
	 * handled scalar property types rejected EVERY call to create_menu
	 * with "argument must be a string" -- caught only by exercising the
	 * tool against a real WordPress install, not by any mocked test, which
	 * is why this one exists now.
	 */
	private const MENU_SCHEMA = array(
		'type'       => 'object',
		'properties' => array(
			'name'  => array( 'type' => 'string' ),
			'items' => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'title'   => array( 'type' => 'string' ),
						'page_id' => array( 'type' => 'integer' ),
						'url'     => array( 'type' => 'string' ),
					),
				),
			),
		),
		'required'   => array( 'name' ),
	);

	public function test_an_array_of_objects_is_validated_and_coerced_element_by_element(): void {
		$validated = $this->validator->validate(
			't',
			self::MENU_SCHEMA,
			array(
				'name'  => 'Menu główne',
				// A model routinely emits numeric ids as strings -- each
				// element must be independently coerced, the same as a
				// top-level integer argument would be.
				'items' => array(
					array( 'page_id' => '6' ),
					array( 'title' => 'Kontakt', 'url' => 'https://example.test/kontakt' ),
				),
			)
		);

		$this->assertSame( 6, $validated['items'][0]['page_id'] );
		$this->assertSame( 'Kontakt', $validated['items'][1]['title'] );
	}

	public function test_a_scalar_is_not_accepted_where_an_array_is_declared(): void {
		$this->expectException( InvalidArgumentsException::class );
		$this->validator->validate( 't', self::MENU_SCHEMA, array( 'name' => 'x', 'items' => 'not-an-array' ) );
	}

	public function test_an_unknown_field_inside_an_array_item_is_rejected_not_ignored(): void {
		$this->expectException( InvalidArgumentsException::class );
		$this->validator->validate(
			't',
			self::MENU_SCHEMA,
			array( 'name' => 'x', 'items' => array( array( 'page_id' => 1, 'inject' => 'DROP TABLE' ) ) )
		);
	}

	public function test_a_wrongly_typed_field_inside_an_array_item_is_rejected(): void {
		$this->expectException( InvalidArgumentsException::class );
		$this->validator->validate(
			't',
			self::MENU_SCHEMA,
			array( 'name' => 'x', 'items' => array( array( 'page_id' => 'not-a-number' ) ) )
		);
	}

	public function test_an_array_argument_with_no_items_schema_passes_through_unvalidated(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'tags' => array( 'type' => 'array' ) ),
		);

		$validated = $this->validator->validate( 't', $schema, array( 'tags' => array( 'a', 'b' ) ) );

		$this->assertSame( array( 'a', 'b' ), $validated['tags'] );
	}
}

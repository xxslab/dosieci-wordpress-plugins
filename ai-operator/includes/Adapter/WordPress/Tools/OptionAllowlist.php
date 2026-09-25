<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\Tools;

/**
 * The exact set of WordPress options the operator may write, and the rules
 * for each.
 *
 * This class exists because "let the AI set an option" and "let the AI take
 * over the site" are the same sentence without it. An unrestricted
 * update_option() reachable from a model is a privilege-escalation
 * primitive: setting `users_can_register=1` together with
 * `default_role=administrator` hands anyone on the internet an admin
 * account, `siteurl`/`home` can be pointed at an attacker-controlled
 * domain, and `active_plugins` can be rewritten to disable a security
 * plugin. None of those are hypothetical -- they are the standard payloads
 * for exactly this kind of gap.
 *
 * So the allowlist is a closed set of presentational/behavioural settings,
 * every entry is type-checked and range-checked, and the dangerous ones are
 * absent rather than "protected by a check": an option that cannot be named
 * cannot be set by a cleverly-worded prompt.
 */
final class OptionAllowlist {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return array(
			'blogname' => array(
				'label' => __( 'Site title', 'dosieci-ai-operator' ),
				'type'  => 'string',
				'max'   => 200,
			),
			'blogdescription' => array(
				'label' => __( 'Site tagline / description', 'dosieci-ai-operator' ),
				'type'  => 'string',
				'max'   => 300,
			),
			'start_of_week' => array(
				'label' => __( 'Week starts on', 'dosieci-ai-operator' ),
				'type'  => 'int',
				'min'   => 0,
				'max'   => 6,
			),
			'timezone_string' => array(
				'label' => __( 'Timezone', 'dosieci-ai-operator' ),
				'type'  => 'timezone',
			),
			'date_format' => array(
				'label' => __( 'Date format', 'dosieci-ai-operator' ),
				'type'  => 'string',
				'max'   => 40,
			),
			'time_format' => array(
				'label' => __( 'Time format', 'dosieci-ai-operator' ),
				'type'  => 'string',
				'max'   => 40,
			),
			'posts_per_page' => array(
				'label' => __( 'Posts per page', 'dosieci-ai-operator' ),
				'type'  => 'int',
				'min'   => 1,
				'max'   => 100,
			),
			'default_comment_status' => array(
				'label' => __( 'Default comment status', 'dosieci-ai-operator' ),
				'type'  => 'enum',
				'values' => array( 'open', 'closed' ),
			),
			'comment_registration' => array(
				'label' => __( 'Comments require a logged-in user', 'dosieci-ai-operator' ),
				'type'  => 'bool',
			),
			'comment_moderation' => array(
				'label' => __( 'Comment moderation', 'dosieci-ai-operator' ),
				'type'  => 'bool',
			),
			'blog_public' => array(
				'label' => __( 'Search engine visibility', 'dosieci-ai-operator' ),
				'type'  => 'bool',
			),
			'permalink_structure' => array(
				'label' => __( 'Permalink structure', 'dosieci-ai-operator' ),
				'type'  => 'permalink',
			),
			'show_on_front' => array(
				'label' => __( 'What to show on the front page', 'dosieci-ai-operator' ),
				'type'  => 'enum',
				'values' => array( 'posts', 'page' ),
			),
		);
	}

	public static function has( string $option ): bool {
		return array_key_exists( $option, self::all() );
	}

	/**
	 * @return string[]
	 */
	public static function names(): array {
		return array_keys( self::all() );
	}

	/**
	 * Validates and coerces a model-supplied value for an allowlisted
	 * option.
	 *
	 * @return array{ok: bool, value: mixed, error: ?string}
	 */
	public static function sanitize( string $option, mixed $value ): array {
		$rules = self::all()[ $option ] ?? null;

		if ( null === $rules ) {
			return array(
				'ok'    => false,
				'value' => null,
				/* translators: %s: option name */
				'error' => sprintf( esc_html__( 'The option “%s” is not allowed to be written.', 'dosieci-ai-operator' ), esc_html( $option ) ),
			);
		}

		switch ( $rules['type'] ) {
			case 'string':
				$clean = sanitize_text_field( (string) $value );
				if ( strlen( $clean ) > (int) $rules['max'] ) {
					/* translators: %d: maximum length in characters */
					return self::error( sprintf( esc_html__( 'The value is longer than %d characters.', 'dosieci-ai-operator' ), (int) $rules['max'] ) );
				}

				return self::ok( $clean );

			case 'int':
				if ( ! is_numeric( $value ) ) {
					return self::error( esc_html__( 'A number was expected.', 'dosieci-ai-operator' ) );
				}
				$int = (int) $value;
				if ( $int < (int) $rules['min'] || $int > (int) $rules['max'] ) {
					/* translators: 1: minimum value, 2: maximum value */
					return self::error( sprintf( esc_html__( 'The value must be between %1$d and %2$d.', 'dosieci-ai-operator' ), (int) $rules['min'], (int) $rules['max'] ) );
				}

				return self::ok( $int );

			case 'bool':
				// WordPress stores these as '1'/'0' strings, and a real
				// boolean here would round-trip inconsistently.
				return self::ok( self::truthy( $value ) ? '1' : '0' );

			case 'enum':
				$clean = (string) $value;
				if ( ! in_array( $clean, (array) $rules['values'], true ) ) {
					/* translators: %s: comma-separated list of allowed values */
					return self::error( sprintf( esc_html__( 'Allowed values: %s.', 'dosieci-ai-operator' ), esc_html( implode( ', ', (array) $rules['values'] ) ) ) );
				}

				return self::ok( $clean );

			case 'timezone':
				$clean = (string) $value;
				if ( ! in_array( $clean, timezone_identifiers_list(), true ) ) {
					return self::error( esc_html__( 'Unknown timezone.', 'dosieci-ai-operator' ) );
				}

				return self::ok( $clean );

			case 'permalink':
				$clean = (string) $value;

				// An empty structure means "plain" links, which is valid.
				if ( '' === $clean ) {
					return self::ok( '' );
				}

				// Anything other than a leading-slash path of %tags% and
				// literal segments is refused: permalink_structure is
				// interpolated into rewrite rules, so an unconstrained
				// value is not merely a cosmetic setting.
				if ( 1 !== preg_match( '#^/[A-Za-z0-9%/_\-\.]*$#', $clean ) ) {
					return self::error( esc_html__( 'Invalid permalink structure.', 'dosieci-ai-operator' ) );
				}

				return self::ok( $clean );
		}

		return self::error( esc_html__( 'Unsupported option type.', 'dosieci-ai-operator' ) );
	}

	private static function truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * @return array{ok: bool, value: mixed, error: ?string}
	 */
	private static function ok( mixed $value ): array {
		return array(
			'ok'    => true,
			'value' => $value,
			'error' => null,
		);
	}

	/**
	 * @return array{ok: bool, value: mixed, error: ?string}
	 */
	private static function error( string $message ): array {
		return array(
			'ok'    => false,
			'value' => null,
			'error' => $message,
		);
	}
}

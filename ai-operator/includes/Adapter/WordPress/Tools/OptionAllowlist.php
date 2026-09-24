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
				'label' => 'Nazwa witryny',
				'type'  => 'string',
				'max'   => 200,
			),
			'blogdescription' => array(
				'label' => 'Opis / tagline witryny',
				'type'  => 'string',
				'max'   => 300,
			),
			'start_of_week' => array(
				'label' => 'Pierwszy dzień tygodnia',
				'type'  => 'int',
				'min'   => 0,
				'max'   => 6,
			),
			'timezone_string' => array(
				'label' => 'Strefa czasowa',
				'type'  => 'timezone',
			),
			'date_format' => array(
				'label' => 'Format daty',
				'type'  => 'string',
				'max'   => 40,
			),
			'time_format' => array(
				'label' => 'Format godziny',
				'type'  => 'string',
				'max'   => 40,
			),
			'posts_per_page' => array(
				'label' => 'Wpisów na stronę',
				'type'  => 'int',
				'min'   => 1,
				'max'   => 100,
			),
			'default_comment_status' => array(
				'label' => 'Domyślny status komentarzy',
				'type'  => 'enum',
				'values' => array( 'open', 'closed' ),
			),
			'comment_registration' => array(
				'label' => 'Komentarze tylko dla zalogowanych',
				'type'  => 'bool',
			),
			'comment_moderation' => array(
				'label' => 'Moderacja komentarzy',
				'type'  => 'bool',
			),
			'blog_public' => array(
				'label' => 'Widoczność dla wyszukiwarek',
				'type'  => 'bool',
			),
			'permalink_structure' => array(
				'label' => 'Struktura bezpośrednich odnośników',
				'type'  => 'permalink',
			),
			'show_on_front' => array(
				'label' => 'Co pokazywać na stronie głównej',
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
				'error' => sprintf( 'Opcja "%s" nie jest dozwolona do zapisu.', $option ),
			);
		}

		switch ( $rules['type'] ) {
			case 'string':
				$clean = sanitize_text_field( (string) $value );
				if ( strlen( $clean ) > (int) $rules['max'] ) {
					return self::error( sprintf( 'Wartość jest dłuższa niż %d znaków.', (int) $rules['max'] ) );
				}

				return self::ok( $clean );

			case 'int':
				if ( ! is_numeric( $value ) ) {
					return self::error( 'Oczekiwano liczby.' );
				}
				$int = (int) $value;
				if ( $int < (int) $rules['min'] || $int > (int) $rules['max'] ) {
					return self::error( sprintf( 'Wartość musi mieścić się w zakresie %d-%d.', (int) $rules['min'], (int) $rules['max'] ) );
				}

				return self::ok( $int );

			case 'bool':
				// WordPress stores these as '1'/'0' strings, and a real
				// boolean here would round-trip inconsistently.
				return self::ok( self::truthy( $value ) ? '1' : '0' );

			case 'enum':
				$clean = (string) $value;
				if ( ! in_array( $clean, (array) $rules['values'], true ) ) {
					return self::error( sprintf( 'Dozwolone wartości: %s.', implode( ', ', (array) $rules['values'] ) ) );
				}

				return self::ok( $clean );

			case 'timezone':
				$clean = (string) $value;
				if ( ! in_array( $clean, timezone_identifiers_list(), true ) ) {
					return self::error( 'Nieznana strefa czasowa.' );
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
					return self::error( 'Nieprawidłowa struktura odnośników.' );
				}

				return self::ok( $clean );
		}

		return self::error( 'Nieobsługiwany typ opcji.' );
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

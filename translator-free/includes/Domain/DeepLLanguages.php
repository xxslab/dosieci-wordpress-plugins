<?php

declare(strict_types=1);

namespace DoSieci\Translator\Domain;

/**
 * The target languages this build offers, validated as an allowlist.
 *
 * An allowlist rather than free text because the target code is
 * interpolated into an outbound API request: accepting arbitrary input
 * there is how a harmless-looking select box becomes a request-smuggling
 * surface.
 */
final class DeepLLanguages {

	private const TARGETS = array(
		'BG' => 'bułgarski',
		'CS' => 'czeski',
		'DA' => 'duński',
		'DE' => 'niemiecki',
		'EL' => 'grecki',
		'EN-GB' => 'angielski (UK)',
		'EN-US' => 'angielski (US)',
		'ES' => 'hiszpański',
		'ET' => 'estoński',
		'FI' => 'fiński',
		'FR' => 'francuski',
		'HU' => 'węgierski',
		'IT' => 'włoski',
		'JA' => 'japoński',
		'LT' => 'litewski',
		'LV' => 'łotewski',
		'NL' => 'niderlandzki',
		'PL' => 'polski',
		'PT-PT' => 'portugalski',
		'RO' => 'rumuński',
		'RU' => 'rosyjski',
		'SK' => 'słowacki',
		'SL' => 'słoweński',
		'SV' => 'szwedzki',
		'UK' => 'ukraiński',
	);

	/** @return array<string, string> */
	public static function all(): array {
		return self::TARGETS;
	}

	public static function isSupported( string $code ): bool {
		return isset( self::TARGETS[ strtoupper( $code ) ] );
	}

	public static function label( string $code ): string {
		return self::TARGETS[ strtoupper( $code ) ] ?? $code;
	}
}

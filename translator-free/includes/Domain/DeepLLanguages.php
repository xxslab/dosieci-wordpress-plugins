<?php

declare(strict_types=1);

namespace DoSieci\Translator\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The target languages this plugin offers, validated as an allowlist.
 *
 * An allowlist rather than free text because the code is interpolated into
 * an outbound API request. The list holds DeepL's long-established target
 * languages, which every DeepL API plan supports.
 */
final class DeepLLanguages {

	/** @return array<string, string> code => translated language name, sorted by name */
	public static function all(): array {
		$languages = array(
			'AR'      => __( 'Arabic', 'dosieci-translator' ),
			'BG'      => __( 'Bulgarian', 'dosieci-translator' ),
			'CS'      => __( 'Czech', 'dosieci-translator' ),
			'DA'      => __( 'Danish', 'dosieci-translator' ),
			'DE'      => __( 'German', 'dosieci-translator' ),
			'EL'      => __( 'Greek', 'dosieci-translator' ),
			'EN-GB'   => __( 'English (British)', 'dosieci-translator' ),
			'EN-US'   => __( 'English (American)', 'dosieci-translator' ),
			'ES'      => __( 'Spanish', 'dosieci-translator' ),
			'ET'      => __( 'Estonian', 'dosieci-translator' ),
			'FI'      => __( 'Finnish', 'dosieci-translator' ),
			'FR'      => __( 'French', 'dosieci-translator' ),
			'HE'      => __( 'Hebrew', 'dosieci-translator' ),
			'HU'      => __( 'Hungarian', 'dosieci-translator' ),
			'ID'      => __( 'Indonesian', 'dosieci-translator' ),
			'IT'      => __( 'Italian', 'dosieci-translator' ),
			'JA'      => __( 'Japanese', 'dosieci-translator' ),
			'KO'      => __( 'Korean', 'dosieci-translator' ),
			'LT'      => __( 'Lithuanian', 'dosieci-translator' ),
			'LV'      => __( 'Latvian', 'dosieci-translator' ),
			'NB'      => __( 'Norwegian (Bokmål)', 'dosieci-translator' ),
			'NL'      => __( 'Dutch', 'dosieci-translator' ),
			'PL'      => __( 'Polish', 'dosieci-translator' ),
			'PT-BR'   => __( 'Portuguese (Brazilian)', 'dosieci-translator' ),
			'PT-PT'   => __( 'Portuguese (European)', 'dosieci-translator' ),
			'RO'      => __( 'Romanian', 'dosieci-translator' ),
			'RU'      => __( 'Russian', 'dosieci-translator' ),
			'SK'      => __( 'Slovak', 'dosieci-translator' ),
			'SL'      => __( 'Slovenian', 'dosieci-translator' ),
			'SV'      => __( 'Swedish', 'dosieci-translator' ),
			'TH'      => __( 'Thai', 'dosieci-translator' ),
			'TR'      => __( 'Turkish', 'dosieci-translator' ),
			'UK'      => __( 'Ukrainian', 'dosieci-translator' ),
			'VI'      => __( 'Vietnamese', 'dosieci-translator' ),
			'ZH-HANS' => __( 'Chinese (simplified)', 'dosieci-translator' ),
			'ZH-HANT' => __( 'Chinese (traditional)', 'dosieci-translator' ),
		);

		asort( $languages, SORT_NATURAL | SORT_FLAG_CASE );

		return $languages;
	}

	public static function isSupported( string $code ): bool {
		return array_key_exists( strtoupper( $code ), self::all() );
	}

	public static function label( string $code ): string {
		return self::all()[ strtoupper( $code ) ] ?? $code;
	}
}

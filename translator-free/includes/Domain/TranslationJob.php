<?php

declare(strict_types=1);

namespace DoSieci\Translator\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One field of one post, to be translated, with the source text it was
 * created from.
 *
 * One item, one field and one target language at a time, always through a
 * preview: there is deliberately no bulk path and no path that writes a
 * translation nobody has seen. A bad machine translation applied silently
 * across a catalogue is very expensive to undo.
 */
final class TranslationJob {

	public const FIELD_TITLE   = 'title';
	public const FIELD_EXCERPT = 'excerpt';
	public const FIELD_CONTENT = 'content';

	private const COLUMNS = array(
		self::FIELD_TITLE   => 'post_title',
		self::FIELD_EXCERPT => 'post_excerpt',
		self::FIELD_CONTENT => 'post_content',
	);

	public function __construct(
		public readonly int $postId,
		public readonly string $field,
		public readonly string $sourceText,
		public readonly string $targetLanguage
	) {
	}

	public static function isSupportedField( string $field ): bool {
		return isset( self::COLUMNS[ $field ] );
	}

	/**
	 * The wp_posts column that holds a field.
	 */
	public static function column( string $field ): string {
		return self::COLUMNS[ $field ] ?? self::COLUMNS[ self::FIELD_CONTENT ];
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\Translator\Domain;

/**
 * One field of one post, queued for translation, with the source text it
 * was created from.
 *
 * The free tier translates one product/post at a time, manually, into one
 * target language, and ALWAYS through a preview -- there is no bulk path
 * and no path that writes a translation the human has not seen. That is a
 * scope choice, but it is also the safety property: a bad machine
 * translation applied silently across a catalogue is very expensive to
 * undo.
 */
final class TranslationJob {

	public const FIELD_TITLE       = 'title';
	public const FIELD_EXCERPT     = 'excerpt';
	public const FIELD_CONTENT     = 'content';

	public function __construct(
		public readonly int $postId,
		public readonly string $field,
		public readonly string $sourceText,
		public readonly string $targetLanguage
	) {
	}

	public static function isSupportedField( string $field ): bool {
		return in_array( $field, array( self::FIELD_TITLE, self::FIELD_EXCERPT, self::FIELD_CONTENT ), true );
	}
}

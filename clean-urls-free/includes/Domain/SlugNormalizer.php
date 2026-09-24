<?php

declare(strict_types=1);

namespace DoSieci\Clean\Urls\Domain;

/**
 * Turns a title into a clean, ASCII-safe slug.
 *
 * Polish transliteration is explicit rather than relying on iconv's locale
 * behaviour, which differs between servers -- the same product must produce
 * the same slug on the developer's machine and on the client's shared
 * hosting, or a migration generates different redirect targets in staging
 * than in production.
 */
final class SlugNormalizer {

	private const TRANSLITERATION = array(
		'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
		'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
		'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n',
		'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
		'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
		'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a',
		'í' => 'i', 'ú' => 'u', 'ñ' => 'n', 'ç' => 'c',
	);

	public const MAX_LENGTH = 190;

	public function normalize( string $input ): string {
		$slug = strtr( $input, self::TRANSLITERATION );
		$slug = mb_strtolower( $slug, 'UTF-8' );

		// Anything that is not a-z, 0-9 or a separator becomes a hyphen.
		$slug = preg_replace( '/[^a-z0-9]+/u', '-', $slug ) ?? '';
		$slug = trim( $slug, '-' );

		// Collapse runs of hyphens produced by adjacent punctuation.
		$slug = preg_replace( '/-{2,}/', '-', $slug ) ?? '';

		if ( mb_strlen( $slug ) > self::MAX_LENGTH ) {
			$slug = mb_substr( $slug, 0, self::MAX_LENGTH );
			// Never end on a partial word fragment followed by a hyphen.
			$slug = rtrim( $slug, '-' );
		}

		return $slug;
	}

	/**
	 * Slugs that would collide with WordPress's own routing. Using one of
	 * these as a post slug produces a page that is unreachable or that
	 * shadows core behaviour, so the scanner flags them as blockers rather
	 * than warnings.
	 */
	public function isReserved( string $slug ): bool {
		$reserved = array(
			'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'rss', 'rss2',
			'atom', 'rdf', 'page', 'comments', 'trackback', 'embed', 'attachment',
			'author', 'category', 'tag', 'search', 'login', 'admin', 'robots',
		);

		return in_array( $slug, $reserved, true );
	}
}

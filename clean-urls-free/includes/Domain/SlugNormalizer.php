<?php

declare(strict_types=1);

namespace DoSieci\Clean\Urls\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a title into a clean, ASCII-safe slug.
 *
 * Polish and German transliteration is explicit rather than left to iconv,
 * whose output depends on the server's locale: the same site must produce the
 * same slug on staging and in production, or a migration generates different
 * redirect targets in each. Any other accented Latin characters go through
 * the optional transliterator (WordPress's remove_accents() at runtime).
 */
final class SlugNormalizer {

	private const TRANSLITERATION = array(
		'ą' => 'a',
		'ć' => 'c',
		'ę' => 'e',
		'ł' => 'l',
		'ń' => 'n',
		'ó' => 'o',
		'ś' => 's',
		'ź' => 'z',
		'ż' => 'z',
		'Ą' => 'a',
		'Ć' => 'c',
		'Ę' => 'e',
		'Ł' => 'l',
		'Ń' => 'n',
		'Ó' => 'o',
		'Ś' => 's',
		'Ź' => 'z',
		'Ż' => 'z',
		'ä' => 'ae',
		'ö' => 'oe',
		'ü' => 'ue',
		'Ä' => 'ae',
		'Ö' => 'oe',
		'Ü' => 'ue',
		'ß' => 'ss',
	);

	public const MAX_LENGTH = 190;

	/** @var callable(string): string */
	private $transliterator;

	/**
	 * @param (callable(string): string)|null $transliterator
	 */
	public function __construct( ?callable $transliterator = null ) {
		$this->transliterator = $transliterator ?? static fn( string $text ): string => $text;
	}

	public function normalize( string $input ): string {
		$slug = strtr( $input, self::TRANSLITERATION );
		$slug = (string) ( $this->transliterator )( $slug );
		$slug = mb_strtolower( $slug, 'UTF-8' );

		// Anything that is not a-z or 0-9 becomes a hyphen.
		$slug = preg_replace( '/[^a-z0-9]+/u', '-', $slug ) ?? '';
		$slug = trim( $slug, '-' );

		if ( mb_strlen( $slug ) > self::MAX_LENGTH ) {
			$slug = rtrim( mb_substr( $slug, 0, self::MAX_LENGTH ), '-' );
		}

		return $slug;
	}

	/**
	 * Slugs that would collide with WordPress's own routing. Using one of
	 * these as a post slug produces a page that is unreachable or that
	 * shadows core behaviour, so the scanner flags them as blockers.
	 */
	public function isReserved( string $slug ): bool {
		$reserved = array(
			'wp-admin',
			'wp-content',
			'wp-includes',
			'wp-json',
			'feed',
			'rss',
			'rss2',
			'atom',
			'rdf',
			'page',
			'comments',
			'trackback',
			'embed',
			'attachment',
			'author',
			'category',
			'tag',
			'search',
			'login',
			'admin',
			'robots',
		);

		return in_array( $slug, $reserved, true );
	}
}

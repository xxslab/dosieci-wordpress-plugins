<?php

declare(strict_types=1);

namespace DoSieci\SEO\Doctor\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only SEO audit of a single piece of content.
 *
 * Pure analysis on plain values: no SEO-plugin API and no network. That
 * makes the audit testable, and makes coexistence with Yoast, Rank Math,
 * AIOSEO and SEOPress structurally true: this class has no way to write a
 * title tag, a canonical or a sitemap entry.
 *
 * Thresholds follow widely published search-result truncation guidance; they
 * are advisory, and every finding explains itself.
 */
final class SeoAuditor {

	public const TITLE_MIN          = 30;
	public const TITLE_MAX          = 60;
	public const DESCRIPTION_MIN    = 70;
	public const DESCRIPTION_MAX    = 160;
	public const THIN_CONTENT_WORDS = 300;
	public const SLUG_MAX           = 75;

	/**
	 * @param array<int, array{src:string, alt:string}> $images
	 *
	 * @return SeoFinding[]
	 */
	public function audit( string $title, string $metaDescription, string $contentText, array $images, string $slug ): array {
		return array(
			$this->auditTitle( $title ),
			$this->auditDescription( $metaDescription ),
			$this->auditContentLength( $contentText ),
			$this->auditImageAlts( $images ),
			$this->auditSlug( $slug ),
		);
	}

	private function auditTitle( string $title ): SeoFinding {
		$length = mb_strlen( trim( $title ) );

		if ( 0 === $length ) {
			return new SeoFinding(
				'title',
				SeoFinding::SEVERITY_CRITICAL,
				__( 'There is no title.', 'dosieci-seo-doctor' ),
				__( 'Add a title. Without one, search engines make up their own.', 'dosieci-seo-doctor' )
			);
		}

		if ( $length < self::TITLE_MIN ) {
			return new SeoFinding(
				'title',
				SeoFinding::SEVERITY_WARNING,
				/* translators: %d: number of characters */
				sprintf( _n( 'The title is %d character long, which is short.', 'The title is %d characters long, which is short.', $length, 'dosieci-seo-doctor' ), $length ),
				/* translators: 1: minimum number of characters, 2: maximum number of characters */
				sprintf( __( 'Expand it to %1$d-%2$d characters to use the space in search results.', 'dosieci-seo-doctor' ), self::TITLE_MIN, self::TITLE_MAX )
			);
		}

		if ( $length > self::TITLE_MAX ) {
			return new SeoFinding(
				'title',
				SeoFinding::SEVERITY_WARNING,
				/* translators: %d: number of characters */
				sprintf( __( 'The title is %d characters long and will be cut off in search results.', 'dosieci-seo-doctor' ), $length ),
				/* translators: %d: maximum number of characters */
				sprintf( __( 'Shorten it to %d characters at most.', 'dosieci-seo-doctor' ), self::TITLE_MAX )
			);
		}

		return new SeoFinding(
			'title',
			SeoFinding::SEVERITY_OK,
			/* translators: %d: number of characters */
			sprintf( __( 'The title is %d characters long.', 'dosieci-seo-doctor' ), $length ),
			''
		);
	}

	private function auditDescription( string $description ): SeoFinding {
		$length = mb_strlen( trim( $description ) );

		if ( 0 === $length ) {
			return new SeoFinding(
				'meta_description',
				SeoFinding::SEVERITY_WARNING,
				__( 'There is no meta description.', 'dosieci-seo-doctor' ),
				/* translators: 1: minimum number of characters, 2: maximum number of characters */
				sprintf( __( 'Add a description of %1$d-%2$d characters. Without one, the search engine picks a snippet of the content you have no control over.', 'dosieci-seo-doctor' ), self::DESCRIPTION_MIN, self::DESCRIPTION_MAX )
			);
		}

		if ( $length < self::DESCRIPTION_MIN ) {
			return new SeoFinding(
				'meta_description',
				SeoFinding::SEVERITY_WARNING,
				/* translators: %d: number of characters */
				sprintf( _n( 'The meta description is %d character long, which is short.', 'The meta description is %d characters long, which is short.', $length, 'dosieci-seo-doctor' ), $length ),
				/* translators: 1: minimum number of characters, 2: maximum number of characters */
				sprintf( __( 'Expand it to %1$d-%2$d characters.', 'dosieci-seo-doctor' ), self::DESCRIPTION_MIN, self::DESCRIPTION_MAX )
			);
		}

		if ( $length > self::DESCRIPTION_MAX ) {
			return new SeoFinding(
				'meta_description',
				SeoFinding::SEVERITY_WARNING,
				/* translators: %d: number of characters */
				sprintf( __( 'The meta description is %d characters long and will be cut off.', 'dosieci-seo-doctor' ), $length ),
				/* translators: %d: maximum number of characters */
				sprintf( __( 'Shorten it to %d characters at most.', 'dosieci-seo-doctor' ), self::DESCRIPTION_MAX )
			);
		}

		return new SeoFinding(
			'meta_description',
			SeoFinding::SEVERITY_OK,
			/* translators: %d: number of characters */
			sprintf( __( 'The meta description is %d characters long.', 'dosieci-seo-doctor' ), $length ),
			''
		);
	}

	private function auditContentLength( string $contentText ): SeoFinding {
		$words = self::countWords( $contentText );
		/* translators: %d: number of words */
		$summary = sprintf( _n( 'The content has about %d word.', 'The content has about %d words.', $words, 'dosieci-seo-doctor' ), $words );

		return $words < self::THIN_CONTENT_WORDS
			? new SeoFinding(
				'content_length',
				SeoFinding::SEVERITY_WARNING,
				$summary,
				/* translators: %d: number of words */
				sprintf( __( 'Below about %d words, content is often seen as thin. Add real information, not filler.', 'dosieci-seo-doctor' ), self::THIN_CONTENT_WORDS )
			)
			: new SeoFinding( 'content_length', SeoFinding::SEVERITY_OK, $summary, '' );
	}

	/**
	 * @param array<int, array{src:string, alt:string}> $images
	 */
	private function auditImageAlts( array $images ): SeoFinding {
		if ( array() === $images ) {
			return new SeoFinding( 'image_alt', SeoFinding::SEVERITY_OK, __( 'There are no images in the content.', 'dosieci-seo-doctor' ), '' );
		}

		$missing = array_values(
			array_filter( $images, static fn( array $image ): bool => '' === trim( $image['alt'] ) )
		);

		return array() !== $missing
			? new SeoFinding(
				'image_alt',
				SeoFinding::SEVERITY_WARNING,
				sprintf(
					/* translators: 1: number of images without alt text, 2: total number of images */
					_n( '%1$d of %2$d image has no alternative text.', '%1$d of %2$d images have no alternative text.', count( $images ), 'dosieci-seo-doctor' ),
					count( $missing ),
					count( $images )
				),
				__( 'Fill in the alt attribute: it helps both accessibility and image search.', 'dosieci-seo-doctor' ),
				array( 'missing' => array_column( $missing, 'src' ) )
			)
			: new SeoFinding(
				'image_alt',
				SeoFinding::SEVERITY_OK,
				/* translators: %d: number of images */
				sprintf( _n( '%d image has alternative text.', 'All %d images have alternative text.', count( $images ), 'dosieci-seo-doctor' ), count( $images ) ),
				''
			);
	}

	private function auditSlug( string $slug ): SeoFinding {
		$readable = rawurldecode( $slug );

		if ( '' === $slug ) {
			return new SeoFinding(
				'slug',
				SeoFinding::SEVERITY_WARNING,
				__( 'There is no slug yet.', 'dosieci-seo-doctor' ),
				__( 'Set a readable URL before publishing.', 'dosieci-seo-doctor' )
			);
		}

		if ( preg_match( '/^\d+$/', $slug ) ) {
			return new SeoFinding(
				'slug',
				SeoFinding::SEVERITY_WARNING,
				/* translators: %s: the slug */
				sprintf( __( 'The slug "%s" is just a number.', 'dosieci-seo-doctor' ), $readable ),
				__( 'Use a slug that describes the content: a numeric address tells neither people nor search engines anything.', 'dosieci-seo-doctor' )
			);
		}

		if ( mb_strlen( $readable ) > self::SLUG_MAX ) {
			return new SeoFinding(
				'slug',
				SeoFinding::SEVERITY_WARNING,
				/* translators: %d: number of characters */
				sprintf( __( 'The slug is %d characters long.', 'dosieci-seo-doctor' ), mb_strlen( $readable ) ),
				__( 'Shorten the address: long slugs are harder to share and more often cut off.', 'dosieci-seo-doctor' )
			);
		}

		return new SeoFinding(
			'slug',
			SeoFinding::SEVERITY_OK,
			/* translators: %s: the slug */
			sprintf( __( 'The slug "%s" looks fine.', 'dosieci-seo-doctor' ), $readable ),
			''
		);
	}

	public static function countWords( string $text ): int {
		$normalised = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );

		return '' === $normalised ? 0 : count( explode( ' ', $normalised ) );
	}
}

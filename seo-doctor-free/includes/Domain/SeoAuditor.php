<?php

declare(strict_types=1);

namespace DoSieci\SEO\Doctor\Domain;

/**
 * Read-only SEO audit of a single piece of content.
 *
 * Pure analysis on plain values -- no WordPress, no SEO-plugin API, no
 * network. That matters for two reasons: it is testable, and it makes the
 * "coexistence" rule from PRODUCT_SCOPE.md structurally true rather than a
 * promise -- this class has no way to write a title tag, a canonical or a
 * sitemap entry, so it cannot fight with Yoast/Rank Math/AIOSEO/SEOPress
 * over them even by accident.
 *
 * Thresholds follow widely-published SERP truncation guidance; they are
 * advisory, and every finding says why rather than just failing.
 */
final class SeoAuditor {

	public const TITLE_MIN = 30;
	public const TITLE_MAX = 60;
	public const DESCRIPTION_MIN = 70;
	public const DESCRIPTION_MAX = 160;
	public const THIN_CONTENT_WORDS = 300;

	/**
	 * @param array<int, array{src:string, alt:string}> $images
	 *
	 * @return SeoFinding[]
	 */
	public function audit( string $title, string $metaDescription, string $contentText, array $images, string $slug ): array {
		return array_values(
			array_filter(
				array(
					$this->auditTitle( $title ),
					$this->auditDescription( $metaDescription ),
					$this->auditContentLength( $contentText ),
					$this->auditImageAlts( $images ),
					$this->auditSlug( $slug ),
				)
			)
		);
	}

	private function auditTitle( string $title ): SeoFinding {
		$length = mb_strlen( trim( $title ) );

		if ( 0 === $length ) {
			return new SeoFinding( 'title', SeoFinding::SEVERITY_CRITICAL, 'Brak tytułu.', 'Dodaj tytuł — bez niego wyszukiwarka wymyśli własny.' );
		}

		if ( $length < self::TITLE_MIN ) {
			return new SeoFinding(
				'title',
				SeoFinding::SEVERITY_WARNING,
				sprintf( 'Tytuł ma %d znaków — jest krótki.', $length ),
				sprintf( 'Rozwiń do %d–%d znaków, żeby wykorzystać miejsce w wynikach wyszukiwania.', self::TITLE_MIN, self::TITLE_MAX )
			);
		}

		if ( $length > self::TITLE_MAX ) {
			return new SeoFinding(
				'title',
				SeoFinding::SEVERITY_WARNING,
				sprintf( 'Tytuł ma %d znaków — zostanie ucięty w wynikach.', $length ),
				sprintf( 'Skróć do maksymalnie %d znaków.', self::TITLE_MAX )
			);
		}

		return new SeoFinding( 'title', SeoFinding::SEVERITY_OK, sprintf( 'Tytuł ma %d znaków.', $length ), '' );
	}

	private function auditDescription( string $description ): SeoFinding {
		$length = mb_strlen( trim( $description ) );

		if ( 0 === $length ) {
			return new SeoFinding(
				'meta_description',
				SeoFinding::SEVERITY_WARNING,
				'Brak meta opisu.',
				'Dodaj opis 70–160 znaków. Bez niego wyszukiwarka wytnie fragment treści, na który nie masz wpływu.'
			);
		}

		if ( $length < self::DESCRIPTION_MIN ) {
			return new SeoFinding(
				'meta_description',
				SeoFinding::SEVERITY_WARNING,
				sprintf( 'Meta opis ma %d znaków — jest krótki.', $length ),
				sprintf( 'Rozwiń do %d–%d znaków.', self::DESCRIPTION_MIN, self::DESCRIPTION_MAX )
			);
		}

		if ( $length > self::DESCRIPTION_MAX ) {
			return new SeoFinding(
				'meta_description',
				SeoFinding::SEVERITY_WARNING,
				sprintf( 'Meta opis ma %d znaków — zostanie ucięty.', $length ),
				sprintf( 'Skróć do maksymalnie %d znaków.', self::DESCRIPTION_MAX )
			);
		}

		return new SeoFinding( 'meta_description', SeoFinding::SEVERITY_OK, sprintf( 'Meta opis ma %d znaków.', $length ), '' );
	}

	private function auditContentLength( string $contentText ): SeoFinding {
		$words = self::countWords( $contentText );

		return $words < self::THIN_CONTENT_WORDS
			? new SeoFinding(
				'content_length',
				SeoFinding::SEVERITY_WARNING,
				sprintf( 'Treść ma około %d słów.', $words ),
				sprintf( 'Poniżej ~%d słów treść bywa oceniana jako uboga. Rozwiń opis o realne informacje, nie o wypełniacz.', self::THIN_CONTENT_WORDS )
			)
			: new SeoFinding( 'content_length', SeoFinding::SEVERITY_OK, sprintf( 'Treść ma około %d słów.', $words ), '' );
	}

	/**
	 * @param array<int, array{src:string, alt:string}> $images
	 */
	private function auditImageAlts( array $images ): SeoFinding {
		if ( array() === $images ) {
			return new SeoFinding( 'image_alt', SeoFinding::SEVERITY_OK, 'Brak obrazków w treści.', '' );
		}

		$missing = array_values(
			array_filter( $images, static fn( array $image ): bool => '' === trim( $image['alt'] ) )
		);

		return array() !== $missing
			? new SeoFinding(
				'image_alt',
				SeoFinding::SEVERITY_WARNING,
				sprintf( '%d z %d obrazków nie ma tekstu alternatywnego.', count( $missing ), count( $images ) ),
				'Uzupełnij atrybut alt — to zarówno dostępność, jak i sygnał dla wyszukiwarki grafiki.',
				array( 'missing' => array_column( $missing, 'src' ) )
			)
			: new SeoFinding( 'image_alt', SeoFinding::SEVERITY_OK, sprintf( 'Wszystkie %d obrazków ma alt.', count( $images ) ), '' );
	}

	private function auditSlug( string $slug ): SeoFinding {
		if ( '' === $slug ) {
			return new SeoFinding( 'slug', SeoFinding::SEVERITY_WARNING, 'Brak sluga.', 'Ustaw czytelny adres URL.' );
		}

		if ( preg_match( '/^\d+$/', $slug ) ) {
			return new SeoFinding(
				'slug',
				SeoFinding::SEVERITY_WARNING,
				sprintf( 'Slug „%s” to sam numer.', $slug ),
				'Użyj sluga opisującego treść — numeryczny adres nic nie mówi ani użytkownikowi, ani wyszukiwarce.'
			);
		}

		if ( mb_strlen( $slug ) > 75 ) {
			return new SeoFinding(
				'slug',
				SeoFinding::SEVERITY_WARNING,
				sprintf( 'Slug ma %d znaków.', mb_strlen( $slug ) ),
				'Skróć adres — długie slugi gorzej się udostępnia i częściej są ucinane.'
			);
		}

		return new SeoFinding( 'slug', SeoFinding::SEVERITY_OK, sprintf( 'Slug „%s” wygląda poprawnie.', $slug ), '' );
	}

	public static function countWords( string $text ): int {
		$normalised = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );

		return '' === $normalised ? 0 : count( explode( ' ', $normalised ) );
	}
}

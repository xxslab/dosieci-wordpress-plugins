<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Media;

/**
 * Decides whether any image in the library is genuinely about a given page.
 *
 * ## Why this is deliberately hard to satisfy
 *
 * The tempting implementation is "the library has images, so put one on
 * each page". That produces a site where the Kontakt page is illustrated
 * with a scanned invoice, and the user has to undo work they never asked
 * for. A wrong image is worse than no image, because no image reads as
 * "unfinished" while a wrong image reads as "broken".
 *
 * So a match requires a real shared token between the page's own words and
 * the image's title, filename or alt text. Generic tokens carry no
 * evidence and are dropped, and very short tokens are dropped because
 * "o", "i" and "3" match everything.
 *
 * The decision is made at PLAN time and named in the step description
 * ("Ustaw obrazek wyroniajacy strony Oferta: warsztat.jpg"), so a
 * questionable match costs the human one glance, not an undo.
 */
final class MediaMatcher {

	private const MIN_TOKEN_LENGTH = 4;

	/**
	 * Words that appear in half the filenames on a WordPress install, or
	 * in every Polish page title, and therefore prove nothing.
	 *
	 * @var string[]
	 */
	private const STOPWORDS = array(
		'image', 'img', 'photo', 'foto', 'picture', 'pic', 'zdjecie', 'obraz', 'obrazek',
		'screenshot', 'scaled', 'copy', 'kopia', 'final', 'nowy', 'nowa', 'test',
		'jpeg', 'jpg', 'png', 'webp', 'avif', 'gif',
		'strona', 'page', 'nasz', 'nasze', 'nasza', 'firma', 'oraz', 'dla',
	);

	/**
	 * The best image for a page, or null when nothing is clearly about it.
	 *
	 * @param MediaCandidate[] $candidates
	 */
	public function bestFor( string $pageTitle, array $candidates ): ?MediaCandidate {
		$wanted = $this->tokens( $pageTitle );

		if ( array() === $wanted ) {
			return null;
		}

		$best      = null;
		$bestScore = 0;

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate instanceof MediaCandidate || ! $candidate->isUsableImage() ) {
				continue;
			}

			$score = $this->score( $wanted, $candidate );

			// Strictly greater, so the FIRST candidate wins a tie. The
			// library returns newest first, and a deterministic winner
			// matters: the same site must produce the same plan hash.
			if ( $score > $bestScore ) {
				$best      = $candidate;
				$bestScore = $score;
			}
		}

		return $best;
	}

	/** @param string[] $wanted */
	private function score( array $wanted, MediaCandidate $candidate ): int {
		$have = $this->tokens(
			$candidate->title . ' ' . $candidate->altText . ' ' . $this->filenameWords( $candidate->filename )
		);

		return count( array_intersect( $wanted, $have ) );
	}

	/** Turns "warsztat-blacharski_02.jpg" into "warsztat blacharski 02". */
	private function filenameWords( string $filename ): string {
		$name = (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $filename );

		return (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name );
	}

	/** @return string[] */
	private function tokens( string $text ): array {
		$text = mb_strtolower( $text );

		$text = strtr(
			$text,
			array(
				'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
				'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
			)
		);

		$parts = preg_split( '/[^a-z0-9]+/', $text ) ?: array();

		$tokens = array();

		foreach ( $parts as $part ) {
			if ( strlen( $part ) < self::MIN_TOKEN_LENGTH ) {
				continue;
			}

			if ( in_array( $part, self::STOPWORDS, true ) ) {
				continue;
			}

			$tokens[ $part ] = $part;
		}

		return array_values( $tokens );
	}
}

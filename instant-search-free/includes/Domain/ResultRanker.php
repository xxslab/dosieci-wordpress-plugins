<?php

declare(strict_types=1);

namespace DoSieci\Instant\Search\Domain;

/**
 * Orders raw matches so the most obviously-relevant one is first.
 *
 * Pure scoring, no database: an exact title match beats a title that starts
 * with the term, which beats a term appearing later in the title, which
 * beats an SKU-only match. Without this the results come back in whatever
 * order the database happened to produce, which for live suggestions reads
 * as broken even when every result technically matches.
 */
final class ResultRanker {

	private const SCORE_EXACT_TITLE  = 100;
	private const SCORE_TITLE_PREFIX = 60;
	private const SCORE_TITLE_WORD   = 40;
	private const SCORE_TITLE_ANY    = 20;
	private const SCORE_SKU_EXACT    = 80;
	private const SCORE_SKU_PARTIAL  = 30;

	/**
	 * @param SearchResult[] $results
	 *
	 * @return SearchResult[]
	 */
	public function rank( SearchQuery $query, array $results ): array {
		$needle = mb_strtolower( $query->normalised );

		$scored = array_map(
			function ( SearchResult $result ) use ( $needle ): SearchResult {
				return new SearchResult(
					$result->id,
					$result->title,
					$result->url,
					$result->sku,
					$result->price,
					$result->imageUrl,
					$this->score( $needle, $result )
				);
			},
			$results
		);

		usort(
			$scored,
			static function ( SearchResult $a, SearchResult $b ): int {
				// Stable tiebreak on title so identical scores produce a
				// deterministic order rather than a shuffling suggestion list.
				return $b->score <=> $a->score ?: strcasecmp( $a->title, $b->title );
			}
		);

		return $scored;
	}

	private function score( string $needle, SearchResult $result ): int {
		$title = mb_strtolower( $result->title );
		$score = 0;

		if ( $title === $needle ) {
			$score += self::SCORE_EXACT_TITLE;
		} elseif ( str_starts_with( $title, $needle ) ) {
			$score += self::SCORE_TITLE_PREFIX;
		} elseif ( str_contains( ' ' . $title, ' ' . $needle ) ) {
			$score += self::SCORE_TITLE_WORD;
		} elseif ( str_contains( $title, $needle ) ) {
			$score += self::SCORE_TITLE_ANY;
		}

		if ( null !== $result->sku && '' !== $result->sku ) {
			$sku = mb_strtolower( $result->sku );
			if ( $sku === $needle ) {
				$score += self::SCORE_SKU_EXACT;
			} elseif ( str_contains( $sku, $needle ) ) {
				$score += self::SCORE_SKU_PARTIAL;
			}
		}

		return $score;
	}
}

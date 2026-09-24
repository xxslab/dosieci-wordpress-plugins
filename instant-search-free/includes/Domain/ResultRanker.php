<?php

declare(strict_types=1);

namespace DoSieci\Instant\Search\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orders matches so the most obviously relevant one comes first.
 *
 * Pure scoring, no database: an exact title beats a title that starts with
 * the query, which beats the query starting a later word, which beats titles
 * that only contain every word somewhere; an exact SKU is a strong signal on
 * its own. Without this, suggestions come back in whatever order the
 * database produced, which reads as broken even when every result matches.
 */
final class ResultRanker {

	private const SCORE_EXACT_TITLE  = 100;
	private const SCORE_TITLE_PREFIX = 60;
	private const SCORE_TITLE_WORD   = 40;
	private const SCORE_PER_TOKEN    = 10;
	private const SCORE_SKU_EXACT    = 80;
	private const SCORE_SKU_PARTIAL  = 30;

	/**
	 * @param SearchResult[] $results
	 *
	 * @return SearchResult[]
	 */
	public function rank( SearchQuery $query, array $results ): array {
		$needle = mb_strtolower( $query->normalised );
		$tokens = array_map( static fn( string $token ): string => mb_strtolower( $token ), $query->tokens );

		$scored = array_map(
			fn( SearchResult $result ): SearchResult => new SearchResult(
				$result->id,
				$result->title,
				$result->url,
				$result->sku,
				$result->price,
				$result->imageUrl,
				$this->score( $needle, $tokens, $result )
			),
			$results
		);

		usort(
			$scored,
			// Stable tiebreak on title so equal scores give a deterministic
			// order rather than a shuffling suggestion list.
			static fn( SearchResult $a, SearchResult $b ): int => $b->score <=> $a->score ?: strcasecmp( $a->title, $b->title )
		);

		return $scored;
	}

	/**
	 * @param string[] $tokens
	 */
	private function score( string $needle, array $tokens, SearchResult $result ): int {
		$title = mb_strtolower( $result->title );
		$words = ' ' . str_replace( '-', ' ', $title );
		$score = 0;

		if ( $title === $needle ) {
			$score += self::SCORE_EXACT_TITLE;
		} elseif ( str_starts_with( $title, $needle ) ) {
			$score += self::SCORE_TITLE_PREFIX;
		} elseif ( str_contains( $words, ' ' . $needle ) ) {
			$score += self::SCORE_TITLE_WORD;
		}

		foreach ( $tokens as $token ) {
			if ( str_contains( $words, ' ' . $token ) ) {
				$score += self::SCORE_PER_TOKEN;
			}
		}

		if ( null !== $result->sku && '' !== $result->sku ) {
			$sku = mb_strtolower( $result->sku );

			if ( $sku === $needle ) {
				$score += self::SCORE_SKU_EXACT;
			} elseif ( mb_strlen( $needle ) >= 3 && str_contains( $sku, $needle ) ) {
				// Two letters inside a SKU is noise, not a signal.
				$score += self::SCORE_SKU_PARTIAL;
			}
		}

		return $score;
	}
}

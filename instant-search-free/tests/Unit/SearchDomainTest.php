<?php

declare(strict_types=1);

namespace DoSieci\Instant\Search\Tests\Unit;

use DoSieci\Instant\Search\Domain\ResultRanker;
use DoSieci\Instant\Search\Domain\SearchQuery;
use DoSieci\Instant\Search\Domain\SearchResult;
use PHPUnit\Framework\TestCase;

final class SearchDomainTest extends TestCase {

	public function test_a_short_term_is_not_searchable(): void {
		$this->assertFalse( SearchQuery::fromString( 'a' )->isSearchable() );
		$this->assertTrue( SearchQuery::fromString( 'ab' )->isSearchable() );
	}

	public function test_like_wildcards_are_stripped_so_a_user_cannot_force_a_full_scan(): void {
		$query = SearchQuery::fromString( '%%%' );

		$this->assertSame( '', $query->normalised );
		$this->assertFalse( $query->isSearchable() );
	}

	public function test_underscore_wildcards_are_stripped_too(): void {
		$this->assertSame( 'a b', SearchQuery::fromString( 'a_b' )->normalised );
	}

	public function test_the_term_is_length_bounded(): void {
		$query = SearchQuery::fromString( str_repeat( 'x', 5000 ) );

		$this->assertSame( SearchQuery::MAX_LENGTH, mb_strlen( $query->normalised ) );
	}

	public function test_whitespace_is_collapsed_and_trimmed(): void {
		$this->assertSame( 'czarne buty', SearchQuery::fromString( "  czarne \n\t  buty  " )->normalised );
	}

	public function test_the_default_pattern_is_a_prefix_not_a_leading_wildcard(): void {
		// A leading % makes the title index unusable -- this is the single
		// performance decision the whole product rests on.
		$pattern = SearchQuery::fromString( 'buty' )->prefixPattern();

		$this->assertSame( 'buty%', $pattern );
		$this->assertStringStartsNotWith( '%', $pattern );
	}

	public function test_an_exact_title_match_outranks_a_prefix_match(): void {
		$query = SearchQuery::fromString( 'buty' );

		$ranked = ( new ResultRanker() )->rank(
			$query,
			array(
				new SearchResult( 1, 'Buty zimowe', 'https://x/1' ),
				new SearchResult( 2, 'Buty', 'https://x/2' ),
			)
		);

		$this->assertSame( 2, $ranked[0]->id );
	}

	public function test_an_exact_sku_match_beats_a_weak_title_match(): void {
		$query = SearchQuery::fromString( 'abc123' );

		$ranked = ( new ResultRanker() )->rank(
			$query,
			array(
				new SearchResult( 1, 'Coś tam abc123 w środku', 'https://x/1' ),
				new SearchResult( 2, 'Zupełnie inny produkt', 'https://x/2', 'abc123' ),
			)
		);

		$this->assertSame( 2, $ranked[0]->id );
	}

	public function test_ranking_is_deterministic_for_equal_scores(): void {
		$query = SearchQuery::fromString( 'zzz' );

		$results = array(
			new SearchResult( 1, 'Beta', 'https://x/1' ),
			new SearchResult( 2, 'Alfa', 'https://x/2' ),
		);

		$first  = ( new ResultRanker() )->rank( $query, $results );
		$second = ( new ResultRanker() )->rank( $query, array_reverse( $results ) );

		$this->assertSame( 'Alfa', $first[0]->title );
		$this->assertSame( 'Alfa', $second[0]->title, 'Equal scores must tiebreak deterministically, not by input order.' );
	}

	public function test_result_serialisation_exposes_only_display_fields(): void {
		$array = ( new SearchResult( 5, 'T', 'https://x/5', 'SKU-1', '9,99 zł', null, 42 ) )->toArray();

		$this->assertSame( array( 'id', 'title', 'url', 'sku', 'price', 'image' ), array_keys( $array ) );
		$this->assertArrayNotHasKey( 'score', $array, 'Internal ranking score is not part of the public payload.' );
	}
}

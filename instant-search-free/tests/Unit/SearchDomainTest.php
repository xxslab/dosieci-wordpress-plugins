<?php

declare(strict_types=1);

namespace DoSieci\Instant\Search\Tests\Unit;

use DoSieci\Instant\Search\Adapter\WpdbSearchRepository;
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

	public function test_each_word_must_start_a_word_in_the_title(): void {
		$patterns = SearchQuery::fromString( 'shirt blue' )->wordStartPatterns();

		$this->assertSame(
			array(
				array( 'shirt%', '% shirt%', '%-shirt%' ),
				array( 'blue%', '% blue%', '%-blue%' ),
			),
			$patterns
		);
	}

	public function test_the_like_escape_character_is_stripped(): void {
		$this->assertSame( 'buty a', SearchQuery::fromString( 'buty\\a' )->normalised );
	}

	public function test_the_number_of_words_is_bounded_and_duplicates_are_dropped(): void {
		$query = SearchQuery::fromString( 'a b a c d e f g' );

		$this->assertSame( array( 'a', 'b', 'c', 'd', 'e' ), $query->tokens );
		$this->assertCount( SearchQuery::MAX_TOKENS, $query->wordStartPatterns() );
	}

	public function test_a_multi_word_query_prefers_titles_containing_every_word(): void {
		$ranked = ( new ResultRanker() )->rank(
			SearchQuery::fromString( 'shoes black' ),
			array(
				new SearchResult( 1, 'Black hat', 'https://x/1' ),
				new SearchResult( 2, 'Black running shoes', 'https://x/2' ),
			)
		);

		$this->assertSame( 2, $ranked[0]->id );
	}

	public function test_a_word_after_a_hyphen_counts_as_a_word_start(): void {
		$ranked = ( new ResultRanker() )->rank(
			SearchQuery::fromString( 'shirt' ),
			array(
				new SearchResult( 1, 'Shirtless tank top', 'https://x/1' ),
				new SearchResult( 2, 'Blue T-shirt', 'https://x/2' ),
				new SearchResult( 3, 'Undershirt', 'https://x/3' ),
			)
		);

		$this->assertSame( array( 1, 2, 3 ), array_map( static fn( SearchResult $r ): int => $r->id, $ranked ) );
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

	public function test_a_sale_price_is_reduced_to_the_current_price_in_plain_text(): void {
		$html = '<del aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>100,00&nbsp;<span class="woocommerce-Price-currencySymbol">&#122;&#322;</span></bdi></span></del> '
			. '<span class="screen-reader-text">Original price was: 100,00&nbsp;&#122;&#322;.</span>'
			. '<ins aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>80,00&nbsp;<span class="woocommerce-Price-currencySymbol">&#122;&#322;</span></bdi></span></ins>'
			. '<span class="screen-reader-text">Current price is: 80,00&nbsp;&#122;&#322;.</span>';

		$this->assertSame( "80,00 zł", WpdbSearchRepository::plainPrice( $html ) );
	}

	public function test_a_price_range_keeps_both_ends(): void {
		$html = '<span class="woocommerce-Price-amount amount" aria-hidden="true"><bdi>10,00&nbsp;<span class="woocommerce-Price-currencySymbol">&#122;&#322;</span></bdi></span>'
			. ' <span aria-hidden="true">&ndash;</span> '
			. '<span class="woocommerce-Price-amount amount" aria-hidden="true"><bdi>20,00&nbsp;<span class="woocommerce-Price-currencySymbol">&#122;&#322;</span></bdi></span>'
			. '<span class="screen-reader-text">Price range: 10,00&nbsp;&#122;&#322; through 20,00&nbsp;&#122;&#322;</span>';

		$this->assertSame( "10,00 zł – 20,00 zł", WpdbSearchRepository::plainPrice( $html ) );
	}

	public function test_an_empty_price_is_null(): void {
		$this->assertNull( WpdbSearchRepository::plainPrice( '' ) );
	}
}

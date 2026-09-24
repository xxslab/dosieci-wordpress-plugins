<?php

declare(strict_types=1);

namespace DoSieci\Clean\Urls\Tests\Unit;

use DoSieci\Clean\Urls\Domain\CollisionScanner;
use DoSieci\Clean\Urls\Domain\RedirectMap;
use DoSieci\Clean\Urls\Domain\SlugNormalizer;
use DoSieci\Clean\Urls\Domain\UrlChange;
use PHPUnit\Framework\TestCase;

final class CleanUrlsDomainTest extends TestCase {

	private SlugNormalizer $normalizer;
	private CollisionScanner $scanner;

	protected function setUp(): void {
		$this->normalizer = new SlugNormalizer();
		$this->scanner    = new CollisionScanner( $this->normalizer );
	}

	public function test_polish_characters_are_transliterated_deterministically(): void {
		$this->assertSame( 'zolta-jazn-lodz', $this->normalizer->normalize( 'Żółta jaźń Łódź' ) );
	}

	public function test_punctuation_collapses_into_single_hyphens(): void {
		$this->assertSame( 'buty-meskie-40', $this->normalizer->normalize( 'Buty   męskie!!! (40)' ) );
	}

	public function test_slug_is_length_bounded_without_a_trailing_hyphen(): void {
		$slug = $this->normalizer->normalize( str_repeat( 'ab ', 200 ) );

		$this->assertLessThanOrEqual( SlugNormalizer::MAX_LENGTH, mb_strlen( $slug ) );
		$this->assertStringEndsNotWith( '-', $slug );
	}

	public function test_reserved_wordpress_paths_are_recognised(): void {
		$this->assertTrue( $this->normalizer->isReserved( 'wp-admin' ) );
		$this->assertTrue( $this->normalizer->isReserved( 'feed' ) );
		$this->assertFalse( $this->normalizer->isReserved( 'buty-meskie' ) );
	}

	public function test_a_reserved_target_slug_is_a_blocker(): void {
		$scanned = $this->scanner->scan(
			array( new UrlChange( 1, 'Feed', 'stary-feed', 'feed' ) ),
			array()
		);

		$this->assertSame( UrlChange::SEVERITY_BLOCKER, $scanned[0]->severity );
		$this->assertFalse( $scanned[0]->isApplicable() );
	}

	public function test_two_posts_proposing_the_same_slug_produce_a_blocker_for_the_second(): void {
		$scanned = $this->scanner->scan(
			array(
				new UrlChange( 1, 'Buty', 'stare-1', 'buty' ),
				new UrlChange( 2, 'Buty', 'stare-2', 'buty' ),
			),
			array()
		);

		$this->assertTrue( $scanned[0]->isApplicable() );
		$this->assertSame( UrlChange::SEVERITY_BLOCKER, $scanned[1]->severity );
		$this->assertStringContainsString( '#1', (string) $scanned[1]->issue );
	}

	public function test_colliding_with_an_untouched_existing_post_is_a_blocker(): void {
		$scanned = $this->scanner->scan(
			array( new UrlChange( 5, 'Nowe', 'stary', 'zajety' ) ),
			array( 'zajety' => 99 )
		);

		$this->assertSame( UrlChange::SEVERITY_BLOCKER, $scanned[0]->severity );
		$this->assertStringContainsString( '#99', (string) $scanned[0]->issue );
	}

	public function test_a_post_keeping_its_own_slug_is_not_a_collision_with_itself(): void {
		$scanned = $this->scanner->scan(
			array( new UrlChange( 7, 'Bez zmian', 'ten-sam', 'ten-sam' ) ),
			array( 'ten-sam' => 7 )
		);

		$this->assertSame( UrlChange::SEVERITY_OK, $scanned[0]->severity );
		$this->assertFalse( $scanned[0]->isChange() );
	}

	public function test_summary_counts_each_category(): void {
		$summary = $this->scanner->summarise(
			$this->scanner->scan(
				array(
					new UrlChange( 1, 'a', 'old-a', 'new-a' ),
					new UrlChange( 2, 'b', 'same', 'same' ),
					new UrlChange( 3, 'c', 'old-c', 'wp-admin' ),
				),
				array()
			)
		);

		$this->assertSame( array( 'total' => 3, 'applicable' => 1, 'blockers' => 1, 'unchanged' => 1 ), $summary );
	}

	public function test_redirect_chains_are_flattened_on_insert(): void {
		$map = new RedirectMap();
		$map->add( '/a', '/b' );
		$map->add( '/b', '/c' );

		// /a must now point straight at /c, not at /b.
		$this->assertSame( '/c', $map->target( '/a' ) );
		$this->assertSame( '/c', $map->target( '/b' ) );
	}

	public function test_a_redirect_loop_is_refused(): void {
		$map = new RedirectMap();
		$map->add( '/a', '/b' );
		$map->add( '/b', '/a' );

		$this->assertSame( '/b', $map->target( '/a' ) );
		$this->assertFalse( $map->has( '/b' ), 'The cycle-creating entry must not be stored at all.' );
	}

	public function test_a_self_redirect_is_ignored(): void {
		$map = new RedirectMap();
		$map->add( '/a', '/a' );

		$this->assertSame( 0, $map->count() );
	}

	public function test_paths_are_normalised_so_trailing_slashes_do_not_create_duplicates(): void {
		$map = new RedirectMap();
		$map->add( '/stary/', '/nowy' );

		$this->assertTrue( $map->has( '/stary' ) );
		$this->assertSame( '/nowy', $map->resolve( 'stary/' ) );
	}

	public function test_a_full_url_is_reduced_to_its_path(): void {
		$map = new RedirectMap();
		$map->add( 'https://example.test/stary', 'https://example.test/nowy' );

		$this->assertSame( '/nowy', $map->target( '/stary' ) );
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\SEO\Doctor\Tests\Unit;

use DoSieci\SEO\Doctor\Domain\ByokKeyStore;
use DoSieci\SEO\Doctor\Domain\SeoAuditor;
use DoSieci\SEO\Doctor\Domain\SeoFinding;
use DoSieci\SEO\Doctor\Domain\SuggestionPrompt;
use PHPUnit\Framework\TestCase;

final class SeoDoctorDomainTest extends TestCase {

	private SeoAuditor $auditor;

	protected function setUp(): void {
		$this->auditor = new SeoAuditor();
	}

	private function find( array $findings, string $id ): SeoFinding {
		foreach ( $findings as $finding ) {
			if ( $id === $finding->id ) {
				return $finding;
			}
		}

		$this->fail( "No finding with id {$id}." );
	}

	private function audit( array $o = array() ): array {
		return $this->auditor->audit(
			$o['title'] ?? 'Buty trekkingowe męskie skórzane wodoodporne',
			$o['description'] ?? 'Wygodne buty trekkingowe z membraną wodoodporną, wzmocnionym noskiem i podeszwą Vibram. Sprawdzą się w górach i na co dzień.',
			$o['content'] ?? str_repeat( 'słowo ', 400 ),
			$o['images'] ?? array( array( 'src' => 'a.jpg', 'alt' => 'Buty' ) ),
			$o['slug'] ?? 'buty-trekkingowe-meskie'
		);
	}

	public function test_a_well_optimised_page_reports_no_problems(): void {
		foreach ( $this->audit() as $finding ) {
			$this->assertFalse( $finding->isProblem(), "Unexpected problem: {$finding->id} — {$finding->summary}" );
		}
	}

	public function test_a_missing_title_is_critical(): void {
		$this->assertSame( SeoFinding::SEVERITY_CRITICAL, $this->find( $this->audit( array( 'title' => '' ) ), 'title' )->severity );
	}

	public function test_a_too_long_title_is_flagged(): void {
		$finding = $this->find( $this->audit( array( 'title' => str_repeat( 'a', 90 ) ) ), 'title' );

		$this->assertSame( SeoFinding::SEVERITY_WARNING, $finding->severity );
		$this->assertStringContainsString( '90', $finding->summary );
	}

	public function test_a_missing_meta_description_is_flagged(): void {
		$this->assertTrue( $this->find( $this->audit( array( 'description' => '' ) ), 'meta_description' )->isProblem() );
	}

	public function test_thin_content_is_flagged(): void {
		$finding = $this->find( $this->audit( array( 'content' => 'kilka słów tylko' ) ), 'content_length' );

		$this->assertSame( SeoFinding::SEVERITY_WARNING, $finding->severity );
	}

	public function test_missing_image_alts_are_counted_and_listed(): void {
		$finding = $this->find(
			$this->audit(
				array(
					'images' => array(
						array( 'src' => 'a.jpg', 'alt' => 'ok' ),
						array( 'src' => 'b.jpg', 'alt' => '' ),
						array( 'src' => 'c.jpg', 'alt' => '   ' ),
					),
				)
			),
			'image_alt'
		);

		$this->assertSame( SeoFinding::SEVERITY_WARNING, $finding->severity );
		$this->assertSame( array( 'b.jpg', 'c.jpg' ), $finding->details['missing'] );
	}

	public function test_a_numeric_slug_is_flagged(): void {
		$this->assertTrue( $this->find( $this->audit( array( 'slug' => '12345' ) ), 'slug' )->isProblem() );
	}

	public function test_word_counting_handles_irregular_whitespace(): void {
		$this->assertSame( 3, SeoAuditor::countWords( "  jeden \n\t dwa   trzy  " ) );
		$this->assertSame( 0, SeoAuditor::countWords( "   \n  " ) );
	}

	public function test_the_prompt_forbids_inventing_product_attributes(): void {
		$prompt = SuggestionPrompt::build( 'Tytuł', 'Treść produktu' );

		$this->assertStringContainsString( 'Do not invent product features', $prompt );
		$this->assertStringContainsString( 'ONLY with valid JSON', $prompt );
	}

	public function test_the_prompt_answers_in_the_language_of_the_content_not_a_fixed_one(): void {
		$prompt = SuggestionPrompt::build( 'Tytuł', 'Treść produktu' );

		$this->assertStringContainsString( 'same language as the content', $prompt );
		$this->assertStringNotContainsString( 'Polish', $prompt );
	}

	public function test_the_json_schema_lists_every_field(): void {
		$this->assertSame( array( 'title', 'description', 'keyphrase' ), SuggestionPrompt::schema()['required'] );
	}

	public function test_an_encoded_slug_is_shown_readable(): void {
		$finding = $this->find( $this->audit( array( 'slug' => 'buty-m%c4%99skie' ) ), 'slug' );

		$this->assertStringContainsString( 'buty-męskie', $finding->summary );
	}

	public function test_a_single_short_count_uses_the_singular(): void {
		$finding = $this->find( $this->audit( array( 'content' => 'jedno' ) ), 'content_length' );

		$this->assertStringContainsString( 'about 1 word.', $finding->summary );
	}

	public function test_the_prompt_bounds_how_much_content_is_sent(): void {
		$prompt = SuggestionPrompt::build( 'T', str_repeat( 'x', 20000 ) );

		$this->assertLessThan( 20000, mb_strlen( $prompt ) );
	}

	public function test_a_clean_json_response_is_parsed(): void {
		$parsed = SuggestionPrompt::parse( '{"title":"T","description":"D","keyphrase":"K"}' );

		$this->assertSame( array( 'title' => 'T', 'description' => 'D', 'keyphrase' => 'K' ), $parsed );
	}

	public function test_a_fenced_json_response_is_tolerated(): void {
		$parsed = SuggestionPrompt::parse( "Proszę:\n```json\n{\"title\":\"T\",\"description\":\"D\"}\n```" );

		$this->assertSame( 'T', $parsed['title'] );
		$this->assertSame( '', $parsed['keyphrase'], 'A missing optional field becomes an empty string, not a crash.' );
	}

	public function test_an_unparseable_response_raises_a_clear_error(): void {
		$this->expectException( \RuntimeException::class );
		SuggestionPrompt::parse( 'Oczywiście! Oto propozycja tytułu: Buty.' );
	}

	public function test_a_response_missing_a_required_field_is_rejected(): void {
		$this->expectException( \RuntimeException::class );
		SuggestionPrompt::parse( '{"title":"T"}' );
	}

	public function test_a_key_is_masked_for_display(): void {
		$masked = ByokKeyStore::mask( 'sk-proj-ABCDEFGHIJKLMNOPQRSTUV' );

		$this->assertStringStartsWith( 'sk-p', $masked );
		$this->assertStringEndsWith( 'STUV', $masked );
		$this->assertStringNotContainsString( 'GHIJKLMNOP', $masked );
	}

	public function test_a_short_key_is_fully_masked(): void {
		$this->assertSame( '••••••', ByokKeyStore::mask( 'abcdef' ) );
	}

	public function test_obviously_wrong_key_pastes_are_rejected(): void {
		$this->assertFalse( ByokKeyStore::looksPlausible( '' ) );
		$this->assertFalse( ByokKeyStore::looksPlausible( 'too-short' ) );
		$this->assertFalse( ByokKeyStore::looksPlausible( 'curl -H "Authorization: Bearer sk-xxx"' ) );
		$this->assertTrue( ByokKeyStore::looksPlausible( 'sk-proj-ABCDEFGHIJKLMNOPQRSTUV' ) );
	}

	public function test_a_key_echoed_back_in_an_error_message_is_redacted(): void {
		$message = ByokKeyStore::redactFrom( 'Invalid key sk-secret-value provided', 'sk-secret-value' );

		$this->assertStringNotContainsString( 'sk-secret-value', $message );
		$this->assertStringContainsString( '[redacted]', $message );
	}
}

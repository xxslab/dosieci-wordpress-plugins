<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\Gutenberg\BlockComposer;
use PHPUnit\Framework\TestCase;

/**
 * Block markup is generated from model-supplied text, so this class is both
 * a correctness boundary (invalid markup means "Attempt recovery" in the
 * editor) and a security boundary (generated pages are rendered to every
 * visitor).
 */
final class BlockComposerTest extends TestCase {

	private BlockComposer $composer;

	protected function setUp(): void {
		$this->composer = new BlockComposer();
	}

	public function test_a_heading_block_has_matching_comment_and_tag(): void {
		$markup = $this->composer->compose( array( array( 'type' => 'heading', 'text' => 'Oferta', 'level' => 2 ) ) );

		$this->assertStringContainsString( '<!-- wp:heading -->', $markup );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Oferta</h2>', $markup );
		$this->assertStringContainsString( '<!-- /wp:heading -->', $markup );
	}

	public function test_a_non_default_heading_level_declares_it_in_the_attributes(): void {
		// A level attribute that disagrees with the tag is exactly what
		// makes the editor mark a block invalid.
		$markup = $this->composer->compose( array( array( 'type' => 'heading', 'text' => 'Start', 'level' => 1 ) ) );

		$this->assertStringContainsString( '<!-- wp:heading {"level":1} -->', $markup );
		$this->assertStringContainsString( '<h1 class="wp-block-heading">Start</h1>', $markup );
	}

	public function test_every_opened_block_comment_is_closed(): void {
		$markup = $this->composer->compose(
			array(
				array( 'type' => 'hero', 'title' => 'HydroMax', 'subtitle' => 'Hydraulik', 'cta_label' => 'Kontakt', 'cta_url' => '#kontakt' ),
				array( 'type' => 'services', 'title' => 'Usługi', 'items' => array( array( 'title' => 'A', 'text' => 'B' ) ) ),
				array( 'type' => 'separator' ),
				array( 'type' => 'spacer', 'height' => 60 ),
			)
		);

		preg_match_all( '/<!-- wp:([a-z-]+)/', $markup, $opens );
		preg_match_all( '#<!-- /wp:([a-z-]+)#', $markup, $closes );

		$openCounts  = array_count_values( $opens[1] );
		$closeCounts = array_count_values( $closes[1] );
		ksort( $openCounts );
		ksort( $closeCounts );

		// Self-closing blocks (separator, spacer) still emit a closing
		// comment in this composer, so counts must match exactly.
		$this->assertSame( $openCounts, $closeCounts );
	}

	public function test_script_tags_in_model_text_are_escaped_not_rendered(): void {
		$markup = $this->composer->compose(
			array( array( 'type' => 'paragraph', 'text' => '<script>alert(1)</script>' ) )
		);

		$this->assertStringNotContainsString( '<script>', $markup );
		$this->assertStringContainsString( '&lt;script&gt;', $markup );
	}

	public function test_a_javascript_url_in_a_button_is_dropped(): void {
		// A javascript: href in a generated button would be stored XSS
		// authored by a prompt.
		$markup = $this->composer->compose(
			array( array( 'type' => 'cta', 'title' => 'Klik', 'cta_label' => 'Tutaj', 'cta_url' => 'javascript:alert(1)' ) )
		);

		$this->assertStringNotContainsString( 'javascript:', $markup );
		$this->assertStringNotContainsString( 'href=', $markup );
	}

	public function test_a_data_url_in_a_button_is_dropped(): void {
		$markup = $this->composer->compose(
			array( array( 'type' => 'cta', 'title' => 'X', 'cta_label' => 'Y', 'cta_url' => 'data:text/html;base64,PHNjcmlwdD4=' ) )
		);

		$this->assertStringNotContainsString( 'data:', $markup );
	}

	public function test_a_relative_and_an_https_url_are_both_kept(): void {
		$relative = $this->composer->compose(
			array( array( 'type' => 'cta', 'title' => 'X', 'cta_label' => 'Y', 'cta_url' => '/kontakt' ) )
		);
		$absolute = $this->composer->compose(
			array( array( 'type' => 'cta', 'title' => 'X', 'cta_label' => 'Y', 'cta_url' => 'https://example.test/a' ) )
		);

		$this->assertStringContainsString( 'href="/kontakt"', $relative );
		$this->assertStringContainsString( 'href="https://example.test/a"', $absolute );
	}

	public function test_an_unknown_section_type_produces_nothing_rather_than_raw_output(): void {
		// The bounded-output property: a model inventing a section name
		// cannot get arbitrary markup onto the page.
		$markup = $this->composer->compose(
			array( array( 'type' => 'raw_html', 'text' => '<iframe src="https://evil.test"></iframe>' ) )
		);

		$this->assertSame( '', $markup );
	}

	public function test_a_contact_section_only_embeds_a_well_formed_shortcode(): void {
		$good = $this->composer->compose(
			array( array( 'type' => 'contact', 'title' => 'Kontakt', 'form_shortcode' => '[contact-form-7 id="12"]' ) )
		);
		$bad = $this->composer->compose(
			array( array( 'type' => 'contact', 'title' => 'Kontakt', 'form_shortcode' => '<script>x</script>' ) )
		);

		$this->assertStringContainsString( '<!-- wp:shortcode -->', $good );
		$this->assertStringNotContainsString( '<!-- wp:shortcode -->', $bad );
	}

	public function test_a_services_grid_emits_one_column_per_item(): void {
		$markup = $this->composer->compose(
			array(
				array(
					'type'  => 'services',
					'items' => array(
						array( 'title' => 'A', 'text' => '1' ),
						array( 'title' => 'B', 'text' => '2' ),
						array( 'title' => 'C', 'text' => '3' ),
					),
				),
			)
		);

		$this->assertSame( 3, substr_count( $markup, '<!-- wp:column -->' ) );
		$this->assertSame( 3, substr_count( $markup, '<!-- /wp:column -->' ) );
		$this->assertSame( 1, substr_count( $markup, '<!-- wp:columns -->' ) );
	}

	public function test_spacer_height_is_clamped(): void {
		$markup = $this->composer->compose( array( array( 'type' => 'spacer', 'height' => 99999 ) ) );

		$this->assertStringContainsString( '240px', $markup );
	}

	public function test_overlong_text_is_truncated(): void {
		$markup = $this->composer->compose(
			array( array( 'type' => 'paragraph', 'text' => str_repeat( 'a', 5000 ) ) )
		);

		$this->assertLessThan( 2500, strlen( $markup ) );
	}
}

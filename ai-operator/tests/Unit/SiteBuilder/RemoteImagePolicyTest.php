<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\Media\NullStockImageProvider;
use DoSieci\AiOperator\Domain\SiteBuilder\Media\RemoteImagePolicy;
use DoSieci\AiOperator\Domain\SiteBuilder\Media\StockImage;
use PHPUnit\Framework\TestCase;

/**
 * "Fetch a picture for my site" is the friendliest possible phrasing of
 * "make an outbound request to an address I choose, from inside your
 * hosting". These tests pin the gate that stands in front of that.
 */
final class RemoteImagePolicyTest extends TestCase {

	private RemoteImagePolicy $policy;

	protected function setUp(): void {
		$this->policy = new RemoteImagePolicy();
	}

	public function test_an_ordinary_cdn_image_is_allowed(): void {
		$this->assertTrue( $this->policy->allows( 'https://images.example.com/photos/warsztat-1200.jpg' ) );
		$this->assertTrue( $this->policy->allows( 'https://cdn.example.org/a/b/c.webp?w=1200' ) );
	}

	public function test_plaintext_http_is_refused(): void {
		// Anything on the path could swap the image, and a provider that
		// cannot serve TLS is not one worth supporting.
		$this->assertFalse( $this->policy->allows( 'http://images.example.com/a.jpg' ) );
	}

	public function test_non_http_schemes_are_refused(): void {
		$this->assertFalse( $this->policy->allows( 'file:///etc/passwd' ) );
		$this->assertFalse( $this->policy->allows( 'gopher://example.com/a.jpg' ) );
		$this->assertFalse( $this->policy->allows( 'data:image/png;base64,iVBORw0KGgo=' ) );
	}

	public function test_the_cloud_metadata_endpoint_is_refused(): void {
		// The single most valuable target for an SSRF on managed hosting.
		$this->assertFalse( $this->policy->allows( 'https://169.254.169.254/latest/meta-data/iam/a.jpg' ) );
	}

	public function test_loopback_and_private_addresses_are_refused(): void {
		$this->assertFalse( $this->policy->allows( 'https://127.0.0.1/a.jpg' ) );
		$this->assertFalse( $this->policy->allows( 'https://10.0.0.5/a.jpg' ) );
		$this->assertFalse( $this->policy->allows( 'https://192.168.1.1/a.jpg' ) );
		$this->assertFalse( $this->policy->allows( 'https://[::1]/a.jpg' ) );
	}

	public function test_obfuscated_ip_notations_are_refused(): void {
		// 2130706433 and 0x7f.1 both reach 127.0.0.1 through some
		// resolvers; refusing every all-numeric host is simpler than
		// enumerating the notations.
		$this->assertFalse( $this->policy->allows( 'https://2130706433/a.jpg' ) );
		$this->assertFalse( $this->policy->allows( 'https://0x7f000001/a.jpg' ) );
		$this->assertFalse( $this->policy->allows( 'https://0177.0.0.1/a.jpg' ) );
	}

	public function test_internal_names_are_refused(): void {
		$this->assertFalse( $this->policy->allows( 'https://localhost/a.jpg' ) );
		$this->assertFalse( $this->policy->allows( 'https://db.internal/a.jpg' ) );
		$this->assertFalse( $this->policy->allows( 'https://printer.local/a.jpg' ) );
		// A single-label name resolves through the local search domain.
		$this->assertFalse( $this->policy->allows( 'https://intranet-server/a.jpg' ) );
	}

	public function test_a_name_that_merely_contains_a_blocked_word_is_still_allowed(): void {
		// Whole-label matching, so a legitimate host is not caught by
		// accident.
		$this->assertTrue( $this->policy->allows( 'https://localhost.example.com/a.jpg' ) );
		$this->assertTrue( $this->policy->allows( 'https://internal-cdn.example.com/a.jpg' ) );
	}

	public function test_a_trailing_dot_does_not_bypass_the_name_check(): void {
		$this->assertFalse( $this->policy->allows( 'https://localhost./a.jpg' ) );
	}

	public function test_credentials_in_the_url_are_refused(): void {
		// Reads as one host to a human, resolves as another to a parser.
		$this->assertFalse( $this->policy->allows( 'https://images.example.com@169.254.169.254/a.jpg' ) );
		$this->assertFalse( $this->policy->allows( 'https://user:pass@images.example.com/a.jpg' ) );
	}

	public function test_a_non_https_port_is_refused(): void {
		// Port scanning through an image fetcher is a real technique.
		$this->assertFalse( $this->policy->allows( 'https://images.example.com:8080/a.jpg' ) );
		$this->assertFalse( $this->policy->allows( 'https://images.example.com:22/a.jpg' ) );
	}

	public function test_a_url_that_is_not_an_image_is_refused(): void {
		$this->assertFalse( $this->policy->allows( 'https://images.example.com/admin' ) );
		$this->assertFalse( $this->policy->allows( 'https://images.example.com/shell.php' ) );
		// SVG is script-capable and is not on the list.
		$this->assertFalse( $this->policy->allows( 'https://images.example.com/logo.svg' ) );
	}

	public function test_an_absurdly_long_url_is_refused(): void {
		$this->assertFalse( $this->policy->allows( 'https://images.example.com/' . str_repeat( 'a', 3000 ) . '.jpg' ) );
	}

	public function test_the_mime_allowlist_excludes_svg_and_html(): void {
		$this->assertTrue( $this->policy->allowsMimeType( 'image/jpeg' ) );
		$this->assertFalse( $this->policy->allowsMimeType( 'image/svg+xml' ) );
		$this->assertFalse( $this->policy->allowsMimeType( 'text/html' ) );
	}

	// --- the gate is in the constructor, not in a caller -------------------

	public function test_a_stock_image_cannot_be_constructed_for_a_refused_url(): void {
		// This is what makes the policy unskippable: a provider physically
		// cannot hand a bad candidate onward.
		$this->expectException( \InvalidArgumentException::class );

		new StockImage( 'https://169.254.169.254/a.jpg', 'meta', 'x', 'CC0' );
	}

	public function test_a_stock_image_requires_a_licence(): void {
		// Dropping the credit line exposes the site owner to a licence
		// problem they never agreed to.
		$this->expectException( \InvalidArgumentException::class );

		new StockImage( 'https://images.example.com/a.jpg', 'warsztat', 'Jan Nowak', '  ' );
	}

	public function test_a_valid_stock_image_carries_its_attribution(): void {
		$image = new StockImage( 'https://images.example.com/a.jpg', 'warsztat', 'Jan Nowak', 'CC0' );

		$this->assertSame( 'Jan Nowak (CC0)', $image->attribution() );
	}

	public function test_the_shipping_provider_is_never_configured_and_returns_nothing(): void {
		// The builder cannot download anything today, and this is the test
		// that has to change before it can.
		$provider = new NullStockImageProvider();

		$this->assertFalse( $provider->isConfigured() );
		$this->assertSame( array(), $provider->search( 'warsztat' ) );
	}
}

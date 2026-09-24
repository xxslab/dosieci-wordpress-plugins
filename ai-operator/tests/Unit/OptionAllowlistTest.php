<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Adapter\WordPress\Tools\OptionAllowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The boundary between "the operator can change a setting" and "the
 * operator can take over the site".
 *
 * The negative tests here are the important ones. An unrestricted
 * update_option() reachable from a model is a privilege-escalation
 * primitive, and the specific options named below are the standard
 * payloads: users_can_register + default_role mints an administrator
 * account for anyone on the internet, siteurl/home redirect the whole site
 * to an attacker's domain, and active_plugins can disable a security
 * plugin. None of them may be settable, and it must be because they are
 * absent from the allowlist -- not because of a check that a future edit
 * could remove.
 */
final class OptionAllowlistTest extends TestCase {

	/**
	 * @return array<string, array{string}>
	 */
	public static function dangerousOptions(): array {
		return array(
			'user registration'    => array( 'users_can_register' ),
			'default role'         => array( 'default_role' ),
			'site url'             => array( 'siteurl' ),
			'home url'             => array( 'home' ),
			'admin email'          => array( 'admin_email' ),
			'active plugins'       => array( 'active_plugins' ),
			'current theme'        => array( 'template' ),
			'upload path'          => array( 'upload_path' ),
			'cron'                 => array( 'cron' ),
			'mailserver password'  => array( 'mailserver_pass' ),
		);
	}

	#[DataProvider( 'dangerousOptions' )]
	public function test_a_privilege_escalating_option_is_not_writable( string $option ): void {
		$this->assertFalse(
			OptionAllowlist::has( $option ),
			sprintf( 'Option "%s" must never be writable by the operator.', $option )
		);

		$result = OptionAllowlist::sanitize( $option, 'anything' );

		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['value'] );
	}

	public function test_an_allowlisted_string_option_is_accepted_and_trimmed(): void {
		$result = OptionAllowlist::sanitize( 'blogname', '  Mój Sklep  ' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Mój Sklep', $result['value'] );
	}

	public function test_html_is_stripped_from_a_string_option(): void {
		$result = OptionAllowlist::sanitize( 'blogdescription', 'Sklep <script>alert(1)</script>' );

		$this->assertTrue( $result['ok'] );
		$this->assertStringNotContainsString( '<script>', (string) $result['value'] );
	}

	public function test_an_over_long_string_is_refused(): void {
		$result = OptionAllowlist::sanitize( 'blogname', str_repeat( 'a', 500 ) );

		$this->assertFalse( $result['ok'] );
		$this->assertNotNull( $result['error'] );
	}

	public function test_an_integer_option_is_range_checked(): void {
		$this->assertTrue( OptionAllowlist::sanitize( 'posts_per_page', 20 )['ok'] );
		$this->assertFalse( OptionAllowlist::sanitize( 'posts_per_page', 0 )['ok'] );
		$this->assertFalse( OptionAllowlist::sanitize( 'posts_per_page', 5000 )['ok'] );
		$this->assertFalse( OptionAllowlist::sanitize( 'posts_per_page', 'dużo' )['ok'] );
	}

	public function test_a_boolean_option_is_stored_as_a_wordpress_style_flag(): void {
		$this->assertSame( '1', OptionAllowlist::sanitize( 'blog_public', true )['value'] );
		$this->assertSame( '1', OptionAllowlist::sanitize( 'blog_public', 'yes' )['value'] );
		$this->assertSame( '0', OptionAllowlist::sanitize( 'blog_public', false )['value'] );
		$this->assertSame( '0', OptionAllowlist::sanitize( 'blog_public', 'nonsense' )['value'] );
	}

	public function test_an_enum_option_rejects_a_value_outside_its_set(): void {
		$this->assertTrue( OptionAllowlist::sanitize( 'show_on_front', 'page' )['ok'] );
		$this->assertFalse( OptionAllowlist::sanitize( 'show_on_front', 'anything-else' )['ok'] );
	}

	public function test_an_unknown_timezone_is_refused(): void {
		$this->assertTrue( OptionAllowlist::sanitize( 'timezone_string', 'Europe/Warsaw' )['ok'] );
		$this->assertFalse( OptionAllowlist::sanitize( 'timezone_string', 'Mars/Olympus' )['ok'] );
	}

	/**
	 * permalink_structure is interpolated into rewrite rules, so it is not
	 * the merely-cosmetic setting it looks like.
	 */
	public function test_a_permalink_structure_must_look_like_a_path(): void {
		$this->assertTrue( OptionAllowlist::sanitize( 'permalink_structure', '/%postname%/' )['ok'] );
		$this->assertTrue( OptionAllowlist::sanitize( 'permalink_structure', '' )['ok'], 'Empty means plain links.' );

		$this->assertFalse( OptionAllowlist::sanitize( 'permalink_structure', 'no-leading-slash' )['ok'] );
		$this->assertFalse( OptionAllowlist::sanitize( 'permalink_structure', '/<script>' )['ok'] );
		$this->assertFalse( OptionAllowlist::sanitize( 'permalink_structure', '/%postname%/?x=1' )['ok'] );
	}

	public function test_the_advertised_names_match_what_is_actually_writable(): void {
		foreach ( OptionAllowlist::names() as $name ) {
			$this->assertTrue(
				OptionAllowlist::has( $name ),
				sprintf( 'names() advertises "%s" but has() denies it.', $name )
			);
		}
	}
}

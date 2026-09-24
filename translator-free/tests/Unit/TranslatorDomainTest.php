<?php

declare(strict_types=1);

namespace DoSieci\Translator\Tests\Unit;

use DoSieci\Translator\Domain\DeepLLanguages;
use DoSieci\Translator\Domain\DeepLResponseParser;
use DoSieci\Translator\Domain\TranslationException;
use DoSieci\Translator\Domain\TranslationJob;
use PHPUnit\Framework\TestCase;

final class TranslatorDomainTest extends TestCase {

	public function test_a_successful_response_yields_the_translation(): void {
		$body = (string) json_encode(
			array( 'translations' => array( array( 'detected_source_language' => 'PL', 'text' => 'Black shoes' ) ) )
		);

		DeepLResponseParser::assertOk( 200, $body );

		$this->assertSame( 'Black shoes', DeepLResponseParser::extractTranslation( $body ) );
		$this->assertSame( 'PL', DeepLResponseParser::detectedSourceLanguage( $body ) );
	}

	public function test_quota_exhaustion_produces_a_specific_actionable_message(): void {
		try {
			DeepLResponseParser::assertOk( 456, '' );
			$this->fail( 'Expected TranslationException.' );
		} catch ( TranslationException $e ) {
			$this->assertSame( 456, $e->statusCode );
			$this->assertStringContainsString( 'character limit', $e->getMessage() );
		}
	}

	public function test_a_rejected_key_mentions_the_free_versus_pro_endpoint_trap(): void {
		try {
			DeepLResponseParser::assertOk( 403, '' );
			$this->fail( 'Expected TranslationException.' );
		} catch ( TranslationException $e ) {
			$this->assertStringContainsString( ':fx', $e->getMessage() );
		}
	}

	public function test_rate_limit_and_payload_too_large_are_distinguished(): void {
		foreach ( array( 429 => 'Too many requests', 413 => 'too long' ) as $status => $needle ) {
			try {
				DeepLResponseParser::assertOk( $status, '' );
				$this->fail( "Expected TranslationException for HTTP {$status}." );
			} catch ( TranslationException $e ) {
				$this->assertStringContainsString( $needle, $e->getMessage() );
			}
		}
	}

	public function test_a_server_error_is_reported_as_temporary(): void {
		$this->expectException( TranslationException::class );
		$this->expectExceptionMessage( 'temporarily unavailable' );

		DeepLResponseParser::assertOk( 503, '' );
	}

	public function test_a_2xx_response_with_no_translations_key_is_rejected(): void {
		$this->expectException( TranslationException::class );

		DeepLResponseParser::extractTranslation( '{"message":"ok"}' );
	}

	public function test_a_non_json_body_is_rejected(): void {
		$this->expectException( TranslationException::class );

		DeepLResponseParser::extractTranslation( '<html>error</html>' );
	}

	public function test_detected_language_is_null_when_absent_rather_than_throwing(): void {
		$this->assertNull(
			DeepLResponseParser::detectedSourceLanguage( '{"translations":[{"text":"x"}]}' )
		);
	}

	public function test_target_languages_are_an_allowlist(): void {
		$this->assertTrue( DeepLLanguages::isSupported( 'EN-GB' ) );
		$this->assertTrue( DeepLLanguages::isSupported( 'de' ), 'Codes are case-insensitive.' );
		$this->assertFalse( DeepLLanguages::isSupported( 'XX' ) );
		$this->assertFalse( DeepLLanguages::isSupported( "EN\r\nX-Injected: 1" ) );
	}

	public function test_supported_fields_are_restricted(): void {
		$this->assertTrue( TranslationJob::isSupportedField( TranslationJob::FIELD_TITLE ) );
		$this->assertTrue( TranslationJob::isSupportedField( TranslationJob::FIELD_CONTENT ) );
		$this->assertFalse( TranslationJob::isSupportedField( 'post_status' ) );
	}

	public function test_languages_include_the_current_deepl_targets_and_are_sorted_by_name(): void {
		foreach ( array( 'KO', 'NB', 'TR', 'PT-BR', 'ZH-HANS', 'UK', 'PL' ) as $code ) {
			$this->assertTrue( DeepLLanguages::isSupported( $code ), $code );
		}

		$names = array_values( DeepLLanguages::all() );
		$sorted = $names;
		natcasesort( $sorted );

		$this->assertSame( array_values( $sorted ), $names );
	}

	public function test_every_field_maps_to_its_own_posts_column(): void {
		$this->assertSame( 'post_title', TranslationJob::column( TranslationJob::FIELD_TITLE ) );
		$this->assertSame( 'post_excerpt', TranslationJob::column( TranslationJob::FIELD_EXCERPT ) );
		$this->assertSame( 'post_content', TranslationJob::column( TranslationJob::FIELD_CONTENT ) );
	}

	public function test_an_error_message_is_escaped_for_display(): void {
		try {
			DeepLResponseParser::assertOk( 403, '' );
			$this->fail( 'Expected TranslationException.' );
		} catch ( TranslationException $e ) {
			$this->assertStringContainsString( '&quot;:fx&quot;', $e->getMessage() );
			$this->assertSame( 403, $e->statusCode );
		}
	}
}

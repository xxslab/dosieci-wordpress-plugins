<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\Gateway\ProviderSettings;
use PHPUnit\Framework\TestCase;

final class ProviderSettingsTest extends TestCase {

	public function test_the_default_is_the_hub(): void {
		$settings = new ProviderSettings();

		$this->assertSame( ProviderSettings::MODE_HUB, $settings->mode );
		$this->assertFalse( $settings->isByok() );
		$this->assertTrue( $settings->isUsable() );
	}

	/**
	 * A corrupted or hand-edited option must not be able to switch the site
	 * into an unmetered direct-provider mode. Failing back to the Hub is the
	 * safe direction.
	 */
	public function test_an_unknown_stored_mode_falls_back_to_the_hub(): void {
		$settings = ProviderSettings::fromArray( array( 'mode' => 'some-other-provider' ) );

		$this->assertSame( ProviderSettings::MODE_HUB, $settings->mode );
	}

	public function test_a_byok_mode_without_a_key_is_not_usable(): void {
		$settings = ProviderSettings::fromArray( array( 'mode' => ProviderSettings::MODE_ANTHROPIC ) );

		$this->assertTrue( $settings->isByok() );
		$this->assertFalse( $settings->isUsable() );
	}

	public function test_a_whitespace_only_key_is_not_a_key(): void {
		$settings = ProviderSettings::fromArray(
			array(
				'mode'    => ProviderSettings::MODE_OPENAI,
				'api_key' => "   \n ",
			)
		);

		$this->assertFalse( $settings->isUsable() );
	}

	public function test_each_byok_mode_has_a_default_model(): void {
		$anthropic = ProviderSettings::fromArray( array( 'mode' => ProviderSettings::MODE_ANTHROPIC ) );
		$openai    = ProviderSettings::fromArray( array( 'mode' => ProviderSettings::MODE_OPENAI ) );

		$this->assertSame( ProviderSettings::DEFAULT_ANTHROPIC_MODEL, $anthropic->effectiveModel() );
		$this->assertSame( ProviderSettings::DEFAULT_OPENAI_MODEL, $openai->effectiveModel() );
	}

	public function test_an_explicit_model_overrides_the_default(): void {
		$settings = ProviderSettings::fromArray(
			array(
				'mode'  => ProviderSettings::MODE_ANTHROPIC,
				'model' => '  claude-opus-4-1  ',
			)
		);

		$this->assertSame( 'claude-opus-4-1', $settings->effectiveModel() );
	}

	public function test_the_masked_key_reveals_a_prefix_and_suffix_only(): void {
		$settings = ProviderSettings::fromArray(
			array(
				'mode'    => ProviderSettings::MODE_ANTHROPIC,
				'api_key' => 'sk-ant-SECRETSECRETSECRET-1234',
			)
		);

		$masked = $settings->maskedApiKey();

		$this->assertStringStartsWith( 'sk-ant', $masked );
		$this->assertStringEndsWith( '1234', $masked );
		$this->assertStringNotContainsString( 'SECRETSECRETSECRET', $masked );
	}

	public function test_a_short_key_is_masked_entirely(): void {
		$settings = ProviderSettings::fromArray(
			array(
				'mode'    => ProviderSettings::MODE_OPENAI,
				'api_key' => 'sk-short',
			)
		);

		$this->assertSame( str_repeat( '•', 8 ), $settings->maskedApiKey() );
	}

	public function test_no_key_masks_to_an_empty_string(): void {
		$this->assertSame( '', ( new ProviderSettings() )->maskedApiKey() );
	}

	public function test_max_tokens_and_timeout_are_clamped_to_sane_bounds(): void {
		$tooBig = ProviderSettings::fromArray(
			array(
				'max_tokens' => 9999999,
				'timeout'    => 100000,
			)
		);

		$tooSmall = ProviderSettings::fromArray(
			array(
				'max_tokens' => 1,
				'timeout'    => 0,
			)
		);

		$this->assertSame( 32000, $tooBig->maxTokens );
		$this->assertSame( 300, $tooBig->timeoutSeconds );
		$this->assertSame( 256, $tooSmall->maxTokens );
		$this->assertSame( 10, $tooSmall->timeoutSeconds );
	}

	public function test_with_api_key_preserves_everything_else(): void {
		$settings = ProviderSettings::fromArray(
			array(
				'mode'       => ProviderSettings::MODE_OPENAI,
				'api_key'    => 'old',
				'model'      => 'gpt-4o-mini',
				'max_tokens' => 2048,
			)
		);

		$updated = $settings->withApiKey( 'new' );

		$this->assertSame( 'new', $updated->apiKey );
		$this->assertSame( ProviderSettings::MODE_OPENAI, $updated->mode );
		$this->assertSame( 'gpt-4o-mini', $updated->model );
		$this->assertSame( 2048, $updated->maxTokens );
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\Audit\SecretScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretScrubberTest extends TestCase {

	private SecretScrubber $scrubber;

	protected function setUp(): void {
		$this->scrubber = new SecretScrubber();
	}

	public function test_ordinary_values_pass_through_untouched(): void {
		$context = array( 'tool' => 'get_site_info', 'limit' => 5, 'ok' => true );

		$this->assertSame( $context, $this->scrubber->scrub( $context ) );
	}

	#[DataProvider( 'sensitiveKeys' )]
	public function test_sensitive_keys_are_redacted( string $key ): void {
		$scrubbed = $this->scrubber->scrub( array( $key => 'the-actual-value' ) );

		$this->assertSame( SecretScrubber::REDACTED, $scrubbed[ $key ] );
	}

	/** @return array<int, array<int, string>> */
	public static function sensitiveKeys(): array {
		return array(
			array( 'secret' ),
			array( 'pairing_secret' ),
			array( 'api_key' ),
			array( 'apiKey' ),
			array( 'password' ),
			array( 'token' ),
			array( 'Authorization' ),
			array( 'X-DoSieci-Signature' ),
			array( 'private_key' ),
			array( 'credential' ),
		);
	}

	public function test_redaction_is_recursive(): void {
		$scrubbed = $this->scrubber->scrub(
			array(
				'arguments' => array(
					'nested' => array( 'api_key' => 'sk-live-123' ),
					'safe'   => 'value',
				),
			)
		);

		$this->assertSame( SecretScrubber::REDACTED, $scrubbed['arguments']['nested']['api_key'] );
		$this->assertSame( 'value', $scrubbed['arguments']['safe'] );
	}

	public function test_matching_is_case_insensitive_and_substring_based(): void {
		$scrubbed = $this->scrubber->scrub( array( 'HUB_SECRET_VALUE' => 'x', 'my_auth_token_here' => 'y' ) );

		$this->assertSame( SecretScrubber::REDACTED, $scrubbed['HUB_SECRET_VALUE'] );
		$this->assertSame( SecretScrubber::REDACTED, $scrubbed['my_auth_token_here'] );
	}
}

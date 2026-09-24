<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\HubClient;
use DoSieci\AiOperator\Domain\HubException;
use DoSieci\AiOperator\Domain\Signing\RequestSigner;
use DoSieci\AiOperator\Domain\TransportException;
use DoSieci\AiOperator\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class HubClientTest extends TestCase {

	private function client( FakeTransport $transport ): HubClient {
		return new HubClient( $transport, new RequestSigner() );
	}

	public function test_pairing_returns_a_complete_connection(): void {
		$transport = new FakeTransport(
			array(
				array(
					'status' => 201,
					'body'   => (string) json_encode(
						array(
							'site_id' => 'site-abc',
							'key_id'  => 'lic_xyz',
							'secret'  => 'super-secret',
						)
					),
				),
			)
		);

		$connection = $this->client( $transport )->completePairing( 'https://license.dosieci.pl/', 'tok-1', 'https://shop.example/' );

		$this->assertTrue( $connection->isComplete() );
		$this->assertSame( 'https://license.dosieci.pl', $connection->hubUrl, 'Trailing slash must be normalised away.' );
		$this->assertSame( 'lic_xyz', $connection->keyId );
		$this->assertStringEndsWith( '/api/v1/pairing/complete', $transport->requests[0]['url'] );
	}

	public function test_pairing_is_sent_unsigned_because_no_credential_exists_yet(): void {
		$transport = new FakeTransport(
			array(
				array(
					'status' => 201,
					'body'   => (string) json_encode( array( 'site_id' => 's', 'key_id' => 'k', 'secret' => 'v' ) ),
				),
			)
		);

		$this->client( $transport )->completePairing( 'https://license.dosieci.pl', 'tok', 'https://shop.example/' );

		$this->assertArrayNotHasKey( RequestSigner::HEADER_SIGNATURE, $transport->requests[0]['headers'] );
	}

	public function test_a_plain_http_hub_url_is_refused(): void {
		$this->expectException( HubException::class );
		$this->expectExceptionMessage( 'https://' );

		$this->client( new FakeTransport() )->completePairing( 'http://license.dosieci.pl', 'tok', 'https://shop.example/' );
	}

	public function test_a_pairing_response_missing_the_secret_is_refused(): void {
		$transport = new FakeTransport(
			array(
				array(
					'status' => 201,
					'body'   => (string) json_encode( array( 'site_id' => 's', 'key_id' => 'k' ) ),
				),
			)
		);

		$this->expectException( HubException::class );
		$this->client( $transport )->completePairing( 'https://license.dosieci.pl', 'tok', 'https://shop.example/' );
	}

	public function test_a_non_2xx_response_becomes_a_hub_exception_carrying_the_error_code(): void {
		$transport = new FakeTransport(
			array(
				array(
					'status' => 402,
					'body'   => (string) json_encode( array( 'error' => 'insufficient_credits' ) ),
				),
			)
		);

		try {
			$this->client( $transport )->completePairing( 'https://license.dosieci.pl', 'tok', 'https://shop.example/' );
			$this->fail( 'Expected HubException.' );
		} catch ( HubException $e ) {
			$this->assertSame( 402, $e->statusCode );
			$this->assertSame( 'insufficient_credits', $e->errorCode );
			$this->assertFalse( $e->retryable );
		}
	}

	public function test_the_retryable_hint_is_preserved(): void {
		$transport = new FakeTransport(
			array(
				array(
					'status' => 503,
					'body'   => (string) json_encode( array( 'error' => 'provider_unavailable', 'retryable' => true ) ),
				),
			)
		);

		try {
			$this->client( $transport )->completePairing( 'https://license.dosieci.pl', 'tok', 'https://shop.example/' );
			$this->fail( 'Expected HubException.' );
		} catch ( HubException $e ) {
			$this->assertTrue( $e->retryable );
		}
	}

	public function test_a_connection_failure_surfaces_as_a_transport_exception(): void {
		$this->expectException( TransportException::class );

		$this->client( FakeTransport::throwing( 'cURL error 28' ) )
			->completePairing( 'https://license.dosieci.pl', 'tok', 'https://shop.example/' );
	}

	public function test_a_non_json_error_body_still_produces_a_usable_exception(): void {
		$transport = new FakeTransport( array( array( 'status' => 502, 'body' => '<html>Bad Gateway</html>' ) ) );

		try {
			$this->client( $transport )->completePairing( 'https://license.dosieci.pl', 'tok', 'https://shop.example/' );
			$this->fail( 'Expected HubException.' );
		} catch ( HubException $e ) {
			$this->assertSame( 502, $e->statusCode );
			$this->assertSame( 'hub_error', $e->errorCode );
		}
	}
}

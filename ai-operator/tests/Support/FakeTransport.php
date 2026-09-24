<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Support;

use DoSieci\AiOperator\Domain\HttpTransportInterface;
use DoSieci\AiOperator\Domain\TransportException;

final class FakeTransport implements HttpTransportInterface {

	/** @var array<int, array{url:string, headers:array<string,string>, body:string}> */
	public array $requests = array();

	/** @var array<int, array{status:int, body:string}> */
	private array $responses;

	private ?string $throwMessage = null;

	/**
	 * @param array<int, array{status:int, body:string}> $responses replayed in order
	 */
	public function __construct( array $responses = array() ) {
		$this->responses = $responses;
	}

	public static function throwing( string $message ): self {
		$transport               = new self();
		$transport->throwMessage = $message;

		return $transport;
	}

	public function post( string $url, array $headers, string $body, int $timeoutSeconds ): array {
		$this->requests[] = array(
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
		);

		if ( null !== $this->throwMessage ) {
			throw new TransportException( $this->throwMessage );
		}

		if ( array() === $this->responses ) {
			throw new \LogicException( 'FakeTransport ran out of scripted responses.' );
		}

		return array_shift( $this->responses );
	}

	/** @return array<string, mixed> */
	public function lastBodyDecoded(): array {
		$last = end( $this->requests );

		return is_array( $last ) ? (array) json_decode( $last['body'], true ) : array();
	}
}

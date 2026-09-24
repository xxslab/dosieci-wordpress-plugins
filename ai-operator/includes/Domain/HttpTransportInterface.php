<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain;

/**
 * The one seam between this plugin's Hub client and WordPress's HTTP stack.
 * Domain code depends on this; the WordPress adapter implements it with
 * wp_remote_request(). Tests implement it with a fake, which is what makes
 * HubClient testable with no WordPress and no network.
 */
interface HttpTransportInterface {

	/**
	 * @param array<string, string> $headers
	 *
	 * @return array{status:int, body:string}
	 *
	 * @throws TransportException on a connection-level failure (DNS, TLS, timeout)
	 */
	public function post( string $url, array $headers, string $body, int $timeoutSeconds ): array;
}

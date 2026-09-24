<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress;

use DoSieci\AiOperator\Domain\HttpTransportInterface;
use DoSieci\AiOperator\Domain\TransportException;

/**
 * WordPress implementation of the plugin's one outbound-HTTP seam, using
 * wp_remote_post() so the site's own HTTP filters, proxy configuration and
 * CA bundle apply -- rather than opening raw sockets and bypassing all of
 * that.
 *
 * sslverify is deliberately left at WordPress's default (true) and is NOT
 * exposed as a plugin setting: the only thing this plugin talks to is the
 * DoSieci Hub over https, and a "disable SSL verification" toggle in a
 * plugin that transmits a signing secret is a footgun, not a feature.
 */
final class WpHttpTransport implements HttpTransportInterface {

	public function post( string $url, array $headers, string $body, int $timeoutSeconds ): array {
		$response = wp_remote_post(
			$url,
			array(
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $timeoutSeconds,
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'DoSieci-AI-Operator/' . DOSIECI_AI_OPERATOR_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new TransportException( $response->get_error_message() );
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}
}

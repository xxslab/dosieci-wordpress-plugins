<?php

declare(strict_types=1);

namespace DoSieci\Translator\Adapter;

use DoSieci\Translator\Domain\DeepLLanguages;
use DoSieci\Translator\Domain\DeepLResponseParser;
use DoSieci\Translator\Domain\TranslationException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DeepL call with the site owner's own key: from this site straight to
 * DeepL, never through DoSieci.
 *
 * DeepL serves Free and Pro keys from different hosts. A Free key sent to the
 * Pro host fails with a confusing 403, so the host is derived from the key's
 * documented ":fx" suffix instead of asking the user.
 */
final class DeepLClient {

	private const HOST_FREE = 'https://api-free.deepl.com/v2/translate';
	private const HOST_PRO  = 'https://api.deepl.com/v2/translate';

	private ?string $detectedSourceLanguage = null;

	public function __construct( private string $apiKey, private int $timeoutSeconds = 45 ) {
	}

	public function endpoint(): string {
		return str_ends_with( trim( $this->apiKey ), ':fx' ) ? self::HOST_FREE : self::HOST_PRO;
	}

	/**
	 * @throws TranslationException With a message safe to show to the operator.
	 */
	public function translate( string $text, string $targetLanguage, bool $isHtml ): string {
		if ( ! DeepLLanguages::isSupported( $targetLanguage ) ) {
			throw new TranslationException( esc_html__( 'Unsupported target language.', 'dosieci-translator' ) );
		}

		$payload = array(
			'text'        => array( $text ),
			'target_lang' => strtoupper( $targetLanguage ),
		);

		if ( $isHtml ) {
			// Without this DeepL translates the contents of HTML attributes and
			// breaks the markup of a description.
			$payload['tag_handling'] = 'html';
		}

		$response = wp_remote_post(
			$this->endpoint(),
			array(
				'headers' => array(
					'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) wp_json_encode( $payload ),
				'timeout' => $this->timeoutSeconds,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new TranslationException( esc_html( str_replace( $this->apiKey, '[redacted]', $response->get_error_message() ) ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		DeepLResponseParser::assertOk( $status, $body );

		$this->detectedSourceLanguage = DeepLResponseParser::detectedSourceLanguage( $body );

		return DeepLResponseParser::extractTranslation( $body );
	}

	/**
	 * The source language DeepL detected in the last translate() call.
	 */
	public function detectedSourceLanguage(): ?string {
		return $this->detectedSourceLanguage;
	}
}

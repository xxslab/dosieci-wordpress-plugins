<?php

declare(strict_types=1);

namespace DoSieci\Translator\Adapter;

use DoSieci\Translator\Domain\DeepLLanguages;
use DoSieci\Translator\Domain\DeepLResponseParser;
use DoSieci\Translator\Domain\TranslationException;

/**
 * BYOK DeepL call: WordPress -> DeepL directly, never through DoSieci.
 *
 * DeepL routes Free and Pro keys to different hosts. A Free key sent to the
 * Pro endpoint fails with a confusing 403, so the host is derived from the
 * key's own documented ":fx" suffix rather than asked of the user.
 */
final class DeepLClient {

	private const HOST_FREE = 'https://api-free.deepl.com/v2/translate';
	private const HOST_PRO  = 'https://api.deepl.com/v2/translate';

	public function __construct( private string $apiKey, private int $timeoutSeconds = 30 ) {
	}

	public function endpoint(): string {
		return str_ends_with( trim( $this->apiKey ), ':fx' ) ? self::HOST_FREE : self::HOST_PRO;
	}

	/**
	 * @throws TranslationException
	 */
	public function translate( string $text, string $targetLanguage, bool $isHtml ): string {
		if ( ! DeepLLanguages::isSupported( $targetLanguage ) ) {
			throw new TranslationException( 'Nieobsługiwany język docelowy.' );
		}

		$response = wp_remote_post(
			$this->endpoint(),
			array(
				'headers'   => array(
					'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'      => (string) wp_json_encode(
					array(
						'text'        => array( $text ),
						'target_lang' => strtoupper( $targetLanguage ),
						// Without this, DeepL translates the contents of HTML
						// attributes and mangles the markup of a product
						// description.
						'tag_handling' => $isHtml ? 'html' : null,
					)
				),
				'timeout'   => $this->timeoutSeconds,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new TranslationException(
				str_replace( $this->apiKey, '[redacted]', $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		DeepLResponseParser::assertOk( $status, $body );

		return DeepLResponseParser::extractTranslation( $body );
	}
}

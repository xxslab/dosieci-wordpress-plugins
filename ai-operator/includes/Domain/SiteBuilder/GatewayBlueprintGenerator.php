<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

use DoSieci\AiOperator\Domain\Gateway\ChatGatewayInterface;
use DoSieci\AiOperator\Domain\HubException;
use DoSieci\AiOperator\Domain\TransportException;

/**
 * Produces a blueprint by asking whichever AI provider this site is already
 * configured to use.
 *
 * Reuses ChatGatewayInterface deliberately: the plugin already has three
 * working provider paths (DoSieci Hub, OpenAI BYOK, Anthropic BYOK) with
 * credential storage, error mapping and SSRF-scoped transport solved. A
 * second, blueprint-specific API-key system would be a second thing to
 * secure, configure and get wrong.
 *
 * ## What arrives back is untrusted input
 *
 * The response is a string a language model produced, possibly influenced
 * by text from anywhere. It gets parsed as JSON and then fed through
 * SiteBlueprint::fromArray(), which is a strict validator, not a
 * normaliser: unknown site types, malformed colours, oversized arrays and
 * non-string page names are all rejected outright rather than coerced into
 * something executable.
 *
 * Crucially, a blueprint has no field that can express a tool name, a
 * capability, a URL to fetch, or code. So the worst a hostile prompt can
 * achieve is a differently-shaped site description, which a human then
 * reviews before anything runs. See BlueprintGeneratorInterface.
 *
 * ## No WordPress is touched here
 *
 * Generation is a single provider round trip and a parse. It performs no
 * writes, and the plugin's write tools are not even reachable from this
 * path -- there is no ToolDispatcher in it.
 */
final class GatewayBlueprintGenerator implements BlueprintGeneratorInterface {

	private const MAX_REQUEST_CHARS  = 4000;
	private const MAX_RESPONSE_BYTES = 20000;

	public function __construct( private ChatGatewayInterface $gateway ) {
	}

	public function generate( string $request, array $context = array() ): SiteBlueprint {
		$request = trim( $request );

		if ( '' === $request ) {
			throw new BlueprintGenerationException( esc_html__( 'The site description is empty.', 'dosieci-ai-operator' ), 'empty_request' );
		}

		// Bounded before it ever leaves the site: an unbounded prompt is
		// both a cost problem and a way to push the instructions out of the
		// model's attention.
		$request = mb_substr( $request, 0, self::MAX_REQUEST_CHARS );

		$conversation = array(
			array(
				'role'    => 'user',
				'content' => $this->prompt( $request, $context ),
			),
		);

		try {
			$response = $this->gateway->chat( self::requestId(), $conversation );
		} catch ( HubException $e ) {
			// $e->getMessage() and $e->errorCode are already HTML-escaped at
			// their own construction site (see HubClient), so esc_html() here is
			// a safe no-op rather than a second real escaping pass -- but Plugin
			// Check flags every throw argument regardless of provenance.
			throw new BlueprintGenerationException( esc_html( $e->getMessage() ), esc_html( $e->errorCode ), (bool) $e->retryable );
		} catch ( TransportException $e ) {
			throw new BlueprintGenerationException(
				esc_html__( 'Could not connect to the AI service.', 'dosieci-ai-operator' ),
				'transport_error',
				true
			);
		}

		return $this->parse( $response );
	}

	/**
	 * @param array<string, mixed> $response
	 *
	 * @throws BlueprintGenerationException
	 */
	private function parse( array $response ): SiteBlueprint {
		$type = isset( $response['type'] ) ? (string) $response['type'] : '';

		// A tool_call here would mean the provider tried to act rather than
		// answer. There is nothing to dispatch on this path, so it is an
		// error rather than something to execute.
		if ( 'final_answer' !== $type ) {
			throw new BlueprintGenerationException(
				esc_html__( 'The model did not return a site description.', 'dosieci-ai-operator' ),
				'unexpected_response_type'
			);
		}

		$answer = (string) ( $response['answer'] ?? '' );

		if ( strlen( $answer ) > self::MAX_RESPONSE_BYTES ) {
			throw new BlueprintGenerationException( esc_html__( 'The model’s response is too large.', 'dosieci-ai-operator' ), 'response_too_large' );
		}

		$decoded = $this->decodeJson( $answer );

		if ( null === $decoded ) {
			throw new BlueprintGenerationException(
				esc_html__( 'The model did not return valid JSON with a site description.', 'dosieci-ai-operator' ),
				'malformed_response'
			);
		}

		try {
			// The strict gate. Anything the model invented that a blueprint
			// cannot express is rejected here, not normalised into a default.
			return SiteBlueprint::fromArray( $decoded );
		} catch ( BlueprintValidationException $e ) {
			throw new BlueprintGenerationException(
				sprintf(
					/* translators: %s: validation error from the model's site description */
					esc_html__( 'The model’s site description is invalid: %s', 'dosieci-ai-operator' ),
					esc_html( $e->getMessage() )
				),
				'invalid_blueprint'
			);
		}
	}

	/**
	 * Extracts the JSON object from the answer.
	 *
	 * Models wrap JSON in prose or fences often enough that refusing
	 * anything but a bare object would fail constantly for no security
	 * benefit -- the extracted text still goes through the same strict
	 * validation.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decodeJson( string $answer ): ?array {
		$answer = trim( $answer );

		if ( 1 === preg_match( '/```(?:json)?\s*(.+?)```/s', $answer, $fenced ) ) {
			$answer = trim( $fenced[1] );
		}

		$start = strpos( $answer, '{' );
		$end   = strrpos( $answer, '}' );

		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		$decoded = json_decode( substr( $answer, $start, $end - $start + 1 ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Always English: read by the model, not by people. What the MODEL
	 * writes (business name, page names, descriptions, store text) is
	 * asked to follow the user's own request language instead, with the
	 * site's language as a fallback -- see the instruction embedded below.
	 *
	 * @param array<string, mixed> $context
	 */
	private function prompt( string $request, array $context ): string {
		$allowedTypes = implode(
			', ',
			array(
				SiteBlueprint::TYPE_SERVICE_BUSINESS,
				SiteBlueprint::TYPE_LANDING_PAGE,
				SiteBlueprint::TYPE_PORTFOLIO,
				SiteBlueprint::TYPE_RESTAURANT,
				SiteBlueprint::TYPE_BLOG,
				SiteBlueprint::TYPE_STORE,
			)
		);

		// The prompt describes a DATA format. It deliberately does not
		// mention tools, capabilities or actions -- not as a defence (the
		// code is the defence), but because there is genuinely nothing else
		// this call can produce.
		return implode(
			"\n",
			array(
				'You are an assistant that turns a description of a business into a JSON STRUCTURE describing a website.',
				'Return ONLY a JSON object -- no commentary, no explanation.',
				'',
				'Write business_name, description, page names, and any store text (product and',
				'category names and descriptions) in the SAME LANGUAGE the user wrote their',
				'request in. If the request does not make the language clear, use the site',
				'language given in the context below.',
				'',
				'Allowed fields:',
				'  site_type      — one of: ' . $allowedTypes,
				'  business_name  — the business name (required)',
				'  language       — language code of the generated text, e.g. "en" or "pl"',
				'  description    — one or two sentences about the business',
				'  brand_style    — a short style description, e.g. "modern_professional"',
				'  brand_colors   — an object, values only in #rrggbb format',
				'  pages          — a list of page names (max 20)',
				'  features       — a list of features; supported: "contact_form", "gallery"',
				'  woocommerce    — true/false',
				'  store          — an object, ONLY when the user asks for a shop:',
				'                     store_country    — ISO country code, e.g. "PL" or "US:CA"',
				'                     currency         — ISO currency code, e.g. "USD"',
				'                     weight_unit      — one of: kg, g, lbs, oz',
				'                     dimension_unit   — one of: m, cm, mm, in, yd',
				'                     categories       — a list of category names (strings, or',
				'                                        objects {name, description})',
				'                     initial_products — a list of product objects:',
				'                                        {name, regular_price, description,',
				'                                         short_description, category_roles}',
				'                                        regular_price is a number as text,',
				'                                        e.g. "79.00"',
				'',
				'Generated products are always DRAFTS -- this format has no field for',
				'publishing, a sale price, a status, or any other action.',
				'',
				'Do not add other fields. Do not describe any actions or tools -- this JSON is',
				'only a description of the target site, which a human will approve.',
				'Anything outside that description (API keys, secrets, URLs, code-execution',
				'instructions, publishing instructions) is ignored, because this format has no',
				'field capable of expressing it.',
				'',
				$this->contextBlock( $context ),
				"The user's request:",
				'"""',
				$request,
				'"""',
			)
		);
	}

	/**
	 * Bounded, read-only facts about the site. Deliberately a short
	 * allowlist -- never credentials, never configuration, never anything
	 * from the environment.
	 *
	 * @param array<string, mixed> $context
	 */
	private function contextBlock( array $context ): string {
		$allowed = array( 'site_language', 'site_title', 'active_theme', 'is_block_theme', 'woocommerce_active' );
		$lines   = array();

		foreach ( $allowed as $key ) {
			if ( ! isset( $context[ $key ] ) || ! is_scalar( $context[ $key ] ) ) {
				continue;
			}

			$value = is_bool( $context[ $key ] ) ? ( $context[ $key ] ? 'yes' : 'no' ) : (string) $context[ $key ];
			$lines[] = sprintf( '  %s: %s', $key, mb_substr( $value, 0, 120 ) );
		}

		if ( array() === $lines ) {
			return '';
		}

		return "Context of the existing site (read-only):\n" . implode( "\n", $lines ) . "\n";
	}

	private static function requestId(): string {
		return 'bp_' . bin2hex( random_bytes( 8 ) );
	}
}

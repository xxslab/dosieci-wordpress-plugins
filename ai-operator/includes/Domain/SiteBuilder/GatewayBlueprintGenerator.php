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
			throw new BlueprintGenerationException( 'Opis witryny jest pusty.', 'empty_request' );
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
			throw new BlueprintGenerationException( $e->getMessage(), $e->errorCode, $e->retryable );
		} catch ( TransportException $e ) {
			throw new BlueprintGenerationException(
				'Nie udało się połączyć z usługą AI.',
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
				'Model nie zwrócił opisu witryny.',
				'unexpected_response_type'
			);
		}

		$answer = (string) ( $response['answer'] ?? '' );

		if ( strlen( $answer ) > self::MAX_RESPONSE_BYTES ) {
			throw new BlueprintGenerationException( 'Odpowiedź modelu jest zbyt duża.', 'response_too_large' );
		}

		$decoded = $this->decodeJson( $answer );

		if ( null === $decoded ) {
			throw new BlueprintGenerationException(
				'Model nie zwrócił poprawnego JSON-a z opisem witryny.',
				'malformed_response'
			);
		}

		try {
			// The strict gate. Anything the model invented that a blueprint
			// cannot express is rejected here, not normalised into a default.
			return SiteBlueprint::fromArray( $decoded );
		} catch ( BlueprintValidationException $e ) {
			throw new BlueprintGenerationException(
				sprintf( 'Opis witryny od modelu jest nieprawidłowy: %s', $e->getMessage() ),
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

	/** @param array<string, mixed> $context */
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
				'Jesteś asystentem, który zamienia opis firmy na STRUKTURĘ JSON opisującą witrynę.',
				'Zwróć WYŁĄCZNIE obiekt JSON, bez komentarza i bez wyjaśnień.',
				'',
				'Dozwolone pola:',
				'  site_type      — jedna z wartości: ' . $allowedTypes,
				'  business_name  — nazwa firmy (wymagane)',
				'  language       — kod języka, np. "pl" albo "en"',
				'  description    — jedno–dwa zdania o firmie',
				'  brand_style    — krótki opis stylu, np. "modern_professional"',
				'  brand_colors   — obiekt, wartości wyłącznie w formacie #rrggbb',
				'  pages          — lista nazw stron (maks. 20)',
				'  features       — lista cech; obsługiwane: "contact_form", "gallery"',
				'  woocommerce    — true/false',
				'  store          — obiekt, TYLKO gdy użytkownik prosi o sklep:',
				'                     store_country    — kod kraju ISO, np. "PL" albo "US:CA"',
				'                     currency         — kod waluty ISO, np. "PLN"',
				'                     weight_unit      — jedna z: kg, g, lbs, oz',
				'                     dimension_unit   — jedna z: m, cm, mm, in, yd',
				'                     categories       — lista nazw kategorii (napisy albo',
				'                                        obiekty {name, description})',
				'                     initial_products — lista obiektów produktu:',
				'                                        {name, regular_price, description,',
				'                                         short_description, category_roles}',
				'                                        regular_price to liczba jako tekst,',
				'                                        np. "79.00" albo "79,90"',
				'',
				'Wygenerowane produkty są zawsze SZKICAMI — ten format nie ma pola',
				'do publikacji, ceny promocyjnej, statusu ani żadnej innej akcji.',
				'',
				'Nie dodawaj innych pól. Nie opisuj żadnych działań ani narzędzi —',
				'ten JSON to wyłącznie opis docelowej witryny, który człowiek zatwierdzi.',
				'Wszystko poza tym opisem (klucze API, sekrety, adresy URL, polecenia',
				'wykonania kodu, instrukcje publikacji) jest ignorowane, ponieważ ten',
				'format nie ma pola zdolnego je wyrazić.',
				'',
				$this->contextBlock( $context ),
				'Opis od użytkownika:',
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

			$value = is_bool( $context[ $key ] ) ? ( $context[ $key ] ? 'tak' : 'nie' ) : (string) $context[ $key ];
			$lines[] = sprintf( '  %s: %s', $key, mb_substr( $value, 0, 120 ) );
		}

		if ( array() === $lines ) {
			return '';
		}

		return "Kontekst istniejącej witryny (tylko do odczytu):\n" . implode( "\n", $lines ) . "\n";
	}

	private static function requestId(): string {
		return 'bp_' . bin2hex( random_bytes( 8 ) );
	}
}

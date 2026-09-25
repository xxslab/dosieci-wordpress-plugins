<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

/**
 * What a person is told when a model call fails, by stable error code.
 *
 * The gateways (Hub, WordPress AI connectors, direct Anthropic/OpenAI)
 * all map their failures onto the same codes, so the chat and the Site
 * Builder can give the same, actionable advice whichever mode the site
 * uses -- "top up your credits" and "your key was rejected" need
 * different next steps, and a generic "error" helps nobody.
 */
final class ErrorMessages {

	/**
	 * @return string|null null when the code has no specific advice
	 */
	public static function forCode( string $code ): ?string {
		return match ( $code ) {
			'insufficient_credits'      => __( 'Your DoSieci plan has run out of AI credits. Top them up with DoSieci, or switch to your own AI key in the settings.', 'dosieci-ai-operator' ),
			'not_connected'             => __( 'This site is not connected to DoSieci. Pair it on the Connection screen, or choose your own AI key in the settings.', 'dosieci-ai-operator' ),
			'site_not_active'           => __( 'This site’s connection to DoSieci was revoked. Pair it again.', 'dosieci-ai-operator' ),
			'byok_key_missing'          => __( 'A provider with your own API key is selected, but no key is saved. Add it in the settings.', 'dosieci-ai-operator' ),
			'byok_key_rejected'         => __( 'The AI provider rejected your API key. Check that it is correct and active.', 'dosieci-ai-operator' ),
			'byok_quota_exhausted'      => __( 'Your account with the AI provider has no funds left. Top it up with the provider.', 'dosieci-ai-operator' ),
			'provider_rate_limited'     => __( 'Too many requests to the model. Try again in a moment.', 'dosieci-ai-operator' ),
			'provider_bad_request'      => __( 'The AI provider rejected the request. Check the model name in the settings.', 'dosieci-ai-operator' ),
			'provider_timeout',
			'provider_unavailable'      => __( 'The AI model is temporarily unavailable. Try again in a moment.', 'dosieci-ai-operator' ),
			'provider_output_truncated' => __( 'The model reached its output limit before it finished answering. Try a shorter request, or split the task into steps.', 'dosieci-ai-operator' ),
			'ai_gateway_misconfigured'  => __( 'The DoSieci AI gateway is misconfigured. Contact DoSieci support.', 'dosieci-ai-operator' ),
			'wp_ai_unavailable'         => __( 'WordPress AI connectors are not available on this site: they need WordPress 7.0 or later with AI features enabled. Choose another mode in the settings.', 'dosieci-ai-operator' ),
			'wp_ai_no_model'            => __( 'None of the AI providers connected in Settings > Connectors has a model that can use tools. Connect a provider (for example OpenAI, Anthropic or Google), or choose another mode.', 'dosieci-ai-operator' ),
			'transport_error'           => __( 'Could not connect to the AI service. Check the site’s network connection.', 'dosieci-ai-operator' ),
			default                     => null,
		};
	}

	/**
	 * Same as forCode(), with a generic fallback for unknown codes.
	 */
	public static function forCodeOrGeneric( string $code ): string {
		return self::forCode( $code ) ?? __( 'The AI service returned an error. Try again in a moment.', 'dosieci-ai-operator' );
	}
}

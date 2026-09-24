<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Gateway;

/**
 * Which model this site talks to, and on whose account.
 *
 * MODE_HUB routes through DoSieci: the site never holds a provider key,
 * and usage is metered against the workspace's DoSieci credits.
 * MODE_WORDPRESS uses the AI connectors built into WordPress 7.0+
 * (Settings > Connectors): the site owner's own key, stored and managed by
 * WordPress, with whichever provider they connected there.
 * MODE_ANTHROPIC / MODE_OPENAI are BYOK with a key stored by this plugin,
 * for sites without the WordPress connectors or for a specific model.
 * None of the BYOK modes consume DoSieci credits or need the Hub.
 *
 * The key is deliberately NOT part of the value object's string
 * representation anywhere: __toString is not implemented, and
 * maskedApiKey() is what the UI renders. A settings object that could
 * accidentally be var_dumped into a debug log with a live API key in it is
 * a credential leak waiting for its first support ticket.
 */
final class ProviderSettings {

	public const MODE_HUB       = 'hub';
	public const MODE_WORDPRESS = 'wordpress';
	public const MODE_ANTHROPIC = 'anthropic';
	public const MODE_OPENAI    = 'openai';

	/**
	 * Defaults are the current generally-available models for each
	 * provider. They are defaults, not constants used at call time: the
	 * stored model string is what is sent, so a site can move to a newer
	 * model without a plugin update.
	 */
	public const DEFAULT_ANTHROPIC_MODEL = 'claude-sonnet-5';
	public const DEFAULT_OPENAI_MODEL    = 'gpt-5';

	/**
	 * Output budget per model call. Generous on purpose: reasoning models
	 * spend part of it thinking, and a budget that runs out mid-answer
	 * costs more (a failed turn, a retry) than the unused headroom.
	 */
	public const DEFAULT_MAX_TOKENS = 8192;

	public function __construct(
		public readonly string $mode = self::MODE_HUB,
		public readonly string $apiKey = '',
		public readonly string $model = '',
		public readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
		public readonly int $timeoutSeconds = 60
	) {
	}

	/**
	 * @return array<string, string> mode => label for the settings screen
	 */
	public static function modes(): array {
		return array(
			self::MODE_HUB       => __( 'DoSieci Hub: AI credits from your DoSieci plan', 'dosieci-ai-operator' ),
			self::MODE_WORDPRESS => __( 'WordPress AI connectors: your own key, set in Settings > Connectors (WordPress 7.0+)', 'dosieci-ai-operator' ),
			self::MODE_ANTHROPIC => __( 'Anthropic: your own API key', 'dosieci-ai-operator' ),
			self::MODE_OPENAI    => __( 'OpenAI: your own API key', 'dosieci-ai-operator' ),
		);
	}

	public function isByok(): bool {
		return self::MODE_HUB !== $this->mode;
	}

	/**
	 * Whether this mode needs the API key stored by this plugin. The
	 * WordPress mode uses the key WordPress itself stores for the connector.
	 */
	public function needsApiKey(): bool {
		return self::MODE_ANTHROPIC === $this->mode || self::MODE_OPENAI === $this->mode;
	}

	/**
	 * A key-based mode without a key is not usable, and saying so up front
	 * is far better than a 401 from the provider halfway through a chat
	 * turn. (Whether a WordPress connector is configured is checked where
	 * WordPress is available -- see WpAiClientGateway::isConfigured().)
	 */
	public function isUsable(): bool {
		if ( ! $this->needsApiKey() ) {
			return true;
		}

		return '' !== trim( $this->apiKey );
	}

	public function effectiveModel(): string {
		if ( '' !== trim( $this->model ) ) {
			return trim( $this->model );
		}

		return match ( $this->mode ) {
			self::MODE_ANTHROPIC => self::DEFAULT_ANTHROPIC_MODEL,
			self::MODE_OPENAI    => self::DEFAULT_OPENAI_MODEL,
			default              => '',
		};
	}

	/**
	 * Shows enough of the key to recognise which one is configured, and not
	 * enough to use it. Never returns the raw value.
	 */
	public function maskedApiKey(): string {
		$key = trim( $this->apiKey );

		if ( '' === $key ) {
			return '';
		}

		if ( strlen( $key ) <= 12 ) {
			return str_repeat( '•', strlen( $key ) );
		}

		return substr( $key, 0, 6 ) . str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	public function withApiKey( string $apiKey ): self {
		return new self( $this->mode, $apiKey, $this->model, $this->maxTokens, $this->timeoutSeconds );
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	public static function fromArray( array $stored ): self {
		$mode = isset( $stored['mode'] ) ? (string) $stored['mode'] : self::MODE_HUB;

		// An unrecognised stored mode falls back to the Hub rather than to
		// a BYOK mode: failing closed here means a corrupted option can
		// never cause the site to start making unmetered direct calls.
		if ( ! array_key_exists( $mode, self::modes() ) ) {
			$mode = self::MODE_HUB;
		}

		return new self(
			$mode,
			isset( $stored['api_key'] ) ? (string) $stored['api_key'] : '',
			isset( $stored['model'] ) ? (string) $stored['model'] : '',
			isset( $stored['max_tokens'] ) ? max( 256, min( 32000, (int) $stored['max_tokens'] ) ) : self::DEFAULT_MAX_TOKENS,
			isset( $stored['timeout'] ) ? max( 10, min( 300, (int) $stored['timeout'] ) ) : 60
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'mode'       => $this->mode,
			'api_key'    => $this->apiKey,
			'model'      => $this->model,
			'max_tokens' => $this->maxTokens,
			'timeout'    => $this->timeoutSeconds,
		);
	}
}

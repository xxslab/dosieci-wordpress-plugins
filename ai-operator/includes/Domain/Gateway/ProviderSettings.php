<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Gateway;

/**
 * Which model this site talks to, and on whose account.
 *
 * MODE_HUB routes through DoSieci: the site never holds a provider key,
 * and usage is metered against the workspace's DoSieci credits.
 * MODE_ANTHROPIC / MODE_OPENAI are BYOK -- the site owner's own key, their
 * own bill, no DoSieci credits consumed and no dependency on the Hub being
 * reachable for chat (pairing is still required for licensing).
 *
 * The key is deliberately NOT part of the value object's string
 * representation anywhere: __toString is not implemented, and
 * maskedApiKey() is what the UI renders. A settings object that could
 * accidentally be var_dumped into a debug log with a live API key in it is
 * a credential leak waiting for its first support ticket.
 */
final class ProviderSettings {

	public const MODE_HUB       = 'hub';
	public const MODE_ANTHROPIC = 'anthropic';
	public const MODE_OPENAI    = 'openai';

	/**
	 * Defaults are the current generally-available models for each
	 * provider. They are defaults, not constants used at call time: the
	 * stored model string is what is sent, so a site can move to a newer
	 * model without a plugin update.
	 */
	public const DEFAULT_ANTHROPIC_MODEL = 'claude-sonnet-4-5';
	public const DEFAULT_OPENAI_MODEL    = 'gpt-4o';

	public function __construct(
		public readonly string $mode = self::MODE_HUB,
		public readonly string $apiKey = '',
		public readonly string $model = '',
		public readonly int $maxTokens = 4096,
		public readonly int $timeoutSeconds = 60
	) {
	}

	public static function modes(): array {
		return array(
			self::MODE_HUB       => 'DoSieci Hub (kredyty z Twojego planu)',
			self::MODE_ANTHROPIC => 'Anthropic — własny klucz API',
			self::MODE_OPENAI    => 'OpenAI — własny klucz API',
		);
	}

	public function isByok(): bool {
		return self::MODE_HUB !== $this->mode;
	}

	/**
	 * A BYOK mode without a key is not usable, and saying so up front is
	 * far better than a 401 from the provider halfway through a chat turn.
	 */
	public function isUsable(): bool {
		if ( ! $this->isByok() ) {
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
			isset( $stored['max_tokens'] ) ? max( 256, min( 32000, (int) $stored['max_tokens'] ) ) : 4096,
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

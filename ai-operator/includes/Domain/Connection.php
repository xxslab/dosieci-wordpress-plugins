<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain;

/**
 * This installation's identity with the Hub, established once by pairing.
 *
 * $secret is the HMAC signing secret. It is written to the WordPress
 * options table once (autoload disabled) and from then on is only ever read
 * to compute a signature -- it is never rendered in the admin UI, never
 * returned by any AJAX/REST endpoint this plugin registers, and never
 * written to the audit log (AI_OPERATOR_SECURITY.md section 5). There is
 * deliberately no "show my secret" screen: if it is lost, the correct
 * recovery is to re-pair, which rotates it.
 *
 * Note what is NOT here: any AI provider API key. The plugin authenticates
 * to the Hub, and the Hub -- never the plugin -- talks to the AI provider
 * (AI_OPERATOR_ARCHITECTURE.md section 3).
 */
final class Connection {

	public function __construct(
		public readonly string $hubUrl,
		public readonly string $siteId,
		public readonly string $keyId,
		public readonly string $secret,
		public readonly int $pairedAt = 0
	) {
	}

	public function isComplete(): bool {
		return '' !== $this->hubUrl
			&& '' !== $this->siteId
			&& '' !== $this->keyId
			&& '' !== $this->secret;
	}

	/**
	 * Safe-to-display subset. Deliberately has no secret field at all, so
	 * there is no way for a template to render it by accident.
	 *
	 * @return array<string, mixed>
	 */
	public function toPublicArray(): array {
		return array(
			'hub_url'   => $this->hubUrl,
			'site_id'   => $this->siteId,
			'key_id'    => $this->keyId,
			'paired_at' => $this->pairedAt,
		);
	}
}

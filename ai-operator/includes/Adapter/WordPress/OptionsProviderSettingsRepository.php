<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress;

use DoSieci\AiOperator\Domain\Gateway\ProviderSettings;

/**
 * Stores the site's AI provider choice and, in BYOK mode, the owner's API
 * key.
 *
 * autoload is 'no' deliberately: a live provider API key has no business
 * being loaded into memory on every front-end page view when it is only
 * ever read on the handful of admin requests that actually call a model.
 *
 * The key is stored as the site owner entered it. WordPress has no
 * general-purpose secret store, and encrypting with a key that also lives
 * in the same database (or in wp-config.php, which sits next to it in
 * every backup) would be theatre rather than protection -- it would move
 * the secret, not protect it. What IS enforced is that the key never
 * leaves the site: it is written only to this option, sent only to the
 * provider's own HTTPS endpoint, masked in the UI, and scrubbed from the
 * audit log by SecretScrubber. The honest summary of this trade-off is in
 * the settings screen itself, so an owner can decide with the facts.
 */
final class OptionsProviderSettingsRepository {

	public const OPTION_NAME = 'dosieci_ai_operator_provider';

	public function get(): ProviderSettings {
		$stored = get_option( self::OPTION_NAME );

		return ProviderSettings::fromArray( is_array( $stored ) ? $stored : array() );
	}

	public function save( ProviderSettings $settings ): void {
		update_option( self::OPTION_NAME, $settings->toArray(), false );
	}

	/**
	 * Saves everything except the key, keeping whatever key is already
	 * stored.
	 *
	 * This is what the settings form uses when the key field is submitted
	 * empty, so that saving an unrelated change (a different model, a
	 * longer timeout) does not silently wipe a working key just because the
	 * form renders it masked rather than in full.
	 */
	public function saveKeepingKey( ProviderSettings $settings ): void {
		$existing = $this->get();

		$this->save( $settings->withApiKey( $existing->apiKey ) );
	}

	public function clear(): void {
		delete_option( self::OPTION_NAME );
	}
}

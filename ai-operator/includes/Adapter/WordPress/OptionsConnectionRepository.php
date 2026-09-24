<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress;

use DoSieci\AiOperator\Domain\Connection;

/**
 * Stores this installation's Hub identity in the options table.
 *
 * autoload is 'no' on purpose: the secret should not be loaded into memory
 * on every single page request of the whole site when it is only needed on
 * the handful of admin requests that talk to the Hub.
 *
 * The connection is stored under one option so that clearing it is atomic
 * -- a half-cleared connection (key id present, secret gone) would produce
 * confusing "signature failed" errors instead of an honest "not connected".
 */
final class OptionsConnectionRepository {

	public const OPTION_NAME = 'dosieci_ai_operator_connection';

	public function get(): ?Connection {
		$stored = get_option( self::OPTION_NAME );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		$connection = new Connection(
			(string) ( $stored['hub_url'] ?? '' ),
			(string) ( $stored['site_id'] ?? '' ),
			(string) ( $stored['key_id'] ?? '' ),
			(string) ( $stored['secret'] ?? '' ),
			(int) ( $stored['paired_at'] ?? 0 )
		);

		return $connection->isComplete() ? $connection : null;
	}

	public function save( Connection $connection ): void {
		update_option(
			self::OPTION_NAME,
			array(
				'hub_url'   => $connection->hubUrl,
				'site_id'   => $connection->siteId,
				'key_id'    => $connection->keyId,
				'secret'    => $connection->secret,
				'paired_at' => $connection->pairedAt,
			),
			false
		);
	}

	/**
	 * Forgets the pairing locally. Note this does NOT revoke the key on the
	 * Hub side -- the admin UI says so explicitly, because a user who
	 * disconnects because they think the site is compromised needs to know
	 * they must also revoke it in the DoSieci panel.
	 */
	public function clear(): void {
		delete_option( self::OPTION_NAME );
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress;

use DoSieci\AiOperator\Domain\Tools\CapabilityCheckerInterface;

/**
 * The real capability gate: the AI's authority is exactly the authority of
 * the wp-admin user currently driving the chat, no more.
 */
final class WpCapabilityChecker implements CapabilityCheckerInterface {

	public function currentUserCan( string $capability ): bool {
		return current_user_can( $capability );
	}
}

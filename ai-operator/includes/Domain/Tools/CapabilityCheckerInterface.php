<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Tools;

/**
 * Answers "may the human currently driving this chat session do X?".
 *
 * The AI never has authority of its own: every tool runs with, and only
 * with, the capabilities of the logged-in wp-admin user who is talking to
 * it (AI_OPERATOR_SECURITY.md section 3). The WordPress adapter implements
 * this with current_user_can(); tests implement it with a fake.
 */
interface CapabilityCheckerInterface {

	public function currentUserCan( string $capability ): bool;
}

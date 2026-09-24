<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * Performs the actual WordPress-side undo for one step.
 *
 * Receives the approved action and the snapshot captured before it ran, and
 * NOTHING from the current request -- see PlanRollbackService's docblock for
 * why that constraint is the whole point.
 */
interface RollbackExecutorInterface {

	/** @return bool false when this step could not be undone */
	public function undo( PlanAction $action, ActionState $state, int $userId ): bool;
}

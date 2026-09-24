<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * Captures whatever is needed to undo a step, BEFORE that step runs.
 *
 * Split out from the executor because the answer is entirely
 * WordPress-specific (what was this option's old value? which theme was
 * active?) while the executor itself is not. The domain executor stays
 * unit-testable with no WordPress present; the real implementation lives in
 * Adapter/WordPress.
 */
interface RollbackDataCollectorInterface {

	/**
	 * @param array<string, mixed> $resolvedArguments what will actually be
	 *        dispatched. Resolver-driven ids (a page_id filled in from an
	 *        earlier step) are 0 in the plan itself, so snapshotting from
	 *        the plan's literal arguments would capture nothing and leave
	 *        the step unrollbackable.
	 *
	 * @return array<string, mixed>|null null when the action declares no
	 *                                    rollback strategy, or when there is
	 *                                    genuinely no prior state to record
	 */
	public function capture( PlanAction $action, array $resolvedArguments ): ?array;
}

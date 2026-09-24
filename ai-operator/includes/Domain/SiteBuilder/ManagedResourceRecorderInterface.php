<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * Stamps ownership metadata onto a resource a step just created or
 * updated, so the next run recognises it.
 *
 * Separate from the resolver because the two run at different times and
 * for different reasons: the resolver decides at PLAN time (its answer
 * must be visible in what the human approves), this records at EXECUTION
 * time (after the write actually succeeded and was verified).
 */
interface ManagedResourceRecorderInterface {

	/**
	 * @param array<string, mixed> $result the step's own recorded result,
	 *        which is where the created object's id comes from
	 */
	public function record( PlanAction $action, string $projectId, array $result ): void;
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * Confirms a completed step actually changed WordPress, by reading state
 * back through a read-only tool rather than trusting the write handler's
 * own return value.
 *
 * "The handler returned success" and "the site now has a Contact page" are
 * different claims. 1.1's real-WordPress testing found two bugs where the
 * first was true and the second was not, which is why this is a first-class
 * part of the plan model rather than an optional nicety.
 */
interface PlanVerifierInterface {

	public function verify( PlanAction $action, ActionState $state, int $userId ): VerificationResult;
}

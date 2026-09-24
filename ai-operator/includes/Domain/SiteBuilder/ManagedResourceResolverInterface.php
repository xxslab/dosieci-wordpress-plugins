<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * Decides, per logical resource, whether the planner should create,
 * reuse, update or flag a conflict.
 *
 * Consulted at PLAN time, not execution time, so the decision is visible
 * in the plan the human approves. Resolving at execution time instead
 * would mean the user approved "create a Kontakt page" and the system
 * quietly did something else -- exactly the post-approval semantic change
 * the plan hash exists to prevent.
 */
interface ManagedResourceResolverInterface {

	public function resolve( ManagedResource $resource, string $projectId, string $desiredContent ): ResourceResolution;

	/**
	 * Records that this resource is now ours.
	 *
	 * Two different facts get stored, because two different questions are
	 * asked of them later:
	 *
	 * - `$storedContent` is what WordPress actually holds after this step.
	 *   Comparing it against the page later is how a HUMAN EDIT is detected,
	 *   so it must reflect the real final state including anything a
	 *   subsequent builder step appended.
	 * - `$authoredContent` is what the planner asked for. Comparing the
	 *   next plan's desired content against THIS is how "nothing to do" is
	 *   recognised. Comparing it against the stored content instead would
	 *   permanently mis-read every page a later builder step also writes to
	 *   -- the contact page, whose form shortcode is appended after the page
	 *   is written, was rewritten on every single run for exactly that
	 *   reason.
	 *
	 * A step that writes to a resource without authoring its content (the
	 * form embed) passes null, leaving the authored fingerprint alone while
	 * still refreshing the stored one.
	 */
	public function markManaged(
		ManagedResource $resource,
		string $projectId,
		int $objectId,
		string $storedContent,
		?string $authoredContent = null
	): void;
}

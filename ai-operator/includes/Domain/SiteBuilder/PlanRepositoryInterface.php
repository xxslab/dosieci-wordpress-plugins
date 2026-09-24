<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * Server-side plan storage.
 *
 * The existence of this interface is itself a security property: the
 * executor loads the plan it is about to run from HERE, never from the
 * request body. A browser can say "run plan X step 3"; it cannot say what
 * plan X's step 3 actually is.
 *
 * Implementations must scope reads by owner where the caller asks for it --
 * see findForUser() -- rather than leaving cross-user access to be caught
 * later by PlanRecord::assertExecutable(). Two independent checks, same
 * reason as everywhere else in this plugin.
 */
interface PlanRepositoryInterface {

	public function save( PlanRecord $record ): void;

	public function find( string $planId ): ?PlanRecord;

	/**
	 * Loads a plan only if it belongs to this user. Returns null for both
	 * "no such plan" and "not yours", deliberately indistinguishable: a
	 * probe must not be able to enumerate other users' plan ids.
	 */
	public function findForUser( string $planId, int $userId ): ?PlanRecord;

	/** @return PlanRecord[] newest first */
	public function listForUser( int $userId, int $limit = 20 ): array;

	public function delete( string $planId ): void;
}

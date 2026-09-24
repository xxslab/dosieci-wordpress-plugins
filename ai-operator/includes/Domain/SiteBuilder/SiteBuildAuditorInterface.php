<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * The final, independent read-only check that the site the blueprint
 * described actually exists -- run once, after every step has passed.
 *
 * Separate from PlanVerifierInterface because it asks a different
 * question: not "did this step work?" but "is the whole result right?".
 */
interface SiteBuildAuditorInterface {

	public function audit( SiteBlueprint $blueprint, int $now ): SiteAuditReport;
}

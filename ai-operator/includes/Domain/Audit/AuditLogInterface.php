<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Audit;

interface AuditLogInterface {

	public function record( AuditEntry $entry ): void;

	/** @return AuditEntry[] newest first */
	public function recent( int $limit = 50 ): array;
}

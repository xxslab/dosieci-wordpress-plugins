<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Support;

use DoSieci\AiOperator\Domain\Audit\AuditEntry;
use DoSieci\AiOperator\Domain\Audit\AuditLogInterface;

final class InMemoryAuditLog implements AuditLogInterface {

	/** @var AuditEntry[] */
	public array $entries = array();

	public function record( AuditEntry $entry ): void {
		$this->entries[] = $entry;
	}

	public function recent( int $limit = 50 ): array {
		return array_slice( array_reverse( $this->entries ), 0, $limit );
	}

	public function last(): ?AuditEntry {
		return array() === $this->entries ? null : $this->entries[ count( $this->entries ) - 1 ];
	}
}

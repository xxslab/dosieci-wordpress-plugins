<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Support;

use DoSieci\AiOperator\Domain\SiteBuilder\PlanRecord;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanRepositoryInterface;

final class InMemoryPlanRepository implements PlanRepositoryInterface {

	/** @var array<string, PlanRecord> */
	private array $records = array();

	public int $saveCount = 0;

	public function save( PlanRecord $record ): void {
		++$this->saveCount;
		$this->records[ $record->plan->planId ] = $record;
	}

	public function find( string $planId ): ?PlanRecord {
		return $this->records[ $planId ] ?? null;
	}

	public function findForUser( string $planId, int $userId ): ?PlanRecord {
		$record = $this->find( $planId );

		if ( null === $record || $record->plan->ownerUserId !== $userId ) {
			return null;
		}

		return $record;
	}

	public function listForUser( int $userId, int $limit = 20 ): array {
		$out = array();

		foreach ( $this->records as $record ) {
			if ( $record->plan->ownerUserId === $userId ) {
				$out[] = $record;
			}
		}

		return array_slice( array_reverse( $out ), 0, $limit );
	}

	public function delete( string $planId ): void {
		unset( $this->records[ $planId ] );
	}
}

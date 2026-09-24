<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Support;

use DoSieci\AiOperator\Domain\Tools\CapabilityCheckerInterface;

final class FakeCapabilityChecker implements CapabilityCheckerInterface {

	/** @param string[] $granted */
	public function __construct( private array $granted = array() ) {
	}

	public static function allowingEverything(): self {
		return new self( array( '*' ) );
	}

	public function currentUserCan( string $capability ): bool {
		return in_array( '*', $this->granted, true ) || in_array( $capability, $this->granted, true );
	}
}

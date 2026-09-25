<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * The result of the independent read-only audit run after every step of a
 * plan has individually passed.
 *
 * Per-step verification answers "did step 7 do what step 7 said?". This
 * answers a different and larger question: "is the site the blueprint
 * described actually there?" Those can diverge -- every step can pass while
 * a later step silently undoes an earlier one, or while something outside
 * the plan (another plugin, a theme's activation hook) changes the result.
 */
final class SiteAuditReport {

	/** @param array<int, array{check:string, passed:bool, detail:string}> $checks */
	private function __construct(
		public readonly bool $passed,
		public readonly array $checks,
		public readonly int $auditedAt
	) {
	}

	/** @param array<int, array{check:string, passed:bool, detail:string}> $checks */
	public static function fromChecks( array $checks, int $auditedAt ): self {
		$passed = true;

		foreach ( $checks as $check ) {
			if ( true !== ( $check['passed'] ?? false ) ) {
				$passed = false;
				break;
			}
		}

		return new self( $passed, array_values( $checks ), $auditedAt );
	}

	/** @return array<int, array{check:string, passed:bool, detail:string}> */
	public function failures(): array {
		return array_values(
			array_filter( $this->checks, static fn( array $c ): bool => true !== ( $c['passed'] ?? false ) )
		);
	}

	public function summary(): string {
		$total  = count( $this->checks );
		$failed = count( $this->failures() );

		if ( 0 === $failed ) {
			return sprintf(
				/* translators: %d: total number of checks, repeated as both passed and total */
				__( 'Final audit: %1$d/%2$d checks passed.', 'dosieci-ai-operator' ),
				$total,
				$total
			);
		}

		return sprintf(
			/* translators: 1: number of failed checks, 2: total number of checks, 3: semicolon-separated list of failure details */
			__( 'Final audit: %1$d of %2$d checks failed — %3$s', 'dosieci-ai-operator' ),
			$failed,
			$total,
			implode( '; ', array_map( static fn( array $c ): string => (string) $c['detail'], $this->failures() ) )
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'audited_at' => $this->auditedAt,
			'checks'     => $this->checks,
			'passed'     => $this->passed,
		);
	}

	/** @param array<string, mixed> $raw */
	public static function fromArray( array $raw ): self {
		return new self(
			true === ( $raw['passed'] ?? false ),
			is_array( $raw['checks'] ?? null ) ? $raw['checks'] : array(),
			(int) ( $raw['audited_at'] ?? 0 )
		);
	}
}

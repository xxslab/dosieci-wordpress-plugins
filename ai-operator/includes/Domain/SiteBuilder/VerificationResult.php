<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * Outcome of independently re-reading WordPress state after a write.
 *
 * Carries expected/actual so a failure is diagnosable from the audit trail
 * alone -- "menu has 0 items, expected 4" is actionable, "verification
 * failed" is not.
 *
 * These are deliberately SMALL structured summaries, never raw WP_Post or
 * WP_Theme objects: they are persisted per step, and dumping whole
 * WordPress objects into the plan table would bloat it and risk carrying
 * fields nobody audited.
 */
final class VerificationResult {

	/**
	 * @param array<string, mixed>|null $expected
	 * @param array<string, mixed>|null $actual
	 */
	private function __construct(
		public readonly bool $passed,
		public readonly string $detail,
		public readonly ?array $expected = null,
		public readonly ?array $actual = null
	) {
	}

	/**
	 * @param array<string, mixed>|null $expected
	 * @param array<string, mixed>|null $actual
	 */
	public static function passed( string $detail = '', ?array $expected = null, ?array $actual = null ): self {
		return new self( true, $detail, $expected, $actual );
	}

	/**
	 * @param array<string, mixed>|null $expected
	 * @param array<string, mixed>|null $actual
	 */
	public static function failed( string $detail, ?array $expected = null, ?array $actual = null ): self {
		return new self( false, $detail, $expected, $actual );
	}
}

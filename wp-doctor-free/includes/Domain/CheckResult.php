<?php

declare(strict_types=1);

namespace DoSieci\WP\Doctor\Domain;

/**
 * The outcome of one diagnostic check.
 *
 * Every result carries an explanation and a recommendation, not just a
 * pass/fail flag. An audit that says "FAIL: autoload" without saying what
 * that means or what to do about it is a scoreboard, not a diagnosis --
 * and PRODUCT_SCOPE.md's positioning for this product is explicitly
 * "wyjaśnialny audyt techniczny".
 */
final class CheckResult {

	public const STATUS_GOOD     = 'good';
	public const STATUS_WARNING  = 'warning';
	public const STATUS_CRITICAL = 'critical';
	public const STATUS_INFO     = 'info';

	/**
	 * @param array<string, mixed> $details
	 */
	public function __construct(
		public readonly string $checkId,
		public readonly string $label,
		public readonly string $status,
		public readonly string $summary,
		public readonly string $recommendation = '',
		public readonly array $details = array()
	) {
	}

	public function isProblem(): bool {
		return in_array( $this->status, array( self::STATUS_WARNING, self::STATUS_CRITICAL ), true );
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'check_id'       => $this->checkId,
			'label'          => $this->label,
			'status'         => $this->status,
			'summary'        => $this->summary,
			'recommendation' => $this->recommendation,
			'details'        => $this->details,
		);
	}
}

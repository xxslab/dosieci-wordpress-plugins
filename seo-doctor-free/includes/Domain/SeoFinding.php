<?php

declare(strict_types=1);

namespace DoSieci\SEO\Doctor\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SeoFinding {

	public const SEVERITY_OK       = 'ok';
	public const SEVERITY_WARNING  = 'warning';
	public const SEVERITY_CRITICAL = 'critical';

	/** @param array<string, mixed> $details */
	public function __construct(
		public readonly string $id,
		public readonly string $severity,
		public readonly string $summary,
		public readonly string $recommendation,
		public readonly array $details = array()
	) {
	}

	public function isProblem(): bool {
		return self::SEVERITY_OK !== $this->severity;
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\Clean\Urls\Domain;

/**
 * One proposed old -> new slug change, with everything the preflight needs
 * to decide whether it is safe.
 */
final class UrlChange {

	public const SEVERITY_OK       = 'ok';
	public const SEVERITY_WARNING  = 'warning';
	public const SEVERITY_BLOCKER  = 'blocker';

	public function __construct(
		public readonly int $postId,
		public readonly string $title,
		public readonly string $currentSlug,
		public readonly string $proposedSlug,
		public readonly string $severity = self::SEVERITY_OK,
		public readonly ?string $issue = null
	) {
	}

	public function isChange(): bool {
		return $this->currentSlug !== $this->proposedSlug && '' !== $this->proposedSlug;
	}

	public function isApplicable(): bool {
		return $this->isChange() && self::SEVERITY_BLOCKER !== $this->severity;
	}

	public function withIssue( string $severity, string $issue ): self {
		return new self( $this->postId, $this->title, $this->currentSlug, $this->proposedSlug, $severity, $issue );
	}
}

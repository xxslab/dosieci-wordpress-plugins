<?php

declare(strict_types=1);

namespace DoSieci\Clean\Urls\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One proposed old -> new slug change, with everything the preflight needs
 * to decide whether it is safe.
 */
final class UrlChange {

	public const SEVERITY_OK      = 'ok';
	public const SEVERITY_BLOCKER = 'blocker';

	public function __construct(
		public readonly int $postId,
		public readonly string $title,
		public readonly string $currentSlug,
		public readonly string $proposedSlug,
		public readonly string $severity = self::SEVERITY_OK,
		public readonly ?string $issue = null,
		public readonly int $parentId = 0
	) {
	}

	public function isChange(): bool {
		return $this->currentSlug !== $this->proposedSlug && '' !== $this->proposedSlug;
	}

	public function isApplicable(): bool {
		return $this->isChange() && self::SEVERITY_BLOCKER !== $this->severity;
	}

	/**
	 * Whether the current slug is already plain lowercase ASCII (letters,
	 * digits, hyphens, underscores). A clean slug that merely differs from
	 * the title is usually a deliberate, hand-picked address, so it is not
	 * pre-selected for a rename; percent-encoded, non-ASCII slugs are.
	 */
	public function isCurrentSlugClean(): bool {
		return 1 === preg_match( '/^[a-z0-9_-]+$/', $this->currentSlug );
	}

	public function withIssue( string $severity, string $issue ): self {
		return new self( $this->postId, $this->title, $this->currentSlug, $this->proposedSlug, $severity, $issue, $this->parentId );
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * What the planner should do about one logical resource, decided by
 * looking at what is already on the site.
 *
 * Four outcomes, and the distinctions between them are the whole point:
 *
 *  CREATE          nothing suitable exists -- make it.
 *  REUSE           something exists that we should point at but NOT
 *                  rewrite. Either it is ours and unchanged in a way that
 *                  needs no update, or it is the user's and we are only
 *                  linking to it.
 *  UPDATE_MANAGED  we created it, it still carries our fingerprint, and
 *                  the blueprint now wants different content. Safe to
 *                  rewrite, because nobody else has touched it.
 *  CONFLICT        we created it and a HUMAN has since edited it, or an
 *                  unmanaged page occupies the role. Rewriting would
 *                  destroy someone's work, so the plan surfaces it instead
 *                  of guessing.
 *
 * A conflict is not a failure. It is the system declining to make a
 * decision that belongs to the user, and it is visible in the plan before
 * approval -- which is the only place that choice can honestly be made.
 */
final class ResourceResolution {

	public const CREATE         = 'create';
	public const REUSE          = 'reuse';
	public const UPDATE_MANAGED = 'update_managed';
	public const CONFLICT       = 'conflict';

	private function __construct(
		public readonly string $decision,
		public readonly string $resourceKey,
		public readonly ?int $existingId,
		public readonly string $reason,
		public readonly bool $managedByUs
	) {
	}

	public static function create( string $resourceKey, string $reason = '' ): self {
		return new self( self::CREATE, $resourceKey, null, $reason, false );
	}

	public static function reuse( string $resourceKey, int $existingId, string $reason, bool $managedByUs ): self {
		return new self( self::REUSE, $resourceKey, $existingId, $reason, $managedByUs );
	}

	public static function updateManaged( string $resourceKey, int $existingId, string $reason = '' ): self {
		return new self( self::UPDATE_MANAGED, $resourceKey, $existingId, $reason, true );
	}

	public static function conflict( string $resourceKey, ?int $existingId, string $reason ): self {
		return new self( self::CONFLICT, $resourceKey, $existingId, $reason, false );
	}

	public function isCreate(): bool {
		return self::CREATE === $this->decision;
	}

	public function isConflict(): bool {
		return self::CONFLICT === $this->decision;
	}

	/** Whether this resolution points at something that already exists. */
	public function hasExisting(): bool {
		return null !== $this->existingId && $this->existingId > 0;
	}

	/**
	 * The sentence shown in the plan before approval.
	 *
	 * Every resolution that touches or points at existing content says so
	 * explicitly. "Reuse existing page id 42" and "update the page we
	 * created earlier" are materially different promises, and the human is
	 * approving one of them.
	 */
	public function describe( string $subject ): string {
		return match ( $this->decision ) {
			self::CREATE         => sprintf( 'Utwórz „%s”.', $subject ),
			self::REUSE          => sprintf( 'Użyj istniejącej strony „%s” (id %d) bez zmiany treści.', $subject, (int) $this->existingId ),
			self::UPDATE_MANAGED => sprintf( 'Zaktualizuj „%s” (id %d) utworzoną wcześniej przez kreator.', $subject, (int) $this->existingId ),
			self::CONFLICT       => sprintf( 'Konflikt: „%s” (id %d) została zmieniona ręcznie — kreator jej nie nadpisze.', $subject, (int) $this->existingId ),
			default              => $subject,
		};
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * The mutable runtime record for one plan action: what happened when it
 * ran, and what would be needed to undo it.
 *
 * Kept strictly separate from PlanAction (the immutable approved
 * definition) so that the thing the human approved and the thing the
 * executor scribbles on are different objects. Folding them together is
 * how an approved argument list ends up quietly overwritten by a runtime
 * result.
 *
 * $rollbackData is captured BEFORE the write runs, not after -- an
 * update_post that has already replaced the old title cannot report what
 * the old title was.
 */
final class ActionState {

	public const PENDING         = 'pending';
	public const RUNNING         = 'running';
	public const SUCCEEDED       = 'succeeded';
	public const FAILED          = 'failed';
	public const SKIPPED         = 'skipped';
	public const ROLLED_BACK     = 'rolled_back';
	public const ROLLBACK_FAILED = 'rollback_failed';

	/**
	 * @param array<string, mixed>|null $result
	 * @param array<string, mixed>|null $rollbackData
	 */
	public function __construct(
		public readonly string $actionId,
		public string $status = self::PENDING,
		public ?int $startedAt = null,
		public ?int $finishedAt = null,
		public ?array $result = null,
		public ?string $error = null,
		public ?array $rollbackData = null,
		public ?string $verificationStatus = null,
		public ?string $verificationDetail = null,
		/** @var array<string, mixed>|null what the plan expected to find */
		public ?array $verificationExpected = null,
		/** @var array<string, mixed>|null what WordPress actually reported */
		public ?array $verificationActual = null,
		public ?int $verifiedAt = null,
		/**
		 * The arguments actually dispatched, after resolvers filled in ids
		 * that could not exist at plan time. Verifiers must compare against
		 * THESE, not the plan's literal arguments -- a resolver-driven
		 * page_id is 0 in the plan and only becomes real here.
		 *
		 * @var array<string, mixed>|null
		 */
		public ?array $resolvedArguments = null
	) {
	}

	public function isTerminal(): bool {
		return in_array(
			$this->status,
			array( self::SUCCEEDED, self::FAILED, self::SKIPPED, self::ROLLED_BACK, self::ROLLBACK_FAILED ),
			true
		);
	}

	/** Only a step that actually succeeded has anything worth undoing. */
	public function isRollbackCandidate(): bool {
		return self::SUCCEEDED === $this->status;
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'action_id'           => $this->actionId,
			'error'               => $this->error,
			'finished_at'         => $this->finishedAt,
			'result'              => $this->result,
			'rollback_data'       => $this->rollbackData,
			'started_at'          => $this->startedAt,
			'status'              => $this->status,
			'verification_actual'   => $this->verificationActual,
			'verification_detail'   => $this->verificationDetail,
			'verification_expected' => $this->verificationExpected,
			'verification_status'   => $this->verificationStatus,
			'resolved_arguments'    => $this->resolvedArguments,
			'verified_at'           => $this->verifiedAt,
		);
	}

	/** @param array<string, mixed> $raw */
	public static function fromArray( array $raw ): self {
		return new self(
			(string) ( $raw['action_id'] ?? '' ),
			(string) ( $raw['status'] ?? self::PENDING ),
			isset( $raw['started_at'] ) ? (int) $raw['started_at'] : null,
			isset( $raw['finished_at'] ) ? (int) $raw['finished_at'] : null,
			is_array( $raw['result'] ?? null ) ? $raw['result'] : null,
			isset( $raw['error'] ) && null !== $raw['error'] ? (string) $raw['error'] : null,
			is_array( $raw['rollback_data'] ?? null ) ? $raw['rollback_data'] : null,
			isset( $raw['verification_status'] ) && null !== $raw['verification_status'] ? (string) $raw['verification_status'] : null,
			isset( $raw['verification_detail'] ) && null !== $raw['verification_detail'] ? (string) $raw['verification_detail'] : null,
			is_array( $raw['verification_expected'] ?? null ) ? $raw['verification_expected'] : null,
			is_array( $raw['verification_actual'] ?? null ) ? $raw['verification_actual'] : null,
			isset( $raw['verified_at'] ) ? (int) $raw['verified_at'] : null,
			is_array( $raw['resolved_arguments'] ?? null ) ? $raw['resolved_arguments'] : null
		);
	}
}

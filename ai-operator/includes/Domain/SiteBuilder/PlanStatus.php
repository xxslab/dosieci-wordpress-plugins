<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * The plan lifecycle, as an explicit transition table rather than scattered
 * `if ( 'running' === $status )` checks.
 *
 * This is a security boundary, not bookkeeping. "Can this plan execute a
 * step right now?" has exactly one answer and it comes from here -- so a
 * cancelled plan cannot be resumed by replaying an old AJAX request, an
 * unapproved plan cannot execute at all, and a finished plan cannot be
 * re-run to duplicate its writes. Every transition an attacker would want
 * is simply absent from the table below.
 */
final class PlanStatus {

	public const DRAFT                  = 'draft';
	public const AWAITING_APPROVAL      = 'awaiting_approval';
	public const APPROVED               = 'approved';
	public const RUNNING                = 'running';
	public const PAUSED                 = 'paused';
	public const SUCCEEDED              = 'succeeded';
	public const FAILED                 = 'failed';
	public const CANCELLED              = 'cancelled';
	public const ROLLING_BACK           = 'rolling_back';
	public const ROLLED_BACK            = 'rolled_back';
	public const PARTIALLY_ROLLED_BACK  = 'partially_rolled_back';

	/**
	 * from => [allowed next states]. Anything not listed is refused.
	 *
	 * @var array<string, string[]>
	 */
	private const TRANSITIONS = array(
		self::DRAFT                 => array( self::AWAITING_APPROVAL, self::CANCELLED ),
		self::AWAITING_APPROVAL     => array( self::APPROVED, self::CANCELLED ),
		// APPROVED -> RUNNING is the only way execution ever starts.
		self::APPROVED              => array( self::RUNNING, self::CANCELLED ),
		self::RUNNING               => array( self::PAUSED, self::SUCCEEDED, self::FAILED, self::CANCELLED ),
		self::PAUSED                => array( self::RUNNING, self::CANCELLED ),
		// Terminal-but-rollbackable: the writes already happened, so undoing
		// them stays available; re-running them does not.
		self::SUCCEEDED             => array( self::ROLLING_BACK ),
		self::FAILED                => array( self::ROLLING_BACK ),
		self::CANCELLED             => array( self::ROLLING_BACK ),
		self::ROLLING_BACK          => array( self::ROLLED_BACK, self::PARTIALLY_ROLLED_BACK ),
		// Fully terminal.
		self::ROLLED_BACK           => array(),
		self::PARTIALLY_ROLLED_BACK => array(),
	);

	/** States in which the executor may run a next step. */
	private const EXECUTABLE = array( self::APPROVED, self::RUNNING );

	private function __construct() {
	}

	public static function isValid( string $status ): bool {
		return array_key_exists( $status, self::TRANSITIONS );
	}

	public static function canTransition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	/** @throws PlanStateException */
	public static function assertTransition( string $from, string $to ): void {
		if ( ! self::canTransition( $from, $to ) ) {
			throw new PlanStateException(
				sprintf( 'Cannot move a plan from “%s” to “%s”.', esc_html( $from ), esc_html( $to ) )
			);
		}
	}

	/**
	 * Whether the executor is allowed to run a further step. Note PAUSED is
	 * deliberately absent: pausing must actually stop work, not merely
	 * relabel it.
	 */
	public static function isExecutable( string $status ): bool {
		return in_array( $status, self::EXECUTABLE, true );
	}

	public static function isTerminal( string $status ): bool {
		return array() === ( self::TRANSITIONS[ $status ] ?? array() );
	}
}

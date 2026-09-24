<?php

declare(strict_types=1);

namespace DoSieci\Clean\Urls\Domain;

/**
 * The preflight step of the Safe Migration Engine (PRODUCT_SCOPE.md): work
 * out which proposed slug changes are safe BEFORE anything is written.
 *
 * Three classes of problem are detected:
 *  - reserved slug        -> blocker (the URL would be unreachable)
 *  - empty slug           -> blocker (nothing sensible to redirect to)
 *  - duplicate target     -> blocker for all but the first claimant, because
 *                            two posts cannot own one URL and WordPress
 *                            would silently append "-2", producing a URL
 *                            nobody asked for and a redirect that points at
 *                            the wrong page
 *  - collides with an existing, unchanged post's slug -> blocker
 *
 * Nothing here writes. The scanner's whole job is to be run and read before
 * the operator decides to apply anything.
 */
final class CollisionScanner {

	public function __construct( private SlugNormalizer $normalizer ) {
	}

	/**
	 * @param UrlChange[]           $proposed
	 * @param array<string, int>    $existingSlugs slug => post id, for posts NOT in $proposed
	 *
	 * @return UrlChange[] same order, annotated with severity/issue
	 */
	public function scan( array $proposed, array $existingSlugs ): array {
		$claimed  = array();
		$reviewed = array();

		foreach ( $proposed as $change ) {
			if ( ! $change->isChange() ) {
				$reviewed[] = $change;
				continue;
			}

			if ( '' === $change->proposedSlug ) {
				$reviewed[] = $change->withIssue( UrlChange::SEVERITY_BLOCKER, 'Wygenerowany slug jest pusty.' );
				continue;
			}

			if ( $this->normalizer->isReserved( $change->proposedSlug ) ) {
				$reviewed[] = $change->withIssue( UrlChange::SEVERITY_BLOCKER, 'Slug jest zarezerwowany przez WordPressa.' );
				continue;
			}

			if ( isset( $existingSlugs[ $change->proposedSlug ] ) && $existingSlugs[ $change->proposedSlug ] !== $change->postId ) {
				$reviewed[] = $change->withIssue(
					UrlChange::SEVERITY_BLOCKER,
					sprintf( 'Slug jest już zajęty przez wpis #%d.', $existingSlugs[ $change->proposedSlug ] )
				);
				continue;
			}

			if ( isset( $claimed[ $change->proposedSlug ] ) ) {
				$reviewed[] = $change->withIssue(
					UrlChange::SEVERITY_BLOCKER,
					sprintf( 'Ten sam slug proponowany również dla wpisu #%d.', $claimed[ $change->proposedSlug ] )
				);
				continue;
			}

			$claimed[ $change->proposedSlug ] = $change->postId;
			$reviewed[]                       = $change;
		}

		return $reviewed;
	}

	/**
	 * @param UrlChange[] $changes
	 *
	 * @return array{total:int, applicable:int, blockers:int, unchanged:int}
	 */
	public function summarise( array $changes ): array {
		$applicable = 0;
		$blockers   = 0;
		$unchanged  = 0;

		foreach ( $changes as $change ) {
			if ( ! $change->isChange() ) {
				++$unchanged;
			} elseif ( UrlChange::SEVERITY_BLOCKER === $change->severity ) {
				++$blockers;
			} else {
				++$applicable;
			}
		}

		return array(
			'total'      => count( $changes ),
			'applicable' => $applicable,
			'blockers'   => $blockers,
			'unchanged'  => $unchanged,
		);
	}
}

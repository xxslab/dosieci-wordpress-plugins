<?php

declare(strict_types=1);

namespace DoSieci\Clean\Urls\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The preflight: work out which proposed slug changes are safe BEFORE
 * anything is written.
 *
 * Problems detected here, each a blocker:
 *  - empty slug       (nothing sensible to redirect to)
 *  - reserved slug    (the URL would be unreachable)
 *  - taken slug       (collides with a post that is not being renamed)
 *  - duplicate target (two proposals want the same URL; WordPress would
 *                      silently append "-2", producing an address nobody
 *                      asked for and a redirect to the wrong page)
 *
 * Duplicates only count among siblings: WordPress keeps slugs unique per
 * parent, so two pages under different parents may share one.
 *
 * Nothing here writes.
 */
final class CollisionScanner {

	public function __construct( private SlugNormalizer $normalizer ) {
	}

	/**
	 * @param UrlChange[]        $proposed
	 * @param array<string, int> $existingSlugs "parentId/slug" => post id, for posts NOT in $proposed
	 *
	 * @return UrlChange[] same order, annotated with severity/issue
	 */
	public function scan( array $proposed, array $existingSlugs = array() ): array {
		$claimed  = array();
		$reviewed = array();

		foreach ( $proposed as $change ) {
			if ( ! $change->isChange() ) {
				$reviewed[] = $change;
				continue;
			}

			$key = self::key( $change->parentId, $change->proposedSlug );

			if ( '' === $change->proposedSlug ) {
				$reviewed[] = $change->withIssue( UrlChange::SEVERITY_BLOCKER, __( 'The generated slug is empty.', 'dosieci-clean-urls' ) );
				continue;
			}

			if ( $this->normalizer->isReserved( $change->proposedSlug ) ) {
				$reviewed[] = $change->withIssue( UrlChange::SEVERITY_BLOCKER, __( 'WordPress reserves this slug.', 'dosieci-clean-urls' ) );
				continue;
			}

			if ( isset( $existingSlugs[ $key ] ) && $existingSlugs[ $key ] !== $change->postId ) {
				$reviewed[] = $change->withIssue(
					UrlChange::SEVERITY_BLOCKER,
					/* translators: %d: post ID */
					sprintf( __( 'The slug is already used by post #%d.', 'dosieci-clean-urls' ), $existingSlugs[ $key ] )
				);
				continue;
			}

			if ( isset( $claimed[ $key ] ) ) {
				$reviewed[] = $change->withIssue(
					UrlChange::SEVERITY_BLOCKER,
					/* translators: %d: post ID */
					sprintf( __( 'The same slug is also proposed for post #%d.', 'dosieci-clean-urls' ), $claimed[ $key ] )
				);
				continue;
			}

			$claimed[ $key ] = $change->postId;
			$reviewed[]      = $change;
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

	public static function key( int $parentId, string $slug ): string {
		return $parentId . '/' . $slug;
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Media;

/**
 * Read access to the images the site already has.
 *
 * ## Library first, always
 *
 * A site being built usually already owns the right pictures: the
 * business's own photos, its logo, previous uploads. Fetching a generic
 * stock photo of a stranger in a hard hat when the user's own workshop
 * photo is sitting in the library is a worse result, and it is the result
 * that costs a network call, a licence question and a download.
 *
 * So the builder searches here first, and only a configured stock
 * provider (of which none ships -- see StockImageProviderInterface) can
 * ever introduce an image from outside.
 */
interface MediaLibraryInterface {

	/**
	 * Images matching a free-text query, most relevant first.
	 *
	 * @return MediaCandidate[]
	 */
	public function search( string $query, int $limit = 20 ): array;

	/** Every usable image, for the case where no query is meaningful. */
	/** @return MediaCandidate[] */
	public function all( int $limit = 50 ): array;

	public function find( int $attachmentId ): ?MediaCandidate;

	/**
	 * The attachment currently illustrating a page, or 0 for none.
	 *
	 * The planner uses this to leave alone any page that already has a
	 * picture. Which image belongs on a page is a choice a person may have
	 * made deliberately -- and unlike page content, a swapped featured
	 * image leaves no trace in the content fingerprint, so there is no
	 * later signal that would let a rebuild tell "we put this here" from
	 * "somebody chose this". Only ever adding one where none exists is the
	 * rule that cannot get that wrong.
	 */
	public function featuredImageId( int $pageId ): int;
}

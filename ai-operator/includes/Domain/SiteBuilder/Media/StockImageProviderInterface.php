<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Media;

/**
 * A source of images from outside this site.
 *
 * ## Nothing implements this yet, on purpose
 *
 * The only implementation that ships is NullStockImageProvider, which is
 * never configured and returns nothing. The builder therefore cannot
 * download anything: the images it can use are exactly the images already
 * in the site's own media library.
 *
 * That is a deliberate state, not an oversight. A real provider brings a
 * credential to store, a licence obligation to honour, and an outbound
 * request from inside customer hosting to an address chosen elsewhere --
 * three separate decisions, none of which should be made implicitly by
 * merging a convenience feature.
 *
 * The seam exists now so those decisions land in one place when they are
 * made, and so the constraints they must satisfy (RemoteImagePolicy,
 * enforced in StockImage's constructor) are written down and executable
 * rather than remembered.
 *
 * ## What an implementation must do
 *
 * - Return StockImage objects, which refuse to exist for a URL that fails
 *   RemoteImagePolicy.
 * - Treat every field of the provider's response as untrusted input.
 * - Never put its credential in a URL a browser will see.
 * - Leave downloading to a caller that re-checks the resolved IP before
 *   connecting, refuses redirects, and stops at RemoteImagePolicy::MAX_BYTES.
 */
interface StockImageProviderInterface {

	/**
	 * False when no credential is configured, which is the shipping state.
	 * The planner must not emit a step that depends on a provider that
	 * would fail at execution time.
	 */
	public function isConfigured(): bool;

	/**
	 * @return StockImage[]
	 *
	 * @throws \RuntimeException when the provider is reachable but failing
	 */
	public function search( string $query, int $limit = 10 ): array;

	/** Shown to the user so they know where an image would come from. */
	public function label(): string;
}

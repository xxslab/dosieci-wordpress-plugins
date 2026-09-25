<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Media;

/**
 * One image a stock provider offers, before anything has been downloaded.
 *
 * ## The constructor is the security boundary
 *
 * RemoteImagePolicy runs here rather than in a downloader, so a candidate
 * whose URL points anywhere it should not cannot be represented as an
 * object. A provider implementation that tries to return one gets an
 * exception at construction; there is no later stage where the check can
 * be forgotten or skipped for convenience.
 *
 * ## Attribution is not optional
 *
 * Most stock sources require a credit line, and a builder that silently
 * drops it exposes the site owner to a licence problem they never agreed
 * to. So author and licence are constructor arguments, not a nicety a
 * provider may omit.
 */
final class StockImage {

	public function __construct(
		public readonly string $url,
		public readonly string $description,
		public readonly string $author,
		public readonly string $licence,
		public readonly int $width = 0,
		public readonly int $height = 0,
		?RemoteImagePolicy $policy = null
	) {
		$reason = ( $policy ?? new RemoteImagePolicy() )->rejectionReason( $url );

		if ( null !== $reason ) {
			// $reason is translatable text from RemoteImagePolicy; escaped
			// here because it is dynamic from this class's own point of view.
			throw new \InvalidArgumentException( esc_html( $reason ) );
		}

		if ( '' === trim( $licence ) ) {
			throw new \InvalidArgumentException( 'A remote-source image must declare a licence.' );
		}
	}

	/** The credit line to store on the attachment once one is downloaded. */
	public function attribution(): string {
		return '' !== $this->author
			? sprintf( '%s (%s)', $this->author, $this->licence )
			: $this->licence;
	}
}

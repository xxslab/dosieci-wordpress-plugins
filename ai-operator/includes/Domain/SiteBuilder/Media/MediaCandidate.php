<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Media;

/**
 * One image already present in the WordPress media library.
 *
 * Deliberately a flat value object rather than a WP_Post: the matcher and
 * the planner are domain code and must stay testable without WordPress,
 * and everything a match decision needs is a handful of strings.
 */
final class MediaCandidate {

	public function __construct(
		public readonly int $attachmentId,
		public readonly string $title,
		public readonly string $filename,
		public readonly string $altText,
		public readonly string $mimeType,
		public readonly int $width,
		public readonly int $height,
		public readonly string $url
	) {
	}

	/**
	 * Whether this is a raster image worth putting on a page.
	 *
	 * SVG is excluded on purpose: it is a script-capable format, and an
	 * SVG somebody uploaded is not something the builder should promote
	 * into a page it generated.
	 */
	public function isUsableImage(): bool {
		return in_array(
			$this->mimeType,
			array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ),
			true
		);
	}

	/** What the human reads in the approval list. */
	public function label(): string {
		return '' !== $this->title ? $this->title : $this->filename;
	}

	/** @param array<string, mixed> $raw */
	public static function fromArray( array $raw ): self {
		return new self(
			(int) ( $raw['attachment_id'] ?? 0 ),
			(string) ( $raw['title'] ?? '' ),
			(string) ( $raw['filename'] ?? '' ),
			(string) ( $raw['alt_text'] ?? '' ),
			(string) ( $raw['mime_type'] ?? '' ),
			(int) ( $raw['width'] ?? 0 ),
			(int) ( $raw['height'] ?? 0 ),
			(string) ( $raw['url'] ?? '' )
		);
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return array(
			'attachment_id' => $this->attachmentId,
			'title'         => $this->title,
			'filename'      => $this->filename,
			'alt_text'      => $this->altText,
			'mime_type'     => $this->mimeType,
			'width'         => $this->width,
			'height'        => $this->height,
			'url'           => $this->url,
		);
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\Media\MediaCandidate;
use DoSieci\AiOperator\Domain\SiteBuilder\Media\MediaLibraryInterface;

/**
 * The site's own media library, read through WordPress's own APIs.
 *
 * Read-only by construction: this class has no write path at all, so
 * "search the library" can never become "change the library".
 */
final class WpMediaLibrary implements MediaLibraryInterface {

	/** @return MediaCandidate[] */
	public function search( string $query, int $limit = 20 ): array {
		return $this->query( array( 's' => $query ), $limit );
	}

	/** @return MediaCandidate[] */
	public function all( int $limit = 50 ): array {
		return $this->query( array(), $limit );
	}

	public function find( int $attachmentId ): ?MediaCandidate {
		$post = get_post( $attachmentId );

		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}

		return $this->candidate( $post );
	}

	public function featuredImageId( int $pageId ): int {
		return $pageId > 0 ? (int) get_post_thumbnail_id( $pageId ) : 0;
	}

	/**
	 * @param array<string, mixed> $extra
	 *
	 * @return MediaCandidate[]
	 */
	private function query( array $extra, int $limit ): array {
		$posts = get_posts(
			array_merge(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					// Only raster images reach the domain layer. The mime
					// filter is applied by WordPress, so an SVG cannot slip
					// through on a site that allows uploading them.
					'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ),
					'posts_per_page' => max( 1, min( 100, $limit ) ),
					'orderby'        => 'date',
					'order'          => 'DESC',
				),
				$extra
			)
		);

		$out = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$out[] = $this->candidate( $post );
		}

		return $out;
	}

	private function candidate( \WP_Post $post ): MediaCandidate {
		$meta = wp_get_attachment_metadata( $post->ID );
		$file = get_attached_file( $post->ID );

		return new MediaCandidate(
			$post->ID,
			(string) $post->post_title,
			is_string( $file ) ? basename( $file ) : '',
			(string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			(string) get_post_mime_type( $post->ID ),
			is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0,
			is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0,
			(string) wp_get_attachment_url( $post->ID )
		);
	}
}

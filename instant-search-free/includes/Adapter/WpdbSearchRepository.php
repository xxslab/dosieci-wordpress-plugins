<?php

declare(strict_types=1);

namespace DoSieci\Instant\Search\Adapter;

use DoSieci\Instant\Search\Domain\SearchQuery;
use DoSieci\Instant\Search\Domain\SearchResult;

/**
 * Runs the actual query.
 *
 * Deliberately hand-written SQL rather than WP_Query's `s` parameter:
 * WP_Query's default search builds `LIKE '%term%'` across post_title,
 * post_excerpt AND post_content, which on a large catalogue is a full table
 * scan on the biggest column in the database on every keystroke. This
 * queries the indexed post_title with a PREFIX pattern, plus SKU via the
 * indexed postmeta lookup, and never touches post_content.
 *
 * Every value is bound through $wpdb->prepare(). Post status is restricted
 * to 'publish' so a draft/private/hidden product can never leak through the
 * public suggestions endpoint (an explicit PRODUCT_SCOPE.md requirement).
 */
final class WpdbSearchRepository {

	public function __construct( private int $maxResults = 10 ) {
	}

	/** @return SearchResult[] */
	public function search( SearchQuery $query, string $postType ): array {
		global $wpdb;

		$limit = max( 1, min( 20, $this->maxResults ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title
				 FROM {$wpdb->posts}
				 WHERE post_type = %s
				   AND post_status = 'publish'
				   AND post_title LIKE %s
				 ORDER BY post_title ASC
				 LIMIT %d",
				$postType,
				$query->prefixPattern(),
				$limit
			),
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : array();
		$ids  = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );

		if ( 'product' === $postType && count( $ids ) < $limit ) {
			foreach ( $this->searchBySku( $query, $limit - count( $ids ), $ids ) as $skuRow ) {
				$rows[] = $skuRow;
				$ids[]  = (int) $skuRow['ID'];
			}
		}

		return array_map( fn( array $row ): SearchResult => $this->toResult( $row, $postType ), $rows );
	}

	/**
	 * @param int[] $excludeIds
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function searchBySku( SearchQuery $query, int $limit, array $excludeIds ): array {
		global $wpdb;

		// Contained match is intentional here: an SKU fragment ("-XL") is a
		// realistic thing to type, and postmeta is queried by an indexed
		// meta_key first so the scan is bounded to SKU rows only.
		$exclude = array() === $excludeIds
			? '0'
			: implode( ',', array_map( 'intval', $excludeIds ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $exclude is built from intval()'d integers only.
		$sql = "SELECT p.ID, p.post_title
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
			WHERE p.post_type = 'product'
			  AND p.post_status = 'publish'
			  AND p.ID NOT IN ({$exclude})
			  AND m.meta_value LIKE %s
			LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query->containsPattern(), $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function toResult( array $row, string $postType ): SearchResult {
		$id      = (int) $row['ID'];
		$sku     = null;
		$price   = null;
		$product = null;

		if ( 'product' === $postType && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $id );
		}

		if ( $product ) {
			$sku   = $product->get_sku() ?: null;
			$price = wp_strip_all_tags( (string) $product->get_price_html() ) ?: null;
		}

		$thumbnail = get_the_post_thumbnail_url( $id, 'thumbnail' );

		return new SearchResult(
			$id,
			(string) $row['post_title'],
			(string) get_permalink( $id ),
			$sku,
			$price,
			is_string( $thumbnail ) ? $thumbnail : null
		);
	}
}

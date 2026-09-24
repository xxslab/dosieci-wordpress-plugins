<?php

declare(strict_types=1);

namespace DoSieci\Instant\Search\Adapter;

use DoSieci\Instant\Search\Domain\SearchQuery;
use DoSieci\Instant\Search\Domain\SearchResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the suggestion query.
 *
 * Hand-written SQL rather than WP_Query's `s` parameter: WP_Query searches
 * post_content and post_excerpt too, which on a large catalogue means reading
 * the biggest column in the database on every keystroke. This only reads the
 * short post_title (word-start matches) and, for products, the SKU.
 *
 * Only content a visitor may already see is returned: published, not
 * password-protected, and for WooCommerce products not hidden from search
 * (and not out of stock when the shop hides out-of-stock items). This is a
 * public endpoint, so that filter is the security boundary.
 */
final class WpdbSearchRepository {

	private const CACHE_GROUP = 'dosieci_instant_search';
	private const CACHE_TTL   = 60;

	public function __construct( private int $maxResults = 8 ) {
	}

	/** @return SearchResult[] */
	public function search( SearchQuery $query, string $postType ): array {
		$limit    = max( 1, min( 20, $this->maxResults ) );
		$excluded = 'product' === $postType ? $this->hiddenProductTermIds() : array();
		$cacheKey = md5( implode( '|', array( $postType, $query->normalised, $limit, implode( ',', $excluded ) ) ) );
		$cached   = wp_cache_get( $cacheKey, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $this->titleMatches( $query, $postType, $limit, $excluded );
		$ids  = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );

		if ( 'product' === $postType && count( $ids ) < $limit ) {
			foreach ( $this->skuMatches( $query, $limit - count( $ids ), $ids, $excluded ) as $row ) {
				$rows[] = $row;
			}
		}

		$results = array_map( fn( array $row ): SearchResult => $this->toResult( $row, $postType ), $rows );

		// Short-lived: long enough to absorb a burst of keystrokes, short
		// enough that price and stock changes show up quickly.
		wp_cache_set( $cacheKey, $results, self::CACHE_GROUP, self::CACHE_TTL );

		return $results;
	}

	/**
	 * product_visibility term_taxonomy_ids a visitor must not find through
	 * search.
	 *
	 * @return int[]
	 */
	private function hiddenProductTermIds(): array {
		if ( ! function_exists( 'wc_get_product_visibility_term_ids' ) ) {
			return array();
		}

		$terms = wc_get_product_visibility_term_ids();
		$ids   = array( (int) ( $terms['exclude-from-search'] ?? 0 ) );

		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$ids[] = (int) ( $terms['outofstock'] ?? 0 );
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * @param int[] $excludedTerms
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function titleMatches( SearchQuery $query, string $postType, int $limit, array $excludedTerms ): array {
		global $wpdb;

		$where = array();
		$args  = array( $postType );

		foreach ( $query->wordStartPatterns() as $patterns ) {
			$where[] = '(post_title LIKE %s OR post_title LIKE %s OR post_title LIKE %s)';
			array_push( $args, ...$patterns );
		}

		if ( array() === $where ) {
			return array();
		}

		$sql = "SELECT ID, post_title FROM {$wpdb->posts}
			WHERE post_type = %s AND post_status = 'publish' AND post_password = ''
			AND " . implode( ' AND ', $where )
			. $this->visibilityClause( $excludedTerms, $args ) . '
			ORDER BY post_title ASC
			LIMIT %d';

		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- cached in search(); $sql holds only table names and placeholders, every value is bound from $args.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int[] $excludeIds
	 * @param int[] $excludedTerms
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function skuMatches( SearchQuery $query, int $limit, array $excludeIds, array $excludedTerms ): array {
		global $wpdb;

		$args = array( $query->containsPattern() );
		$sql  = "SELECT p.ID, p.post_title
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
			WHERE m.meta_value LIKE %s
			AND p.post_type = 'product' AND p.post_status = 'publish' AND p.post_password = ''";

		if ( array() !== $excludeIds ) {
			$sql .= ' AND p.ID NOT IN (' . implode( ', ', array_fill( 0, count( $excludeIds ), '%d' ) ) . ')';
			array_push( $args, ...$excludeIds );
		}

		$sql   .= $this->visibilityClause( $excludedTerms, $args, 'p.ID' ) . ' LIMIT %d';
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- cached in search(); $sql holds only table names and placeholders, every value is bound from $args.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int[]            $excludedTerms
	 * @param array<int, mixed> $args placeholder values, appended to in place
	 */
	private function visibilityClause( array $excludedTerms, array &$args, string $idColumn = 'ID' ): string {
		global $wpdb;

		if ( array() === $excludedTerms ) {
			return '';
		}

		array_push( $args, ...$excludedTerms );

		return " AND {$idColumn} NOT IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ("
			. implode( ', ', array_fill( 0, count( $excludedTerms ), '%d' ) ) . '))';
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function toResult( array $row, string $postType ): SearchResult {
		$id      = (int) $row['ID'];
		$sku     = null;
		$price   = null;
		$product = 'product' === $postType && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

		if ( $product ) {
			$sku   = $product->get_sku() ? $product->get_sku() : null;
			$price = self::plainPrice( (string) $product->get_price_html() );
		}

		$thumbnail = get_the_post_thumbnail_url( $id, 'thumbnail' );

		return new SearchResult(
			$id,
			html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			(string) get_permalink( $id ),
			$sku,
			$price,
			is_string( $thumbnail ) ? $thumbnail : null
		);
	}

	/**
	 * The price as the shopper sees it, as plain text for a JSON payload: the
	 * struck-through regular price and the screen-reader duplicates are
	 * dropped, and entities are decoded ("&#122;&#322;" becomes "zł").
	 */
	public static function plainPrice( string $html ): ?string {
		$html = (string) preg_replace( '#<del\b[^>]*>.*?</del>#is', '', $html );
		$html = (string) preg_replace( '#<span[^>]*class="[^"]*screen-reader-text[^"]*"[^>]*>.*?</span>#is', '', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return '' === $text ? null : $text;
	}
}

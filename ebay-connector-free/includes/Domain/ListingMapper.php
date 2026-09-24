<?php

declare(strict_types=1);

namespace DoSieci\Ebay\Connector\Domain;

/**
 * Turns eBay Browse API items into a flat shape this plugin can display.
 *
 * Defensive by design: eBay omits optional fields freely, and a missing
 * price or image must render as "—" rather than throw a fatal in wp-admin.
 * Every access here is null-tolerant for that reason.
 */
final class ListingMapper {

	/**
	 * @param array<string, mixed> $payload a Browse API search response
	 *
	 * @return array<int, array<string, string|null>>
	 */
	public static function fromSearchResponse( array $payload ): array {
		$items = $payload['itemSummaries'] ?? null;

		if ( ! is_array( $items ) ) {
			return array();
		}

		$mapped = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$mapped[] = array(
				'item_id'   => isset( $item['itemId'] ) ? (string) $item['itemId'] : null,
				'title'     => isset( $item['title'] ) ? (string) $item['title'] : '(bez tytułu)',
				'price'     => self::formatPrice( $item['price'] ?? null ),
				'condition' => isset( $item['condition'] ) ? (string) $item['condition'] : null,
				'url'       => isset( $item['itemWebUrl'] ) ? (string) $item['itemWebUrl'] : null,
				'image'     => isset( $item['image']['imageUrl'] ) ? (string) $item['image']['imageUrl'] : null,
				'seller'    => isset( $item['seller']['username'] ) ? (string) $item['seller']['username'] : null,
			);
		}

		return $mapped;
	}

	private static function formatPrice( mixed $price ): ?string {
		if ( ! is_array( $price ) || ! isset( $price['value'] ) ) {
			return null;
		}

		$currency = isset( $price['currency'] ) ? (string) $price['currency'] : '';

		return trim( (string) $price['value'] . ' ' . $currency );
	}

	public static function totalFromSearchResponse( array $payload ): int {
		return isset( $payload['total'] ) && is_numeric( $payload['total'] ) ? (int) $payload['total'] : 0;
	}
}

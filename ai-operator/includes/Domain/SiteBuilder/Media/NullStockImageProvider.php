<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Media;

/**
 * The provider that ships: none.
 *
 * Returning an empty result rather than throwing means the absence of a
 * stock source is an ordinary condition the builder plans around -- a site
 * with fewer pictures -- instead of an error the user has to interpret.
 */
final class NullStockImageProvider implements StockImageProviderInterface {

	public function isConfigured(): bool {
		return false;
	}

	/** @return StockImage[] */
	public function search( string $query, int $limit = 10 ): array {
		return array();
	}

	public function label(): string {
		return 'brak';
	}
}

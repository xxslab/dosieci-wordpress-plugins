<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Commerce;

use DoSieci\AiOperator\Domain\SiteBuilder\ManagedResource;

/**
 * Bridges a blueprint's free-text role onto the managed-resource identity
 * the rest of the builder already uses.
 *
 * A merchant renaming "Koszulki" to "T-shirty" must not earn a second
 * category on the next run, and a category a merchant created by hand
 * must not be adopted just because the name matches -- exactly the two
 * failure directions ManagedResource exists to prevent for pages. Store
 * resources get the same treatment rather than a parallel scheme.
 */
final class ManagedCommerceRole {

	public static function category( string $role ): ManagedResource {
		return ManagedResource::forRole( ManagedResource::TYPE_PRODUCT_CATEGORY, $role );
	}

	public static function product( string $role ): ManagedResource {
		return ManagedResource::forRole( ManagedResource::TYPE_PRODUCT, $role );
	}

	/** The comparison form used to detect two blueprint entries meaning the same thing. */
	public static function normalise( string $role ): string {
		return ManagedResource::forRole( ManagedResource::TYPE_PRODUCT, $role )->slug;
	}
}

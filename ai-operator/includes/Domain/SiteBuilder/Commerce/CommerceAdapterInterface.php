<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Commerce;

use DoSieci\AiOperator\Domain\SiteBuilder\ResourceResolution;

/**
 * The whole commerce surface, as one seam.
 *
 * ## Why an interface for a single implementation
 *
 * Not for a hypothetical second shop plugin. It exists so that the
 * question "does this build touch WooCommerce?" has exactly one answer in
 * exactly one place. Without it, `class_exists( 'WooCommerce' )` ends up
 * sprinkled through the planner, the controller, the verifier and the
 * rollback executor -- four copies of the same guard, each of which can
 * rot independently, on a plugin that must work perfectly on the large
 * majority of sites that will never install WooCommerce.
 *
 * Everything below is expressed in the builder's own vocabulary. No
 * caller outside the adapter sees a WC_Product, a taxonomy name or an
 * option key.
 */
interface CommerceAdapterInterface {

	/** False when WooCommerce is not active. Nothing else may be called. */
	public function isAvailable(): bool;

	/** Shown to the user so they know what is being configured. */
	public function label(): string;

	/** The version actually running, for the audit trail. */
	public function version(): string;

	/**
	 * Whether a currency/country code is one the INSTALLED WooCommerce
	 * accepts. The blueprint validates shape; only the running plugin
	 * knows the real lists.
	 */
	public function supportsCurrency( string $code ): bool;

	public function supportsCountry( string $code ): bool;

	/**
	 * CREATE / REUSE / UPDATE_MANAGED / CONFLICT for one product, decided
	 * at plan time so it appears in what the human approves.
	 */
	public function resolveProduct( StoreProduct $product, string $projectId, int $imageId ): ResourceResolution;
}

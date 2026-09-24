<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Navigation;

/**
 * Builds the navigation a given theme actually renders.
 *
 * Classic and block themes disagree about what navigation IS. A classic
 * theme reads nav-menu terms assigned to a registered location; a block
 * theme renders a `core/navigation` block, which ignores classic menus
 * unless something bridges them. Creating only a classic menu on a block
 * theme therefore produces navigation that exists in the database and is
 * invisible on the site -- which is exactly what the builder did before
 * this interface existed.
 */
interface NavigationAdapterInterface {

	/** Whether this adapter matches the currently active theme. */
	public function supports(): bool;

	/** Short identifier used in plan descriptions and audit output. */
	public function label(): string;
}

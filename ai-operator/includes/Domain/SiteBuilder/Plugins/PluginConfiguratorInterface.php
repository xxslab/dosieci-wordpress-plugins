<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder\Plugins;

use DoSieci\AiOperator\Domain\SiteBuilder\SiteBlueprint;

/**
 * Configures one specific third-party plugin after the Site Builder has
 * installed and activated it.
 *
 * Installing a plugin is not configuring it: a site with Contact Form 7
 * active but no form and nothing embedded has a "contact form" only in the
 * sense that the code is present. This interface is where the gap between
 * "plugin active" and "feature actually works" is closed.
 *
 * Per-plugin adapters, deliberately, rather than one generic "write these
 * options" tool. Each plugin's supported surface is different, and a
 * generic option-writer aimed at third-party plugins is an unbounded
 * capability -- the same shape this project refused for core options
 * (OptionAllowlist) and for arbitrary-URL installs.
 *
 * Implementations must use the target plugin's own documented/public API.
 * Guessing at internal database structures produces code that breaks on
 * the next plugin release, silently and in production.
 */
interface PluginConfiguratorInterface {

	/** Whether the target plugin is present and usable right now. */
	public function isAvailable(): bool;

	/**
	 * Applies the configuration this blueprint implies.
	 *
	 * Must be idempotent: running a revised plan against the same site
	 * should reuse what it already created rather than accumulating
	 * "Kontakt", "Kontakt (2)", "Kontakt (3)".
	 *
	 * @return array<string, mixed> result payload, recorded on the step
	 *
	 * @throws PluginConfigurationException
	 */
	public function configure( SiteBlueprint $blueprint, string $planId ): array;
}

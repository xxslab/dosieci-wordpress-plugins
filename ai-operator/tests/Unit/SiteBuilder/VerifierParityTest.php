<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit\SiteBuilder;

use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\WpPlanVerifier;
use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Two lists have to agree: what the planner is willing to emit, and what
 * the WordPress verifier can actually read back.
 *
 * They live in different layers on purpose, and every drift between them
 * is a real defect in one direction or the other -- a plan full of steps
 * that are guaranteed to fail, or a verifier arm nothing can reach. This
 * is a static comparison of two constants, so it belongs here even though
 * one of the classes is a WordPress adapter (see tests/bootstrap.php on
 * why that is otherwise avoided): nothing below calls WordPress.
 */
final class VerifierParityTest extends TestCase {

	public function test_every_tool_the_planner_may_emit_has_a_verifier(): void {
		foreach ( BlueprintPlanner::VERIFIABLE_TOOLS as $tool ) {
			$this->assertTrue(
				WpPlanVerifier::supports( $tool ),
				sprintf(
					'The planner can emit "%s" but WpPlanVerifier cannot verify it, so every plan '
					. 'containing that step would fail at execution time.',
					$tool
				)
			);
		}
	}

	public function test_the_verifier_carries_no_arm_the_planner_can_never_reach(): void {
		// Dead verifier arms are how a tool ends up "covered" on paper while
		// nothing exercises it.
		foreach ( $this->supportedTools() as $tool ) {
			$this->assertContains(
				$tool,
				BlueprintPlanner::VERIFIABLE_TOOLS,
				sprintf( 'WpPlanVerifier verifies "%s", but no plan can contain it.', $tool )
			);
		}
	}

	/** @return string[] */
	private function supportedTools(): array {
		$candidates = array(
			'create_post', 'update_post', 'trash_post', 'install_plugin', 'activate_plugin',
			'deactivate_plugin', 'install_theme', 'activate_theme', 'create_menu', 'set_homepage',
			'set_site_option', 'create_term', 'configure_contact_form', 'embed_contact_form',
			'set_block_navigation', 'set_featured_image', 'upload_media', 'create_product',
			'delete_post', 'run_php',
		);

		return array_values( array_filter( $candidates, static fn( string $t ): bool => WpPlanVerifier::supports( $t ) ) );
	}
}

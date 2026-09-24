<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Support;

use DoSieci\AiOperator\Domain\SiteBuilder\ActionPlan;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanRecord;
use DoSieci\AiOperator\Domain\SiteBuilder\SiteBlueprint;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;

/**
 * Shared builders so each Site Builder test states only what it actually
 * cares about, instead of restating a valid blueprint and a valid plan.
 */
final class SiteBuilderFixtures {

	public const OWNER_ID = 7;
	public const NOW      = 1_700_000_000;

	/** @param array<string, mixed> $overrides */
	public static function blueprint( array $overrides = array() ): SiteBlueprint {
		return SiteBlueprint::fromArray(
			array_merge(
				array(
					'site_type'     => SiteBlueprint::TYPE_SERVICE_BUSINESS,
					'business_name' => 'HydroMax',
					'language'      => 'pl',
					'pages'         => array( 'Start', 'Oferta', 'Kontakt' ),
					'features'      => array( 'contact_form' ),
					'brand_colors'  => array( 'primary' => '#0b2e59' ),
				),
				$overrides
			)
		);
	}

	/** @param array<string, mixed> $overrides */
	public static function action( string $actionId, int $sequence, array $overrides = array() ): PlanAction {
		return PlanAction::fromArray(
			array_merge(
				array(
					'action_id'         => $actionId,
					'sequence'          => $sequence,
					'tool_name'         => 'create_post',
					'arguments'         => array( 'title' => 'Start' ),
					'risk_level'        => ToolDefinition::RISK_REVERSIBLE_WRITE,
					'description'       => 'Tworzy stronę Start.',
					'rollback_strategy' => PlanAction::ROLLBACK_TRASH_POST,
					// Site Builder steps must declare how their effect can be
					// read back; the executor fails any step it cannot verify.
					'verification'      => array( 'tool' => 'search_posts', 'arguments' => array() ),
				),
				$overrides
			)
		);
	}

	/** @param PlanAction[]|null $actions */
	public static function plan( ?array $actions = null, int $ownerId = self::OWNER_ID ): ActionPlan {
		$actions ??= array(
			self::action( 'a1', 1 ),
			self::action( 'a2', 2, array( 'arguments' => array( 'title' => 'Oferta' ) ) ),
		);

		return ActionPlan::create(
			'plan-1',
			'conv-1',
			$ownerId,
			self::blueprint(),
			$actions,
			self::NOW
		);
	}

	/** An already-approved record, ready to execute. */
	public static function approvedRecord( ?array $actions = null, int $ownerId = self::OWNER_ID ): PlanRecord {
		$record = new PlanRecord( self::plan( $actions, $ownerId ) );
		$record->status = \DoSieci\AiOperator\Domain\SiteBuilder\PlanStatus::AWAITING_APPROVAL;
		$record->approve( $ownerId, self::NOW + 10 );

		return $record;
	}
}

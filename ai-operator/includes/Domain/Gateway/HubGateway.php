<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Gateway;

use DoSieci\AiOperator\Domain\Connection;
use DoSieci\AiOperator\Domain\HubClient;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;

/**
 * The hosted path: chat goes to the DoSieci Hub, which holds the provider
 * key and meters the call against the workspace's credits.
 *
 * Besides binding a Connection to a HubClient, this is also the one place
 * that tells the Hub what THIS installation can actually run -- see
 * capabilities() below. That is the plugin-side half of the negotiation
 * contract the Hub's ClientCapabilities/ToolRiskPolicy implement: the Hub
 * never advertises a write tool to the provider unless this site both has
 * write mode turned on AND has declared it supports that exact tool name.
 * $toolRegistry already reflects the write-mode gate (Plugin::toolRegistry()
 * only registers write tools when writesEnabled() is true), so its names()
 * is exactly the set this installation is willing to execute right now.
 */
final class HubGateway implements ChatGatewayInterface {

	private const TOOLSET_VERSION = 2;

	public function __construct(
		private HubClient $hub,
		private Connection $connection,
		private ToolRegistry $toolRegistry = new ToolRegistry(),
		private bool $writesEnabled = false,
		private string $operatorVersion = ''
	) {
	}

	public function chat( string $requestId, array $conversation ): array {
		return $this->hub->chat( $this->connection, $requestId, $conversation, $this->capabilities() );
	}

	/** @return array<string, mixed> */
	private function capabilities(): array {
		return array(
			'operator_version'    => $this->operatorVersion,
			'toolset_version'     => self::TOOLSET_VERSION,
			'write_tools_enabled' => $this->writesEnabled,
			'supported_tools'     => $this->toolRegistry->names(),
		);
	}

	public function label(): string {
		return 'DoSieci Hub';
	}
}

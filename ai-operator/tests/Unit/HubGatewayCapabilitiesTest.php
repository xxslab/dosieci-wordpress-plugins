<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Tests\Unit;

use DoSieci\AiOperator\Domain\Connection;
use DoSieci\AiOperator\Domain\Gateway\HubGateway;
use DoSieci\AiOperator\Domain\HubClient;
use DoSieci\AiOperator\Domain\Signing\RequestSigner;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use DoSieci\AiOperator\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * The plugin-side half of the Hub write-tool negotiation contract: proves
 * HubGateway actually puts a `capabilities` object on the wire, and that it
 * accurately reflects THIS installation's real, current state -- not a
 * hardcoded guess -- because the Hub's ClientCapabilities::fromArray() /
 * ToolRiskPolicy (apps/license-hub) trust exactly what arrives here as the
 * CLIENT_SUPPORTED half of its three-way intersection. A wrong value here
 * either starves the model of tools this site can really run, or (if it
 * over-claims) is what ToolRiskPolicy's SERVER_CANONICAL_ALLOWED /
 * CURRENT_SERVER_POLICY axes exist to catch server-side -- but this test is
 * what proves the plugin is not the one introducing the mismatch.
 */
final class HubGatewayCapabilitiesTest extends TestCase {

	private function connection(): Connection {
		return new Connection( 'https://license.dosieci.pl', 'site-1', 'lic_key', 'secret', 123 );
	}

	private static function finalAnswerResponse(): array {
		return array(
			'status' => 200,
			'body'   => (string) json_encode( array( 'type' => 'final_answer', 'answer' => 'ok' ) ),
		);
	}

	public function test_a_read_only_registry_with_writes_off_declares_write_tools_enabled_false(): void {
		$registry = new ToolRegistry();
		$registry->register( $this->readOnlyTool( 'get_site_info' ) );

		$transport = new FakeTransport( array( self::finalAnswerResponse() ) );
		$gateway   = new HubGateway(
			new HubClient( $transport, new RequestSigner() ),
			$this->connection(),
			$registry,
			false,
			'1.1.0'
		);

		$gateway->chat( 'req-1', array() );

		$capabilities = $transport->lastBodyDecoded()['capabilities'];

		$this->assertSame( '1.1.0', $capabilities['operator_version'] );
		$this->assertFalse( $capabilities['write_tools_enabled'] );
		$this->assertSame( array( 'get_site_info' ), $capabilities['supported_tools'] );
	}

	public function test_a_registry_with_write_tools_registered_and_writes_on_declares_them_all(): void {
		$registry = new ToolRegistry();
		$registry->register( $this->readOnlyTool( 'get_site_info' ) );
		$registry->register( $this->writeTool( 'create_post' ) );
		$registry->register( $this->writeTool( 'install_theme' ) );

		$transport = new FakeTransport( array( self::finalAnswerResponse() ) );
		$gateway   = new HubGateway(
			new HubClient( $transport, new RequestSigner() ),
			$this->connection(),
			$registry,
			true,
			'1.1.0'
		);

		$gateway->chat( 'req-2', array() );

		$capabilities = $transport->lastBodyDecoded()['capabilities'];

		$this->assertTrue( $capabilities['write_tools_enabled'] );
		$this->assertSame(
			array( 'get_site_info', 'create_post', 'install_theme' ),
			$capabilities['supported_tools']
		);
	}

	public function test_declared_supported_tools_never_include_a_tool_the_registry_does_not_actually_have(): void {
		// Mirrors Plugin::toolRegistry(): write tools are only ever added to
		// the registry passed in here when writesEnabled() is true, so a
		// registry built with writes off cannot declare a write tool name
		// even if the caller's own $writesEnabled flag were wrong -- the
		// registry itself, not the flag, is what supported_tools is read
		// from.
		$registry = new ToolRegistry();
		$registry->register( $this->readOnlyTool( 'get_site_info' ) );

		$transport = new FakeTransport( array( self::finalAnswerResponse() ) );
		$gateway   = new HubGateway(
			new HubClient( $transport, new RequestSigner() ),
			$this->connection(),
			$registry,
			false,
			'1.1.0'
		);

		$gateway->chat( 'req-3', array() );

		$capabilities = $transport->lastBodyDecoded()['capabilities'];

		$this->assertNotContains( 'create_post', $capabilities['supported_tools'] );
		$this->assertNotContains( 'install_theme', $capabilities['supported_tools'] );
	}

	public function test_the_system_prompt_and_tool_descriptions_are_sent_as_guidance(): void {
		$registry = new ToolRegistry();
		$registry->register( $this->readOnlyTool( 'get_site_info' ) );

		$transport = new FakeTransport( array( self::finalAnswerResponse() ) );
		$gateway   = new HubGateway(
			new HubClient( $transport, new RequestSigner() ),
			$this->connection(),
			$registry,
			false,
			'1.2.0',
			'You are the WordPress operator.',
			new \DoSieci\AiOperator\Domain\Gateway\ToolSchemaExporter( $registry, static fn(): bool => true )
		);

		$gateway->chat( 'req-g', array() );

		$body = $transport->lastBodyDecoded();

		$this->assertSame( 'You are the WordPress operator.', $body['system'] );
		$this->assertSame( array( 'get_site_info' => 'test' ), $body['tool_descriptions'] );
		// Guidance never displaces the fields the Hub authorises on.
		$this->assertSame( 'req-g', $body['request_id'] );
		$this->assertArrayHasKey( 'capabilities', $body );
	}

	public function test_without_guidance_the_payload_is_unchanged(): void {
		$transport = new FakeTransport( array( self::finalAnswerResponse() ) );
		$gateway   = new HubGateway( new HubClient( $transport, new RequestSigner() ), $this->connection() );

		$gateway->chat( 'req-n', array() );

		$body = $transport->lastBodyDecoded();

		$this->assertArrayNotHasKey( 'system', $body );
		$this->assertArrayNotHasKey( 'tool_descriptions', $body );
	}

	private function readOnlyTool( string $name ): ToolDefinition {
		return new ToolDefinition(
			$name,
			'test',
			array( 'type' => 'object', 'properties' => array() ),
			'manage_options',
			ToolDefinition::RISK_READ_ONLY,
			5,
			static fn(): array => array()
		);
	}

	private function writeTool( string $name ): ToolDefinition {
		return new ToolDefinition(
			$name,
			'test',
			array( 'type' => 'object', 'properties' => array() ),
			'manage_options',
			ToolDefinition::RISK_REVERSIBLE_WRITE,
			5,
			static fn(): array => array()
		);
	}
}

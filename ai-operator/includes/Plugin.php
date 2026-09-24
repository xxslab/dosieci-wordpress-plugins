<?php

declare(strict_types=1);

namespace DoSieci\AiOperator;

use DoSieci\AiOperator\Adapter\WordPress\OptionsConnectionRepository;
use DoSieci\AiOperator\Adapter\WordPress\OptionsProviderSettingsRepository;
use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\WpManagedResourceRecorder;
use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\WpManagedResourceResolver;
use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\WpPlanVerifier;
use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\WpSiteBuildAuditor;
use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\WpRollbackDataCollector;
use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\WpRollbackExecutor;
use DoSieci\AiOperator\Adapter\WordPress\WpdbPlanRepository;
use DoSieci\AiOperator\Adapter\WordPress\Tools\ReadOnlyToolFactory;
use DoSieci\AiOperator\Adapter\WordPress\Tools\SiteBuilderToolFactory;
use DoSieci\AiOperator\Adapter\WordPress\Tools\WriteToolFactory;
use DoSieci\AiOperator\Adapter\WordPress\WpCapabilityChecker;
use DoSieci\AiOperator\Adapter\WordPress\WpAiClientGateway;
use DoSieci\AiOperator\Adapter\WordPress\WpdbAuditLog;
use DoSieci\AiOperator\Adapter\WordPress\WpHttpTransport;
use DoSieci\AiOperator\Domain\Audit\AuditLogInterface;
use DoSieci\AiOperator\Domain\Audit\SecretScrubber;
use DoSieci\AiOperator\Domain\ChatSession;
use DoSieci\AiOperator\Domain\Gateway\AnthropicGateway;
use DoSieci\AiOperator\Domain\Gateway\ChatGatewayInterface;
use DoSieci\AiOperator\Domain\Gateway\HubGateway;
use DoSieci\AiOperator\Domain\Gateway\OpenAiGateway;
use DoSieci\AiOperator\Domain\Gateway\ProviderSettings;
use DoSieci\AiOperator\Domain\Gateway\SystemPrompt;
use DoSieci\AiOperator\Domain\Gateway\ToolSchemaExporter;
use DoSieci\AiOperator\Domain\HubClient;
use DoSieci\AiOperator\Domain\HubException;
use DoSieci\AiOperator\Domain\Signing\RequestSigner;
use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintGeneratorInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\BlueprintPlanner;
use DoSieci\AiOperator\Domain\SiteBuilder\GatewayBlueprintGenerator;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanExecutor;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanRepositoryInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanRollbackService;
use DoSieci\AiOperator\Domain\Tools\ArgumentsValidator;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolDispatcher;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;
use DoSieci\AiOperator\UI\AdminMenu;
use DoSieci\AiOperator\UI\AjaxController;
use DoSieci\AiOperator\UI\SiteBuilderAjaxController;

/**
 * Composition root. The only place that wires concrete WordPress adapters
 * into the framework-free Domain classes -- everything else depends on
 * interfaces, which is what makes the Domain layer unit-testable with no
 * WordPress present (see tests/).
 *
 * Services are built lazily: an admin who never opens the AI Operator
 * screens should not pay for building a tool registry on every request.
 */
final class Plugin {

	/**
	 * Whether this installation may run write tools at all.
	 *
	 * Off by default. Turning it on is necessary but NOT sufficient to make
	 * a write happen: ToolDispatcher still demands the logged-in user's own
	 * WordPress capability for the specific tool, and still demands
	 * per-action human confirmation. This option only decides whether the
	 * write tools are offered to the model in the first place.
	 */
	public const OPTION_ENABLE_WRITES = 'dosieci_ai_operator_enable_writes';

	private static ?self $instance = null;

	private ?ToolRegistry $toolRegistry = null;
	private ?AuditLogInterface $auditLog = null;
	private ?PlanRepositoryInterface $planRepository = null;

	private function __construct() {
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		load_plugin_textdomain( 'dosieci-ai-operator', false, dirname( plugin_basename( DOSIECI_AI_OPERATOR_FILE ) ) . '/languages' );

		if ( is_admin() ) {
			( new AdminMenu( $this ) )->register();
			( new AjaxController( $this ) )->register();
			( new SiteBuilderAjaxController( $this ) )->register();
		}
	}

	public function connections(): OptionsConnectionRepository {
		return new OptionsConnectionRepository();
	}

	public function providerSettings(): OptionsProviderSettingsRepository {
		return new OptionsProviderSettingsRepository();
	}

	public function writesEnabled(): bool {
		return '1' === get_option( self::OPTION_ENABLE_WRITES, '0' );
	}

	public function maxRiskLevel(): string {
		return $this->writesEnabled()
			? ToolDefinition::RISK_DESTRUCTIVE
			: ToolDefinition::RISK_READ_ONLY;
	}

	public function auditLog(): AuditLogInterface {
		if ( null === $this->auditLog ) {
			$this->auditLog = new WpdbAuditLog( new SecretScrubber() );
		}

		return $this->auditLog;
	}

	public function toolRegistry(): ToolRegistry {
		if ( null === $this->toolRegistry ) {
			$registry = new ToolRegistry();
			( new ReadOnlyToolFactory() )->register( $registry );

			// Write tools are registered only when the site has opted in.
			// A tool that is not registered cannot be dispatched at all
			// (ToolRegistry is a closed allowlist), so this is a real
			// boundary rather than a UI-level preference.
			if ( $this->writesEnabled() ) {
				( new WriteToolFactory() )->register( $registry );

				// Site Builder's own write tools (plugin configuration and
				// wiring its output into a page). Gated by the same
				// writesEnabled() switch and dispatched through the same
				// ToolDispatcher as everything else -- they are separate
				// only because they are meaningless outside a build.
				( new SiteBuilderToolFactory() )->register( $registry );

				// Registered whenever writes are on, NOT only when WooCommerce
				// is already active. Installing WooCommerce is itself a step
				// of a store build, so gating registration on its presence
				// made the tools unavailable in exactly the run that needs
				// them -- and the registry is memoised per request, so the
				// ordering was fragile even where it happened to work.
				//
				// Registering them grants nothing: every handler refuses
				// cleanly when WooCommerce is absent, and none of them can
				// express a payment, order or arbitrary-option operation in
				// the first place.
				( new \DoSieci\AiOperator\Adapter\WordPress\Tools\CommerceToolFactory( $this->commerce() ) )->register( $registry );
			}

			/**
			 * Lets a site add its own tools. Anything registered here goes
			 * through exactly the same ToolDispatcher gates as a built-in
			 * tool -- schema validation, risk level, capability check,
			 * confirmation for writes, audit -- so a third party cannot use
			 * this filter to smuggle in an ungated capability.
			 *
			 * @param ToolRegistry $registry
			 */
			do_action( 'dosieci_ai_operator_register_tools', $registry );

			$this->toolRegistry = $registry;
		}

		return $this->toolRegistry;
	}

	public function dispatcher(): ToolDispatcher {
		return new ToolDispatcher(
			$this->toolRegistry(),
			new ArgumentsValidator(),
			new WpCapabilityChecker(),
			$this->auditLog(),
			$this->maxRiskLevel()
		);
	}

	public function hubClient(): HubClient {
		return new HubClient( new WpHttpTransport(), new RequestSigner(), 30 );
	}

	/**
	 * Builds the gateway this site is configured to use.
	 *
	 * Fails closed in two directions. A BYOK mode with no key does NOT
	 * silently fall back to the Hub (that would spend the customer's
	 * DoSieci credits without them asking), and Hub mode with no pairing
	 * does not fall back to BYOK. Each misconfiguration produces its own
	 * explicit error the settings screen can act on.
	 *
	 * @throws HubException
	 */
	public function gateway(): ChatGatewayInterface {
		$settings = $this->providerSettings()->get();

		if ( ! $settings->isByok() ) {
			$connection = $this->connections()->get();

			if ( null === $connection ) {
				throw new HubException(
					'This site is not paired with DoSieci.',
					409,
					'not_connected',
					false
				);
			}

			return new HubGateway(
				$this->hubClient(),
				$connection,
				$this->toolRegistry(),
				$this->writesEnabled(),
				DOSIECI_AI_OPERATOR_VERSION,
				$this->systemPrompt(),
				$this->toolExporter()
			);
		}

		if ( ProviderSettings::MODE_WORDPRESS === $settings->mode ) {
			if ( ! WpAiClientGateway::isAvailable() ) {
				throw new HubException(
					'The WordPress AI client is not available on this site.',
					409,
					'wp_ai_unavailable',
					false
				);
			}

			return new WpAiClientGateway(
				$this->toolExporter(),
				$this->systemPrompt(),
				trim( $settings->model ),
				$settings->maxTokens
			);
		}

		if ( ! $settings->isUsable() ) {
			throw new HubException(
				'A provider with your own key is selected, but no API key is saved.',
				409,
				'byok_key_missing',
				false
			);
		}

		return ProviderSettings::MODE_OPENAI === $settings->mode
			? new OpenAiGateway( new WpHttpTransport(), $settings, $this->toolExporter(), $this->systemPrompt() )
			: new AnthropicGateway( new WpHttpTransport(), $settings, $this->toolExporter(), $this->systemPrompt() );
	}

	/**
	 * The tools the model may be offered: registered, within the site's
	 * risk ceiling, and allowed for the logged-in user.
	 */
	private function toolExporter(): ToolSchemaExporter {
		return new ToolSchemaExporter(
			$this->toolRegistry(),
			static fn( string $capability ): bool => current_user_can( $capability ),
			$this->maxRiskLevel()
		);
	}

	private function systemPrompt(): string {
		return SystemPrompt::build(
			home_url( '/' ),
			(string) get_bloginfo( 'name' ),
			$this->writesEnabled(),
			(string) get_locale(),
			(string) get_user_locale()
		);
	}

	/**
	 * @throws HubException
	 */
	public function planRepository(): PlanRepositoryInterface {
		if ( null === $this->planRepository ) {
			$this->planRepository = new WpdbPlanRepository();
		}

		return $this->planRepository;
	}

	/**
	 * Blueprint generation reuses whichever provider this site already
	 * uses for chat -- Hub, OpenAI BYOK or Anthropic BYOK. A separate
	 * key store for blueprints would be a second thing to secure.
	 *
	 * @throws HubException when no provider is usable
	 */
	public function blueprintGenerator(): BlueprintGeneratorInterface {
		return new GatewayBlueprintGenerator( $this->gateway() );
	}

	/**
	 * Bounded, read-only facts the model may see. Deliberately a short
	 * allowlist of public site properties -- no credentials, no options
	 * beyond these, nothing from the environment.
	 *
	 * @return array<string, mixed>
	 */
	public function siteContext(): array {
		return array(
			'site_language'      => (string) get_locale(),
			'site_title'         => (string) get_bloginfo( 'name' ),
			'active_theme'       => (string) wp_get_theme()->get( 'Name' ),
			'is_block_theme'     => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
			'woocommerce_active' => class_exists( 'WooCommerce' ),
		);
	}

	/**
	 * Identifies this site's Site Builder project, so a second run
	 * reconciles against the first run's resources instead of duplicating
	 * them. Generated once and stored; never supplied by the model or the
	 * browser.
	 */
	public const OPTION_PROJECT_ID = 'dosieci_ai_operator_project_id';

	public function projectId(): string {
		$stored = (string) get_option( self::OPTION_PROJECT_ID, '' );

		if ( '' !== $stored ) {
			return $stored;
		}

		$generated = 'proj_' . bin2hex( random_bytes( 8 ) );
		update_option( self::OPTION_PROJECT_ID, $generated, false );

		return $generated;
	}

	public function managedResources(): WpManagedResourceResolver {
		return new WpManagedResourceResolver();
	}

	public function commerce(): \DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooCommerceAdapter {
		return new \DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooCommerceAdapter( $this->managedResources() );
	}

	public function blueprintPlanner(): BlueprintPlanner {
		return new BlueprintPlanner(
			new \DoSieci\AiOperator\Domain\SiteBuilder\Gutenberg\PageContentFactory(
				new \DoSieci\AiOperator\Domain\SiteBuilder\Gutenberg\BlockComposer()
			),
			$this->managedResources(),
			new \DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\WpMediaLibrary(),
			new \DoSieci\AiOperator\Domain\SiteBuilder\Media\MediaMatcher(),
			// Always passed. Availability is the ADAPTER's question to answer
			// per call, not a reason to withhold it: at plan time a store
			// build has not installed WooCommerce yet, so gating on presence
			// here planned a shop with no shop steps in it.
			$this->commerce()
		);
	}

	/**
	 * Site Builder execution reuses the SAME ToolDispatcher the single-action
	 * chat path uses -- same registry, same validator, same capability
	 * checker, same risk ceiling. Plan approval buys fewer human round trips,
	 * not a weaker gate; see PlanExecutor's own docblock.
	 */
	public function planExecutor(): PlanExecutor {
		return new PlanExecutor(
			$this->planRepository(),
			$this->dispatcher(),
			new WpRollbackDataCollector(),
			// Site Builder mode always verifies: a step whose effect cannot
			// be confirmed against real WordPress state must not be
			// reported as done. See WpPlanVerifier's docblock for the bug
			// that made this non-negotiable.
			new WpPlanVerifier(),
			// The final independent read-only audit: every step passing is
			// necessary but not sufficient for SUCCEEDED.
			new WpSiteBuildAuditor( new \DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooStoreAuditor( $this->commerce(), $this->managedResources() ), $this->projectId() ),
			// Stamps ownership after each verified write, so the next run
			// recognises this run's work.
			new WpManagedResourceRecorder( $this->managedResources() )
		);
	}

	public function planRollbackService(): PlanRollbackService {
		return new PlanRollbackService(
			$this->planRepository(),
			new WpRollbackExecutor( new WpCapabilityChecker() )
		);
	}

	public function chatSession(): ChatSession {
		return new ChatSession(
			$this->gateway(),
			$this->dispatcher(),
			$this->toolRegistry()
		);
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

use DoSieci\AiOperator\Adapter\WordPress\WpAiClientGateway;
use DoSieci\AiOperator\Domain\Gateway\ProviderSettings;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Plugin;

/**
 * The chat screen.
 *
 * The header states plainly which gateway is answering and whether the
 * operator can change anything, because those two facts decide what the
 * user should expect and who is being billed. Getting that wrong in the UI
 * is how a user ends up believing the AI is broken when it is merely
 * read-only, or believing it is safe when it can rewrite the site.
 */
final class ChatPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		$settings   = $this->plugin->providerSettings()->get();
		$connection = $this->plugin->connections()->get();
		$writesOn   = $this->plugin->writesEnabled();

		$settingsUrl = admin_url( 'admin.php?page=' . AdminMenu::SLUG . '-settings' );

		$blocker = $this->blocker( $settings, $connection );

		$tools      = $this->plugin->toolRegistry()->all();
		$writeTools = array_filter(
			$tools,
			static fn( ToolDefinition $tool ): bool => ToolDefinition::RISK_READ_ONLY !== $tool->riskLevel
		);
		?>
		<div class="wrap dosieci-ai">
			<h1><?php esc_html_e( 'DoSieci AI Operator', 'dosieci-ai-operator' ); ?></h1>

			<?php if ( null !== $blocker ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php echo wp_kses( $blocker, array( 'a' => array( 'href' => array() ) ) ); ?>
					</p>
				</div>
			<?php else : ?>
				<p class="description">
					<strong><?php esc_html_e( 'Mode:', 'dosieci-ai-operator' ); ?></strong>
					<?php echo esc_html( $this->modeDescription( $settings ) ); ?>
				</p>

				<p class="description">
					<?php if ( $writesOn ) : ?>
						<?php
						printf(
							/* translators: 1: total tools, 2: write tools */
							esc_html__( 'The operator has %1$d tools, %2$d of which can change the site. Every change needs your confirmation in the chat.', 'dosieci-ai-operator' ),
							count( $tools ),
							count( $writeTools )
						);
						?>
					<?php else : ?>
						<?php
						printf(
							/* translators: %d: number of available tools */
							esc_html__( 'The operator has access to %d read-only tools. None of them change data on this site.', 'dosieci-ai-operator' ),
							count( $tools )
						);
						?>
						<a href="<?php echo esc_url( $settingsUrl ); ?>">
							<?php esc_html_e( 'Allow changes to the site', 'dosieci-ai-operator' ); ?>
						</a>
					<?php endif; ?>
				</p>

				<div id="dosieci-ai-chat-log" class="dosieci-ai-chat-log" aria-live="polite" role="log"></div>

				<form id="dosieci-ai-chat-form" class="dosieci-ai-chat-form">
					<label class="screen-reader-text" for="dosieci-ai-message">
						<?php esc_html_e( 'Message to the operator', 'dosieci-ai-operator' ); ?>
					</label>
					<textarea id="dosieci-ai-message" rows="3"
						placeholder="<?php echo esc_attr( $writesOn
							? __( 'e.g. Build a homepage with an about section and add it to the menu', 'dosieci-ai-operator' )
							: __( 'e.g. Check the site’s technical health and its largest autoloaded options', 'dosieci-ai-operator' ) ); ?>"></textarea>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Send', 'dosieci-ai-operator' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Whether this site can chat at all right now, and if not, what to do
	 * about it -- as a ready-to-echo HTML string (only `<a href>` allowed).
	 * Hub mode needs a pairing, the WordPress mode needs a connected
	 * provider, and the two key-based BYOK modes need a saved key: each has
	 * its own remedy, so each gets its own message rather than a shared
	 * "not configured".
	 *
	 * @param \DoSieci\AiOperator\Domain\Connection|null $connection
	 */
	private function blocker( ProviderSettings $settings, $connection ): ?string {
		$statusUrl   = esc_url( admin_url( 'admin.php?page=' . AdminMenu::SLUG . '-status' ) );
		$settingsUrl = esc_url( admin_url( 'admin.php?page=' . AdminMenu::SLUG . '-settings' ) );

		if ( ! $settings->isByok() && null === $connection ) {
			return sprintf(
				/* translators: 1: URL of the Connection screen, 2: URL of the Settings screen */
				esc_html__( 'This site is not connected to DoSieci yet. %1$s or use your own AI key in %2$s.', 'dosieci-ai-operator' ),
				'<a href="' . $statusUrl . '">' . esc_html__( 'Go to Connection', 'dosieci-ai-operator' ) . '</a>',
				'<a href="' . $settingsUrl . '">' . esc_html__( 'Settings', 'dosieci-ai-operator' ) . '</a>'
			);
		}

		if ( ProviderSettings::MODE_WORDPRESS === $settings->mode && ! WpAiClientGateway::isAvailable() ) {
			return sprintf(
				/* translators: %s: URL of the Settings screen */
				esc_html__( 'WordPress AI connectors need WordPress 7.0 or later with AI features enabled, which this site does not have. Choose another mode in %s.', 'dosieci-ai-operator' ),
				'<a href="' . $settingsUrl . '">' . esc_html__( 'Settings', 'dosieci-ai-operator' ) . '</a>'
			);
		}

		if ( ProviderSettings::MODE_WORDPRESS === $settings->mode && ! WpAiClientGateway::isConfigured() ) {
			return sprintf(
				/* translators: %s: URL of the Settings screen */
				esc_html__( 'No AI provider is connected under Settings > Connectors yet. Connect one there, or choose another mode in %s.', 'dosieci-ai-operator' ),
				'<a href="' . $settingsUrl . '">' . esc_html__( 'Settings', 'dosieci-ai-operator' ) . '</a>'
			);
		}

		if ( $settings->needsApiKey() && ! $settings->isUsable() ) {
			return sprintf(
				/* translators: %s: URL of the Settings screen */
				esc_html__( 'A provider with your own API key is selected, but no key is saved. Add it in %s.', 'dosieci-ai-operator' ),
				'<a href="' . $settingsUrl . '">' . esc_html__( 'Settings', 'dosieci-ai-operator' ) . '</a>'
			);
		}

		return null;
	}

	private function modeDescription( ProviderSettings $settings ): string {
		if ( ProviderSettings::MODE_WORDPRESS === $settings->mode ) {
			$providers = array_values( WpAiClientGateway::connectedProviders() );

			return sprintf(
				/* translators: %s: comma-separated list of connected AI provider names */
				__( 'WordPress AI connectors (%s) — your own key, no DoSieci credits used', 'dosieci-ai-operator' ),
				implode( ', ', $providers )
			);
		}

		if ( $settings->isByok() ) {
			/* translators: %s: model name */
			return sprintf( __( 'your own API key, model %s — no DoSieci credits used', 'dosieci-ai-operator' ), $settings->effectiveModel() );
		}

		return __( 'DoSieci Hub — requests are billed from your plan’s credits', 'dosieci-ai-operator' );
	}
}

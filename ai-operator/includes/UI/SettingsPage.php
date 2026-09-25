<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

use DoSieci\AiOperator\Adapter\WordPress\WpAiClientGateway;
use DoSieci\AiOperator\Domain\Gateway\ProviderSettings;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Plugin;

/**
 * Settings, plus the honest inventory of what this plugin can and cannot
 * do. The tool table is generated from the live registry rather than
 * hand-maintained, so it can never drift from what is actually installed.
 */
final class SettingsPage {

	public const OPTION_DELETE_DATA = 'dosieci_ai_operator_delete_data_on_uninstall';

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		$notice = $this->handleSubmit();

		$settings = $this->plugin->providerSettings()->get();
		$deleteData = '1' === get_option( self::OPTION_DELETE_DATA, '0' );
		$writesOn = $this->plugin->writesEnabled();
		?>
		<div class="wrap dosieci-ai">
			<h1><?php esc_html_e( 'AI Operator settings', 'dosieci-ai-operator' ); ?></h1>

			<?php if ( null !== $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'dosieci_ai_settings' ); ?>

				<h2><?php esc_html_e( 'AI provider', 'dosieci-ai-operator' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Where the model comes from', 'dosieci-ai-operator' ); ?></th>
						<td>
							<?php foreach ( ProviderSettings::modes() as $value => $label ) : ?>
								<p>
									<label>
										<input type="radio" name="provider_mode" value="<?php echo esc_attr( $value ); ?>"
											<?php checked( $settings->mode, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								</p>
							<?php endforeach; ?>
							<p class="description">
								<?php esc_html_e( 'The DoSieci Hub mode spends credits from your DoSieci plan and needs no key of your own. Every other mode connects directly to the provider: no DoSieci credits are used, and you pay the provider directly.', 'dosieci-ai-operator' ); ?>
							</p>
							<?php if ( ProviderSettings::MODE_WORDPRESS === $settings->mode ) : ?>
								<p class="description"><?php echo wp_kses( $this->wordPressConnectorsStatus(), array( 'a' => array( 'href' => array() ) ) ); ?></p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="dosieci-api-key"><?php esc_html_e( 'API key', 'dosieci-ai-operator' ); ?></label>
						</th>
						<td>
							<input id="dosieci-api-key" type="password" name="api_key" class="regular-text"
								autocomplete="off" placeholder="<?php esc_attr_e( 'paste a key to set or change it', 'dosieci-ai-operator' ); ?>">
							<?php if ( '' !== $settings->maskedApiKey() ) : ?>
								<p class="description">
									<?php esc_html_e( 'Saved key:', 'dosieci-ai-operator' ); ?>
									<code><?php echo esc_html( $settings->maskedApiKey() ); ?></code>
									&nbsp;
									<label>
										<input type="checkbox" name="clear_api_key" value="1">
										<?php esc_html_e( 'remove the saved key', 'dosieci-ai-operator' ); ?>
									</label>
								</p>
							<?php endif; ?>
							<p class="description">
								<?php esc_html_e( 'Used only for the “Anthropic” and “OpenAI” modes above; the Hub and WordPress AI connectors modes ignore it. Empty = leave the current key unchanged. The key is stored in this site’s database and sent only to the chosen provider over HTTPS — never to DoSieci and never to the audit log.', 'dosieci-ai-operator' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="dosieci-model"><?php esc_html_e( 'Model', 'dosieci-ai-operator' ); ?></label>
						</th>
						<td>
							<input id="dosieci-model" type="text" name="model" class="regular-text"
								value="<?php echo esc_attr( $settings->model ); ?>"
								placeholder="<?php echo esc_attr( $this->modelPlaceholder( $settings ) ); ?>">
							<p class="description">
								<?php if ( ProviderSettings::MODE_WORDPRESS === $settings->mode ) : ?>
									<?php esc_html_e( 'Empty = let WordPress pick a suitable model from your connected providers. Fill this in only to prefer a specific one.', 'dosieci-ai-operator' ); ?>
								<?php else : ?>
									<?php
									printf(
										/* translators: %s: model identifier */
										esc_html__( 'Empty = the default model for the chosen provider (currently: %s). Not used in Hub mode.', 'dosieci-ai-operator' ),
										esc_html( $settings->effectiveModel() ?: '—' )
									);
									?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Changes to the site', 'dosieci-ai-operator' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Write tools', 'dosieci-ai-operator' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enable_writes" value="1" <?php checked( $writesOn ); ?>>
								<?php esc_html_e( 'Let the operator change this site (create content, install themes and plugins, change settings).', 'dosieci-ai-operator' ); ?>
							</label>
							<p class="description">
								<strong><?php esc_html_e( 'Turning this on does NOT mean the operator acts without oversight.', 'dosieci-ai-operator' ); ?></strong>
								<?php esc_html_e( 'Every single change still needs its own “Approve” click in the chat, and each one only acts within the logged-in WordPress user’s own permissions. Without this option, write tools are not even registered — the model does not know they exist.', 'dosieci-ai-operator' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Plugin data', 'dosieci-ai-operator' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Data removal', 'dosieci-ai-operator' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $deleteData ); ?>>
								<?php esc_html_e( 'Delete all of this plugin’s data when it is uninstalled (pairing, API key, history, audit log).', 'dosieci-ai-operator' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default: deactivating or uninstalling the plugin does not delete data until you deliberately turn this on.', 'dosieci-ai-operator' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save', 'dosieci-ai-operator' ), 'primary', 'dosieci_save_settings' ); ?>
			</form>

			<h2><?php esc_html_e( 'Available tools', 'dosieci-ai-operator' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: 1: total tools, 2: write tools */
					esc_html__( 'Registered tools: %1$d, of which %2$d can change data.', 'dosieci-ai-operator' ),
					count( $this->plugin->toolRegistry()->all() ),
					count(
						array_filter(
							$this->plugin->toolRegistry()->all(),
							static fn( ToolDefinition $tool ): bool => ToolDefinition::RISK_READ_ONLY !== $tool->riskLevel
						)
					)
				);
				?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Tool', 'dosieci-ai-operator' ); ?></th>
						<th><?php esc_html_e( 'Description', 'dosieci-ai-operator' ); ?></th>
						<th><?php esc_html_e( 'Required capability', 'dosieci-ai-operator' ); ?></th>
						<th><?php esc_html_e( 'Risk level', 'dosieci-ai-operator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $this->plugin->toolRegistry()->all() as $tool ) : ?>
						<tr>
							<td><code><?php echo esc_html( $tool->name ); ?></code></td>
							<td><?php echo esc_html( $tool->description ); ?></td>
							<td><code><?php echo esc_html( $tool->requiredCapability ); ?></code></td>
							<td>
								<span class="dosieci-ai-badge <?php echo ToolDefinition::RISK_READ_ONLY === $tool->riskLevel ? 'ok' : 'warn'; ?>">
									<?php echo esc_html( $tool->riskLevel ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Which providers WordPress itself has connected under Settings >
	 * Connectors, or a link there when none are usable yet -- as a
	 * ready-to-echo HTML string (only `<a href>` allowed).
	 */
	private function wordPressConnectorsStatus(): string {
		if ( ! WpAiClientGateway::isAvailable() ) {
			return esc_html__( 'This site’s WordPress version does not support AI connectors (needs WordPress 7.0+ with AI features enabled).', 'dosieci-ai-operator' );
		}

		$providers = WpAiClientGateway::connectedProviders();

		if ( array() === $providers ) {
			return sprintf(
				/* translators: %s: URL of the WordPress Settings > Connectors screen */
				esc_html__( 'No provider is connected yet. Add one under %s.', 'dosieci-ai-operator' ),
				'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Settings > Connectors', 'dosieci-ai-operator' ) . '</a>'
			);
		}

		return sprintf(
			/* translators: %s: comma-separated list of connected AI provider names */
			esc_html__( 'Connected: %s.', 'dosieci-ai-operator' ),
			esc_html( implode( ', ', $providers ) )
		);
	}

	private function modelPlaceholder( ProviderSettings $settings ): string {
		return match ( $settings->mode ) {
			ProviderSettings::MODE_OPENAI    => ProviderSettings::DEFAULT_OPENAI_MODEL,
			ProviderSettings::MODE_ANTHROPIC => ProviderSettings::DEFAULT_ANTHROPIC_MODEL,
			default                          => '',
		};
	}

	/**
	 * @return array{type: string, message: string}|null
	 */
	private function handleSubmit(): ?array {
		if ( ! isset( $_POST['dosieci_save_settings'] ) ) {
			return null;
		}

		check_admin_referer( 'dosieci_ai_settings' );

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'You are not allowed to do this.', 'dosieci-ai-operator' ),
			);
		}

		update_option( self::OPTION_DELETE_DATA, isset( $_POST['delete_data_on_uninstall'] ) ? '1' : '0', false );
		update_option( Plugin::OPTION_ENABLE_WRITES, isset( $_POST['enable_writes'] ) ? '1' : '0', false );

		$repository = $this->plugin->providerSettings();
		$current    = $repository->get();

		$mode = isset( $_POST['provider_mode'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['provider_mode'] ) ) : ProviderSettings::MODE_HUB;

		$settings = ProviderSettings::fromArray(
			array(
				'mode'       => $mode,
				'model'      => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['model'] ) ) : '',
				'max_tokens' => $current->maxTokens,
				'timeout'    => $current->timeoutSeconds,
			)
		);

		// The key field is intentionally never pre-filled with the real
		// value, so an empty submission must mean "leave it alone" rather
		// than "erase it" -- otherwise saving an unrelated change would
		// silently break a working configuration. Clearing is a separate,
		// explicit checkbox.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- deliberately not run through sanitize_text_field(), which can mangle characters a provider's real API key legitimately contains.
		$submittedKey = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '';

		if ( isset( $_POST['clear_api_key'] ) ) {
			$repository->save( $settings->withApiKey( '' ) );
		} elseif ( '' !== $submittedKey ) {
			$repository->save( $settings->withApiKey( $submittedKey ) );
		} else {
			$repository->saveKeepingKey( $settings );
		}

		$saved = $repository->get();

		if ( $saved->needsApiKey() && ! $saved->isUsable() ) {
			return array(
				'type'    => 'warning',
				'message' => __( 'Saved, but the chosen provider needs an API key — the chat will not work until you add one.', 'dosieci-ai-operator' ),
			);
		}

		if ( ProviderSettings::MODE_WORDPRESS === $saved->mode && WpAiClientGateway::isAvailable() && ! WpAiClientGateway::isConfigured() ) {
			return array(
				'type'    => 'warning',
				'message' => __( 'Saved, but no AI provider is connected under Settings > Connectors yet — the chat will not work until you connect one.', 'dosieci-ai-operator' ),
			);
		}

		return array(
			'type'    => 'success',
			'message' => __( 'Saved.', 'dosieci-ai-operator' ),
		);
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

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
			<h1><?php esc_html_e( 'Ustawienia AI Operator', 'dosieci-ai-operator' ); ?></h1>

			<?php if ( null !== $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'dosieci_ai_settings' ); ?>

				<h2><?php esc_html_e( 'Dostawca AI', 'dosieci-ai-operator' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Skąd brać model', 'dosieci-ai-operator' ); ?></th>
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
								<?php esc_html_e( 'Tryb DoSieci Hub zużywa kredyty z Twojego planu i nie wymaga własnego klucza. Tryby z własnym kluczem łączą się bezpośrednio z dostawcą — nie zużywają kredytów DoSieci, a za zapytania płacisz bezpośrednio dostawcy.', 'dosieci-ai-operator' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="dosieci-api-key"><?php esc_html_e( 'Klucz API', 'dosieci-ai-operator' ); ?></label>
						</th>
						<td>
							<input id="dosieci-api-key" type="password" name="api_key" class="regular-text"
								autocomplete="off" placeholder="<?php esc_attr_e( 'wklej klucz, aby ustawić lub zmienić', 'dosieci-ai-operator' ); ?>">
							<?php if ( '' !== $settings->maskedApiKey() ) : ?>
								<p class="description">
									<?php esc_html_e( 'Zapisany klucz:', 'dosieci-ai-operator' ); ?>
									<code><?php echo esc_html( $settings->maskedApiKey() ); ?></code>
									&nbsp;
									<label>
										<input type="checkbox" name="clear_api_key" value="1">
										<?php esc_html_e( 'usuń zapisany klucz', 'dosieci-ai-operator' ); ?>
									</label>
								</p>
							<?php endif; ?>
							<p class="description">
								<?php esc_html_e( 'Puste pole = zostaw obecny klucz bez zmian. Klucz jest zapisywany w bazie tej witryny i wysyłany wyłącznie do wybranego dostawcy po HTTPS. Nie trafia do DoSieci ani do logu audytowego.', 'dosieci-ai-operator' ); ?>
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
								placeholder="<?php echo esc_attr( ProviderSettings::MODE_OPENAI === $settings->mode ? ProviderSettings::DEFAULT_OPENAI_MODEL : ProviderSettings::DEFAULT_ANTHROPIC_MODEL ); ?>">
							<p class="description">
								<?php
								printf(
									/* translators: %s: model identifier */
									esc_html__( 'Puste = domyślny model dla wybranego dostawcy (teraz: %s).', 'dosieci-ai-operator' ),
									esc_html( $settings->effectiveModel() ?: '—' )
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Zmiany na witrynie', 'dosieci-ai-operator' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Narzędzia zapisu', 'dosieci-ai-operator' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enable_writes" value="1" <?php checked( $writesOn ); ?>>
								<?php esc_html_e( 'Pozwól operatorowi zmieniać tę witrynę (tworzyć treści, instalować motywy i wtyczki, zmieniać ustawienia).', 'dosieci-ai-operator' ); ?>
							</label>
							<p class="description">
								<strong><?php esc_html_e( 'Włączenie tej opcji NIE oznacza, że operator działa bez kontroli.', 'dosieci-ai-operator' ); ?></strong>
								<?php esc_html_e( 'Każda pojedyncza zmiana wymaga osobnego kliknięcia „Zatwierdź” w czacie, i każda działa wyłącznie w granicach uprawnień zalogowanego użytkownika WordPressa. Bez tej opcji narzędzia zapisu nie są w ogóle rejestrowane — model o nich nie wie.', 'dosieci-ai-operator' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Dane wtyczki', 'dosieci-ai-operator' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Usuwanie danych', 'dosieci-ai-operator' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $deleteData ); ?>>
								<?php esc_html_e( 'Usuń wszystkie dane wtyczki przy jej odinstalowaniu (parowanie, klucz API, historia, log audytowy).', 'dosieci-ai-operator' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Domyślnie wyłączone: deaktywacja i odinstalowanie nie kasują danych, dopóki świadomie tego nie włączysz.', 'dosieci-ai-operator' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Zapisz', 'dosieci-ai-operator' ), 'primary', 'dosieci_save_settings' ); ?>
			</form>

			<h2><?php esc_html_e( 'Dostępne narzędzia', 'dosieci-ai-operator' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: 1: total tools, 2: write tools */
					esc_html__( 'Zarejestrowanych narzędzi: %1$d, w tym zmieniających dane: %2$d.', 'dosieci-ai-operator' ),
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
						<th><?php esc_html_e( 'Narzędzie', 'dosieci-ai-operator' ); ?></th>
						<th><?php esc_html_e( 'Opis', 'dosieci-ai-operator' ); ?></th>
						<th><?php esc_html_e( 'Wymagane uprawnienie', 'dosieci-ai-operator' ); ?></th>
						<th><?php esc_html_e( 'Poziom ryzyka', 'dosieci-ai-operator' ); ?></th>
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
				'message' => __( 'Brak uprawnień.', 'dosieci-ai-operator' ),
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
		$submittedKey = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '';

		if ( isset( $_POST['clear_api_key'] ) ) {
			$repository->save( $settings->withApiKey( '' ) );
		} elseif ( '' !== $submittedKey ) {
			$repository->save( $settings->withApiKey( $submittedKey ) );
		} else {
			$repository->saveKeepingKey( $settings );
		}

		$saved = $repository->get();

		if ( $saved->isByok() && ! $saved->isUsable() ) {
			return array(
				'type'    => 'warning',
				'message' => __( 'Zapisano, ale wybrany dostawca wymaga klucza API — czat nie zadziała, dopóki go nie uzupełnisz.', 'dosieci-ai-operator' ),
			);
		}

		return array(
			'type'    => 'success',
			'message' => __( 'Zapisano.', 'dosieci-ai-operator' ),
		);
	}
}

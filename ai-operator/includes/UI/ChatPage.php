<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

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

		// Hub mode needs a pairing; BYOK mode needs a key. Each has its own
		// remedy, so they get their own message rather than a shared
		// "not configured".
		$blocker = null;
		if ( ! $settings->isByok() && null === $connection ) {
			$blocker = 'not_paired';
		} elseif ( $settings->isByok() && ! $settings->isUsable() ) {
			$blocker = 'no_key';
		}

		$tools      = $this->plugin->toolRegistry()->all();
		$writeTools = array_filter(
			$tools,
			static fn( ToolDefinition $tool ): bool => ToolDefinition::RISK_READ_ONLY !== $tool->riskLevel
		);
		?>
		<div class="wrap dosieci-ai">
			<h1><?php esc_html_e( 'DoSieci AI Operator', 'dosieci-ai-operator' ); ?></h1>

			<?php if ( 'not_paired' === $blocker ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'Ta witryna nie jest jeszcze połączona z DoSieci.', 'dosieci-ai-operator' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::SLUG . '-status' ) ); ?>">
							<?php esc_html_e( 'Przejdź do połączenia', 'dosieci-ai-operator' ); ?>
						</a>
						<?php esc_html_e( 'albo użyj własnego klucza API w', 'dosieci-ai-operator' ); ?>
						<a href="<?php echo esc_url( $settingsUrl ); ?>"><?php esc_html_e( 'Ustawieniach', 'dosieci-ai-operator' ); ?></a>.
					</p>
				</div>
			<?php elseif ( 'no_key' === $blocker ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'Wybrano własnego dostawcę AI, ale nie zapisano klucza API.', 'dosieci-ai-operator' ); ?>
						<a href="<?php echo esc_url( $settingsUrl ); ?>"><?php esc_html_e( 'Uzupełnij go w Ustawieniach', 'dosieci-ai-operator' ); ?></a>.
					</p>
				</div>
			<?php else : ?>
				<p class="description">
					<strong><?php esc_html_e( 'Tryb:', 'dosieci-ai-operator' ); ?></strong>
					<?php
					echo esc_html(
						$settings->isByok()
							? sprintf(
								/* translators: %s: model name */
								__( 'własny klucz API, model %s — kredyty DoSieci nie są zużywane', 'dosieci-ai-operator' ),
								$settings->effectiveModel()
							)
							: __( 'DoSieci Hub — zapytania rozliczane z kredytów Twojego planu', 'dosieci-ai-operator' )
					);
					?>
				</p>

				<p class="description">
					<?php if ( $writesOn ) : ?>
						<?php
						printf(
							/* translators: 1: total tools, 2: write tools */
							esc_html__( 'Operator ma %1$d narzędzi, w tym %2$d zmieniających witrynę. Każda zmiana wymaga Twojego potwierdzenia w czacie.', 'dosieci-ai-operator' ),
							count( $tools ),
							count( $writeTools )
						);
						?>
					<?php else : ?>
						<?php
						printf(
							/* translators: %d: number of available tools */
							esc_html__( 'Operator ma dostęp do %d narzędzi tylko do odczytu. Żadne z nich nie zmienia danych na tej witrynie.', 'dosieci-ai-operator' ),
							count( $tools )
						);
						?>
						<a href="<?php echo esc_url( $settingsUrl ); ?>">
							<?php esc_html_e( 'Włącz zmiany na witrynie', 'dosieci-ai-operator' ); ?>
						</a>
					<?php endif; ?>
				</p>

				<div id="dosieci-ai-chat-log" class="dosieci-ai-chat-log" aria-live="polite" role="log"></div>

				<form id="dosieci-ai-chat-form" class="dosieci-ai-chat-form">
					<label class="screen-reader-text" for="dosieci-ai-message">
						<?php esc_html_e( 'Wiadomość do operatora', 'dosieci-ai-operator' ); ?>
					</label>
					<textarea id="dosieci-ai-message" rows="3"
						placeholder="<?php echo esc_attr( $writesOn
							? __( 'np. Zbuduj stronę główną z sekcją o firmie i dodaj ją do menu', 'dosieci-ai-operator' )
							: __( 'np. Sprawdź stan techniczny witryny i największe autoloadowane opcje', 'dosieci-ai-operator' ) ); ?>"></textarea>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Wyślij', 'dosieci-ai-operator' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

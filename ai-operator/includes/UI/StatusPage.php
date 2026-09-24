<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

use DoSieci\AiOperator\Plugin;

/**
 * The pairing screen: the whole "Połącz z DoSieci" flow lives here, so an
 * administrator never has to touch curl or the REST API by hand to get the
 * plugin working.
 *
 * The site's signing secret is never rendered on this page -- only the
 * key id and site id, which are safe identifiers.
 */
final class StatusPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		$connection = $this->plugin->connections()->get();
		?>
		<div class="wrap dosieci-ai">
			<h1><?php esc_html_e( 'Połączenie z DoSieci', 'dosieci-ai-operator' ); ?></h1>

			<?php $this->renderNotice(); ?>

			<?php if ( null !== $connection ) : ?>
				<table class="widefat striped dosieci-ai-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'dosieci-ai-operator' ); ?></th>
							<td><span class="dosieci-ai-badge ok"><?php esc_html_e( 'Połączono', 'dosieci-ai-operator' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Adres Hub', 'dosieci-ai-operator' ); ?></th>
							<td><code><?php echo esc_html( $connection->hubUrl ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Identyfikator witryny', 'dosieci-ai-operator' ); ?></th>
							<td><code><?php echo esc_html( $connection->siteId ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Identyfikator klucza', 'dosieci-ai-operator' ); ?></th>
							<td><code><?php echo esc_html( $connection->keyId ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Sparowano', 'dosieci-ai-operator' ); ?></th>
							<td>
								<?php
								echo esc_html(
									$connection->pairedAt > 0
										? wp_date( 'Y-m-d H:i', $connection->pairedAt )
										: '—'
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="description">
					<?php esc_html_e( 'Sekret podpisujący tej witryny nigdy nie jest wyświetlany ani zwracany przez wtyczkę. Jeśli podejrzewasz jego wyciek, unieważnij klucz w panelu DoSieci i sparuj witrynę ponownie.', 'dosieci-ai-operator' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_ai_disconnect' ); ?>
					<input type="hidden" name="action" value="dosieci_ai_disconnect">
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Rozłącz tę witrynę', 'dosieci-ai-operator' ); ?>
					</button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Aby połączyć witrynę, wygeneruj jednorazowy token parowania w panelu DoSieci, a następnie wklej go poniżej.', 'dosieci-ai-operator' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_ai_pair' ); ?>
					<input type="hidden" name="action" value="dosieci_ai_pair">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dosieci-hub-url"><?php esc_html_e( 'Adres Hub', 'dosieci-ai-operator' ); ?></label></th>
							<td>
								<input type="url" id="dosieci-hub-url" name="hub_url" class="regular-text"
									value="https://license.dosieci.pl" required>
								<p class="description"><?php esc_html_e( 'Musi zaczynać się od https://', 'dosieci-ai-operator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dosieci-token"><?php esc_html_e( 'Token parowania', 'dosieci-ai-operator' ); ?></label></th>
							<td>
								<input type="text" id="dosieci-token" name="pairing_token" class="regular-text"
									autocomplete="off" required>
								<p class="description"><?php esc_html_e( 'Token jest jednorazowy i krótko ważny.', 'dosieci-ai-operator' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Połącz z DoSieci', 'dosieci-ai-operator' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function renderNotice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result, no state change.
		if ( ! isset( $_GET['dosieci_notice'], $_GET['dosieci_message'] ) ) {
			return;
		}

		$type    = 'success' === $_GET['dosieci_notice'] ? 'success' : 'error';
		$message = sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['dosieci_message'] ) ) );
		// phpcs:enable

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}

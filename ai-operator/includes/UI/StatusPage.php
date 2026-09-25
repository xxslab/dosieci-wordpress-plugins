<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

use DoSieci\AiOperator\Plugin;

/**
 * The pairing screen: the whole "Connect to DoSieci" flow lives here, so an
 * administrator never has to touch curl or the REST API by hand to get the
 * plugin working.
 *
 * The site's signing secret is never rendered on this page -- only the
 * key id and site id, which are safe identifiers.
 */
final class StatusPage {

	/**
	 * Where the "how to get a pairing token" link points.
	 */
	private const TOKEN_HELP_URL = 'https://dosieci.pl/wtyczki/ai-operator/';

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		$connection = $this->plugin->connections()->get();
		?>
		<div class="wrap dosieci-ai">
			<h1><?php esc_html_e( 'Connection to DoSieci', 'dosieci-ai-operator' ); ?></h1>

			<?php $this->renderNotice(); ?>

			<?php if ( null !== $connection ) : ?>
				<table class="widefat striped dosieci-ai-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'dosieci-ai-operator' ); ?></th>
							<td><span class="dosieci-ai-badge ok"><?php esc_html_e( 'Connected', 'dosieci-ai-operator' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hub address', 'dosieci-ai-operator' ); ?></th>
							<td><code><?php echo esc_html( $connection->hubUrl ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Site ID', 'dosieci-ai-operator' ); ?></th>
							<td><code><?php echo esc_html( $connection->siteId ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Key ID', 'dosieci-ai-operator' ); ?></th>
							<td><code><?php echo esc_html( $connection->keyId ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Paired', 'dosieci-ai-operator' ); ?></th>
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
					<?php esc_html_e( 'The plugin never displays or returns this site’s signing secret. If you suspect it has leaked, ask DoSieci to revoke the key and pair the site again.', 'dosieci-ai-operator' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_ai_disconnect' ); ?>
					<input type="hidden" name="action" value="dosieci_ai_disconnect">
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Disconnect this site', 'dosieci-ai-operator' ); ?>
					</button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Pair this site with DoSieci to use the AI credits from your DoSieci plan: no AI provider account or API key of your own is needed. DoSieci gives you a one-time pairing token; paste it below.', 'dosieci-ai-operator' ); ?></p>
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: URL of the AI Operator page at dosieci.pl, 2: URL of the plugin settings screen */
							__( '<a href="%1$s" target="_blank" rel="noopener">How to get a pairing token</a>. Prefer your own AI key (WordPress AI connectors, OpenAI or Anthropic)? Choose it in the <a href="%2$s">settings</a>; no pairing is needed then.', 'dosieci-ai-operator' ),
							esc_url( self::TOKEN_HELP_URL ),
							esc_url( admin_url( 'admin.php?page=' . AdminMenu::SLUG . '-settings' ) )
						),
						array(
							'a' => array(
								'href'   => array(),
								'target' => array(),
								'rel'    => array(),
							),
						)
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_ai_pair' ); ?>
					<input type="hidden" name="action" value="dosieci_ai_pair">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dosieci-hub-url"><?php esc_html_e( 'Hub address', 'dosieci-ai-operator' ); ?></label></th>
							<td>
								<input type="url" id="dosieci-hub-url" name="hub_url" class="regular-text"
									value="https://license.dosieci.pl" required>
								<p class="description"><?php esc_html_e( 'Must start with https://', 'dosieci-ai-operator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dosieci-token"><?php esc_html_e( 'Pairing token', 'dosieci-ai-operator' ); ?></label></th>
							<td>
								<input type="text" id="dosieci-token" name="pairing_token" class="regular-text"
									autocomplete="off" required>
								<p class="description"><?php esc_html_e( 'The token works once and expires soon after it is issued.', 'dosieci-ai-operator' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Connect to DoSieci', 'dosieci-ai-operator' ) ); ?>
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

		$type = 'success' === sanitize_key( wp_unslash( (string) $_GET['dosieci_notice'] ) ) ? 'success' : 'error';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() is the outermost call; the sniff does not see through rawurldecode().
		$message = sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['dosieci_message'] ) ) );
		// phpcs:enable

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}

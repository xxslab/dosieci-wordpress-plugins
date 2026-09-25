<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

use DoSieci\AiOperator\Plugin;

/**
 * The current user's own chat history. Deliberately per-user: an editor's
 * conversation is not visible to another editor, and clearing it clears
 * only your own.
 */
final class HistoryPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		if ( isset( $_POST['dosieci_clear_history'] ) ) {
			check_admin_referer( 'dosieci_ai_clear_history' );
			AjaxController::clearHistoryForCurrentUser();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'History cleared.', 'dosieci-ai-operator' ) . '</p></div>';
		}

		$conversation = AjaxController::historyForCurrentUser();
		?>
		<div class="wrap dosieci-ai">
			<h1><?php esc_html_e( 'Conversation history', 'dosieci-ai-operator' ); ?></h1>

			<?php if ( array() === $conversation ) : ?>
				<p><?php esc_html_e( 'No history is saved yet.', 'dosieci-ai-operator' ); ?></p>
			<?php else : ?>
				<div class="dosieci-ai-chat-log">
					<?php foreach ( $conversation as $turn ) : ?>
						<?php
						$role    = isset( $turn['role'] ) ? (string) $turn['role'] : '';
						$content = isset( $turn['content'] ) ? (string) $turn['content'] : '';

						if ( 'assistant_tool_use' === $role ) {
							$label   = __( 'Tool (request)', 'dosieci-ai-operator' );
							$content = (string) ( $turn['tool_name'] ?? '' );
						} elseif ( 'tool_result' === $role ) {
							$label = __( 'Tool (result)', 'dosieci-ai-operator' );
						} elseif ( 'assistant' === $role ) {
							$label = __( 'Operator', 'dosieci-ai-operator' );
						} else {
							$label = __( 'You', 'dosieci-ai-operator' );
						}
						?>
						<div class="dosieci-ai-turn dosieci-ai-turn--<?php echo esc_attr( $role ); ?>">
							<strong><?php echo esc_html( $label ); ?></strong>
							<pre><?php echo esc_html( mb_substr( $content, 0, 4000 ) ); ?></pre>
						</div>
					<?php endforeach; ?>
				</div>

				<form method="post">
					<?php wp_nonce_field( 'dosieci_ai_clear_history' ); ?>
					<button type="submit" name="dosieci_clear_history" value="1" class="button">
						<?php esc_html_e( 'Clear my history', 'dosieci-ai-operator' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

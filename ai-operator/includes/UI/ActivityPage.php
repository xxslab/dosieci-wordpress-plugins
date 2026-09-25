<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

use DoSieci\AiOperator\Domain\Audit\AuditEntry;
use DoSieci\AiOperator\Plugin;

/**
 * The local audit trail: every tool invocation attempted on this site,
 * including the ones that were refused. Site-wide (not per-user) on
 * purpose -- an administrator auditing what the AI did needs to see all of
 * it, and the page already requires manage_options.
 */
final class ActivityPage {

	public function __construct( private Plugin $plugin ) {
	}

	public function render(): void {
		$entries = $this->plugin->auditLog()->recent( 100 );
		?>
		<div class="wrap dosieci-ai">
			<h1><?php esc_html_e( 'Tool activity', 'dosieci-ai-operator' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Every tool call the operator attempted, including the ones that were refused. Secrets are never recorded here.', 'dosieci-ai-operator' ); ?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'dosieci-ai-operator' ); ?></th>
						<th><?php esc_html_e( 'User', 'dosieci-ai-operator' ); ?></th>
						<th><?php esc_html_e( 'Tool', 'dosieci-ai-operator' ); ?></th>
						<th><?php esc_html_e( 'Outcome', 'dosieci-ai-operator' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'dosieci-ai-operator' ); ?></th>
						<th><?php esc_html_e( 'Request ID', 'dosieci-ai-operator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $entries ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No activity recorded yet.', 'dosieci-ai-operator' ); ?></td></tr>
					<?php endif; ?>

					<?php foreach ( $entries as $entry ) : ?>
						<?php $user = get_userdata( $entry->userId ); ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $entry->occurredAt ) ); ?></td>
							<td><?php echo esc_html( $user ? $user->user_login : (string) $entry->userId ); ?></td>
							<td><code><?php echo esc_html( $entry->toolName ); ?></code></td>
							<td>
								<span class="dosieci-ai-badge <?php echo AuditEntry::OUTCOME_ALLOWED === $entry->outcome ? 'ok' : 'warn'; ?>">
									<?php echo esc_html( $entry->outcome ); ?>
								</span>
							</td>
							<td><?php echo esc_html( (string) $entry->reason ); ?></td>
							<td><code><?php echo esc_html( $entry->requestId ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

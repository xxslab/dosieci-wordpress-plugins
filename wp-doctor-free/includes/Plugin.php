<?php

declare(strict_types=1);

namespace DoSieci\WP\Doctor;

use DoSieci\WP\Doctor\Adapter\WordPressFactsCollector;
use DoSieci\WP\Doctor\Domain\CheckResult;
use DoSieci\WP\Doctor\Domain\DiagnosticEngine;

/**
 * DoSieci WP Doctor — free tier: a read-only, explainable technical audit.
 *
 * There is intentionally no repair action of any kind in this build. See
 * DiagnosticEngine's docblock for why (the legacy plugin's unconditional
 * autofix is a documented, audited failure this product exists not to
 * repeat).
 */
final class Plugin {

	public const PAGE_SLUG = 'dosieci-wp-doctor';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'registerPage' ) );
	}

	public function registerPage(): void {
		add_management_page(
			__( 'DoSieci WP Doctor', 'dosieci-wp-doctor' ),
			__( 'WP Doctor', 'dosieci-wp-doctor' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'renderPage' )
		);
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-wp-doctor' ), '', array( 'response' => 403 ) );
		}

		$engine  = new DiagnosticEngine();
		$results = $engine->run( ( new WordPressFactsCollector() )->collect() );
		$summary = $engine->summarise( $results );

		$badges = array(
			CheckResult::STATUS_GOOD     => array( '#dcfce7', '#166534', __( 'OK', 'dosieci-wp-doctor' ) ),
			CheckResult::STATUS_WARNING  => array( '#fef3c7', '#78350f', __( 'UWAGA', 'dosieci-wp-doctor' ) ),
			CheckResult::STATUS_CRITICAL => array( '#fee2e2', '#991b1b', __( 'KRYTYCZNE', 'dosieci-wp-doctor' ) ),
			CheckResult::STATUS_INFO     => array( '#e0e7ff', '#3730a3', __( 'INFO', 'dosieci-wp-doctor' ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci WP Doctor', 'dosieci-wp-doctor' ); ?></h1>

			<p>
				<strong><?php echo esc_html( (string) $summary['score'] ); ?>/100</strong> &middot;
				<?php
				printf(
					/* translators: 1: good count, 2: warning count, 3: critical count */
					esc_html__( '%1$d OK, %2$d ostrzeżeń, %3$d krytycznych', 'dosieci-wp-doctor' ),
					(int) $summary['good'],
					(int) $summary['warning'],
					(int) $summary['critical']
				);
				?>
			</p>

			<p class="description">
				<?php esc_html_e( 'Wynik służy wyłącznie do porównywania kolejnych skanów. Wysoki wynik nie oznacza, że witryna jest bezpieczna — to skrót, nie gwarancja.', 'dosieci-wp-doctor' ); ?>
			</p>

			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Ten skan jest wyłącznie do odczytu. Wtyczka niczego nie zmienia, nie usuwa i nie optymalizuje automatycznie.', 'dosieci-wp-doctor' ); ?></p>
			</div>

			<table class="widefat striped" style="margin-top:1rem;">
				<thead>
					<tr>
						<th style="width:130px;"><?php esc_html_e( 'Status', 'dosieci-wp-doctor' ); ?></th>
						<th><?php esc_html_e( 'Sprawdzenie', 'dosieci-wp-doctor' ); ?></th>
						<th><?php esc_html_e( 'Wynik', 'dosieci-wp-doctor' ); ?></th>
						<th><?php esc_html_e( 'Zalecenie', 'dosieci-wp-doctor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $results as $result ) : ?>
						<?php $badge = $badges[ $result->status ]; ?>
						<tr>
							<td>
								<span style="display:inline-block;padding:0.15rem 0.5rem;border-radius:999px;font-size:0.75rem;font-weight:600;background:<?php echo esc_attr( $badge[0] ); ?>;color:<?php echo esc_attr( $badge[1] ); ?>;">
									<?php echo esc_html( $badge[2] ); ?>
								</span>
							</td>
							<td><strong><?php echo esc_html( $result->label ); ?></strong></td>
							<td><?php echo esc_html( $result->summary ); ?></td>
							<td><?php echo esc_html( $result->recommendation ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\WP\Doctor;

use DoSieci\WP\Doctor\Adapter\WordPressFactsCollector;
use DoSieci\WP\Doctor\Domain\CheckResult;
use DoSieci\WP\Doctor\Domain\DiagnosticEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DoSieci WP Doctor: a read-only, explainable technical audit.
 *
 * There is intentionally no repair action of any kind in this plugin. See
 * DiagnosticEngine's docblock for why.
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
		add_filter( 'plugin_action_links_' . plugin_basename( DOSIECI_WP_DOCTOR_FILE ), array( $this, 'actionLinks' ) );
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

	/**
	 * @param array<int|string, string> $links
	 *
	 * @return array<int|string, string>
	 */
	public function actionLinks( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ),
				esc_html__( 'Run scan', 'dosieci-wp-doctor' )
			)
		);

		return $links;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dosieci-wp-doctor' ), '', array( 'response' => 403 ) );
		}

		$engine  = new DiagnosticEngine();
		$results = $engine->run( ( new WordPressFactsCollector() )->collect() );
		$summary = $engine->summarise( $results );

		$badges = array(
			CheckResult::STATUS_GOOD     => array( '#dcfce7', '#166534', __( 'OK', 'dosieci-wp-doctor' ) ),
			CheckResult::STATUS_WARNING  => array( '#fef3c7', '#78350f', __( 'Warning', 'dosieci-wp-doctor' ) ),
			CheckResult::STATUS_CRITICAL => array( '#fee2e2', '#991b1b', __( 'Critical', 'dosieci-wp-doctor' ) ),
			CheckResult::STATUS_INFO     => array( '#e0e7ff', '#3730a3', __( 'Info', 'dosieci-wp-doctor' ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci WP Doctor', 'dosieci-wp-doctor' ); ?></h1>

			<p>
				<strong><?php echo esc_html( (string) $summary['score'] ); ?>/100</strong> &middot;
				<?php
				printf(
					/* translators: 1: number of passed checks, 2: number of warnings, 3: number of critical problems */
					esc_html__( 'OK: %1$d, warnings: %2$d, critical: %3$d', 'dosieci-wp-doctor' ),
					(int) $summary['good'],
					(int) $summary['warning'],
					(int) $summary['critical']
				);
				?>
			</p>

			<p class="description">
				<?php esc_html_e( 'The score is only meant for comparing one scan with the next. A high score does not mean the site is secure: it is a summary, not a guarantee.', 'dosieci-wp-doctor' ); ?>
			</p>

			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'This scan is read-only. The plugin never changes, deletes or optimizes anything automatically.', 'dosieci-wp-doctor' ); ?></p>
			</div>

			<table class="widefat striped" style="margin-top:1rem;">
				<thead>
					<tr>
						<th style="width:130px;"><?php esc_html_e( 'Status', 'dosieci-wp-doctor' ); ?></th>
						<th><?php esc_html_e( 'Check', 'dosieci-wp-doctor' ); ?></th>
						<th><?php esc_html_e( 'Result', 'dosieci-wp-doctor' ); ?></th>
						<th><?php esc_html_e( 'Recommendation', 'dosieci-wp-doctor' ); ?></th>
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

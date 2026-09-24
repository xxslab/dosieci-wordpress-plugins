<?php

declare(strict_types=1);

namespace DoSieci\Ebay\Connector;

use DoSieci\Ebay\Connector\Adapter\EbayClient;
use DoSieci\Ebay\Connector\Domain\EbayEnvironment;
use DoSieci\Ebay\Connector\Domain\EbayException;

/**
 * DoSieci eBay Connector — free tier: connection test and read-only
 * listing browser on the user's own eBay developer credentials.
 *
 * Explicitly NOT in this build: publishing listings, importing orders,
 * pushing inventory. The client class has no method that writes to eBay at
 * all, so this is enforced by what exists rather than by a feature flag.
 */
final class Plugin {

	public const OPTION_CLIENT_ID     = 'dosieci_ebay_client_id';
	public const OPTION_CLIENT_SECRET = 'dosieci_ebay_client_secret';
	public const OPTION_ENVIRONMENT   = 'dosieci_ebay_environment';
	public const PAGE_SLUG            = 'dosieci-ebay-connector';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'registerPage' ) );
		add_action( 'admin_post_dosieci_ebay_save', array( $this, 'handleSave' ) );
	}

	public function registerPage(): void {
		add_management_page(
			__( 'DoSieci eBay Connector', 'dosieci-ebay-connector' ),
			__( 'eBay Connector', 'dosieci-ebay-connector' ),
			self::menuCapability(),
			self::PAGE_SLUG,
			array( $this, 'renderPage' )
		);
	}

	/**
	 * manage_woocommerce only where WooCommerce actually registers it.
	 *
	 * Hardcoding manage_woocommerce here made the menu item invisible on a
	 * site without WooCommerce -- caught by the real ZIP-install smoke test,
	 * where the page simply never appeared. The plugin is documented as
	 * usable standalone, so it must not gate its only entry point behind a
	 * capability that only exists when another plugin is installed.
	 */
	private static function menuCapability(): string {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	private function assertAllowed(): void {
		// manage_woocommerce where WooCommerce exists, manage_options
		// otherwise -- the plugin is usable standalone, so it must not
		// depend on a capability WooCommerce alone registers.
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-ebay-connector' ), '', array( 'response' => 403 ) );
		}
	}

	public function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-ebay-connector' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dosieci_ebay_save' );

		$clientId    = isset( $_POST['client_id'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['client_id'] ) ) ) : '';
		$clientSecret= isset( $_POST['client_secret'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['client_secret'] ) ) ) : '';
		$environment = isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( (string) $_POST['environment'] ) ) : EbayEnvironment::SANDBOX;

		if ( ! EbayEnvironment::isValid( $environment ) ) {
			$environment = EbayEnvironment::SANDBOX;
		}

		update_option( self::OPTION_CLIENT_ID, $clientId, false );

		// An empty secret field means "leave the stored one alone", so the
		// admin can change the environment without re-typing the credential.
		if ( '' !== $clientSecret ) {
			update_option( self::OPTION_CLIENT_SECRET, $clientSecret, false );
		}

		update_option( self::OPTION_ENVIRONMENT, $environment, false );

		// Environment or credentials changed: the cached token is no longer
		// the right one.
		delete_transient( EbayClient::TOKEN_TRANSIENT . '_' . EbayEnvironment::SANDBOX );
		delete_transient( EbayClient::TOKEN_TRANSIENT . '_' . EbayEnvironment::PRODUCTION );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'saved' => '1' ),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public function renderPage(): void {
		$this->assertAllowed();

		$clientId    = (string) get_option( self::OPTION_CLIENT_ID, '' );
		$hasSecret   = '' !== (string) get_option( self::OPTION_CLIENT_SECRET, '' );
		$environment = new EbayEnvironment( (string) get_option( self::OPTION_ENVIRONMENT, EbayEnvironment::SANDBOX ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page state.
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$saved = isset( $_GET['saved'] );
		// phpcs:enable
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci eBay Connector', 'dosieci-ebay-connector' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Zapisano ustawienia.', 'dosieci-ebay-connector' ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'Wersja darmowa jest wyłącznie do odczytu: sprawdza połączenie i przegląda oferty. Nie wystawia ofert, nie importuje zamówień i nie zmienia stanów magazynowych.', 'dosieci-ebay-connector' ); ?>
					<?php esc_html_e( 'Połączenie idzie bezpośrednio z tej witryny do eBay, na Twoich własnych danych aplikacji (BYOK) — nie przez serwery DoSieci.', 'dosieci-ebay-connector' ); ?>
				</p>
			</div>

			<?php if ( $environment->isProduction() ) : ?>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'Środowisko produkcyjne jest aktywne.', 'dosieci-ebay-connector' ); ?></strong> <?php esc_html_e( 'Zapytania trafiają do prawdziwego eBay.', 'dosieci-ebay-connector' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<h2><?php esc_html_e( 'Dane aplikacji eBay', 'dosieci-ebay-connector' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_ebay_save' ); ?>
					<input type="hidden" name="action" value="dosieci_ebay_save">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ebay-client-id"><?php esc_html_e( 'App ID (Client ID)', 'dosieci-ebay-connector' ); ?></label></th>
							<td><input type="text" id="ebay-client-id" name="client_id" class="regular-text" value="<?php echo esc_attr( $clientId ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="ebay-client-secret"><?php esc_html_e( 'Cert ID (Client Secret)', 'dosieci-ebay-connector' ); ?></label></th>
							<td>
								<input type="password" id="ebay-client-secret" name="client_secret" class="regular-text" autocomplete="off"
									placeholder="<?php echo esc_attr( $hasSecret ? '••••••••' : '' ); ?>">
								<p class="description"><?php esc_html_e( 'Zostaw puste, aby zachować już zapisany sekret.', 'dosieci-ebay-connector' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ebay-env"><?php esc_html_e( 'Środowisko', 'dosieci-ebay-connector' ); ?></label></th>
							<td>
								<select id="ebay-env" name="environment">
									<option value="sandbox" <?php selected( $environment->name, EbayEnvironment::SANDBOX ); ?>><?php esc_html_e( 'Sandbox (domyślne)', 'dosieci-ebay-connector' ); ?></option>
									<option value="production" <?php selected( $environment->name, EbayEnvironment::PRODUCTION ); ?>><?php esc_html_e( 'Production', 'dosieci-ebay-connector' ); ?></option>
								</select>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Zapisz', 'dosieci-ebay-connector' ) ); ?>
				</form>
			<?php endif; ?>

			<?php if ( '' !== $clientId && $hasSecret ) : ?>
				<hr>
				<h2><?php esc_html_e( 'Przeglądaj oferty (tylko odczyt)', 'dosieci-ebay-connector' ); ?></h2>

				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
					<input type="search" name="q" value="<?php echo esc_attr( $query ); ?>" class="regular-text"
						placeholder="<?php esc_attr_e( 'np. buty trekkingowe', 'dosieci-ebay-connector' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Szukaj w eBay', 'dosieci-ebay-connector' ); ?></button>
				</form>

				<?php if ( '' !== $query ) : ?>
					<?php $this->renderSearch( $clientId, (string) get_option( self::OPTION_CLIENT_SECRET, '' ), $environment, $query ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function renderSearch( string $clientId, string $clientSecret, EbayEnvironment $environment, string $query ): void {
		try {
			$result = ( new EbayClient( $clientId, $clientSecret, $environment ) )->search( $query );
		} catch ( EbayException $e ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $e->getMessage() )
			);

			return;
		}
		?>
		<p><?php printf( esc_html__( 'Znaleziono około %d ofert.', 'dosieci-ebay-connector' ), (int) $result['total'] ); ?></p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Oferta', 'dosieci-ebay-connector' ); ?></th>
					<th><?php esc_html_e( 'Cena', 'dosieci-ebay-connector' ); ?></th>
					<th><?php esc_html_e( 'Stan', 'dosieci-ebay-connector' ); ?></th>
					<th><?php esc_html_e( 'Sprzedawca', 'dosieci-ebay-connector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $result['items'] ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Brak wyników.', 'dosieci-ebay-connector' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $result['items'] as $item ) : ?>
					<tr>
						<td>
							<?php if ( null !== $item['url'] ) : ?>
								<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( (string) $item['title'] ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( (string) $item['title'] ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $item['price'] ?? '—' ); ?></td>
						<td><?php echo esc_html( $item['condition'] ?? '—' ); ?></td>
						<td><?php echo esc_html( $item['seller'] ?? '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

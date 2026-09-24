<?php

declare(strict_types=1);

namespace DoSieci\Ebay\Connector;

use DoSieci\Ebay\Connector\Adapter\EbayClient;
use DoSieci\Ebay\Connector\Domain\EbayEnvironment;
use DoSieci\Ebay\Connector\Domain\EbayException;
use DoSieci\Ebay\Connector\Domain\EbayMarketplace;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DoSieci eBay Connector: a connection test and read-only listing browser on
 * the site owner's own eBay developer keys.
 *
 * It does not publish listings, import orders or push inventory: the client
 * has no method that writes to eBay at all, so this is enforced by what
 * exists rather than by a feature flag.
 */
final class Plugin {

	public const OPTION_CLIENT_ID     = 'dosieci_ebay_client_id';
	public const OPTION_CLIENT_SECRET = 'dosieci_ebay_client_secret';
	public const OPTION_ENVIRONMENT   = 'dosieci_ebay_environment';
	public const OPTION_MARKETPLACE   = 'dosieci_ebay_marketplace';
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
		add_filter( 'plugin_action_links_' . plugin_basename( DOSIECI_EBAY_CONNECTOR_FILE ), array( $this, 'actionLinks' ) );
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
				esc_html__( 'Settings', 'dosieci-ebay-connector' )
			)
		);

		return $links;
	}

	/**
	 * Shop managers may browse; the capability they have only exists while
	 * WooCommerce is active, and the plugin also works without WooCommerce.
	 */
	private static function menuCapability(): string {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	private static function marketplace(): string {
		$stored = (string) get_option( self::OPTION_MARKETPLACE, '' );

		return EbayMarketplace::isValid( $stored ) ? $stored : EbayMarketplace::forLocale( get_locale() );
	}

	public function handleSave(): void {
		// Storing credentials is an administrator action.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'dosieci-ebay-connector' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dosieci_ebay_save' );

		$clientId     = isset( $_POST['client_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) ) : '';
		$clientSecret = isset( $_POST['client_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) ) : '';
		$environment  = isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : EbayEnvironment::SANDBOX;
		$marketplace  = isset( $_POST['marketplace'] ) ? sanitize_text_field( wp_unslash( $_POST['marketplace'] ) ) : '';

		update_option( self::OPTION_CLIENT_ID, $clientId, false );

		// An empty secret field means "keep the stored one", so the
		// environment can be changed without typing the credential again.
		if ( '' !== $clientSecret ) {
			update_option( self::OPTION_CLIENT_SECRET, $clientSecret, false );
		}

		update_option( self::OPTION_ENVIRONMENT, EbayEnvironment::isValid( $environment ) ? $environment : EbayEnvironment::SANDBOX, false );
		update_option( self::OPTION_MARKETPLACE, EbayMarketplace::isValid( $marketplace ) ? $marketplace : EbayMarketplace::DEFAULT, false );

		// Environment or credentials changed: a cached token is no longer the
		// right one.
		delete_transient( EbayClient::TOKEN_TRANSIENT . '_' . EbayEnvironment::SANDBOX );
		delete_transient( EbayClient::TOKEN_TRANSIENT . '_' . EbayEnvironment::PRODUCTION );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::PAGE_SLUG,
					'saved' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dosieci-ebay-connector' ), '', array( 'response' => 403 ) );
		}

		$clientId    = (string) get_option( self::OPTION_CLIENT_ID, '' );
		$hasSecret   = '' !== (string) get_option( self::OPTION_CLIENT_SECRET, '' );
		$environment = new EbayEnvironment( EbayEnvironment::isValid( (string) get_option( self::OPTION_ENVIRONMENT, '' ) ) ? (string) get_option( self::OPTION_ENVIRONMENT ) : EbayEnvironment::SANDBOX );
		$marketplace = self::marketplace();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page state (search term, saved flag).
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$saved = isset( $_GET['saved'] );
		// phpcs:enable
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci eBay Connector', 'dosieci-ebay-connector' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'dosieci-ebay-connector' ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'Read-only: tests the connection to eBay and browses listings. It does not publish listings, import orders or change stock levels.', 'dosieci-ebay-connector' ); ?>
					<?php esc_html_e( 'Requests go from this site straight to eBay with your own application keys, never through DoSieci.', 'dosieci-ebay-connector' ); ?>
				</p>
			</div>

			<?php if ( $environment->isProduction() ) : ?>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'The Production environment is active.', 'dosieci-ebay-connector' ); ?></strong> <?php esc_html_e( 'Requests go to the live eBay.', 'dosieci-ebay-connector' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<h2><?php esc_html_e( 'eBay application keys', 'dosieci-ebay-connector' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Create a keyset in the eBay Developers Program (developer.ebay.com) and paste it here. Sandbox and Production have separate keysets.', 'dosieci-ebay-connector' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_ebay_save' ); ?>
					<input type="hidden" name="action" value="dosieci_ebay_save">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ebay-client-id"><?php esc_html_e( 'App ID (Client ID)', 'dosieci-ebay-connector' ); ?></label></th>
							<td><input type="text" id="ebay-client-id" name="client_id" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $clientId ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="ebay-client-secret"><?php esc_html_e( 'Cert ID (Client Secret)', 'dosieci-ebay-connector' ); ?></label></th>
							<td>
								<input type="password" id="ebay-client-secret" name="client_secret" class="regular-text" autocomplete="off"
									placeholder="<?php echo esc_attr( $hasSecret ? '••••••••' : '' ); ?>">
								<p class="description"><?php esc_html_e( 'Leave empty to keep the saved secret.', 'dosieci-ebay-connector' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ebay-env"><?php esc_html_e( 'Environment', 'dosieci-ebay-connector' ); ?></label></th>
							<td>
								<select id="ebay-env" name="environment">
									<option value="sandbox" <?php selected( $environment->name, EbayEnvironment::SANDBOX ); ?>><?php esc_html_e( 'Sandbox (default)', 'dosieci-ebay-connector' ); ?></option>
									<option value="production" <?php selected( $environment->name, EbayEnvironment::PRODUCTION ); ?>><?php esc_html_e( 'Production', 'dosieci-ebay-connector' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ebay-marketplace"><?php esc_html_e( 'Marketplace', 'dosieci-ebay-connector' ); ?></label></th>
							<td>
								<select id="ebay-marketplace" name="marketplace">
									<?php foreach ( EbayMarketplace::all() as $id => $site ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $marketplace, $id ); ?>><?php echo esc_html( $site ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'The eBay site searched, which also decides the currency of the prices.', 'dosieci-ebay-connector' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save', 'dosieci-ebay-connector' ) ); ?>
				</form>
			<?php endif; ?>

			<?php if ( '' !== $clientId && $hasSecret ) : ?>
				<hr>
				<h2><?php esc_html_e( 'Browse listings (read-only)', 'dosieci-ebay-connector' ); ?></h2>

				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
					<label class="screen-reader-text" for="ebay-q"><?php esc_html_e( 'Search eBay', 'dosieci-ebay-connector' ); ?></label>
					<input type="search" id="ebay-q" name="q" value="<?php echo esc_attr( $query ); ?>" class="regular-text"
						placeholder="<?php esc_attr_e( 'for example: hiking boots', 'dosieci-ebay-connector' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Search eBay', 'dosieci-ebay-connector' ); ?></button>
				</form>

				<?php if ( '' !== $query ) : ?>
					<?php $this->renderSearch( $clientId, (string) get_option( self::OPTION_CLIENT_SECRET, '' ), $environment, $marketplace, $query ); ?>
				<?php endif; ?>
			<?php elseif ( ! current_user_can( 'manage_options' ) ) : ?>
				<p><?php esc_html_e( 'An administrator needs to enter the eBay application keys first.', 'dosieci-ebay-connector' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function renderSearch( string $clientId, string $clientSecret, EbayEnvironment $environment, string $marketplace, string $query ): void {
		try {
			$result = ( new EbayClient( $clientId, $clientSecret, $environment, $marketplace ) )->search( $query );
		} catch ( EbayException $e ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $e->getMessage() ) );

			return;
		}
		?>
		<p>
			<?php
			printf(
				/* translators: 1: approximate number of listings found, 2: eBay site, e.g. ebay.pl */
				esc_html( _n( 'About %1$d listing found on %2$s.', 'About %1$d listings found on %2$s.', (int) $result['total'], 'dosieci-ebay-connector' ) ),
				(int) $result['total'],
				esc_html( EbayMarketplace::all()[ $marketplace ] ?? $marketplace )
			);
			?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Listing', 'dosieci-ebay-connector' ); ?></th>
					<th><?php esc_html_e( 'Price', 'dosieci-ebay-connector' ); ?></th>
					<th><?php esc_html_e( 'Condition', 'dosieci-ebay-connector' ); ?></th>
					<th><?php esc_html_e( 'Seller', 'dosieci-ebay-connector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $result['items'] ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No results.', 'dosieci-ebay-connector' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $result['items'] as $item ) : ?>
					<tr>
						<td>
							<?php if ( null !== $item['url'] ) : ?>
								<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $item['title'] ); ?></a>
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

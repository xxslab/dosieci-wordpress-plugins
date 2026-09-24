<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\UI;

use DoSieci\AiOperator\Plugin;

/**
 * The wp-admin surface.
 *
 * Only the screens with a real backend behind them are registered. The
 * architecture document also lists "Zadania" (Tasks) and "Harmonogramy"
 * (Schedules); those are deliberately ABSENT here rather than added as
 * empty placeholders -- shipping a menu item that opens an empty page
 * misrepresents what the product does, and this repository's release
 * discipline treats that as worse than a shorter menu.
 *
 * Every page requires 'manage_options'. That is the outer gate; individual
 * tools additionally require their own, narrower capability at execution
 * time (ToolDispatcher).
 */
final class AdminMenu {

	public const CAPABILITY = 'manage_options';
	public const SLUG       = 'dosieci-ai-operator';

	public function __construct( private Plugin $plugin ) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenuPages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'admin_post_dosieci_ai_pair', array( $this, 'handlePairing' ) );
		add_action( 'admin_post_dosieci_ai_disconnect', array( $this, 'handleDisconnect' ) );
	}

	public function addMenuPages(): void {
		add_menu_page(
			__( 'DoSieci AI Operator', 'dosieci-ai-operator' ),
			__( 'DoSieci AI', 'dosieci-ai-operator' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'renderChat' ),
			'dashicons-format-chat',
			58
		);

		$pages = array(
			self::SLUG              => array( __( 'Czat', 'dosieci-ai-operator' ), array( $this, 'renderChat' ) ),
			self::SLUG . '-builder' => array( __( 'Kreator witryny', 'dosieci-ai-operator' ), array( $this, 'renderSiteBuilder' ) ),
			self::SLUG . '-status'  => array( __( 'Połączenie', 'dosieci-ai-operator' ), array( $this, 'renderStatus' ) ),
			self::SLUG . '-history' => array( __( 'Historia', 'dosieci-ai-operator' ), array( $this, 'renderHistory' ) ),
			self::SLUG . '-activity'=> array( __( 'Aktywność', 'dosieci-ai-operator' ), array( $this, 'renderActivity' ) ),
			self::SLUG . '-settings'=> array( __( 'Ustawienia', 'dosieci-ai-operator' ), array( $this, 'renderSettings' ) ),
		);

		foreach ( $pages as $slug => $page ) {
			add_submenu_page( self::SLUG, $page[0], $page[0], self::CAPABILITY, $slug, $page[1] );
		}
	}

	public function enqueueAssets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'dosieci-ai-operator',
			DOSIECI_AI_OPERATOR_URL . 'assets/admin.css',
			array(),
			DOSIECI_AI_OPERATOR_VERSION
		);

		wp_enqueue_script(
			'dosieci-ai-operator',
			DOSIECI_AI_OPERATOR_URL . 'assets/chat.js',
			array(),
			DOSIECI_AI_OPERATOR_VERSION,
			true
		);

		// Only on the Site Builder screen -- the chat screens have no use
		// for it and should not pay for the download.
		if ( str_contains( $hook, self::SLUG . '-builder' ) ) {
			wp_enqueue_script(
				'dosieci-ai-operator-site-builder',
				DOSIECI_AI_OPERATOR_URL . 'assets/site-builder.js',
				array( 'dosieci-ai-operator' ),
				DOSIECI_AI_OPERATOR_VERSION,
				true
			);

			wp_localize_script(
				'dosieci-ai-operator-site-builder',
				'dosieciAiSiteBuilder',
				array( 'strings' => SiteBuilderPage::scriptStrings() )
			);
		}

		wp_localize_script(
			'dosieci-ai-operator',
			'dosieciAiOperator',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				// Nonce for the chat AJAX endpoint; AjaxController verifies
				// it AND re-checks the capability (a nonce proves intent,
				// not authorisation).
				'nonce'   => wp_create_nonce( AjaxController::NONCE_ACTION ),
				// A SEPARATE nonce for the Site Builder endpoints. Reusing
				// the chat nonce would mean a token minted for "send a
				// message" also authorises "approve and run an 18-step
				// build" -- different capability in practice, so different
				// nonce action.
				'builderNonce' => wp_create_nonce( SiteBuilderAjaxController::NONCE_ACTION ),
				'strings' => array(
					'youLabel'      => __( 'Ty', 'dosieci-ai-operator' ),
					'operatorLabel' => __( 'Operator', 'dosieci-ai-operator' ),
					'thinking'      => __( 'Operator myśli…', 'dosieci-ai-operator' ),
					'applying'      => __( 'Wykonuję zatwierdzoną zmianę…', 'dosieci-ai-operator' ),
					'error'         => __( 'Wystąpił błąd.', 'dosieci-ai-operator' ),
					'toolRan'       => __( 'Wykonano narzędzie', 'dosieci-ai-operator' ),
					'toolDenied'    => __( 'Odmówiono narzędzia', 'dosieci-ai-operator' ),
					'confirmNeeded' => __( 'Operator prosi o zgodę na zmianę.', 'dosieci-ai-operator' ),
					'confirmTitle'  => __( 'Zatwierdź zmianę na witrynie', 'dosieci-ai-operator' ),
					'confirmApprove'=> __( 'Zatwierdź i wykonaj', 'dosieci-ai-operator' ),
					'confirmReject' => __( 'Odrzuć', 'dosieci-ai-operator' ),
					'confirmDestructive' => __( 'Uwaga: to działanie usuwa treść. Można ją przywrócić z kosza.', 'dosieci-ai-operator' ),
				),
			)
		);
	}

	private function assertCapability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-ai-operator' ), '', array( 'response' => 403 ) );
		}
	}

	public function renderChat(): void {
		$this->assertCapability();
		( new ChatPage( $this->plugin ) )->render();
	}

	public function renderSiteBuilder(): void {
		$this->assertCapability();
		( new SiteBuilderPage( $this->plugin ) )->render();
	}

	public function renderStatus(): void {
		$this->assertCapability();
		( new StatusPage( $this->plugin ) )->render();
	}

	public function renderHistory(): void {
		$this->assertCapability();
		( new HistoryPage( $this->plugin ) )->render();
	}

	public function renderActivity(): void {
		$this->assertCapability();
		( new ActivityPage( $this->plugin ) )->render();
	}

	public function renderSettings(): void {
		$this->assertCapability();
		( new SettingsPage( $this->plugin ) )->render();
	}

	/**
	 * Handles the "Połącz z DoSieci" form: exchanges the one-time pairing
	 * token for this installation's permanent identity.
	 */
	public function handlePairing(): void {
		$this->assertCapability();
		check_admin_referer( 'dosieci_ai_pair' );

		$hubUrl = isset( $_POST['hub_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['hub_url'] ) ) : '';
		$token  = isset( $_POST['pairing_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['pairing_token'] ) ) : '';

		if ( '' === $hubUrl || '' === $token ) {
			$this->redirectToStatus( 'error', __( 'Podaj adres Hub oraz token parowania.', 'dosieci-ai-operator' ) );
		}

		try {
			$connection = $this->plugin->hubClient()->completePairing( $hubUrl, $token, home_url( '/' ) );
			$this->plugin->connections()->save( $connection );
		} catch ( \Throwable $e ) {
			// The exception text can name the Hub error code but never a
			// credential -- the token the user typed is not echoed back.
			$this->redirectToStatus( 'error', $e->getMessage() );
		}

		$this->redirectToStatus( 'success', __( 'Połączono z DoSieci.', 'dosieci-ai-operator' ) );
	}

	public function handleDisconnect(): void {
		$this->assertCapability();
		check_admin_referer( 'dosieci_ai_disconnect' );

		$this->plugin->connections()->clear();

		$this->redirectToStatus(
			'success',
			__( 'Rozłączono lokalnie. Pamiętaj, aby unieważnić klucz tej witryny również w panelu DoSieci.', 'dosieci-ai-operator' )
		);
	}

	private function redirectToStatus( string $type, string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::SLUG . '-status',
					'dosieci_notice' => $type,
					'dosieci_message'=> rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

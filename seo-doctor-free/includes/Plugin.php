<?php

declare(strict_types=1);

namespace DoSieci\SEO\Doctor;

use DoSieci\SEO\Doctor\Adapter\OpenAiClient;
use DoSieci\SEO\Doctor\Domain\ByokKeyStore;
use DoSieci\SEO\Doctor\Domain\SeoAuditor;
use DoSieci\SEO\Doctor\Domain\SeoFinding;
use DoSieci\SEO\Doctor\Domain\SuggestionPrompt;

/**
 * DoSieci SEO Doctor — free tier.
 *
 * Read-only audit of one post/product at a time, plus optional BYOK AI
 * suggestions. The plugin never writes a title tag, canonical, sitemap or
 * schema — that is the coexistence contract with Yoast/Rank Math/AIOSEO/
 * SEOPress (PRODUCT_SCOPE.md). Suggestions are shown for the human to copy;
 * there is no "apply" button in this build.
 */
final class Plugin {

	public const OPTION_API_KEY = 'dosieci_seo_doctor_api_key';
	public const OPTION_MODEL   = 'dosieci_seo_doctor_model';
	public const PAGE_SLUG      = 'dosieci-seo-doctor';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'registerPage' ) );
		add_action( 'admin_post_dosieci_seo_save_key', array( $this, 'handleSaveKey' ) );
		add_action( 'admin_post_dosieci_seo_suggest', array( $this, 'handleSuggest' ) );
	}

	public function registerPage(): void {
		add_management_page(
			__( 'DoSieci SEO Doctor', 'dosieci-seo-doctor' ),
			__( 'SEO Doctor', 'dosieci-seo-doctor' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'renderPage' )
		);
	}

	private function assertCanEdit(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-seo-doctor' ), '', array( 'response' => 403 ) );
		}
	}

	public function handleSaveKey(): void {
		// Storing a provider credential is an administrator action, not an
		// editor one, so it needs a stricter capability than the audit page.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-seo-doctor' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dosieci_seo_save_key' );

		$key   = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) ) : '';
		$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['model'] ) ) : 'gpt-4o-mini';

		if ( '' === $key ) {
			delete_option( self::OPTION_API_KEY );
			$this->redirect( 'success', __( 'Klucz API usunięty.', 'dosieci-seo-doctor' ) );
		}

		if ( ! ByokKeyStore::looksPlausible( $key ) ) {
			$this->redirect( 'error', __( 'To nie wygląda na klucz API. Wklej sam klucz, bez cudzysłowów i bez całego polecenia curl.', 'dosieci-seo-doctor' ) );
		}

		// autoload=false: a provider credential has no business being loaded
		// into memory on every front-end request.
		update_option( self::OPTION_API_KEY, $key, false );
		update_option( self::OPTION_MODEL, $model, false );

		$this->redirect( 'success', __( 'Zapisano klucz API.', 'dosieci-seo-doctor' ) );
	}

	public function handleSuggest(): void {
		$this->assertCanEdit();
		check_admin_referer( 'dosieci_seo_suggest' );

		$postId = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$post   = get_post( $postId );

		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $postId ) ) {
			$this->redirect( 'error', __( 'Brak dostępu do tego wpisu.', 'dosieci-seo-doctor' ) );
		}

		$key = (string) get_option( self::OPTION_API_KEY, '' );
		if ( '' === $key ) {
			$this->redirect( 'error', __( 'Najpierw zapisz własny klucz API.', 'dosieci-seo-doctor' ) );
		}

		try {
			$raw    = ( new OpenAiClient( $key, (string) get_option( self::OPTION_MODEL, 'gpt-4o-mini' ) ) )
				->complete( SuggestionPrompt::build( $post->post_title, wp_strip_all_tags( $post->post_content ) ) );
			$parsed = SuggestionPrompt::parse( $raw );
		} catch ( \RuntimeException $e ) {
			$this->redirect( 'error', ByokKeyStore::redactFrom( $e->getMessage(), $key ) );
		}

		set_transient( 'dosieci_seo_suggestion_' . get_current_user_id(), $parsed, 10 * MINUTE_IN_SECONDS );

		$this->redirect( 'success', __( 'Propozycje wygenerowane.', 'dosieci-seo-doctor' ), $postId );
	}

	private function redirect( string $type, string $message, int $postId = 0 ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'post_id'     => $postId ?: null,
					'seo_notice'  => $type,
					'seo_message' => rawurlencode( $message ),
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public function renderPage(): void {
		$this->assertCanEdit();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page state.
		$postId  = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		$notice  = isset( $_GET['seo_notice'] ) ? sanitize_key( (string) $_GET['seo_notice'] ) : '';
		$message = isset( $_GET['seo_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['seo_message'] ) ) ) : '';
		// phpcs:enable

		$storedKey  = (string) get_option( self::OPTION_API_KEY, '' );
		$suggestion = get_transient( 'dosieci_seo_suggestion_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci SEO Doctor', 'dosieci-seo-doctor' ); ?></h1>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'Audyt jest wyłącznie do odczytu — wtyczka nie nadpisuje tytułów, canonicali, sitemapy ani schema. Współpracuje z Yoast, Rank Math, AIOSEO i SEOPress, nie zastępuje ich.', 'dosieci-seo-doctor' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'Audyt wpisu', 'dosieci-seo-doctor' ); ?></h2>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label for="dosieci-seo-post"><?php esc_html_e( 'ID wpisu lub produktu', 'dosieci-seo-doctor' ); ?></label>
				<input type="number" id="dosieci-seo-post" name="post_id" value="<?php echo esc_attr( (string) $postId ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Zbadaj', 'dosieci-seo-doctor' ); ?></button>
			</form>

			<?php
			if ( $postId > 0 ) {
				$post = get_post( $postId );

				if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $postId ) ) {
					echo '<p>' . esc_html__( 'Nie znaleziono wpisu lub brak do niego dostępu.', 'dosieci-seo-doctor' ) . '</p>';
				} else {
					$this->renderAudit( $post, $storedKey, is_array( $suggestion ) ? $suggestion : null );
				}
			}
			?>

			<hr>

			<h2><?php esc_html_e( 'Twój klucz API (BYOK)', 'dosieci-seo-doctor' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Podpowiedzi AI korzystają z Twojego własnego klucza i Twojego rozliczenia u dostawcy. Zapytanie idzie bezpośrednio z tej witryny do dostawcy — nie przechodzi przez serwery DoSieci.', 'dosieci-seo-doctor' ); ?>
			</p>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_seo_save_key' ); ?>
					<input type="hidden" name="action" value="dosieci_seo_save_key">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dosieci-seo-key"><?php esc_html_e( 'Klucz API OpenAI', 'dosieci-seo-doctor' ); ?></label></th>
							<td>
								<input type="password" id="dosieci-seo-key" name="api_key" class="regular-text" autocomplete="off"
									placeholder="<?php echo esc_attr( '' !== $storedKey ? ByokKeyStore::mask( $storedKey ) : 'sk-...' ); ?>">
								<p class="description"><?php esc_html_e( 'Zostaw puste i zapisz, aby usunąć zapisany klucz. Klucz nigdy nie jest wyświetlany w całości ani zapisywany w logach.', 'dosieci-seo-doctor' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dosieci-seo-model"><?php esc_html_e( 'Model', 'dosieci-seo-doctor' ); ?></label></th>
							<td>
								<input type="text" id="dosieci-seo-model" name="model" class="regular-text"
									value="<?php echo esc_attr( (string) get_option( self::OPTION_MODEL, 'gpt-4o-mini' ) ); ?>">
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Zapisz klucz', 'dosieci-seo-doctor' ) ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Klucz API może skonfigurować wyłącznie administrator.', 'dosieci-seo-doctor' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array{title:string, description:string, keyphrase:string}|null $suggestion
	 */
	private function renderAudit( \WP_Post $post, string $storedKey, ?array $suggestion ): void {
		$description = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		if ( '' === $description ) {
			$description = (string) get_post_meta( $post->ID, 'rank_math_description', true );
		}
		if ( '' === $description ) {
			$description = (string) $post->post_excerpt;
		}

		$images = array();
		if ( preg_match_all( '/<img[^>]+>/i', $post->post_content, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				preg_match( '/src=["\']([^"\']+)["\']/i', $tag, $src );
				preg_match( '/alt=["\']([^"\']*)["\']/i', $tag, $alt );
				$images[] = array(
					'src' => $src[1] ?? '',
					'alt' => $alt[1] ?? '',
				);
			}
		}

		$findings = ( new SeoAuditor() )->audit(
			$post->post_title,
			$description,
			wp_strip_all_tags( $post->post_content ),
			$images,
			$post->post_name
		);
		?>
		<h3><?php echo esc_html( $post->post_title ); ?></h3>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:110px;"><?php esc_html_e( 'Status', 'dosieci-seo-doctor' ); ?></th>
					<th><?php esc_html_e( 'Wynik', 'dosieci-seo-doctor' ); ?></th>
					<th><?php esc_html_e( 'Zalecenie', 'dosieci-seo-doctor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $findings as $finding ) : ?>
					<tr>
						<td>
							<?php if ( SeoFinding::SEVERITY_OK === $finding->severity ) : ?>
								<span style="color:#166534;font-weight:600;">OK</span>
							<?php elseif ( SeoFinding::SEVERITY_CRITICAL === $finding->severity ) : ?>
								<span style="color:#991b1b;font-weight:600;"><?php esc_html_e( 'KRYTYCZNE', 'dosieci-seo-doctor' ); ?></span>
							<?php else : ?>
								<span style="color:#78350f;font-weight:600;"><?php esc_html_e( 'UWAGA', 'dosieci-seo-doctor' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $finding->summary ); ?></td>
						<td><?php echo esc_html( $finding->recommendation ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( '' !== $storedKey ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem;">
				<?php wp_nonce_field( 'dosieci_seo_suggest' ); ?>
				<input type="hidden" name="action" value="dosieci_seo_suggest">
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Zaproponuj metadane (Twój klucz AI)', 'dosieci-seo-doctor' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php if ( null !== $suggestion ) : ?>
			<h3><?php esc_html_e( 'Propozycje AI', 'dosieci-seo-doctor' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Skopiuj to, co uznasz za trafne. Wtyczka niczego nie nadpisuje automatycznie.', 'dosieci-seo-doctor' ); ?></p>
			<table class="widefat striped">
				<tr><th><?php esc_html_e( 'Tytuł', 'dosieci-seo-doctor' ); ?></th><td><?php echo esc_html( $suggestion['title'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Opis', 'dosieci-seo-doctor' ); ?></th><td><?php echo esc_html( $suggestion['description'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Fraza kluczowa', 'dosieci-seo-doctor' ); ?></th><td><?php echo esc_html( $suggestion['keyphrase'] ); ?></td></tr>
			</table>
		<?php endif; ?>
		<?php
	}
}

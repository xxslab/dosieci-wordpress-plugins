<?php

declare(strict_types=1);

namespace DoSieci\Translator;

use DoSieci\Translator\Adapter\DeepLClient;
use DoSieci\Translator\Domain\DeepLLanguages;
use DoSieci\Translator\Domain\TranslationException;
use DoSieci\Translator\Domain\TranslationJob;

/**
 * DoSieci Translator for WooCommerce — free tier.
 *
 * One post/product, one field, one target language, always with a preview
 * before anything is written. The preview is not a nicety: a machine
 * translation applied straight into a live product description is very hard
 * to notice and very expensive to reverse across a catalogue.
 *
 * BYOK: the user's own DeepL key, called directly from this site. DoSieci
 * never proxies or sees the request.
 */
final class Plugin {

	public const OPTION_API_KEY = 'dosieci_translator_deepl_key';
	public const PAGE_SLUG      = 'dosieci-translator';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'registerPage' ) );
		add_action( 'admin_post_dosieci_translator_save_key', array( $this, 'handleSaveKey' ) );
		add_action( 'admin_post_dosieci_translator_preview', array( $this, 'handlePreview' ) );
		add_action( 'admin_post_dosieci_translator_apply', array( $this, 'handleApply' ) );
	}

	public function registerPage(): void {
		add_management_page(
			__( 'DoSieci Translator', 'dosieci-translator' ),
			__( 'Translator', 'dosieci-translator' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'renderPage' )
		);
	}

	public function handleSaveKey(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-translator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dosieci_translator_save_key' );

		$key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) ) : '';

		if ( '' === $key ) {
			delete_option( self::OPTION_API_KEY );
			$this->redirect( 'success', __( 'Klucz DeepL usunięty.', 'dosieci-translator' ) );
		}

		update_option( self::OPTION_API_KEY, $key, false );
		$this->redirect( 'success', __( 'Zapisano klucz DeepL.', 'dosieci-translator' ) );
	}

	public function handlePreview(): void {
		$job = $this->jobFromRequest( 'dosieci_translator_preview' );
		$key = (string) get_option( self::OPTION_API_KEY, '' );

		if ( '' === $key ) {
			$this->redirect( 'error', __( 'Najpierw zapisz własny klucz DeepL.', 'dosieci-translator' ) );
		}

		try {
			$translated = ( new DeepLClient( $key ) )->translate(
				$job->sourceText,
				$job->targetLanguage,
				TranslationJob::FIELD_CONTENT === $job->field
			);
		} catch ( TranslationException $e ) {
			$this->redirect( 'error', $e->getMessage(), $job->postId );
		}

		set_transient(
			$this->previewKey( $job->postId ),
			array(
				'field'    => $job->field,
				'language' => $job->targetLanguage,
				'source'   => $job->sourceText,
				'result'   => $translated,
			),
			30 * MINUTE_IN_SECONDS
		);

		$this->redirect( 'success', __( 'Tłumaczenie gotowe do podglądu. Nic jeszcze nie zostało zapisane.', 'dosieci-translator' ), $job->postId );
	}

	/**
	 * Writes the previewed translation, and ONLY the previewed one -- the
	 * text applied is read back from the stored preview, never re-fetched
	 * or taken from the request body, so what the human approved is exactly
	 * what gets written.
	 */
	public function handleApply(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-translator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dosieci_translator_apply' );

		$postId = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( ! current_user_can( 'edit_post', $postId ) ) {
			wp_die( esc_html__( 'Brak uprawnień do tego wpisu.', 'dosieci-translator' ), '', array( 'response' => 403 ) );
		}

		$preview = get_transient( $this->previewKey( $postId ) );

		if ( ! is_array( $preview ) || ! isset( $preview['field'], $preview['result'] ) ) {
			$this->redirect( 'error', __( 'Podgląd wygasł. Wygeneruj tłumaczenie ponownie.', 'dosieci-translator' ), $postId );
		}

		$field = (string) $preview['field'];
		if ( ! TranslationJob::isSupportedField( $field ) ) {
			$this->redirect( 'error', __( 'Nieobsługiwane pole.', 'dosieci-translator' ), $postId );
		}

		$column = match ( $field ) {
			TranslationJob::FIELD_TITLE   => 'post_title',
			TranslationJob::FIELD_EXCERPT => 'post_excerpt',
			default                       => 'post_content',
		};

		// wp_update_post creates a revision, so the pre-translation text
		// remains recoverable from the standard WordPress revision UI --
		// this is the free tier's rollback story.
		$updated = wp_update_post(
			array(
				'ID'    => $postId,
				$column => wp_kses_post( (string) $preview['result'] ),
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			$this->redirect( 'error', $updated->get_error_message(), $postId );
		}

		delete_transient( $this->previewKey( $postId ) );

		$this->redirect( 'success', __( 'Tłumaczenie zapisane. Poprzednia wersja jest dostępna w rewizjach wpisu.', 'dosieci-translator' ), $postId );
	}

	private function jobFromRequest( string $nonceAction ): TranslationJob {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-translator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonceAction );

		$postId   = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$field    = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( (string) $_POST['field'] ) ) : '';
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['language'] ) ) : '';

		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $postId ) ) {
			$this->redirect( 'error', __( 'Brak dostępu do tego wpisu.', 'dosieci-translator' ) );
		}

		if ( ! TranslationJob::isSupportedField( $field ) || ! DeepLLanguages::isSupported( $language ) ) {
			$this->redirect( 'error', __( 'Nieprawidłowe pole lub język.', 'dosieci-translator' ), $postId );
		}

		$source = match ( $field ) {
			TranslationJob::FIELD_TITLE   => $post->post_title,
			TranslationJob::FIELD_EXCERPT => $post->post_excerpt,
			default                       => $post->post_content,
		};

		if ( '' === trim( $source ) ) {
			$this->redirect( 'error', __( 'To pole jest puste — nie ma czego tłumaczyć.', 'dosieci-translator' ), $postId );
		}

		return new TranslationJob( $postId, $field, $source, strtoupper( $language ) );
	}

	private function previewKey( int $postId ): string {
		return 'dosieci_translator_preview_' . get_current_user_id() . '_' . $postId;
	}

	private function redirect( string $type, string $message, int $postId = 0 ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'post_id'    => $postId ?: null,
					'tr_notice'  => $type,
					'tr_message' => rawurlencode( $message ),
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-translator' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page state.
		$postId  = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		$notice  = isset( $_GET['tr_notice'] ) ? sanitize_key( (string) $_GET['tr_notice'] ) : '';
		$message = isset( $_GET['tr_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['tr_message'] ) ) ) : '';
		// phpcs:enable

		$hasKey  = '' !== (string) get_option( self::OPTION_API_KEY, '' );
		$preview = $postId > 0 ? get_transient( $this->previewKey( $postId ) ) : false;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci Translator', 'dosieci-translator' ); ?></h1>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Tłumaczenie korzysta z Twojego własnego klucza DeepL i Twojego limitu znaków. Zapytanie idzie bezpośrednio z tej witryny do DeepL — nie przez serwery DoSieci.', 'dosieci-translator' ); ?></p>
			</div>

			<?php if ( $hasKey ) : ?>
				<h2><?php esc_html_e( 'Przetłumacz pole', 'dosieci-translator' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_translator_preview' ); ?>
					<input type="hidden" name="action" value="dosieci_translator_preview">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="tr-post"><?php esc_html_e( 'ID wpisu / produktu', 'dosieci-translator' ); ?></label></th>
							<td><input type="number" id="tr-post" name="post_id" required value="<?php echo esc_attr( (string) $postId ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="tr-field"><?php esc_html_e( 'Pole', 'dosieci-translator' ); ?></label></th>
							<td>
								<select id="tr-field" name="field">
									<option value="title"><?php esc_html_e( 'Tytuł', 'dosieci-translator' ); ?></option>
									<option value="excerpt"><?php esc_html_e( 'Krótki opis', 'dosieci-translator' ); ?></option>
									<option value="content"><?php esc_html_e( 'Opis', 'dosieci-translator' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="tr-lang"><?php esc_html_e( 'Język docelowy', 'dosieci-translator' ); ?></label></th>
							<td>
								<select id="tr-lang" name="language">
									<?php foreach ( DeepLLanguages::all() as $code => $label ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( 'EN-GB', $code ); ?>>
											<?php echo esc_html( $label . ' (' . $code . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Pokaż podgląd tłumaczenia', 'dosieci-translator' ) ); ?>
				</form>
			<?php endif; ?>

			<?php if ( is_array( $preview ) ) : ?>
				<h2><?php esc_html_e( 'Podgląd', 'dosieci-translator' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Nic nie zostało jeszcze zapisane. Po zapisaniu poprzednia wersja pozostanie dostępna w rewizjach wpisu.', 'dosieci-translator' ); ?></p>

				<table class="widefat striped">
					<tr>
						<th style="width:50%;"><?php esc_html_e( 'Przed', 'dosieci-translator' ); ?></th>
						<th><?php esc_html_e( 'Po', 'dosieci-translator' ); ?></th>
					</tr>
					<tr>
						<td><pre style="white-space:pre-wrap;"><?php echo esc_html( mb_substr( (string) $preview['source'], 0, 4000 ) ); ?></pre></td>
						<td><pre style="white-space:pre-wrap;"><?php echo esc_html( mb_substr( (string) $preview['result'], 0, 4000 ) ); ?></pre></td>
					</tr>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem;">
					<?php wp_nonce_field( 'dosieci_translator_apply' ); ?>
					<input type="hidden" name="action" value="dosieci_translator_apply">
					<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $postId ); ?>">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Zapisz tłumaczenie', 'dosieci-translator' ); ?></button>
				</form>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Twój klucz DeepL (BYOK)', 'dosieci-translator' ); ?></h2>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_translator_save_key' ); ?>
					<input type="hidden" name="action" value="dosieci_translator_save_key">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="tr-key"><?php esc_html_e( 'Klucz API DeepL', 'dosieci-translator' ); ?></label></th>
							<td>
								<input type="password" id="tr-key" name="api_key" class="regular-text" autocomplete="off"
									placeholder="<?php echo esc_attr( $hasKey ? '••••••••' : 'xxxxxxxx-xxxx-...' ); ?>">
								<p class="description"><?php esc_html_e( 'Klucze kończące się na „:fx” to klucze DeepL Free — wtyczka sama wybiera właściwy endpoint. Zostaw puste i zapisz, aby usunąć klucz.', 'dosieci-translator' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Zapisz klucz', 'dosieci-translator' ) ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Klucz DeepL może skonfigurować wyłącznie administrator.', 'dosieci-translator' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\Translator;

use DoSieci\Translator\Adapter\DeepLClient;
use DoSieci\Translator\Domain\DeepLLanguages;
use DoSieci\Translator\Domain\TranslationException;
use DoSieci\Translator\Domain\TranslationJob;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DoSieci Translator.
 *
 * Translates one field of one post, page or product with DeepL, always
 * through a preview, and replaces the field's text only after approval.
 * The previous text is kept so it can be restored with one click: WooCommerce
 * products have no revisions, so WordPress alone could not undo a translation.
 */
final class Plugin {

	public const OPTION_API_KEY = 'dosieci_translator_deepl_key';
	public const META_BACKUP    = '_dosieci_translator_backup';
	public const PAGE_SLUG      = 'dosieci-translator';

	private const POST_TYPES = array( 'post', 'page', 'product' );

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
		add_action( 'admin_post_dosieci_translator_restore', array( $this, 'handleRestore' ) );
		add_filter( 'post_row_actions', array( $this, 'rowAction' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'rowAction' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( DOSIECI_TRANSLATOR_FILE ), array( $this, 'actionLinks' ) );
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

	/**
	 * @param array<string, string> $actions
	 *
	 * @return array<string, string>
	 */
	public function rowAction( array $actions, \WP_Post $post ): array {
		if ( in_array( $post->post_type, self::POST_TYPES, true ) && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['dosieci_translator'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::pageUrl( $post->ID ) ),
				esc_html__( 'Translate', 'dosieci-translator' )
			);
		}

		return $actions;
	}

	/**
	 * @param array<int|string, string> $links
	 *
	 * @return array<int|string, string>
	 */
	public function actionLinks( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::pageUrl() ), esc_html__( 'Open', 'dosieci-translator' ) ) );

		return $links;
	}

	private static function pageUrl( int $postId = 0 ): string {
		$args = array( 'page' => self::PAGE_SLUG );

		if ( $postId > 0 ) {
			$args['post_id'] = $postId;
		}

		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	public function handleSaveKey(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'dosieci-translator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dosieci_translator_save_key' );

		$key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';

		if ( isset( $_POST['remove_key'] ) ) {
			delete_option( self::OPTION_API_KEY );
			$this->finish( 'success', __( 'The DeepL key was removed.', 'dosieci-translator' ) );
		}

		if ( '' === $key ) {
			$this->finish( 'error', __( 'Enter a DeepL API key.', 'dosieci-translator' ) );
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9:\-]{20,100}$/', $key ) ) {
			$this->finish( 'error', __( 'That does not look like a DeepL API key. Paste only the key from your DeepL account.', 'dosieci-translator' ) );
		}

		// Not autoloaded: a provider credential has no business being loaded
		// into memory on every front-end request.
		update_option( self::OPTION_API_KEY, $key, false );

		$this->finish( 'success', __( 'The DeepL key was saved.', 'dosieci-translator' ) );
	}

	public function handlePreview(): void {
		$job = $this->jobFromRequest();
		$key = (string) get_option( self::OPTION_API_KEY, '' );

		if ( '' === $key ) {
			$this->finish( 'error', __( 'Save your DeepL API key first.', 'dosieci-translator' ) );
		}

		$client = new DeepLClient( $key );

		try {
			$translated = $client->translate( $job->sourceText, $job->targetLanguage, TranslationJob::FIELD_TITLE !== $job->field );
		} catch ( TranslationException $e ) {
			$this->finish( 'error', $e->getMessage(), $job->postId );
		}

		set_transient(
			$this->previewKey( $job->postId ),
			array(
				'field'    => $job->field,
				'language' => $job->targetLanguage,
				'detected' => (string) $client->detectedSourceLanguage(),
				'source'   => $job->sourceText,
				'result'   => $translated,
			),
			30 * MINUTE_IN_SECONDS
		);

		$this->finish( 'success', __( 'The translation is ready to review below. Nothing has been saved yet.', 'dosieci-translator' ), $job->postId );
	}

	/**
	 * Writes the previewed translation, and only that: the text is read back
	 * from the stored preview, never re-fetched or taken from the request, so
	 * what the human approved is exactly what gets written.
	 */
	public function handleApply(): void {
		$postId = $this->postIdFromRequest( 'dosieci_translator_apply' );
		$post   = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			$this->finish( 'error', __( 'The item was not found, or you cannot edit it.', 'dosieci-translator' ) );
		}

		$preview = get_transient( $this->previewKey( $postId ) );

		if ( ! is_array( $preview ) || ! isset( $preview['field'], $preview['result'], $preview['source'] ) || ! TranslationJob::isSupportedField( (string) $preview['field'] ) ) {
			$this->finish( 'error', __( 'The preview has expired. Translate the text again.', 'dosieci-translator' ), $postId );
		}

		$field  = (string) $preview['field'];
		$column = TranslationJob::column( $field );

		if ( (string) $post->{$column} !== (string) $preview['source'] ) {
			delete_transient( $this->previewKey( $postId ) );
			$this->finish( 'error', __( 'The text was changed after the preview was made, so the translation was not saved. Translate it again.', 'dosieci-translator' ), $postId );
		}

		$backups           = $this->backups( $postId );
		$backups[ $field ] = array(
			'text'     => (string) $post->{$column},
			'language' => (string) $preview['language'],
			'time'     => time(),
		);
		update_post_meta( $postId, self::META_BACKUP, wp_slash( $backups ) );

		$updated = $this->writeField( $postId, $field, (string) $preview['result'] );

		if ( is_wp_error( $updated ) ) {
			$this->finish( 'error', $updated->get_error_message(), $postId );
		}

		delete_transient( $this->previewKey( $postId ) );

		$this->finish( 'success', __( 'The translation was saved. You can restore the previous text below.', 'dosieci-translator' ), $postId );
	}

	public function handleRestore(): void {
		$postId  = $this->postIdFromRequest( 'dosieci_translator_restore' );
		$field   = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in postIdFromRequest().
		$backups = $this->backups( $postId );

		if ( ! TranslationJob::isSupportedField( $field ) || ! isset( $backups[ $field ]['text'] ) ) {
			$this->finish( 'error', __( 'There is nothing to restore for this field.', 'dosieci-translator' ), $postId );
		}

		$updated = $this->writeField( $postId, $field, (string) $backups[ $field ]['text'] );

		if ( is_wp_error( $updated ) ) {
			$this->finish( 'error', $updated->get_error_message(), $postId );
		}

		unset( $backups[ $field ] );

		if ( array() === $backups ) {
			delete_post_meta( $postId, self::META_BACKUP );
		} else {
			update_post_meta( $postId, self::META_BACKUP, wp_slash( $backups ) );
		}

		$this->finish( 'success', __( 'The previous text was restored.', 'dosieci-translator' ), $postId );
	}

	/**
	 * Replaces one field of a post.
	 *
	 * wp_update_post() expects slashed data, so the text is slashed first:
	 * otherwise every backslash in it (block attributes, escaped quotes) would
	 * be silently stripped. KSES is paused for the call because it would also
	 * re-filter the fields that are NOT being changed for users without
	 * unfiltered_html; the one field that is changed goes through
	 * wp_kses_post() for those users instead, exactly as the editor would.
	 *
	 * @return int|\WP_Error
	 */
	private function writeField( int $postId, string $field, string $text ): int|\WP_Error {
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$text = TranslationJob::FIELD_TITLE === $field ? wp_strip_all_tags( $text ) : wp_kses_post( $text );
		}

		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		if ( $kses ) {
			kses_remove_filters();
		}

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'                             => $postId,
					TranslationJob::column( $field ) => $text,
				)
			),
			true
		);

		if ( $kses ) {
			kses_init_filters();
		}

		return $result;
	}

	/** @return array<string, array{text:string, language:string, time:int}> */
	private function backups( int $postId ): array {
		$backups = get_post_meta( $postId, self::META_BACKUP, true );

		return is_array( $backups ) ? $backups : array();
	}

	/**
	 * Checks capability and nonce for a POST action and returns the post it
	 * targets, or stops.
	 */
	private function postIdFromRequest( string $nonceAction ): int {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'dosieci-translator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonceAction );

		$postId = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
			$this->finish( 'error', __( 'The item was not found, or you cannot edit it.', 'dosieci-translator' ) );
		}

		return $postId;
	}

	private function jobFromRequest(): TranslationJob {
		$postId   = $this->postIdFromRequest( 'dosieci_translator_preview' );
		$field    = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in postIdFromRequest().
		$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in postIdFromRequest().
		$post     = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			$this->finish( 'error', __( 'The item was not found, or you cannot edit it.', 'dosieci-translator' ) );
		}

		if ( ! TranslationJob::isSupportedField( $field ) || ! DeepLLanguages::isSupported( $language ) ) {
			$this->finish( 'error', __( 'Choose a field and a target language.', 'dosieci-translator' ), $postId );
		}

		$source = (string) $post->{TranslationJob::column( $field )};

		if ( '' === trim( $source ) ) {
			$this->finish( 'error', __( 'This field is empty, there is nothing to translate.', 'dosieci-translator' ), $postId );
		}

		return new TranslationJob( $postId, $field, $source, strtoupper( $language ) );
	}

	private function previewKey( int $postId ): string {
		return 'dosieci_translator_preview_' . get_current_user_id() . '_' . $postId;
	}

	/**
	 * Stores a one-time notice for the current user and returns to the page.
	 * The message travels in a transient, never in the URL, so a crafted
	 * link cannot make this screen display arbitrary text.
	 */
	private function finish( string $type, string $message, int $postId = 0 ): never {
		set_transient( 'dosieci_translator_notice_' . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::pageUrl( $postId ) );
		exit;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dosieci-translator' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection of which item to show.
		$postId = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$post   = $postId > 0 ? get_post( $postId ) : null;
		$post   = $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID ) ? $post : null;
		$notice = get_transient( 'dosieci_translator_notice_' . get_current_user_id() );
		delete_transient( 'dosieci_translator_notice_' . get_current_user_id() );

		$hasKey  = '' !== (string) get_option( self::OPTION_API_KEY, '' );
		$preview = null !== $post ? get_transient( $this->previewKey( $post->ID ) ) : false;
		$fields  = array(
			TranslationJob::FIELD_TITLE   => __( 'Title', 'dosieci-translator' ),
			TranslationJob::FIELD_EXCERPT => __( 'Excerpt / short description', 'dosieci-translator' ),
			TranslationJob::FIELD_CONTENT => __( 'Content / description', 'dosieci-translator' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci Translator', 'dosieci-translator' ); ?></h1>

			<?php if ( is_array( $notice ) && 2 === count( $notice ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice[0] ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo esc_html( (string) $notice[1] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Translates one field at a time and replaces its text after you approve the preview. It does not create a second language version of the page. Requests go from this site straight to DeepL with your own key, never through DoSieci.', 'dosieci-translator' ); ?></p>
			</div>

			<?php if ( $hasKey ) : ?>
				<h2><?php esc_html_e( 'Translate a field', 'dosieci-translator' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Tip: use the "Translate" link under any post, page or product in its list.', 'dosieci-translator' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_translator_preview' ); ?>
					<input type="hidden" name="action" value="dosieci_translator_preview">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="tr-post"><?php esc_html_e( 'Post, page or product ID', 'dosieci-translator' ); ?></label></th>
							<td>
								<input type="number" min="1" id="tr-post" name="post_id" required value="<?php echo esc_attr( null !== $post ? (string) $post->ID : '' ); ?>">
								<?php if ( null !== $post ) : ?>
									<strong><?php echo esc_html( '' !== $post->post_title ? $post->post_title : __( '(no title)', 'dosieci-translator' ) ); ?></strong>
									&middot; <a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'dosieci-translator' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="tr-field"><?php esc_html_e( 'Field', 'dosieci-translator' ); ?></label></th>
							<td>
								<select id="tr-field" name="field">
									<?php foreach ( $fields as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="tr-lang"><?php esc_html_e( 'Translate into', 'dosieci-translator' ); ?></label></th>
							<td>
								<select id="tr-lang" name="language">
									<?php foreach ( DeepLLanguages::all() as $code => $label ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( 'EN-GB', $code ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'DeepL detects the source language by itself.', 'dosieci-translator' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Preview the translation', 'dosieci-translator' ) ); ?>
				</form>
			<?php endif; ?>

			<?php if ( null !== $post && is_array( $preview ) ) : ?>
				<h2><?php esc_html_e( 'Preview', 'dosieci-translator' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: 1: field name, 2: target language, 3: detected source language code */
						esc_html__( 'Field: %1$s. Into: %2$s. Detected source language: %3$s. Nothing has been saved yet.', 'dosieci-translator' ),
						esc_html( $fields[ (string) $preview['field'] ] ?? (string) $preview['field'] ),
						esc_html( DeepLLanguages::label( (string) $preview['language'] ) ),
						esc_html( '' !== (string) ( $preview['detected'] ?? '' ) ? (string) $preview['detected'] : '?' )
					);
					?>
				</p>

				<table class="widefat striped">
					<tr>
						<th style="width:50%;"><?php esc_html_e( 'Before', 'dosieci-translator' ); ?></th>
						<th><?php esc_html_e( 'After', 'dosieci-translator' ); ?></th>
					</tr>
					<tr>
						<td><pre style="white-space:pre-wrap;"><?php echo esc_html( mb_substr( (string) $preview['source'], 0, 5000 ) ); ?></pre></td>
						<td><pre style="white-space:pre-wrap;"><?php echo esc_html( mb_substr( (string) $preview['result'], 0, 5000 ) ); ?></pre></td>
					</tr>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem;">
					<?php wp_nonce_field( 'dosieci_translator_apply' ); ?>
					<input type="hidden" name="action" value="dosieci_translator_apply">
					<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save the translation', 'dosieci-translator' ); ?></button>
					<span class="description"><?php esc_html_e( 'The current text is kept, so you can restore it with one click.', 'dosieci-translator' ); ?></span>
				</form>
			<?php endif; ?>

			<?php $this->renderBackups( $post, $fields ); ?>

			<hr>

			<h2><?php esc_html_e( 'Your DeepL API key', 'dosieci-translator' ); ?></h2>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<p class="description"><?php esc_html_e( 'Translations use your own DeepL API key and your own character allowance. Both DeepL API Free and Pro keys work; the right endpoint is picked automatically.', 'dosieci-translator' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_translator_save_key' ); ?>
					<input type="hidden" name="action" value="dosieci_translator_save_key">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="tr-key"><?php esc_html_e( 'DeepL API key', 'dosieci-translator' ); ?></label></th>
							<td>
								<input type="password" id="tr-key" name="api_key" class="regular-text" autocomplete="off"
									placeholder="<?php echo esc_attr( $hasKey ? '••••••••' : 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx' ); ?>">
								<?php if ( $hasKey ) : ?>
									<p><label><input type="checkbox" name="remove_key" value="1"> <?php esc_html_e( 'Remove the saved key', 'dosieci-translator' ); ?></label></p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save', 'dosieci-translator' ) ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Only an administrator can set the DeepL key.', 'dosieci-translator' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, string> $fields
	 */
	private function renderBackups( ?\WP_Post $post, array $fields ): void {
		if ( null === $post ) {
			return;
		}

		$backups = $this->backups( $post->ID );

		if ( array() === $backups ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Restore the text from before a translation', 'dosieci-translator' ); ?></h2>
		<table class="widefat striped">
			<?php foreach ( $backups as $field => $backup ) : ?>
				<?php
				if ( ! isset( $fields[ $field ], $backup['text'] ) ) {
					continue;
				}
				?>
				<tr>
					<td style="width:25%;"><strong><?php echo esc_html( $fields[ $field ] ); ?></strong></td>
					<td>
						<?php
						printf(
							/* translators: 1: date and time, 2: language name */
							esc_html__( 'Kept on %1$s, before translating into %2$s.', 'dosieci-translator' ),
							esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) ( $backup['time'] ?? 0 ) ) ),
							esc_html( DeepLLanguages::label( (string) ( $backup['language'] ?? '' ) ) )
						);
						?>
						<br><code><?php echo esc_html( mb_strimwidth( wp_strip_all_tags( (string) $backup['text'] ), 0, 120, '…' ) ); ?></code>
					</td>
					<td style="width:15%;">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'dosieci_translator_restore' ); ?>
							<input type="hidden" name="action" value="dosieci_translator_restore">
							<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
							<input type="hidden" name="field" value="<?php echo esc_attr( $field ); ?>">
							<button type="submit" class="button"><?php esc_html_e( 'Restore', 'dosieci-translator' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}
}

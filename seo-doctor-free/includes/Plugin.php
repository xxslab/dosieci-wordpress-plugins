<?php

declare(strict_types=1);

namespace DoSieci\SEO\Doctor;

use DoSieci\SEO\Doctor\Adapter\OpenAiClient;
use DoSieci\SEO\Doctor\Domain\ByokKeyStore;
use DoSieci\SEO\Doctor\Domain\SeoAuditor;
use DoSieci\SEO\Doctor\Domain\SeoFinding;
use DoSieci\SEO\Doctor\Domain\SuggestionPrompt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DoSieci SEO Doctor.
 *
 * A read-only audit of one post, page or product at a time, plus optional
 * AI suggestions for its metadata. The plugin never writes a title tag,
 * canonical, sitemap or schema, which is what lets it coexist with Yoast,
 * Rank Math, AIOSEO and SEOPress. Suggestions are shown for a human to copy;
 * there is no "apply" button.
 */
final class Plugin {

	public const OPTION_API_KEY = 'dosieci_seo_doctor_api_key';
	public const OPTION_MODEL   = 'dosieci_seo_doctor_model';
	public const DEFAULT_MODEL  = 'gpt-4o-mini';
	public const PAGE_SLUG      = 'dosieci-seo-doctor';

	private const POST_TYPES = array( 'post', 'page', 'product' );

	private static ?self $instance = null;

	private ?bool $wordPressAi = null;

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
		add_filter( 'post_row_actions', array( $this, 'rowAction' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'rowAction' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( DOSIECI_SEO_DOCTOR_FILE ), array( $this, 'actionLinks' ) );
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

	/**
	 * @param array<string, string> $actions
	 *
	 * @return array<string, string>
	 */
	public function rowAction( array $actions, \WP_Post $post ): array {
		if ( in_array( $post->post_type, self::POST_TYPES, true ) && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['dosieci_seo_doctor'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::pageUrl( $post->ID ) ),
				esc_html__( 'SEO audit', 'dosieci-seo-doctor' )
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
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::pageUrl() ), esc_html__( 'Open', 'dosieci-seo-doctor' ) ) );

		return $links;
	}

	private static function pageUrl( int $postId = 0 ): string {
		$args = array( 'page' => self::PAGE_SLUG );

		if ( $postId > 0 ) {
			$args['post_id'] = $postId;
		}

		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	/**
	 * Whether WordPress's own AI client (7.0+) has a provider configured that
	 * can generate text. Checked lazily, and only on this plugin's screens,
	 * because a provider may call its API to answer.
	 */
	private function wordPressAiAvailable(): bool {
		if ( null !== $this->wordPressAi ) {
			return $this->wordPressAi;
		}

		$this->wordPressAi = false;

		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return false;
		}

		try {
			$this->wordPressAi = true === wp_ai_client_prompt( 'Test' )->is_supported_for_text_generation();
		} catch ( \Throwable $e ) {
			// A broken AI provider plugin must not take this screen down with it.
			$this->wordPressAi = false;
		}

		return $this->wordPressAi;
	}

	public function handleSaveKey(): void {
		// Storing a provider credential is an administrator action.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'dosieci-seo-doctor' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dosieci_seo_save_key' );

		$key   = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$model = 1 === preg_match( '/^[A-Za-z0-9._:\-]{1,64}$/', $model ) ? $model : self::DEFAULT_MODEL;

		update_option( self::OPTION_MODEL, $model, false );

		if ( isset( $_POST['remove_key'] ) ) {
			delete_option( self::OPTION_API_KEY );
			$this->finish( 'success', __( 'The API key was removed.', 'dosieci-seo-doctor' ) );
		}

		if ( '' === $key ) {
			$this->finish( 'success', __( 'Settings saved.', 'dosieci-seo-doctor' ) );
		}

		if ( ! ByokKeyStore::looksPlausible( $key ) ) {
			$this->finish( 'error', __( 'That does not look like an API key. Paste only the key, without quotes or a whole command.', 'dosieci-seo-doctor' ) );
		}

		// Not autoloaded: a provider credential has no business being loaded
		// into memory on every front-end request.
		update_option( self::OPTION_API_KEY, $key, false );

		$this->finish( 'success', __( 'The API key was saved.', 'dosieci-seo-doctor' ) );
	}

	public function handleSuggest(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'dosieci-seo-doctor' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dosieci_seo_suggest' );

		$postId = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post   = get_post( $postId );

		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $postId ) ) {
			$this->finish( 'error', __( 'You cannot edit this item.', 'dosieci-seo-doctor' ) );
		}

		$key    = (string) get_option( self::OPTION_API_KEY, '' );
		$prompt = SuggestionPrompt::build( $post->post_title, wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );

		try {
			if ( $this->wordPressAiAvailable() ) {
				try {
					$raw = wp_ai_client_prompt( $prompt )->generate_text();
				} catch ( \Throwable $e ) {
					$raw = new \WP_Error( 'dosieci_seo_ai_provider', __( 'The AI provider plugin failed. Check its settings under Settings > Connectors.', 'dosieci-seo-doctor' ) );
				}

				if ( is_wp_error( $raw ) ) {
					throw new \RuntimeException( esc_html( $raw->get_error_message() ) );
				}
			} elseif ( '' !== $key ) {
				$raw = ( new OpenAiClient( $key, (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL ) ) )->complete( $prompt );
			} else {
				$this->finish( 'error', __( 'Set up an AI provider first (see "AI suggestions" below).', 'dosieci-seo-doctor' ), $postId );
			}

			$parsed = SuggestionPrompt::parse( (string) $raw );
		} catch ( \RuntimeException $e ) {
			$this->finish( 'error', ByokKeyStore::redactFrom( $e->getMessage(), $key ), $postId );
		}

		set_transient( self::userKey( 'suggestion' ), array( 'post_id' => $postId ) + $parsed, 10 * MINUTE_IN_SECONDS );

		$this->finish( 'success', __( 'Suggestions are ready below.', 'dosieci-seo-doctor' ), $postId );
	}

	/**
	 * Stores a one-time notice for the current user and returns to the page.
	 * The message travels in a transient, never in the URL, so a crafted
	 * link cannot make this screen display arbitrary text.
	 */
	private function finish( string $type, string $message, int $postId = 0 ): never {
		set_transient( self::userKey( 'notice' ), array( $type, $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::pageUrl( $postId ) );
		exit;
	}

	private static function userKey( string $what ): string {
		return 'dosieci_seo_doctor_' . $what . '_' . get_current_user_id();
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dosieci-seo-doctor' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection of which item to audit.
		$postId = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$notice = get_transient( self::userKey( 'notice' ) );
		delete_transient( self::userKey( 'notice' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci SEO Doctor', 'dosieci-seo-doctor' ); ?></h1>

			<?php if ( is_array( $notice ) && 2 === count( $notice ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice[0] ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo esc_html( (string) $notice[1] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'The audit is read-only: the plugin never overwrites titles, canonicals, sitemaps or schema, so it works alongside Yoast SEO, Rank Math, All in One SEO and SEOPress.', 'dosieci-seo-doctor' ); ?></p>
			</div>

			<h2><?php esc_html_e( 'Audit an item', 'dosieci-seo-doctor' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Tip: use the "SEO audit" link under any post, page or product in its list.', 'dosieci-seo-doctor' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label for="dosieci-seo-post"><?php esc_html_e( 'Post, page or product ID:', 'dosieci-seo-doctor' ); ?></label>
				<input type="number" min="1" id="dosieci-seo-post" name="post_id" value="<?php echo esc_attr( $postId > 0 ? (string) $postId : '' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Audit', 'dosieci-seo-doctor' ); ?></button>
			</form>

			<?php
			if ( $postId > 0 ) {
				$post = get_post( $postId );

				if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $postId ) ) {
					echo '<p>' . esc_html__( 'The item was not found, or you cannot edit it.', 'dosieci-seo-doctor' ) . '</p>';
				} else {
					$this->renderAudit( $post );
				}
			}

			$this->renderAiSettings();
			?>
		</div>
		<?php
	}

	private function renderAudit( \WP_Post $post ): void {
		[ $description, $source ] = $this->metaDescription( $post );

		$images = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', $post->post_content, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag, $src );
				preg_match( '/\balt=["\']([^"\']*)["\']/i', $tag, $alt );
				$images[] = array(
					'src' => $src[1] ?? '',
					'alt' => $alt[1] ?? '',
				);
			}
		}

		$findings   = ( new SeoAuditor() )->audit(
			$post->post_title,
			$description,
			wp_strip_all_tags( strip_shortcodes( $post->post_content ) ),
			$images,
			$post->post_name
		);
		$suggestion = get_transient( self::userKey( 'suggestion' ) );
		$suggestion = is_array( $suggestion ) && (int) ( $suggestion['post_id'] ?? 0 ) === $post->ID ? $suggestion : null;
		$canSuggest = $this->wordPressAiAvailable() || '' !== (string) get_option( self::OPTION_API_KEY, '' );
		?>
		<h3>
			<?php echo esc_html( '' !== $post->post_title ? $post->post_title : __( '(no title)', 'dosieci-seo-doctor' ) ); ?>
			<small>&middot; <a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'dosieci-seo-doctor' ); ?></a></small>
		</h3>
		<p class="description">
			<?php
			printf(
				/* translators: %s: where the meta description was read from, e.g. "Yoast SEO" */
				esc_html__( 'Meta description read from: %s', 'dosieci-seo-doctor' ),
				esc_html( $source )
			);
			?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:110px;"><?php esc_html_e( 'Status', 'dosieci-seo-doctor' ); ?></th>
					<th><?php esc_html_e( 'Result', 'dosieci-seo-doctor' ); ?></th>
					<th><?php esc_html_e( 'Recommendation', 'dosieci-seo-doctor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $findings as $finding ) : ?>
					<tr>
						<td>
							<?php if ( SeoFinding::SEVERITY_OK === $finding->severity ) : ?>
								<span style="color:#166534;font-weight:600;"><?php esc_html_e( 'OK', 'dosieci-seo-doctor' ); ?></span>
							<?php elseif ( SeoFinding::SEVERITY_CRITICAL === $finding->severity ) : ?>
								<span style="color:#991b1b;font-weight:600;"><?php esc_html_e( 'Critical', 'dosieci-seo-doctor' ); ?></span>
							<?php else : ?>
								<span style="color:#78350f;font-weight:600;"><?php esc_html_e( 'Warning', 'dosieci-seo-doctor' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $finding->summary ); ?></td>
						<td><?php echo esc_html( $finding->recommendation ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $canSuggest ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem;">
				<?php wp_nonce_field( 'dosieci_seo_suggest' ); ?>
				<input type="hidden" name="action" value="dosieci_seo_suggest">
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Suggest a title and description with AI', 'dosieci-seo-doctor' ); ?></button>
				<span class="description"><?php esc_html_e( 'Sends the title and up to 4,000 characters of the content to the AI provider.', 'dosieci-seo-doctor' ); ?></span>
			</form>
		<?php endif; ?>

		<?php if ( null !== $suggestion ) : ?>
			<h3><?php esc_html_e( 'AI suggestions', 'dosieci-seo-doctor' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Copy whatever you find useful into your SEO plugin. Nothing is changed automatically.', 'dosieci-seo-doctor' ); ?></p>
			<table class="widefat striped">
				<tr>
					<th style="width:160px;"><?php esc_html_e( 'Title', 'dosieci-seo-doctor' ); ?></th>
					<td><?php echo esc_html( (string) $suggestion['title'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Description', 'dosieci-seo-doctor' ); ?></th>
					<td><?php echo esc_html( (string) $suggestion['description'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Focus keyphrase', 'dosieci-seo-doctor' ); ?></th>
					<td><?php echo esc_html( (string) $suggestion['keyphrase'] ); ?></td>
				</tr>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * The meta description as the active SEO plugin stores it, falling back
	 * to the excerpt, together with where it came from.
	 *
	 * @return array{0:string, 1:string}
	 */
	private function metaDescription( \WP_Post $post ): array {
		$sources = array(
			'_yoast_wpseo_metadesc' => 'Yoast SEO',
			'rank_math_description' => 'Rank Math',
			'_seopress_titles_desc' => 'SEOPress',
		);

		foreach ( $sources as $metaKey => $label ) {
			$value = trim( (string) get_post_meta( $post->ID, $metaKey, true ) );

			if ( '' !== $value ) {
				return array( $value, $label );
			}
		}

		if ( function_exists( 'aioseo' ) ) {
			global $wpdb;

			// All in One SEO keeps its post data in its own table, with no stable public getter.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read for one admin page view.
			$value = trim( (string) $wpdb->get_var( $wpdb->prepare( "SELECT description FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d", $post->ID ) ) );

			if ( '' !== $value ) {
				return array( $value, 'All in One SEO' );
			}
		}

		if ( '' !== trim( $post->post_excerpt ) ) {
			return array( trim( $post->post_excerpt ), __( 'the excerpt', 'dosieci-seo-doctor' ) );
		}

		return array( '', __( 'nothing found', 'dosieci-seo-doctor' ) );
	}

	private function renderAiSettings(): void {
		$storedKey = (string) get_option( self::OPTION_API_KEY, '' );
		?>
		<hr>
		<h2><?php esc_html_e( 'AI suggestions', 'dosieci-seo-doctor' ); ?></h2>

		<?php if ( $this->wordPressAiAvailable() ) : ?>
			<p>
				<?php esc_html_e( 'AI suggestions use the AI provider configured in WordPress.', 'dosieci-seo-doctor' ); ?>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e( 'Manage connectors', 'dosieci-seo-doctor' ); ?></a>
				<?php endif; ?>
			</p>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Optional. AI suggestions use the AI provider configured in WordPress (Settings > Connectors, WordPress 7.0 or later) or, if there is none, your own OpenAI API key below. Requests go straight from this site to the provider, never through DoSieci, and you pay the provider directly.', 'dosieci-seo-doctor' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
			<?php if ( ! $this->wordPressAiAvailable() ) : ?>
				<p><?php esc_html_e( 'Only an administrator can set up AI suggestions.', 'dosieci-seo-doctor' ); ?></p>
			<?php endif; ?>
			<?php return; ?>
		<?php endif; ?>

		<?php if ( $this->wordPressAiAvailable() && '' === $storedKey ) : ?>
			<?php return; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'dosieci_seo_save_key' ); ?>
			<input type="hidden" name="action" value="dosieci_seo_save_key">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dosieci-seo-key"><?php esc_html_e( 'OpenAI API key', 'dosieci-seo-doctor' ); ?></label></th>
					<td>
						<input type="password" id="dosieci-seo-key" name="api_key" class="regular-text" autocomplete="off"
							placeholder="<?php echo esc_attr( '' !== $storedKey ? ByokKeyStore::mask( $storedKey ) : 'sk-...' ); ?>">
						<p class="description"><?php esc_html_e( 'Leave empty to keep the saved key. The key is never shown in full and never logged.', 'dosieci-seo-doctor' ); ?></p>
						<?php if ( '' !== $storedKey ) : ?>
							<p><label><input type="checkbox" name="remove_key" value="1"> <?php esc_html_e( 'Remove the saved key', 'dosieci-seo-doctor' ); ?></label></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dosieci-seo-model"><?php esc_html_e( 'Model', 'dosieci-seo-doctor' ); ?></label></th>
					<td>
						<input type="text" id="dosieci-seo-model" name="model" class="regular-text"
							value="<?php echo esc_attr( (string) get_option( self::OPTION_MODEL, self::DEFAULT_MODEL ) ); ?>">
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save', 'dosieci-seo-doctor' ) ); ?>
		</form>
		<?php
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\Clean\Urls;

use DoSieci\Clean\Urls\Domain\CollisionScanner;
use DoSieci\Clean\Urls\Domain\RedirectMap;
use DoSieci\Clean\Urls\Domain\SlugNormalizer;
use DoSieci\Clean\Urls\Domain\UrlChange;

/**
 * DoSieci Clean URLs — free tier.
 *
 * Scope of the free build: the Safe Migration Engine's PREFLIGHT and the
 * 301 redirect layer for ONE post type. Preview is mandatory -- there is no
 * code path that renames a slug without the operator having seen the scan
 * result and pressed Apply on that specific scan.
 */
final class Plugin {

	public const OPTION_REDIRECTS = 'dosieci_clean_urls_redirects';
	public const OPTION_POST_TYPE = 'dosieci_clean_urls_post_type';
	public const PAGE_SLUG        = 'dosieci-clean-urls';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'registerPage' ) );
		add_action( 'admin_post_dosieci_clean_urls_apply', array( $this, 'handleApply' ) );
		add_action( 'template_redirect', array( $this, 'maybeRedirect' ) );
	}

	public function registerPage(): void {
		add_management_page(
			__( 'DoSieci Clean URLs', 'dosieci-clean-urls' ),
			__( 'Clean URLs', 'dosieci-clean-urls' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Serves the 301s. Runs on template_redirect and only for requests
	 * WordPress could not resolve (is_404), so a working URL is never
	 * intercepted by an out-of-date redirect entry.
	 */
	public function maybeRedirect(): void {
		if ( ! is_404() ) {
			return;
		}

		$path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( '' === $path ) {
			return;
		}

		$map    = new RedirectMap( $this->storedRedirects() );
		$target = $map->target( $path );

		if ( null === $target ) {
			return;
		}

		// Always 301, never 302: the whole point is to pass the ranking
		// signal to the new URL (PRODUCT_SCOPE.md's Safe Migration Engine).
		wp_safe_redirect( home_url( $target ), 301 );
		exit;
	}

	/** @return array<string, string> */
	private function storedRedirects(): array {
		$stored = get_option( self::OPTION_REDIRECTS, array() );

		return is_array( $stored ) ? $stored : array();
	}

	private function postType(): string {
		$type = (string) get_option( self::OPTION_POST_TYPE, 'post' );

		return in_array( $type, array( 'post', 'page', 'product' ), true ) ? $type : 'post';
	}

	/**
	 * Builds the proposed change set. Read-only.
	 *
	 * @return array{changes: UrlChange[], summary: array<string, int>}
	 */
	public function scan(): array {
		$normalizer = new SlugNormalizer();
		$postType   = $this->postType();

		$posts = get_posts(
			array(
				'post_type'        => $postType,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => 500,
				'suppress_filters' => false,
			)
		);

		$proposed      = array();
		$proposedIds   = array();
		foreach ( $posts as $post ) {
			$proposed[]    = new UrlChange(
				$post->ID,
				$post->post_title,
				$post->post_name,
				$normalizer->normalize( $post->post_title )
			);
			$proposedIds[] = $post->ID;
		}

		// Existing slugs of everything NOT in the proposal, so a rename
		// cannot silently steal a URL from an untouched post.
		global $wpdb;
		$existing = array();
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name FROM {$wpdb->posts} WHERE post_status != 'trash' AND post_name != '' AND post_type = %s",
				$postType
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! in_array( (int) $row['ID'], $proposedIds, true ) ) {
				$existing[ (string) $row['post_name'] ] = (int) $row['ID'];
			}
		}

		$scanner = new CollisionScanner( $normalizer );
		$changes = $scanner->scan( $proposed, $existing );

		return array(
			'changes' => $changes,
			'summary' => $scanner->summarise( $changes ),
		);
	}

	public function handleApply(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-clean-urls' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dosieci_clean_urls_apply' );

		$scan    = $this->scan();
		$map     = new RedirectMap( $this->storedRedirects() );
		$applied = 0;

		foreach ( $scan['changes'] as $change ) {
			if ( ! $change->isApplicable() ) {
				continue;
			}

			$oldPermalink = (string) wp_parse_url( (string) get_permalink( $change->postId ), PHP_URL_PATH );

			$updated = wp_update_post(
				array(
					'ID'        => $change->postId,
					'post_name' => $change->proposedSlug,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				continue;
			}

			$newPermalink = (string) wp_parse_url( (string) get_permalink( $change->postId ), PHP_URL_PATH );

			if ( '' !== $oldPermalink && $oldPermalink !== $newPermalink ) {
				$map->add( $oldPermalink, $newPermalink );
			}

			++$applied;
		}

		update_option( self::OPTION_REDIRECTS, $map->all(), false );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'applied' => $applied ),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-clean-urls' ), '', array( 'response' => 403 ) );
		}

		$scan      = $this->scan();
		$redirects = $this->storedRedirects();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci Clean URLs', 'dosieci-clean-urls' ); ?></h1>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result. ?>
			<?php if ( isset( $_GET['applied'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of applied changes */
							esc_html__( 'Zastosowano %d zmian. Stare adresy przekierowują teraz kodem 301.', 'dosieci-clean-urls' ),
							(int) $_GET['applied'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Podgląd zmian przed ich wykonaniem. Nic nie zostanie zmienione, dopóki nie klikniesz przycisku na dole.', 'dosieci-clean-urls' ); ?>
			</p>

			<p>
				<strong><?php esc_html_e( 'Do zastosowania:', 'dosieci-clean-urls' ); ?></strong> <?php echo (int) $scan['summary']['applicable']; ?> &middot;
				<strong><?php esc_html_e( 'Blokery:', 'dosieci-clean-urls' ); ?></strong> <?php echo (int) $scan['summary']['blockers']; ?> &middot;
				<strong><?php esc_html_e( 'Bez zmian:', 'dosieci-clean-urls' ); ?></strong> <?php echo (int) $scan['summary']['unchanged']; ?> &middot;
				<strong><?php esc_html_e( 'Zapisane przekierowania:', 'dosieci-clean-urls' ); ?></strong> <?php echo count( $redirects ); ?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Wpis', 'dosieci-clean-urls' ); ?></th>
						<th><?php esc_html_e( 'Obecny slug', 'dosieci-clean-urls' ); ?></th>
						<th><?php esc_html_e( 'Proponowany slug', 'dosieci-clean-urls' ); ?></th>
						<th><?php esc_html_e( 'Status', 'dosieci-clean-urls' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $scan['changes'] as $change ) : ?>
						<?php if ( ! $change->isChange() ) { continue; } ?>
						<tr>
							<td><?php echo esc_html( $change->title ); ?> <small>#<?php echo (int) $change->postId; ?></small></td>
							<td><code><?php echo esc_html( $change->currentSlug ); ?></code></td>
							<td><code><?php echo esc_html( $change->proposedSlug ); ?></code></td>
							<td>
								<?php if ( UrlChange::SEVERITY_BLOCKER === $change->severity ) : ?>
									<span style="color:#b91c1c;font-weight:600;"><?php esc_html_e( 'BLOKER', 'dosieci-clean-urls' ); ?></span>
									— <?php echo esc_html( (string) $change->issue ); ?>
								<?php else : ?>
									<span style="color:#166534;font-weight:600;"><?php esc_html_e( 'OK', 'dosieci-clean-urls' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $scan['summary']['applicable'] > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem;">
					<?php wp_nonce_field( 'dosieci_clean_urls_apply' ); ?>
					<input type="hidden" name="action" value="dosieci_clean_urls_apply">
					<button type="submit" class="button button-primary">
						<?php
						printf(
							/* translators: %d: number of applicable changes */
							esc_html__( 'Zastosuj %d bezpiecznych zmian (z przekierowaniem 301)', 'dosieci-clean-urls' ),
							(int) $scan['summary']['applicable']
						);
						?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

<?php

declare(strict_types=1);

namespace DoSieci\Clean\Urls;

use DoSieci\Clean\Urls\Domain\CollisionScanner;
use DoSieci\Clean\Urls\Domain\RedirectMap;
use DoSieci\Clean\Urls\Domain\SlugNormalizer;
use DoSieci\Clean\Urls\Domain\UrlChange;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DoSieci Clean URLs.
 *
 * A preview-first slug clean-up for one post type at a time, with a 301
 * redirect layer. Nothing is renamed unless it was shown in the preview and
 * its row was ticked; the slug that gets written is the one that was shown.
 */
final class Plugin {

	public const OPTION_REDIRECTS = 'dosieci_clean_urls_redirects';
	public const OPTION_POST_TYPE = 'dosieci_clean_urls_post_type';
	public const PAGE_SLUG        = 'dosieci-clean-urls';

	/** How many changed rows the preview lists at once; the rest follow after applying. */
	public const MAX_ROWS = 300;

	private const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

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
		// Before redirect_canonical() and wp_old_slug_redirect(), which would
		// otherwise "guess" a similar-looking URL for an address we know
		// exactly.
		add_action( 'template_redirect', array( $this, 'maybeRedirect' ), 1 );
		add_filter( 'plugin_action_links_' . plugin_basename( DOSIECI_CLEAN_URLS_FILE ), array( $this, 'actionLinks' ) );
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
				esc_html__( 'Preview', 'dosieci-clean-urls' )
			)
		);

		return $links;
	}

	/**
	 * Serves the 301s, only for requests WordPress could not resolve, so a
	 * working URL is never intercepted by an outdated entry.
	 */
	public function maybeRedirect(): void {
		if ( ! is_404() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$requestUri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path       = (string) wp_parse_url( $requestUri, PHP_URL_PATH );

		if ( '' === $path ) {
			return;
		}

		$target = ( new RedirectMap( $this->storedRedirects() ) )->target( $path );

		if ( null === $target ) {
			return;
		}

		$query = (string) wp_parse_url( $requestUri, PHP_URL_QUERY );

		// Always 301, never 302: the point is to pass the old URL's ranking
		// signal on to the new one.
		wp_safe_redirect( self::originUrl() . $target . ( '' !== $query ? '?' . $query : '' ), 301, 'DoSieci Clean URLs' );
		exit;
	}

	/**
	 * scheme://host[:port] of the site. Stored paths already contain any
	 * subdirectory WordPress is installed in, so they must not go through
	 * home_url() again.
	 */
	private static function originUrl(): string {
		$home = wp_parse_url( home_url() );

		return ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	}

	/** @return array<string, string> */
	private function storedRedirects(): array {
		$stored = get_option( self::OPTION_REDIRECTS, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/** @return array<string, string> post type => label */
	private function postTypes(): array {
		$types = array(
			'post' => __( 'Posts', 'dosieci-clean-urls' ),
			'page' => __( 'Pages', 'dosieci-clean-urls' ),
		);

		if ( post_type_exists( 'product' ) ) {
			$types['product'] = __( 'Products', 'dosieci-clean-urls' );
		}

		return $types;
	}

	private function validPostType( string $candidate ): string {
		$types = $this->postTypes();

		if ( isset( $types[ $candidate ] ) ) {
			return $candidate;
		}

		$stored = (string) get_option( self::OPTION_POST_TYPE, '' );

		return isset( $types[ $stored ] ) ? $stored : ( isset( $types['product'] ) ? 'product' : 'post' );
	}

	/**
	 * Builds the proposed change set for one post type. Read-only.
	 *
	 * @return array{changes: UrlChange[], summary: array<string, int>}
	 */
	public function scan( string $postType ): array {
		global $wpdb;

		$normalizer = new SlugNormalizer( 'remove_accents' );
		$statuses   = implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) );

		// A preflight over every item of the type: get_posts() would build a
		// full WP_Post object per row just to read three columns.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one-off admin preview; $statuses is a list of %s placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$statuses}) ORDER BY ID ASC",
				array_merge( array( $postType ), self::STATUSES )
			),
			ARRAY_A
		);

		// Slugs held by items of this type that are NOT part of the proposal
		// (for example in the trash), so a rename cannot steal their address.
		$others = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name, post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_name != '' AND post_status NOT IN ({$statuses})",
				array_merge( array( $postType ), self::STATUSES )
			),
			ARRAY_A
		);
		// phpcs:enable

		$proposed = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$proposed[] = new UrlChange(
				(int) $row['ID'],
				(string) $row['post_title'],
				(string) $row['post_name'],
				$normalizer->normalize( (string) $row['post_title'] ),
				UrlChange::SEVERITY_OK,
				null,
				(int) $row['post_parent']
			);
		}

		$existing = array();
		foreach ( is_array( $others ) ? $others : array() as $row ) {
			$existing[ CollisionScanner::key( (int) $row['post_parent'], (string) $row['post_name'] ) ] = (int) $row['ID'];
		}

		$scanner = new CollisionScanner( $normalizer );
		$changes = array_map(
			static fn( UrlChange $change ): UrlChange => self::confirmWithWordPress( $change, $postType ),
			$scanner->scan( $proposed, $existing )
		);

		return array(
			'changes' => $changes,
			'summary' => $scanner->summarise( $changes ),
		);
	}

	/**
	 * Asks WordPress what it would actually store. wp_unique_post_slug()
	 * knows rules the scanner cannot (attachment slugs, numeric slugs that
	 * clash with pagination, feed names, filters added by other plugins); if
	 * it would alter the slug, applying it would produce an address nobody
	 * previewed, so the change is blocked instead.
	 */
	private static function confirmWithWordPress( UrlChange $change, string $postType ): UrlChange {
		if ( ! $change->isApplicable() ) {
			return $change;
		}

		// 'publish' so the uniqueness rules apply to drafts too, as they will
		// once the draft is published.
		$unique = wp_unique_post_slug( $change->proposedSlug, $change->postId, 'publish', $postType, $change->parentId );

		if ( $unique === $change->proposedSlug ) {
			return $change;
		}

		return $change->withIssue(
			UrlChange::SEVERITY_BLOCKER,
			/* translators: %s: the slug WordPress would use instead */
			sprintf( __( 'This address is already taken, WordPress would save it as "%s".', 'dosieci-clean-urls' ), $unique )
		);
	}

	public function handleApply(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'dosieci-clean-urls' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dosieci_clean_urls_apply' );

		$postType = $this->validPostType( isset( $_POST['content_type'] ) ? sanitize_key( wp_unslash( $_POST['content_type'] ) ) : '' );

		// post id => the proposed slug that was on screen when the row was ticked.
		$selected = array();
		if ( isset( $_POST['selected'] ) && is_array( $_POST['selected'] ) ) {
			foreach ( wp_unslash( $_POST['selected'] ) as $postId => $slug ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key and value is sanitized on the next line.
				$selected[ absint( $postId ) ] = sanitize_title( (string) $slug );
			}
		}

		$scan     = $this->scan( $postType );
		$redirect = new RedirectMap( $this->storedRedirects() );
		$applied  = 0;
		$skipped  = 0;

		foreach ( $scan['changes'] as $change ) {
			if ( ! isset( $selected[ $change->postId ] ) ) {
				continue;
			}

			// Re-validated against a fresh scan: if the content changed since
			// the preview, the slug on screen is not the one that would be
			// written, and nothing is applied for that row.
			if ( ! $change->isApplicable() || $selected[ $change->postId ] !== $change->proposedSlug ) {
				++$skipped;
				continue;
			}

			$before  = $this->permalinkPaths( $change->postId );
			$updated = self::renameSlug( $change->postId, $change->proposedSlug );

			if ( is_wp_error( $updated ) ) {
				++$skipped;
				continue;
			}

			clean_post_cache( $change->postId );

			// The item itself and, for pages, every descendant whose URL
			// contains the renamed slug.
			foreach ( $this->permalinkPaths( $change->postId ) as $id => $after ) {
				if ( isset( $before[ $id ] ) && '' !== $before[ $id ] && $before[ $id ] !== $after ) {
					$redirect->add( $before[ $id ], $after );
				}
			}

			++$applied;
		}

		update_option( self::OPTION_REDIRECTS, $redirect->all(), false );
		update_option( self::OPTION_POST_TYPE, $postType, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE_SLUG,
					'content_type' => $postType,
					'applied'      => $applied,
					'skipped'      => $skipped,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Changes only the slug, through wp_update_post() so other plugins (SEO,
	 * caches, sitemaps) see a normal update.
	 *
	 * wp_update_post() re-saves every field, and for a user without the
	 * unfiltered_html capability (multisite site admins, or any site with
	 * DISALLOW_UNFILTERED_HTML) that runs the untouched content through KSES
	 * and strips embeds from it. The content saved here is byte-for-byte what
	 * is already in the database, so the filters are paused for this call.
	 *
	 * @return int|\WP_Error
	 */
	private static function renameSlug( int $postId, string $slug ): int|\WP_Error {
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		if ( $kses ) {
			kses_remove_filters();
		}

		$result = wp_update_post(
			array(
				'ID'        => $postId,
				'post_name' => $slug,
			),
			true
		);

		if ( $kses ) {
			kses_init_filters();
		}

		return $result;
	}

	/**
	 * URL paths of a post and all its descendants (for hierarchical types).
	 *
	 * @return array<int, string> post id => path
	 */
	private function permalinkPaths( int $postId ): array {
		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$ids = array( $postId );

		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$descendants = get_posts(
				array(
					'post_type'   => $post->post_type,
					'post_status' => self::STATUSES,
					'numberposts' => -1,
					'fields'      => 'ids',
					'post_parent' => $postId,
				)
			);

			foreach ( $descendants as $childId ) {
				$ids = array_merge( $ids, array_keys( $this->permalinkPaths( (int) $childId ) ) );
			}
		}

		$paths = array();
		foreach ( array_unique( $ids ) as $id ) {
			clean_post_cache( (int) $id );
			$paths[ (int) $id ] = (string) wp_parse_url( (string) get_permalink( (int) $id ), PHP_URL_PATH );
		}

		return $paths;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dosieci-clean-urls' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page state (post type filter, result counts).
		// Not "post_type": wp-admin reads that parameter itself and would no longer find this page.
		$postType = $this->validPostType( isset( $_GET['content_type'] ) ? sanitize_key( wp_unslash( $_GET['content_type'] ) ) : '' );
		$applied  = isset( $_GET['applied'] ) ? absint( $_GET['applied'] ) : null;
		$skipped  = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		// phpcs:enable

		$scan      = $this->scan( $postType );
		$redirects = $this->storedRedirects();
		$rows      = array_values( array_filter( $scan['changes'], static fn( UrlChange $change ): bool => $change->isChange() ) );

		// Messy slugs first: they are what the tool exists for.
		usort( $rows, static fn( UrlChange $a, UrlChange $b ): int => (int) $a->isCurrentSlugClean() <=> (int) $b->isCurrentSlugClean() );
		$hidden = max( 0, count( $rows ) - self::MAX_ROWS );
		$rows   = array_slice( $rows, 0, self::MAX_ROWS );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci Clean URLs', 'dosieci-clean-urls' ); ?></h1>

			<?php if ( null !== $applied ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of renamed items */
							esc_html( _n( 'Renamed %d item. Its old address now redirects with a 301.', 'Renamed %d items. Their old addresses now redirect with a 301.', $applied, 'dosieci-clean-urls' ) ),
							(int) $applied
						);
						?>
						<?php if ( $skipped > 0 ) : ?>
							<?php
							printf(
								/* translators: %d: number of skipped items */
								esc_html( _n( '%d item was skipped because it changed after the preview. Check it again below.', '%d items were skipped because they changed after the preview. Check them again below.', $skipped, 'dosieci-clean-urls' ) ),
								(int) $skipped
							);
							?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Preview the new addresses before anything changes. Only the rows you tick are renamed, and every old address keeps working through a 301 redirect.', 'dosieci-clean-urls' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label for="dosieci-cu-type"><?php esc_html_e( 'Content type:', 'dosieci-clean-urls' ); ?></label>
				<select id="dosieci-cu-type" name="content_type">
					<?php foreach ( $this->postTypes() as $type => $label ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $postType, $type ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Scan', 'dosieci-clean-urls' ); ?></button>
			</form>

			<p>
				<strong><?php esc_html_e( 'Can be renamed:', 'dosieci-clean-urls' ); ?></strong> <?php echo (int) $scan['summary']['applicable']; ?> &middot;
				<strong><?php esc_html_e( 'Blocked:', 'dosieci-clean-urls' ); ?></strong> <?php echo (int) $scan['summary']['blockers']; ?> &middot;
				<strong><?php esc_html_e( 'No change needed:', 'dosieci-clean-urls' ); ?></strong> <?php echo (int) $scan['summary']['unchanged']; ?> &middot;
				<strong><?php esc_html_e( 'Stored redirects:', 'dosieci-clean-urls' ); ?></strong> <?php echo count( $redirects ); ?>
			</p>

			<?php if ( array() === $rows ) : ?>
				<p><em><?php esc_html_e( 'Every address of this content type already matches its title. Nothing to do.', 'dosieci-clean-urls' ); ?></em></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'dosieci_clean_urls_apply' ); ?>
					<input type="hidden" name="action" value="dosieci_clean_urls_apply">
					<input type="hidden" name="content_type" value="<?php echo esc_attr( $postType ); ?>">

					<p class="description"><?php esc_html_e( 'Encoded or messy addresses are ticked for you. Clean addresses that simply differ from the title are usually chosen on purpose, so they are left unticked.', 'dosieci-clean-urls' ); ?></p>

					<table class="widefat striped">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column">
									<label class="screen-reader-text" for="dosieci-cu-all"><?php esc_html_e( 'Select all', 'dosieci-clean-urls' ); ?></label>
									<input type="checkbox" id="dosieci-cu-all">
								</td>
								<th><?php esc_html_e( 'Title', 'dosieci-clean-urls' ); ?></th>
								<th><?php esc_html_e( 'Current slug', 'dosieci-clean-urls' ); ?></th>
								<th><?php esc_html_e( 'New slug', 'dosieci-clean-urls' ); ?></th>
								<th><?php esc_html_e( 'Status', 'dosieci-clean-urls' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $change ) : ?>
								<tr>
									<th scope="row" class="check-column">
										<?php if ( $change->isApplicable() ) : ?>
											<input type="checkbox" id="dosieci-cu-<?php echo (int) $change->postId; ?>"
												name="selected[<?php echo (int) $change->postId; ?>]"
												value="<?php echo esc_attr( $change->proposedSlug ); ?>"
												<?php checked( ! $change->isCurrentSlugClean() ); ?>>
										<?php endif; ?>
									</th>
									<td>
										<label for="dosieci-cu-<?php echo (int) $change->postId; ?>"><?php echo esc_html( '' !== $change->title ? $change->title : __( '(no title)', 'dosieci-clean-urls' ) ); ?></label>
										<small>#<?php echo (int) $change->postId; ?></small>
									</td>
									<td><code><?php echo esc_html( urldecode( $change->currentSlug ) ); ?></code></td>
									<td><code><?php echo esc_html( $change->proposedSlug ); ?></code></td>
									<td>
										<?php if ( UrlChange::SEVERITY_BLOCKER === $change->severity ) : ?>
											<span style="color:#b91c1c;font-weight:600;"><?php esc_html_e( 'Blocked', 'dosieci-clean-urls' ); ?></span>
											&mdash; <?php echo esc_html( (string) $change->issue ); ?>
										<?php elseif ( $change->isCurrentSlugClean() ) : ?>
											<span style="color:#1d4ed8;font-weight:600;"><?php esc_html_e( 'Custom slug', 'dosieci-clean-urls' ); ?></span>
										<?php else : ?>
											<span style="color:#166534;font-weight:600;"><?php esc_html_e( 'Ready', 'dosieci-clean-urls' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( $hidden > 0 ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %d: number of rows not shown */
								esc_html( _n( '%d more item is not listed yet. It will appear here after you apply this batch.', '%d more items are not listed yet. They will appear here after you apply this batch.', $hidden, 'dosieci-clean-urls' ) ),
								(int) $hidden
							);
							?>
						</p>
					<?php endif; ?>

					<?php if ( $scan['summary']['applicable'] > 0 ) : ?>
						<?php submit_button( __( 'Rename the ticked items (with 301 redirects)', 'dosieci-clean-urls' ) ); ?>
					<?php endif; ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

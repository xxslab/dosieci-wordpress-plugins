<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Navigation;

use DoSieci\AiOperator\Domain\SiteBuilder\Navigation\NavigationAdapterInterface;

/**
 * Navigation for block themes: a `wp_navigation` post the theme's
 * `core/navigation` block renders.
 *
 * ## Why this exists at all
 *
 * The builder previously created a classic nav menu on every theme. On
 * Twenty Twenty-Four that produced a menu term sitting in the database
 * that no visitor ever saw, because the theme's header renders a
 * `core/navigation` block which does not read classic menus.
 *
 * ## Why a wp_navigation post rather than editing the template part
 *
 * Verified against WordPress 7.0.3 + Twenty Twenty-Four before writing
 * this: the theme's header template part contains
 * `<!-- wp:navigation ... /-->` with NO `ref` attribute. A ref-less
 * navigation block resolves its content through core's own fallback, and
 * that fallback prefers an existing `wp_navigation` post. Creating one is
 * therefore enough -- confirmed by rendering the header part and seeing
 * our links come out.
 *
 * The alternative, rewriting the theme's template part to point at a
 * specific ref, means writing to theme-owned content and re-doing it on
 * every theme switch. Letting core resolve it is both less invasive and
 * less likely to break on a WordPress release.
 *
 * ## Markup
 *
 * `core/navigation-link` blocks with the attribute set core itself emits
 * (see `block_core_navigation_get_fallback_blocks()`): kind, type, id,
 * label, url. Hand-waving any of those produces links the editor flags as
 * broken.
 */
final class BlockThemeNavigationAdapter implements NavigationAdapterInterface {

	/**
	 * The title of the wp_navigation post this adapter writes. A method
	 * rather than a class constant: constant expressions cannot call __(),
	 * and this becomes real content on the user's site (rule: bundle the
	 * Polish original as this string's translation).
	 */
	public static function navTitle(): string {
		return __( 'Navigation', 'dosieci-ai-operator' );
	}

	public function supports(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	public function label(): string {
		return 'block-theme';
	}

	/**
	 * Creates or updates the navigation post for the given pages.
	 *
	 * @param array<int, array{title: string, page_id: int}> $items
	 *
	 * @return array<string, mixed>
	 */
	public function apply( array $items, ?int $existingId = null ): array {
		$blocks = array();

		foreach ( $items as $item ) {
			$pageId = (int) ( $item['page_id'] ?? 0 );
			$title  = (string) ( $item['title'] ?? '' );

			if ( $pageId <= 0 || ! get_post( $pageId ) instanceof \WP_Post ) {
				continue;
			}

			if ( '' === $title ) {
				$title = (string) get_the_title( $pageId );
			}

			$blocks[] = sprintf(
				'<!-- wp:navigation-link %s /-->',
				(string) wp_json_encode(
					array(
						'label' => $title,
						'type'  => 'page',
						'id'    => $pageId,
						'kind'  => 'post-type',
						'url'   => get_permalink( $pageId ),
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			);
		}

		if ( array() === $blocks ) {
			return array(
				'success' => false,
				'error'   => __( 'No pages to place in the navigation.', 'dosieci-ai-operator' ),
			);
		}

		$content = implode( "\n", $blocks );

		$postData = array(
			'post_type'    => 'wp_navigation',
			'post_status'  => 'publish',
			'post_title'   => self::navTitle(),
			'post_content' => $content,
		);

		if ( null !== $existingId && get_post( $existingId ) instanceof \WP_Post ) {
			$postData['ID'] = $existingId;
			$navId          = wp_update_post( $postData, true );
		} else {
			$navId = wp_insert_post( $postData, true );
		}

		if ( is_wp_error( $navId ) ) {
			return array( 'success' => false, 'error' => $navId->get_error_message() );
		}

		return array(
			'navigation_id' => (int) $navId,
			'item_count'    => count( $blocks ),
			'mode'          => 'block-theme',
		);
	}

	/** The navigation post this plugin previously created, if any. */
	public function findExisting( string $projectId ): ?int {
		$found = get_posts(
			array(
				'post_type'        => 'wp_navigation',
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded to 1 result; a site has at most one wp_navigation post per project.
				'meta_query'       => array(
					array( 'key' => '_dosieci_ai_resource_key', 'value' => 'navigation:primary' ),
					array( 'key' => '_dosieci_ai_project_id', 'value' => $projectId ),
				),
			)
		);

		return isset( $found[0] ) ? (int) $found[0] : null;
	}
}

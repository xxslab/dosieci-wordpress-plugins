<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\SiteBuilder;

use DoSieci\AiOperator\Adapter\WordPress\Tools\OptionAllowlist;
use DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooCommerceAdapter;
use DoSieci\AiOperator\Domain\SiteBuilder\ActionState;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanVerifierInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\VerificationResult;

/**
 * Reads WordPress back after a write and decides whether the step really
 * did what the plan said it would.
 *
 * ## Why this exists
 *
 * A write handler returning `success => true` is the handler's own
 * account of events. It is not evidence. The first real-WordPress Site
 * Builder run proved the gap concretely: `create_menu` returned success
 * while producing a menu with ZERO items, because the item shape it was
 * handed was one its own code silently skips. Every step reported green
 * and the built site was wrong.
 *
 * So each verifier here asks WordPress, through WordPress's own read APIs,
 * whether the observable end state matches what the approved plan
 * described. Nothing consults the write's return payload except where that
 * payload is the only source of an id WordPress itself then confirms (a
 * created post id, which is immediately re-read with get_post()).
 *
 * ## Reading, never writing
 *
 * Nothing in this class mutates anything. It is called after a write, on
 * every step, and a verifier with a side effect would be a write nobody
 * approved.
 *
 * ## Small structured evidence
 *
 * Each result carries a compact expected/actual pair, not raw WP_Post or
 * WP_Theme objects. These are persisted per step; dumping whole WordPress
 * objects into the plan table would bloat it and carry fields nobody
 * audited. "menu has 0 items, expected 4" is what makes a failure
 * diagnosable later.
 */
final class WpPlanVerifier implements PlanVerifierInterface {

	public function verify( PlanAction $action, ActionState $state, int $userId ): VerificationResult {
		// Resolved arguments where present: a resolver-driven page_id is 0
		// in the approved plan and only becomes a real id at dispatch time.
		// Verifying against the plan's literal value would report a
		// perfectly good step as "page 0 does not exist".
		$arguments = $state->resolvedArguments ?? $action->arguments;
		$result    = $state->result ?? array();

		return match ( $action->toolName ) {
			'create_post'       => $this->verifyCreatePost( $arguments, $result ),
			'update_post'       => $this->verifyUpdatePost( $arguments ),
			'trash_post'        => $this->verifyTrashPost( $arguments ),
			'install_plugin'    => $this->verifyPluginInstalled( $arguments ),
			'activate_plugin'   => $this->verifyPluginActive( $arguments, true ),
			'deactivate_plugin' => $this->verifyPluginActive( $arguments, false ),
			'install_theme'     => $this->verifyThemeInstalled( $arguments ),
			'activate_theme'    => $this->verifyThemeActive( $arguments ),
			'create_menu'       => $this->verifyMenu( $action, $state ),
			'set_homepage'      => $this->verifyHomepage( $action, $state ),
			'set_site_option'   => $this->verifySiteOption( $arguments ),
			'create_term'       => $this->verifyTerm( $result ),
			'configure_contact_form' => $this->verifyContactForm( $result ),
			'embed_contact_form'     => $this->verifyContactFormEmbedded( $arguments, $result ),
			'set_block_navigation'   => $this->verifyBlockNavigation( $arguments, $result ),
			'set_featured_image'     => $this->verifyFeaturedImage( $arguments, $result ),
			'ensure_store_pages'     => $this->verifyStorePages(),
			'set_store_basics'       => $this->verifyStoreBasics( $arguments ),
			'create_product_category',
			'update_product_category' => $this->verifyProductCategory( $arguments, $result ),
			'create_product_draft',
			'update_product_draft'   => $this->verifyProductDraft( $arguments, $result ),
			// Fail closed: an unrecognised tool in Site Builder mode means
			// nobody can confirm what it did, and green-by-default is
			// exactly the failure mode this class exists to remove.
			default             => VerificationResult::failed(
				sprintf( 'Brak weryfikatora dla narzędzia "%s".', $action->toolName ),
				array( 'tool' => $action->toolName ),
				null
			),
		};
	}

	/** True when a verifier exists for this tool -- used by the planner's own guard. */
	public static function supports( string $toolName ): bool {
		return in_array(
			$toolName,
			array(
				'create_post',
				'update_post',
				'trash_post',
				'install_plugin',
				'activate_plugin',
				'deactivate_plugin',
				'install_theme',
				'activate_theme',
				'create_menu',
				'set_homepage',
				'set_site_option',
				'create_term',
				'configure_contact_form',
				'embed_contact_form',
				'set_block_navigation',
				'set_featured_image',
				'ensure_store_pages',
				'set_store_basics',
				'create_product_category',
				'update_product_category',
				'create_product_draft',
				'update_product_draft',
			),
			true
		);
	}

	// -----------------------------------------------------------------
	// Content
	// -----------------------------------------------------------------

	/**
	 * @param array<string, mixed> $arguments
	 * @param array<string, mixed> $result
	 */
	private function verifyCreatePost( array $arguments, array $result ): VerificationResult {
		$postId = (int) ( $result['post_id'] ?? 0 );

		$expected = array(
			'title'     => (string) ( $arguments['title'] ?? '' ),
			'post_type' => (string) ( $arguments['post_type'] ?? 'page' ),
			'status'    => (string) ( $arguments['status'] ?? 'draft' ),
		);

		if ( $postId <= 0 ) {
			return VerificationResult::failed( 'Narzędzie nie zwróciło identyfikatora wpisu.', $expected, null );
		}

		// The id comes from the write, but everything compared below comes
		// from WordPress reading its own database.
		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			return VerificationResult::failed(
				sprintf( 'Wpis o id %d nie istnieje w WordPressie.', $postId ),
				$expected,
				array( 'post_id' => $postId, 'exists' => false )
			);
		}

		$actual = array(
			'post_id'   => $postId,
			'title'     => $post->post_title,
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
		);

		if ( $post->post_type !== $expected['post_type'] ) {
			return VerificationResult::failed( 'Utworzono inny typ treści niż zaplanowano.', $expected, $actual );
		}

		if ( $post->post_status !== $expected['status'] ) {
			return VerificationResult::failed(
				sprintf( 'Status wpisu to "%s", oczekiwano "%s".', $post->post_status, $expected['status'] ),
				$expected,
				$actual
			);
		}

		// Titles are compared after sanitisation, because create_post runs
		// the requested title through sanitize_text_field() -- comparing
		// raw input would fail on any title WordPress legitimately altered.
		if ( sanitize_text_field( $expected['title'] ) !== $post->post_title ) {
			return VerificationResult::failed( 'Tytuł strony nie zgadza się z planem.', $expected, $actual );
		}

		if ( isset( $arguments['content'] ) && '' !== (string) $arguments['content'] && '' === trim( $post->post_content ) ) {
			return VerificationResult::failed( 'Strona istnieje, ale jest pusta.', $expected, $actual );
		}

		return VerificationResult::passed( 'Strona istnieje z oczekiwanym tytułem i statusem.', $expected, $actual );
	}

	/** @param array<string, mixed> $arguments */
	private function verifyUpdatePost( array $arguments ): VerificationResult {
		$postId = (int) ( $arguments['post_id'] ?? 0 );
		$post   = $postId > 0 ? get_post( $postId ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return VerificationResult::failed( sprintf( 'Wpis %d nie istnieje.', $postId ), $arguments, null );
		}

		$expected = array();
		$actual   = array();

		foreach ( array( 'title' => 'post_title', 'status' => 'post_status', 'excerpt' => 'post_excerpt' ) as $arg => $field ) {
			if ( ! isset( $arguments[ $arg ] ) ) {
				continue;
			}

			$want = 'status' === $arg
				? (string) $arguments[ $arg ]
				: sanitize_text_field( (string) $arguments[ $arg ] );

			$expected[ $field ] = $want;
			$actual[ $field ]   = $post->{$field};

			if ( $post->{$field} !== $want ) {
				return VerificationResult::failed(
					sprintf( 'Pole "%s" nie zostało zaktualizowane.', $field ),
					$expected,
					$actual
				);
			}
		}

		return VerificationResult::passed( 'Wpis zawiera oczekiwane zmiany.', $expected, $actual );
	}

	/** @param array<string, mixed> $arguments */
	private function verifyTrashPost( array $arguments ): VerificationResult {
		$postId = (int) ( $arguments['post_id'] ?? 0 );
		$status = get_post_status( $postId );

		$expected = array( 'post_id' => $postId, 'status' => 'trash' );
		$actual   = array( 'post_id' => $postId, 'status' => false === $status ? null : $status );

		return 'trash' === $status
			? VerificationResult::passed( 'Wpis jest w koszu.', $expected, $actual )
			: VerificationResult::failed( 'Wpis nie trafił do kosza.', $expected, $actual );
	}

	// -----------------------------------------------------------------
	// Plugins and themes
	// -----------------------------------------------------------------

	/** @param array<string, mixed> $arguments */
	private function verifyPluginInstalled( array $arguments ): VerificationResult {
		$slug = (string) ( $arguments['slug'] ?? '' );
		$file = $this->pluginFile( $slug );

		$expected = array( 'slug' => $slug, 'installed' => true );

		if ( null === $file ) {
			return VerificationResult::failed(
				sprintf( 'Wtyczka "%s" nie jest zainstalowana.', $slug ),
				$expected,
				array( 'slug' => $slug, 'installed' => false )
			);
		}

		$actual = array( 'slug' => $slug, 'installed' => true, 'plugin_file' => $file, 'active' => is_plugin_active( $file ) );

		// install_plugin's own `activate` argument is part of the approved
		// plan, so "installed but not activated when activation was asked
		// for" is a failed step, not a partial success.
		if ( ! empty( $arguments['activate'] ) && ! is_plugin_active( $file ) ) {
			$expected['active'] = true;

			return VerificationResult::failed(
				sprintf( 'Wtyczka "%s" jest zainstalowana, ale nieaktywna.', $slug ),
				$expected,
				$actual
			);
		}

		return VerificationResult::passed( 'Wtyczka jest zainstalowana zgodnie z planem.', $expected, $actual );
	}

	/** @param array<string, mixed> $arguments */
	private function verifyPluginActive( array $arguments, bool $shouldBeActive ): VerificationResult {
		$slug = (string) ( $arguments['slug'] ?? '' );
		$file = $this->pluginFile( $slug );

		$expected = array( 'slug' => $slug, 'active' => $shouldBeActive );

		if ( null === $file ) {
			return VerificationResult::failed(
				sprintf( 'Wtyczka "%s" nie jest zainstalowana.', $slug ),
				$expected,
				array( 'slug' => $slug, 'installed' => false )
			);
		}

		$isActive = is_plugin_active( $file );
		$actual   = array( 'slug' => $slug, 'active' => $isActive );

		return $isActive === $shouldBeActive
			? VerificationResult::passed( 'Stan wtyczki zgodny z planem.', $expected, $actual )
			: VerificationResult::failed( 'Stan aktywacji wtyczki nie zgadza się z planem.', $expected, $actual );
	}

	/** @param array<string, mixed> $arguments */
	private function verifyThemeInstalled( array $arguments ): VerificationResult {
		$slug  = (string) ( $arguments['slug'] ?? '' );
		$theme = wp_get_theme( $slug );

		$expected = array( 'slug' => $slug, 'installed' => true );

		if ( ! $theme->exists() ) {
			return VerificationResult::failed(
				sprintf( 'Motyw "%s" nie jest zainstalowany.', $slug ),
				$expected,
				array( 'slug' => $slug, 'installed' => false )
			);
		}

		$activeStylesheet = wp_get_theme()->get_stylesheet();
		$actual           = array( 'slug' => $slug, 'installed' => true, 'active_stylesheet' => $activeStylesheet );

		if ( ! empty( $arguments['activate'] ) && $activeStylesheet !== $slug ) {
			$expected['active_stylesheet'] = $slug;

			return VerificationResult::failed(
				sprintf( 'Motyw "%s" jest zainstalowany, ale aktywny jest "%s".', $slug, $activeStylesheet ),
				$expected,
				$actual
			);
		}

		return VerificationResult::passed( 'Motyw jest zainstalowany zgodnie z planem.', $expected, $actual );
	}

	/** @param array<string, mixed> $arguments */
	private function verifyThemeActive( array $arguments ): VerificationResult {
		$slug   = (string) ( $arguments['slug'] ?? '' );
		$active = wp_get_theme()->get_stylesheet();

		$expected = array( 'active_stylesheet' => $slug );
		$actual   = array( 'active_stylesheet' => $active );

		return $active === $slug
			? VerificationResult::passed( 'Motyw jest aktywny.', $expected, $actual )
			: VerificationResult::failed( 'Aktywny jest inny motyw niż zaplanowany.', $expected, $actual );
	}

	// -----------------------------------------------------------------
	// Structure
	// -----------------------------------------------------------------

	/**
	 * The verifier that would have caught the original empty-menu bug.
	 *
	 * Checks the menu exists AND contains one item per planned entry AND
	 * that those items point at the pages the plan named -- not merely that
	 * a menu object was created.
	 */
	private function verifyMenu( PlanAction $action, ActionState $state ): VerificationResult {
		$name   = (string) ( $action->arguments['name'] ?? '' );
		$result = $state->result ?? array();

		$menuId = (int) ( $result['menu_id'] ?? 0 );
		$menu   = $menuId > 0 ? wp_get_nav_menu_object( $menuId ) : wp_get_nav_menu_object( $name );

		// The plan's menu items are resolver-driven, so the expected count
		// comes from the approved resolver spec rather than the (empty)
		// literal arguments.
		$expectedItems = $this->expectedMenuItemCount( $action );
		$expected      = array( 'name' => $name, 'item_count' => $expectedItems );

		if ( ! $menu instanceof \WP_Term ) {
			return VerificationResult::failed(
				sprintf( 'Menu "%s" nie istnieje.', $name ),
				$expected,
				array( 'exists' => false )
			);
		}

		$items = wp_get_nav_menu_items( $menu->term_id );
		$items = is_array( $items ) ? $items : array();

		$actual = array(
			'menu_id'    => (int) $menu->term_id,
			'item_count' => count( $items ),
			'titles'     => array_map( static fn( $item ): string => (string) $item->title, $items ),
		);

		if ( $expectedItems > 0 && array() === $items ) {
			// The exact bug the real build hit: menu created, zero links.
			return VerificationResult::failed(
				sprintf( 'Menu "%s" istnieje, ale nie zawiera żadnych pozycji.', $name ),
				$expected,
				$actual
			);
		}

		if ( count( $items ) < $expectedItems ) {
			return VerificationResult::failed(
				sprintf( 'Menu zawiera %d pozycji, oczekiwano %d.', count( $items ), $expectedItems ),
				$expected,
				$actual
			);
		}

		// Every planned destination must actually be linked. A menu with the
		// right COUNT but the wrong targets is still a broken menu.
		$linkedObjectIds = array();
		foreach ( $items as $item ) {
			$linkedObjectIds[] = (int) $item->object_id;
		}

		foreach ( $this->expectedMenuPageIds( $action, $state ) as $pageId ) {
			if ( ! in_array( $pageId, $linkedObjectIds, true ) ) {
				$actual['linked_object_ids'] = $linkedObjectIds;

				return VerificationResult::failed(
					sprintf( 'Menu nie zawiera odnośnika do strony o id %d.', $pageId ),
					$expected,
					$actual
				);
			}
		}

		return VerificationResult::passed( 'Menu istnieje i zawiera oczekiwane odnośniki.', $expected, $actual );
	}

	private function expectedMenuItemCount( PlanAction $action ): int {
		$spec = $action->resolvers['items'] ?? null;

		if ( is_array( $spec ) && 'menu_items' === ( $spec['kind'] ?? '' ) ) {
			return count( (array) ( $spec['items'] ?? array() ) );
		}

		return count( (array) ( $action->arguments['items'] ?? array() ) );
	}

	/** @return int[] */
	private function expectedMenuPageIds( PlanAction $action, ActionState $state ): array {
		// Resolved item ids are not recoverable from the plan alone (they
		// were filled in at run time), so fall back to whatever the menu
		// handler reported linking. Where the write recorded nothing, the
		// count check above is the binding assertion.
		$ids = array();

		foreach ( (array) ( $state->result['items_added'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['page_id'] ) ) {
				$ids[] = (int) $entry['page_id'];
			}
		}

		return $ids;
	}

	/** @param array<string, mixed> $arguments */
	private function verifyHomepage( PlanAction $action, ActionState $state ): VerificationResult {
		// The page id was resolved at run time, so read what the write
		// actually reported setting and confirm WordPress agrees.
		$result       = $state->result ?? array();
		$expectedPage = (int) ( $result['front_page_id'] ?? 0 );

		$actual = array(
			'show_on_front'  => (string) get_option( 'show_on_front', 'posts' ),
			'page_on_front'  => (int) get_option( 'page_on_front', 0 ),
			'page_for_posts' => (int) get_option( 'page_for_posts', 0 ),
		);

		$expected = array( 'show_on_front' => 'page', 'page_on_front' => $expectedPage );

		if ( 'page' !== $actual['show_on_front'] ) {
			return VerificationResult::failed( 'Witryna nadal wyświetla wpisy na stronie głównej.', $expected, $actual );
		}

		if ( $expectedPage > 0 && $actual['page_on_front'] !== $expectedPage ) {
			return VerificationResult::failed( 'Jako strona główna ustawiona jest inna strona.', $expected, $actual );
		}

		if ( $actual['page_on_front'] <= 0 ) {
			return VerificationResult::failed( 'Nie ustawiono strony głównej.', $expected, $actual );
		}

		// A front page that is not published is a blank site for visitors.
		if ( 'publish' !== get_post_status( $actual['page_on_front'] ) ) {
			return VerificationResult::failed( 'Strona główna nie jest opublikowana.', $expected, $actual );
		}

		return VerificationResult::passed( 'Strona główna jest ustawiona i opublikowana.', $expected, $actual );
	}

	/** @param array<string, mixed> $arguments */
	private function verifySiteOption( array $arguments ): VerificationResult {
		$option = (string) ( $arguments['option'] ?? '' );

		if ( '' === $option || ! OptionAllowlist::has( $option ) ) {
			return VerificationResult::failed( 'Opcja spoza dozwolonej listy.', array( 'option' => $option ), null );
		}

		// Compared against the SANITISED expectation: set_site_option runs
		// the value through OptionAllowlist::sanitize(), so a raw comparison
		// would report a false mismatch for any value WordPress normalised
		// (a bool becoming '1', an int being cast).
		$sanitised = OptionAllowlist::sanitize( $option, $arguments['value'] ?? '' );
		$want      = $sanitised['ok'] ? $sanitised['value'] : null;
		$current   = get_option( $option );

		$expected = array( 'option' => $option, 'value' => $want );
		$actual   = array( 'option' => $option, 'value' => is_scalar( $current ) ? $current : null );

		// Loose comparison on purpose: WordPress stores everything as
		// strings, so (int) 10 and '10' are the same stored option.
		return (string) $want === (string) $current
			? VerificationResult::passed( 'Opcja ma oczekiwaną wartość.', $expected, $actual )
			: VerificationResult::failed( 'Opcja nie ma oczekiwanej wartości.', $expected, $actual );
	}

	/** @param array<string, mixed> $result */
	private function verifyTerm( array $result ): VerificationResult {
		$termId   = (int) ( $result['term_id'] ?? 0 );
		$taxonomy = (string) ( $result['taxonomy'] ?? '' );

		$expected = array( 'term_id' => $termId, 'taxonomy' => $taxonomy );

		if ( $termId <= 0 || '' === $taxonomy ) {
			return VerificationResult::failed( 'Narzędzie nie zwróciło identyfikatora terminu.', $expected, null );
		}

		$term = get_term( $termId, $taxonomy );

		return $term instanceof \WP_Term
			? VerificationResult::passed( 'Termin istnieje.', $expected, array( 'term_id' => (int) $term->term_id, 'taxonomy' => $term->taxonomy ) )
			: VerificationResult::failed( 'Termin nie istnieje.', $expected, array( 'exists' => false ) );
	}

	/**
	 * The form must exist as a real CF7 post -- not merely have been
	 * reported created.
	 *
	 * @param array<string, mixed> $result
	 */
	private function verifyContactForm( array $result ): VerificationResult {
		$formId    = (int) ( $result['form_id'] ?? 0 );
		$shortcode = (string) ( $result['shortcode'] ?? '' );

		$expected = array( 'form_exists' => true );

		if ( $formId <= 0 ) {
			return VerificationResult::failed( 'Nie utworzono formularza.', $expected, null );
		}

		$post   = get_post( $formId );
		$actual = array(
			'form_id'   => $formId,
			'post_type' => $post instanceof \WP_Post ? $post->post_type : null,
			'shortcode' => $shortcode,
		);

		if ( ! $post instanceof \WP_Post || 'wpcf7_contact_form' !== $post->post_type ) {
			return VerificationResult::failed( 'Formularz nie istnieje w WordPressie.', $expected, $actual );
		}

		if ( '' === $shortcode ) {
			return VerificationResult::failed( 'Formularz istnieje, ale nie zwrócono shortcode.', $expected, $actual );
		}

		return VerificationResult::passed( 'Formularz kontaktowy istnieje.', $expected, $actual );
	}

	/**
	 * The page must reference a form that ACTUALLY EXISTS. A shortcode
	 * pointing at a deleted form renders an error to visitors, which is
	 * worse than showing no form at all.
	 *
	 * @param array<string, mixed> $arguments
	 * @param array<string, mixed> $result
	 */
	private function verifyContactFormEmbedded( array $arguments, array $result ): VerificationResult {
		$pageId = (int) ( $arguments['page_id'] ?? 0 );
		$page   = $pageId > 0 ? get_post( $pageId ) : null;

		$expected = array( 'page_id' => $pageId, 'contains_form' => true );

		if ( ! $page instanceof \WP_Post ) {
			return VerificationResult::failed( 'Strona kontaktowa nie istnieje.', $expected, null );
		}

		$hasShortcode = str_contains( $page->post_content, '[contact-form-7' );
		$actual       = array( 'page_id' => $pageId, 'contains_form' => $hasShortcode );

		if ( ! $hasShortcode ) {
			return VerificationResult::failed( 'Strona kontaktowa nie zawiera formularza.', $expected, $actual );
		}

		// Re-parse the page as blocks: an embed that broke the block
		// document would leave the page uneditable in Gutenberg.
		$blocks = parse_blocks( $page->post_content );
		if ( serialize_blocks( $blocks ) !== $page->post_content ) {
			$actual['blocks_valid'] = false;

			return VerificationResult::failed( 'Osadzenie formularza uszkodziło strukturę bloków.', $expected, $actual );
		}

		return VerificationResult::passed( 'Strona kontaktowa zawiera formularz.', $expected, $actual );
	}

	/**
	 * The navigation must exist AND contain a link per planned page AND be
	 * what the theme actually renders. A wp_navigation post nobody renders
	 * is the same invisible-menu failure this tool exists to fix.
	 *
	 * @param array<string, mixed> $arguments
	 * @param array<string, mixed> $result
	 */
	private function verifyBlockNavigation( array $arguments, array $result ): VerificationResult {
		$navId    = (int) ( $result['navigation_id'] ?? 0 );
		$expected = array( 'item_count' => count( (array) ( $arguments['items'] ?? array() ) ) );

		if ( $navId <= 0 || 'wp_navigation' !== get_post_type( $navId ) ) {
			return VerificationResult::failed( 'Nie utworzono wpisu nawigacji.', $expected, null );
		}

		$post   = get_post( $navId );
		$blocks = parse_blocks( $post instanceof \WP_Post ? $post->post_content : '' );
		$links  = array_values(
			array_filter( $blocks, static fn( array $b ): bool => 'core/navigation-link' === $b['blockName'] )
		);

		$actual = array(
			'navigation_id' => $navId,
			'item_count'    => count( $links ),
			'labels'        => array_map( static fn( array $b ): string => (string) ( $b['attrs']['label'] ?? '' ), $links ),
		);

		if ( count( $links ) < $expected['item_count'] ) {
			return VerificationResult::failed( 'Nawigacja zawiera mniej pozycji niż zaplanowano.', $expected, $actual );
		}

		// Read it back the way the theme will: core resolves a ref-less
		// navigation block through its fallback, so this confirms the post
		// is the one that will actually render.
		if ( function_exists( 'block_core_navigation_get_fallback_blocks' ) ) {
			$fallback = block_core_navigation_get_fallback_blocks();
			$rendered = array_map( static fn( array $b ): string => (string) ( $b['attrs']['label'] ?? '' ), (array) $fallback );

			$actual['rendered_labels'] = $rendered;

			foreach ( $actual['labels'] as $label ) {
				if ( '' !== $label && ! in_array( $label, $rendered, true ) ) {
					return VerificationResult::failed(
						sprintf( 'Nawigacja istnieje, ale motyw jej nie renderuje (brak „%s”).', $label ),
						$expected,
						$actual
					);
				}
			}
		}

		return VerificationResult::passed( 'Nawigacja motywu blokowego zawiera oczekiwane odnośniki.', $expected, $actual );
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @param array<string, mixed> $result
	 */
	private function verifyFeaturedImage( array $arguments, array $result ): VerificationResult {
		$pageId       = (int) ( $arguments['page_id'] ?? 0 );
		$attachmentId = (int) ( $arguments['attachment_id'] ?? 0 );

		$expected = array( 'page_id' => $pageId, 'attachment_id' => $attachmentId );

		if ( $pageId <= 0 || $attachmentId <= 0 ) {
			return VerificationResult::failed( 'Krok nie wskazał strony lub załącznika.', $expected, null );
		}

		// Read back from WordPress, not from the handler's own result: the
		// handler saying it set the thumbnail is not evidence the thumbnail
		// is set.
		$actualId = (int) get_post_thumbnail_id( $pageId );

		$actual = array(
			'page_id'       => $pageId,
			'attachment_id' => $actualId,
			'has_image'     => $actualId > 0,
		);

		if ( $actualId !== $attachmentId ) {
			return VerificationResult::failed(
				sprintf( 'Obrazek wyróżniający strony %d to %d, oczekiwano %d.', $pageId, $actualId, $attachmentId ),
				$expected,
				$actual
			);
		}

		// A thumbnail id pointing at a deleted attachment renders nothing,
		// so "the meta is set" is not enough on its own.
		if ( ! get_post( $attachmentId ) instanceof \WP_Post ) {
			return VerificationResult::failed(
				sprintf( 'Załącznik %d nie istnieje.', $attachmentId ),
				$expected,
				$actual
			);
		}

		$actual['url'] = (string) wp_get_attachment_url( $attachmentId );

		return VerificationResult::passed( 'Strona ma ustawiony oczekiwany obrazek wyróżniający.', $expected, $actual );
	}

	// -----------------------------------------------------------------
	// Commerce
	// -----------------------------------------------------------------

	private function verifyStorePages(): VerificationResult {
		$woo = new WooCommerceAdapter();

		if ( ! $woo->isAvailable() ) {
			return VerificationResult::failed( 'WooCommerce nie jest aktywny.', array( 'woocommerce' => true ), null );
		}

		$state    = $woo->corePageState();
		$expected = array( 'pages' => array_keys( WooCommerceAdapter::CORE_PAGES ) );

		$missing = array();
		foreach ( $state as $slug => $page ) {
			// A non-zero option is NOT proof: WooCommerce keeps the id after
			// the page is deleted. Verified on 11.0.1 -- see the API notes.
			if ( true !== $page['exists'] ) {
				$missing[] = $slug;
			}
		}

		$actual = array( 'pages' => $state, 'missing' => $missing );

		if ( array() !== $missing ) {
			return VerificationResult::failed(
				sprintf( 'Brakuje działających stron sklepu: %s.', implode( ', ', $missing ) ),
				$expected,
				$actual
			);
		}

		return VerificationResult::passed( 'Wszystkie strony sklepu istnieją i są przypisane.', $expected, $actual );
	}

	/** @param array<string, mixed> $arguments */
	private function verifyStoreBasics( array $arguments ): VerificationResult {
		$woo = new WooCommerceAdapter();

		if ( ! $woo->isAvailable() ) {
			return VerificationResult::failed( 'WooCommerce nie jest aktywny.', array(), null );
		}

		$expected = array(
			'country'        => strtoupper( (string) ( $arguments['country'] ?? '' ) ),
			'currency'       => strtoupper( (string) ( $arguments['currency'] ?? '' ) ),
			'weight_unit'    => strtolower( (string) ( $arguments['weight_unit'] ?? 'kg' ) ),
			'dimension_unit' => strtolower( (string) ( $arguments['dimension_unit'] ?? 'cm' ) ),
		);

		// Read through WooCommerce's own accessors, which is what the store
		// actually behaves according to -- not the raw options we wrote.
		$actual = $woo->effectiveSettings();

		foreach ( $expected as $field => $want ) {
			if ( '' === $want ) {
				continue;
			}

			if ( strcasecmp( $want, (string) ( $actual[ $field ] ?? '' ) ) !== 0 ) {
				return VerificationResult::failed(
					sprintf( 'Ustawienie „%s” to „%s”, oczekiwano „%s”.', $field, (string) ( $actual[ $field ] ?? '' ), $want ),
					$expected,
					$actual
				);
			}
		}

		return VerificationResult::passed( 'Podstawowe ustawienia sklepu są zgodne z planem.', $expected, $actual );
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @param array<string, mixed> $result
	 */
	private function verifyProductCategory( array $arguments, array $result ): VerificationResult {
		$termId   = (int) ( $result['term_id'] ?? 0 );
		$expected = array( 'name' => (string) ( $arguments['name'] ?? '' ), 'taxonomy' => WooCommerceAdapter::TAXONOMY );

		if ( $termId <= 0 ) {
			return VerificationResult::failed( 'Narzędzie nie zwróciło identyfikatora kategorii.', $expected, null );
		}

		$category = ( new WooCommerceAdapter() )->readCategory( $termId );

		if ( null === $category ) {
			return VerificationResult::failed(
				sprintf( 'Kategoria %d nie istnieje w taksonomii produktów.', $termId ),
				$expected,
				array( 'term_id' => $termId, 'exists' => false )
			);
		}

		if ( $category['name'] !== $expected['name'] ) {
			return VerificationResult::failed(
				sprintf( 'Kategoria nazywa się „%s”, oczekiwano „%s”.', $category['name'], $expected['name'] ),
				$expected,
				$category
			);
		}

		return VerificationResult::passed( 'Kategoria produktów istnieje z oczekiwaną nazwą.', $expected, $category );
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @param array<string, mixed> $result
	 */
	private function verifyProductDraft( array $arguments, array $result ): VerificationResult {
		$productId = (int) ( $result['product_id'] ?? 0 );

		$expectedCategories = array_map( 'intval', (array) ( $arguments['category_ids'] ?? array() ) );
		sort( $expectedCategories );

		$expected = array(
			'name'          => (string) ( $arguments['name'] ?? '' ),
			'status'        => 'draft',
			'regular_price' => (string) ( $arguments['regular_price'] ?? '' ),
			'categories'    => $expectedCategories,
			'image_id'      => (int) ( $arguments['image_id'] ?? 0 ),
		);

		if ( $productId <= 0 ) {
			return VerificationResult::failed( 'Narzędzie nie zwróciło identyfikatora produktu.', $expected, null );
		}

		$product = ( new WooCommerceAdapter() )->readProduct( $productId );

		if ( null === $product ) {
			return VerificationResult::failed(
				sprintf( 'Produkt %d nie istnieje.', $productId ),
				$expected,
				array( 'product_id' => $productId, 'exists' => false )
			);
		}

		$actualCategories = $product['categories'];
		sort( $actualCategories );

		$actual = array(
			'name'          => $product['name'],
			'status'        => $product['status'],
			'regular_price' => $product['price'],
			'categories'    => $actualCategories,
			'image_id'      => $product['image_id'],
		);

		// The invariant that matters most: a generated product is a draft.
		// If this ever reads "publish", something priced went live without
		// anybody approving publication.
		if ( 'draft' !== $actual['status'] ) {
			return VerificationResult::failed(
				sprintf( 'Produkt ma status „%s”, a musi pozostać szkicem.', $actual['status'] ),
				$expected,
				$actual
			);
		}

		if ( $actual['name'] !== $expected['name'] ) {
			return VerificationResult::failed( 'Nazwa produktu nie zgadza się z planem.', $expected, $actual );
		}

		// Both sides are normalised to the same canonical decimal string
		// before comparison. WooCommerce may store "79" where the plan said
		// "79.00" -- the same price -- and a raw string compare would fail a
		// perfectly correct step.
		if ( $this->canonicalPrice( $actual['regular_price'] ) !== $this->canonicalPrice( $expected['regular_price'] ) ) {
			return VerificationResult::failed(
				sprintf( 'Cena to %s, oczekiwano %s.', $actual['regular_price'], $expected['regular_price'] ),
				$expected,
				$actual
			);
		}

		if ( $expectedCategories !== $actualCategories ) {
			return VerificationResult::failed( 'Kategorie produktu nie zgadzają się z planem.', $expected, $actual );
		}

		if ( $expected['image_id'] > 0 && $actual['image_id'] !== $expected['image_id'] ) {
			return VerificationResult::failed( 'Obrazek produktu nie zgadza się z planem.', $expected, $actual );
		}

		return VerificationResult::passed( 'Produkt istnieje jako szkic z oczekiwaną ceną.', $expected, $actual );
	}

	/**
	 * A money value as one canonical string.
	 *
	 * Money is never compared as a float here. Two decimal places is the
	 * only shape either side is allowed to differ in, so normalising the
	 * text is both sufficient and free of rounding surprises.
	 */
	private function canonicalPrice( string $price ): string {
		$price = trim( $price );

		if ( 1 !== preg_match( '/^\d{1,9}(\.\d{1,2})?$/', $price ) ) {
			return $price;
		}

		return number_format( (float) $price, 2, '.', '' );
	}

	private function pluginFile( string $slug ): ?string {
		if ( '' === $slug ) {
			return null;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( dirname( (string) $file ) === $slug ) {
				return (string) $file;
			}
		}

		return null;
	}
}

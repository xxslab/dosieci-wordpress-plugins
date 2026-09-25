<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\Tools;

use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;

/**
 * The tools that actually build a site: content, structure, appearance,
 * plugins and settings.
 *
 * Every tool here is registered at RISK_REVERSIBLE_WRITE or above, which
 * means ToolDispatcher refuses to run it without per-action human
 * confirmation -- there is no "trusted mode" that bypasses that, and
 * raising the installation's max risk level does not grant it either.
 *
 * Three security properties are load-bearing and deliberately implemented
 * here rather than left to the caller:
 *
 *  1. Plugins and themes are installed BY SLUG from the wordpress.org
 *     directory only. There is no "install from URL" tool, because that is
 *     a remote-code-execution primitive: a model that can be talked into
 *     fetching an arbitrary ZIP can be talked into installing a backdoor.
 *     A slug resolves through the official API to an official package.
 *
 *  2. Options go through OptionAllowlist, never straight to
 *     update_option(). See that class for why an unrestricted option write
 *     is a site takeover.
 *
 *  3. Deletions trash rather than erase. wp_trash_post() is recoverable
 *     from the WordPress UI by the same human who confirmed it; permanent
 *     deletion is not offered at all.
 *
 * Each handler returns a structured summary including what changed, so the
 * model can report accurately and the audit log records something a human
 * can actually review.
 */
final class WriteToolFactory {

	private const DEFAULT_TIMEOUT = 45;
	private const INSTALL_TIMEOUT = 120;

	public function register( ToolRegistry $registry ): void {
		foreach ( $this->definitions() as $definition ) {
			$registry->register( $definition );
		}
	}

	/** @return ToolDefinition[] */
	private function definitions(): array {
		return array(
			new ToolDefinition(
				'create_post',
				__( 'Creates a new page or post. Returns its id and URL. Content accepts HTML or Gutenberg blocks.', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'post_type' => array( 'type' => 'string', 'enum' => array( 'page', 'post' ) ),
						'status'    => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'private' ) ),
						'excerpt'   => array( 'type' => 'string' ),
						'parent_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'title' ),
				),
				'publish_pages',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'createPost' )
			),
			new ToolDefinition(
				'update_post',
				__( 'Updates an existing page or post (title, content, status, excerpt).', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'private' ) ),
						'excerpt' => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_id' ),
				),
				'edit_pages',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'updatePost' )
			),
			new ToolDefinition(
				'trash_post',
				__( 'Moves a page or post to the trash. Not a permanent delete — the content can be restored from the trash.', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'delete_pages',
				ToolDefinition::RISK_DESTRUCTIVE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'trashPost' )
			),
			new ToolDefinition(
				'set_site_option',
				__( 'Changes one site setting from the allowed list (title, description, timezone, permalinks, comments, visibility).', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array(
						'option' => array( 'type' => 'string', 'enum' => OptionAllowlist::names() ),
						'value'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'option', 'value' ),
				),
				'manage_options',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'setSiteOption' )
			),
			new ToolDefinition(
				'set_homepage',
				__( 'Sets the given page as the site’s homepage, optionally also the posts page.', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array(
						'page_id'       => array( 'type' => 'integer' ),
						'posts_page_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'page_id' ),
				),
				'manage_options',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'setHomepage' )
			),
			new ToolDefinition(
				'install_plugin',
				__( 'Installs a plugin from the official WordPress.org directory by its slug (e.g. "contact-form-7") and optionally activates it.', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array(
						'slug'     => array( 'type' => 'string' ),
						'activate' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'slug' ),
				),
				'install_plugins',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::INSTALL_TIMEOUT,
				array( $this, 'installPlugin' )
			),
			new ToolDefinition(
				'activate_plugin',
				__( 'Activates an already-installed plugin by its slug.', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array( 'slug' => array( 'type' => 'string' ) ),
					'required'   => array( 'slug' ),
				),
				'activate_plugins',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'activatePlugin' )
			),
			new ToolDefinition(
				'deactivate_plugin',
				__( 'Deactivates a plugin by its slug. Does not remove its files or data.', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array( 'slug' => array( 'type' => 'string' ) ),
					'required'   => array( 'slug' ),
				),
				'activate_plugins',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'deactivatePlugin' )
			),
			new ToolDefinition(
				'install_theme',
				__( 'Installs a theme from the official WordPress.org directory by its slug (e.g. "twentytwentyfour") and optionally activates it.', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array(
						'slug'     => array( 'type' => 'string' ),
						'activate' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'slug' ),
				),
				'install_themes',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::INSTALL_TIMEOUT,
				array( $this, 'installTheme' )
			),
			new ToolDefinition(
				'activate_theme',
				__( 'Activates an already-installed theme by its slug.', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array( 'slug' => array( 'type' => 'string' ) ),
					'required'   => array( 'slug' ),
				),
				'switch_themes',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'activateTheme' )
			),
			new ToolDefinition(
				'create_menu',
				__( 'Creates a navigation menu from the given items (pages by id or custom links) and optionally assigns it to a location in the theme.', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array(
						'name'     => array( 'type' => 'string' ),
						'location' => array( 'type' => 'string' ),
						'items'    => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'title'   => array( 'type' => 'string' ),
									'page_id' => array( 'type' => 'integer' ),
									'url'     => array( 'type' => 'string' ),
								),
							),
						),
					),
					'required'   => array( 'name' ),
				),
				'edit_theme_options',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'createMenu' )
			),
			new ToolDefinition(
				'create_term',
				__( 'Creates a category, tag or other taxonomy term.', 'dosieci-ai-operator' ),
				array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array( 'type' => 'string' ),
						'taxonomy'    => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'name' ),
				),
				'manage_categories',
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				self::DEFAULT_TIMEOUT,
				array( $this, 'createTerm' )
			),
		);
	}

	// ---------------------------------------------------------------
	// Content
	// ---------------------------------------------------------------

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function createPost( array $args ): array {
		$postType = isset( $args['post_type'] ) ? (string) $args['post_type'] : 'page';

		if ( ! in_array( $postType, array( 'page', 'post' ), true ) ) {
			return $this->failure( __( 'Allowed types are "page" and "post".', 'dosieci-ai-operator' ) );
		}

		// wp_insert_post() with wp_error => true reports WHY it refused,
		// instead of returning 0 and leaving the model to guess.
		$postId = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( (string) $args['title'] ),
				'post_content' => isset( $args['content'] ) ? wp_kses_post( (string) $args['content'] ) : '',
				'post_excerpt' => isset( $args['excerpt'] ) ? sanitize_text_field( (string) $args['excerpt'] ) : '',
				'post_status'  => $this->status( $args ),
				'post_type'    => $postType,
				'post_parent'  => isset( $args['parent_id'] ) ? (int) $args['parent_id'] : 0,
			),
			true
		);

		if ( is_wp_error( $postId ) ) {
			return $this->failure( $postId->get_error_message() );
		}

		return array(
			'created'   => true,
			'post_id'   => (int) $postId,
			'post_type' => $postType,
			'status'    => get_post_status( $postId ),
			'permalink' => get_permalink( $postId ),
			'edit_url'  => get_edit_post_link( $postId, 'raw' ),
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function updatePost( array $args ): array {
		$postId = (int) $args['post_id'];
		$post   = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			return $this->failure( sprintf( /* translators: %d: post id */ __( 'There is no post with id %d.', 'dosieci-ai-operator' ), $postId ) );
		}

		// The tool-level capability (edit_pages) is not enough on its own:
		// it does not imply the right to edit THIS post, which may belong
		// to somebody else or be locked.
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return $this->failure( __( 'The current user cannot edit this post.', 'dosieci-ai-operator' ) );
		}

		$update = array( 'ID' => $postId );

		if ( isset( $args['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$update['post_content'] = wp_kses_post( (string) $args['content'] );
		}
		if ( isset( $args['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_text_field( (string) $args['excerpt'] );
		}
		if ( isset( $args['status'] ) ) {
			$update['post_status'] = $this->status( $args );
		}

		if ( 1 === count( $update ) ) {
			return $this->failure( __( 'No field to change was given.', 'dosieci-ai-operator' ) );
		}

		$result = wp_update_post( $update, true );

		if ( is_wp_error( $result ) ) {
			return $this->failure( $result->get_error_message() );
		}

		return array(
			'updated'        => true,
			'post_id'        => $postId,
			'changed_fields' => array_values( array_diff( array_keys( $update ), array( 'ID' ) ) ),
			'status'         => get_post_status( $postId ),
			'permalink'      => get_permalink( $postId ),
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function trashPost( array $args ): array {
		$postId = (int) $args['post_id'];
		$post   = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			return $this->failure( sprintf( /* translators: %d: post id */ __( 'There is no post with id %d.', 'dosieci-ai-operator' ), $postId ) );
		}

		if ( ! current_user_can( 'delete_post', $postId ) ) {
			return $this->failure( __( 'The current user cannot delete this post.', 'dosieci-ai-operator' ) );
		}

		// Trash, never wp_delete_post( force = true ): the human who
		// confirmed this can undo it from the WordPress UI.
		$result = wp_trash_post( $postId );

		if ( ! $result ) {
			return $this->failure( __( 'WordPress refused to move the post to the trash.', 'dosieci-ai-operator' ) );
		}

		return array(
			'trashed'     => true,
			'post_id'     => $postId,
			'title'       => $post->post_title,
			'recoverable' => true,
		);
	}

	// ---------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function setSiteOption( array $args ): array {
		$option = (string) $args['option'];

		$sanitized = OptionAllowlist::sanitize( $option, $args['value'] ?? '' );

		if ( ! $sanitized['ok'] ) {
			return $this->failure( (string) $sanitized['error'] );
		}

		$previous = get_option( $option );
		update_option( $option, $sanitized['value'] );

		if ( 'permalink_structure' === $option ) {
			// Changing the structure without flushing leaves every
			// permalink 404ing until something else happens to flush.
			flush_rewrite_rules( false );
		}

		return array(
			'updated'  => true,
			'option'   => $option,
			'previous' => is_scalar( $previous ) ? $previous : null,
			'current'  => $sanitized['value'],
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function setHomepage( array $args ): array {
		$pageId = (int) $args['page_id'];
		$page   = get_post( $pageId );

		if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type ) {
			return $this->failure( sprintf( /* translators: %d: post id */ __( 'Id %d does not point to a page.', 'dosieci-ai-operator' ), $pageId ) );
		}

		if ( 'publish' !== $page->post_status ) {
			// A draft set as the front page produces a blank site for
			// logged-out visitors, which looks like an outage.
			return $this->failure( __( 'The homepage must be published. Publish it first.', 'dosieci-ai-operator' ) );
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $pageId );

		$postsPageId = isset( $args['posts_page_id'] ) ? (int) $args['posts_page_id'] : 0;
		if ( $postsPageId > 0 ) {
			update_option( 'page_for_posts', $postsPageId );
		}

		return array(
			'updated'       => true,
			'front_page_id' => $pageId,
			'front_page'    => get_the_title( $pageId ),
			'posts_page_id' => $postsPageId > 0 ? $postsPageId : null,
			'home_url'      => home_url( '/' ),
		);
	}

	// ---------------------------------------------------------------
	// Plugins and themes
	// ---------------------------------------------------------------

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function installPlugin( array $args ): array {
		$slug = $this->slug( $args['slug'] ?? '' );

		if ( '' === $slug ) {
			return $this->failure( __( 'Invalid plugin slug.', 'dosieci-ai-operator' ) );
		}

		$this->requireUpgraderFiles();

		$existing = $this->findPluginFile( $slug );

		if ( null === $existing ) {
			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			);

			if ( is_wp_error( $api ) ) {
				return $this->failure( sprintf( /* translators: 1: plugin slug, 2: error message from WordPress.org */ __( 'Plugin "%1\$s" was not found in the WordPress.org directory: %2\$s', 'dosieci-ai-operator' ), $slug, $api->get_error_message() ) );
			}

			// The package URL comes from the official API response, never
			// from the model -- that is what keeps this from being an
			// install-arbitrary-code tool.
			$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
			$result   = $upgrader->install( $api->download_link );

			if ( is_wp_error( $result ) ) {
				return $this->failure( $result->get_error_message() );
			}

			if ( true !== $result ) {
				return $this->failure( __( 'The plugin installation failed.', 'dosieci-ai-operator' ) );
			}

			$existing = $this->findPluginFile( $slug );
		}

		if ( null === $existing ) {
			return $this->failure( __( 'The plugin was installed, but its main file could not be found.', 'dosieci-ai-operator' ) );
		}

		$activated = false;
		if ( ! empty( $args['activate'] ) ) {
			$activation = activate_plugin( $existing );

			if ( is_wp_error( $activation ) ) {
				return array(
					'installed'        => true,
					'plugin_file'      => $existing,
					'activated'        => false,
					'activation_error' => $activation->get_error_message(),
				);
			}

			$activated = true;
		}

		return array(
			'installed'   => true,
			'slug'        => $slug,
			'plugin_file' => $existing,
			'activated'   => $activated,
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function activatePlugin( array $args ): array {
		$this->requireUpgraderFiles();

		$slug = $this->slug( $args['slug'] ?? '' );
		$file = $this->findPluginFile( $slug );

		if ( null === $file ) {
			return $this->failure( sprintf( /* translators: %s: plugin slug */ __( 'Plugin "%s" is not installed.', 'dosieci-ai-operator' ), $slug ) );
		}

		if ( is_plugin_active( $file ) ) {
			return array(
				'activated'      => true,
				'already_active' => true,
				'plugin_file'    => $file,
			);
		}

		$result = activate_plugin( $file );

		if ( is_wp_error( $result ) ) {
			return $this->failure( $result->get_error_message() );
		}

		return array(
			'activated'   => true,
			'plugin_file' => $file,
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function deactivatePlugin( array $args ): array {
		$this->requireUpgraderFiles();

		$slug = $this->slug( $args['slug'] ?? '' );
		$file = $this->findPluginFile( $slug );

		if ( null === $file ) {
			return $this->failure( sprintf( /* translators: %s: plugin slug */ __( 'Plugin "%s" is not installed.', 'dosieci-ai-operator' ), $slug ) );
		}

		// Refusing to deactivate ourselves is not vanity: doing so would
		// kill the request mid-flight and leave the chat with no way to
		// report what happened.
		if ( str_contains( $file, 'dosieci-ai-operator' ) ) {
			return $this->failure( __( 'AI Operator cannot deactivate itself.', 'dosieci-ai-operator' ) );
		}

		deactivate_plugins( $file );

		return array(
			'deactivated' => true,
			'plugin_file' => $file,
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function installTheme( array $args ): array {
		$slug = $this->slug( $args['slug'] ?? '' );

		if ( '' === $slug ) {
			return $this->failure( __( 'Invalid theme slug.', 'dosieci-ai-operator' ) );
		}

		$this->requireUpgraderFiles();

		if ( ! wp_get_theme( $slug )->exists() ) {
			$api = themes_api(
				'theme_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			);

			if ( is_wp_error( $api ) ) {
				return $this->failure( sprintf( /* translators: 1: theme slug, 2: error message from WordPress.org */ __( 'Theme "%1\$s" was not found in the WordPress.org directory: %2\$s', 'dosieci-ai-operator' ), $slug, $api->get_error_message() ) );
			}

			$upgrader = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
			$result   = $upgrader->install( $api->download_link );

			if ( is_wp_error( $result ) ) {
				return $this->failure( $result->get_error_message() );
			}

			if ( true !== $result ) {
				return $this->failure( __( 'The theme installation failed.', 'dosieci-ai-operator' ) );
			}
		}

		$theme = wp_get_theme( $slug );

		if ( ! $theme->exists() ) {
			return $this->failure( __( 'The theme was installed, but could not be found.', 'dosieci-ai-operator' ) );
		}

		$activated = false;
		if ( ! empty( $args['activate'] ) ) {
			switch_theme( $slug );
			$activated = true;
		}

		return array(
			'installed' => true,
			'slug'      => $slug,
			'name'      => $theme->get( 'Name' ),
			'version'   => $theme->get( 'Version' ),
			'activated' => $activated,
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function activateTheme( array $args ): array {
		$slug  = $this->slug( $args['slug'] ?? '' );
		$theme = wp_get_theme( $slug );

		if ( ! $theme->exists() ) {
			return $this->failure( sprintf( 'Motyw "%s" nie jest zainstalowany.', $slug ) );
		}

		switch_theme( $slug );

		return array(
			'activated' => true,
			'slug'      => $slug,
			'name'      => $theme->get( 'Name' ),
		);
	}

	// ---------------------------------------------------------------
	// Structure
	// ---------------------------------------------------------------

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function createMenu( array $args ): array {
		$name = sanitize_text_field( (string) $args['name'] );

		$existing = wp_get_nav_menu_object( $name );
		$menuId   = $existing ? (int) $existing->term_id : (int) wp_create_nav_menu( $name );

		if ( is_wp_error( $menuId ) || 0 === $menuId ) {
			return $this->failure( __( 'Could not create the menu.', 'dosieci-ai-operator' ) );
		}

		// What the menu already links, so a second call updates those entries
		// instead of appending a duplicate set. Passing 0 as the item id
		// ALWAYS creates, which is how re-running a build turned a four-item
		// menu into eight and then twelve. Found on a real re-run.
		//
		// Items nobody named are left alone rather than pruned: a user may
		// have added their own entries, and "make the menu match this list"
		// would silently delete them.
		$existingItems = $this->menuItemIndex( $menuId );

		$added = array();
		foreach ( (array) ( $args['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$pageId = isset( $item['page_id'] ) ? (int) $item['page_id'] : 0;
			$title  = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';

			if ( $pageId > 0 ) {
				$page = get_post( $pageId );
				if ( ! $page instanceof \WP_Post ) {
					continue;
				}

				$itemId = wp_update_nav_menu_item(
					$menuId,
					(int) ( $existingItems[ 'post:' . $pageId ] ?? 0 ),
					array(
						'menu-item-title'     => '' !== $title ? $title : $page->post_title,
						'menu-item-object-id' => $pageId,
						'menu-item-object'    => $page->post_type,
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
					)
				);
			} else {
				$url = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';

				if ( '' === $url || '' === $title ) {
					continue;
				}

				$itemId = wp_update_nav_menu_item(
					$menuId,
					(int) ( $existingItems[ 'url:' . $url ] ?? 0 ),
					array(
						'menu-item-title'  => $title,
						'menu-item-url'    => $url,
						'menu-item-type'   => 'custom',
						'menu-item-status' => 'publish',
					)
				);
			}

			if ( ! is_wp_error( $itemId ) ) {
				// Structured rather than a bare title: the Site Builder
				// verifier reads this back to confirm the menu actually
				// links the pages the plan named. A list of strings cannot
				// prove that, which is how a menu with the right number of
				// wrong links would pass.
				$added[] = array(
					'menu_item_id' => (int) $itemId,
					'title'        => '' !== $title ? $title : (string) $itemId,
					'page_id'      => $pageId > 0 ? $pageId : null,
				);
			}
		}

		$assignedLocation = null;
		$location         = isset( $args['location'] ) ? (string) $args['location'] : '';

		if ( '' !== $location ) {
			$registered = get_registered_nav_menus();

			if ( ! array_key_exists( $location, $registered ) ) {
				return array(
					'created'             => true,
					'menu_id'             => $menuId,
					'items_added'         => $added,
					'assigned_location'   => null,
					'location_error'      => sprintf( /* translators: %s: theme navigation menu location */ __( 'The theme has no “%s” location.', 'dosieci-ai-operator' ), $location ),
					'available_locations' => array_keys( $registered ),
				);
			}

			$locations              = get_theme_mod( 'nav_menu_locations', array() );
			$locations              = is_array( $locations ) ? $locations : array();
			$locations[ $location ] = $menuId;
			set_theme_mod( 'nav_menu_locations', $locations );
			$assignedLocation = $location;
		}

		return array(
			'created'           => true,
			'menu_id'           => $menuId,
			'name'              => $name,
			'items_added'       => $added,
			'assigned_location' => $assignedLocation,
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function createTerm( array $args ): array {
		$taxonomy = isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : 'category';

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->failure( sprintf( /* translators: %s: taxonomy name */ __( 'The taxonomy "%s" does not exist.', 'dosieci-ai-operator' ), $taxonomy ) );
		}

		$result = wp_insert_term(
			sanitize_text_field( (string) $args['name'] ),
			$taxonomy,
			array( 'description' => isset( $args['description'] ) ? sanitize_text_field( (string) $args['description'] ) : '' )
		);

		if ( is_wp_error( $result ) ) {
			return $this->failure( $result->get_error_message() );
		}

		return array(
			'created'  => true,
			'term_id'  => (int) $result['term_id'],
			'taxonomy' => $taxonomy,
		);
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * @param array<string, mixed> $args
	 */
	private function status( array $args ): string {
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'draft';

		// Defaulting to draft rather than publish is deliberate: an
		// unrequested publish puts content in front of real visitors, which
		// is not undoable in the way an unpublished draft is.
		return in_array( $status, array( 'draft', 'publish', 'private' ), true ) ? $status : 'draft';
	}

	private function slug( mixed $raw ): string {
		$slug = sanitize_key( (string) $raw );

		// sanitize_key already strips path separators; this is a second,
		// explicit refusal of anything that could escape the slug namespace.
		return preg_match( '/^[a-z0-9\-_]{1,64}$/', $slug ) ? $slug : '';
	}

	private function requireUpgraderFiles(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		}
		if ( ! class_exists( '\Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	}

	private function findPluginFile( string $slug ): ?string {
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

	/**
	 * Existing menu entries keyed by what they point at, so a repeat call
	 * updates the entry for a page rather than adding a second one.
	 *
	 * @return array<string, int>
	 */
	private function menuItemIndex( int $menuId ): array {
		$items = wp_get_nav_menu_items( $menuId );

		if ( ! is_array( $items ) ) {
			return array();
		}

		$index = array();

		foreach ( $items as $item ) {
			if ( 'post_type' === $item->type && (int) $item->object_id > 0 ) {
				// First one wins: if a menu somehow already holds two entries
				// for the same page, we update one and leave the other rather
				// than deleting something we did not create.
				$index['post:' . (int) $item->object_id] ??= (int) $item->ID;

				continue;
			}

			if ( 'custom' === $item->type && '' !== (string) $item->url ) {
				$index[ 'url:' . (string) $item->url ] ??= (int) $item->ID;
			}
		}

		return $index;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function failure( string $message ): array {
		// Returned rather than thrown: ToolDispatcher turns a throw into an
		// audited "failed" outcome, but a predictable, explainable refusal
		// is more useful to the model as data it can act on.
		return array(
			'success' => false,
			'error'   => $message,
		);
	}
}

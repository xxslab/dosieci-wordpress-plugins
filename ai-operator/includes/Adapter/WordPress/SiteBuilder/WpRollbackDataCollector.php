<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\RollbackDataCollectorInterface;

/**
 * Reads the WordPress state a step is about to overwrite, so it can be put
 * back later.
 *
 * Runs BEFORE the write, which is the only time the answer is still
 * available: an update_post that has already replaced the title cannot
 * report the old one. Everything captured here comes from WordPress's own
 * APIs, never from the action's arguments -- the arguments describe the
 * desired new state, not the current one.
 *
 * A capture failure must never block the write. Not knowing how to undo
 * something is a reason to mark it non-rollbackable, not a reason to refuse
 * work the human already approved -- so every branch degrades to null
 * rather than throwing.
 */
final class WpRollbackDataCollector implements RollbackDataCollectorInterface {

	public function capture( PlanAction $action, array $resolvedArguments = array() ): ?array {
		$arguments = array() !== $resolvedArguments ? $resolvedArguments : $action->arguments;
		switch ( $action->rollbackStrategy ) {
			case PlanAction::ROLLBACK_RESTORE_POST:
				return $this->postSnapshot( $arguments );

			case PlanAction::ROLLBACK_RESTORE_OPTION:
				return $this->optionSnapshot( $arguments );

			case PlanAction::ROLLBACK_RESTORE_HOMEPAGE:
				return array(
					'show_on_front'  => (string) get_option( 'show_on_front', 'posts' ),
					'page_on_front'  => (int) get_option( 'page_on_front', 0 ),
					'page_for_posts' => (int) get_option( 'page_for_posts', 0 ),
				);

			case PlanAction::ROLLBACK_RESTORE_THEME:
				$current = wp_get_theme();

				return array( 'previous_theme' => $current->get_stylesheet() );

			case PlanAction::ROLLBACK_RESTORE_PLUGIN_STATE:
			case PlanAction::ROLLBACK_DEACTIVATE_PLUGIN:
				return $this->pluginSnapshot( $arguments );

			case PlanAction::ROLLBACK_DELETE_MENU:
				$name = isset( $arguments['name'] ) ? (string) $arguments['name'] : '';
				$existing = '' !== $name ? wp_get_nav_menu_object( $name ) : false;

				return array(
					// Whether the menu already existed decides whether
					// rollback may delete it: undoing our own creation is
					// fine, deleting a menu that predated the plan is not.
					'menu_existed_before' => (bool) $existing,
					'menu_name'           => $name,
					'previous_locations'  => (array) get_theme_mod( 'nav_menu_locations', array() ),
				);

			case PlanAction::ROLLBACK_RESTORE_NAVIGATION:
				// Snapshot every navigation post that exists now, so an undo
				// can restore the previous content of one we overwrite and
				// remove only one we created. A user's other navigation
				// entries are recorded but never touched.
				$existing = get_posts(
					array( 'post_type' => 'wp_navigation', 'post_status' => 'any', 'posts_per_page' => 20 )
				);

				$snapshot = array();
				foreach ( $existing as $nav ) {
					$snapshot[ (string) $nav->ID ] = $nav->post_content;
				}

				return array( 'navigations' => $snapshot );

			case PlanAction::ROLLBACK_RESTORE_FEATURED_IMAGE:
				$pageId = isset( $arguments['page_id'] ) ? (int) $arguments['page_id'] : 0;

				return array(
					'page_id' => $pageId,
					// 0 means "the page had no featured image", which is a
					// state rollback must be able to restore -- leaving our
					// image behind would be a change the user never approved.
					'previous_attachment_id' => $pageId > 0 ? (int) get_post_thumbnail_id( $pageId ) : 0,
				);

			case PlanAction::ROLLBACK_RESTORE_STORE_PAGES:
				// Only the four assignments, so an undo can put Woo's own
				// pointers back. The pages themselves are never destroyed:
				// they may hold shortcodes or content a merchant added.
				$pages = array();
				foreach ( array_keys( \DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooCommerceAdapter::CORE_PAGES ) as $slug ) {
					$pages[ $slug ] = (int) get_option( 'woocommerce_' . $slug . '_page_id', 0 );
				}

				return array( 'store_pages' => $pages );

			case PlanAction::ROLLBACK_RESTORE_STORE_SETTINGS:
				// Exactly the four fields this builder can write. Nothing
				// else about the shop is captured -- a snapshot is a copy of
				// merchant data, and the smallest one that works is right.
				return array( 'store_settings' => ( new \DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooCommerceAdapter() )->storeSettings() );

			case PlanAction::ROLLBACK_RESTORE_PRODUCT_CATEGORY:
				$termId = isset( $arguments['term_id'] ) ? (int) $arguments['term_id'] : 0;
				$term   = $termId > 0 ? get_term( $termId, 'product_cat' ) : null;

				return $term instanceof \WP_Term
					? array( 'term_id' => $termId, 'name' => $term->name, 'description' => $term->description )
					: null;

			case PlanAction::ROLLBACK_RESTORE_PRODUCT:
				$productId = isset( $arguments['product_id'] ) ? (int) $arguments['product_id'] : 0;
				$product   = $productId > 0
					? ( new \DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooCommerceAdapter() )->readProduct( $productId )
					: null;

				// Only the fields the builder itself writes. Stock, tax class
				// and everything else the merchant configured stays out of
				// our snapshot and out of our undo.
				return null === $product ? null : array( 'product' => $product );

			case PlanAction::ROLLBACK_DELETE_PRODUCT_CATEGORY:
			case PlanAction::ROLLBACK_TRASH_PRODUCT:
			case PlanAction::ROLLBACK_TRASH_POST:
			case PlanAction::ROLLBACK_DELETE_TERM:
				// Nothing existed beforehand; the created id is recorded from
				// the step's own result at rollback time instead.
				return array( 'created_by_plan' => true );

			case PlanAction::ROLLBACK_NONE:
			default:
				return null;
		}
	}

	/** @param array<string, mixed> $arguments @return array<string, mixed>|null */
	private function postSnapshot( array $arguments ): ?array {
		$postId = isset( $arguments['post_id'] ) ? (int) $arguments['post_id'] : 0;

		// embed_contact_form edits a page whose id came from a resolver, so
		// fall back to page_id when post_id is absent.
		if ( $postId <= 0 && isset( $arguments['page_id'] ) ) {
			$postId = (int) $arguments['page_id'];
		}

		if ( $postId <= 0 ) {
			return null;
		}

		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return array(
			'post_id'      => $postId,
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => $post->post_status,
			'post_parent'  => (int) $post->post_parent,
		);
	}

	/** @return array<string, mixed>|null */
	private function optionSnapshot( array $arguments ): ?array {
		$option = isset( $arguments['option'] ) ? (string) $arguments['option'] : '';

		if ( '' === $option ) {
			return null;
		}

		$previous = get_option( $option );

		return array(
			'option'   => $option,
			// Only scalars round-trip predictably through JSON storage; an
			// array/object option is recorded as absent rather than as
			// something that would restore wrongly.
			'previous' => is_scalar( $previous ) ? $previous : null,
			'existed'  => false !== $previous,
		);
	}

	/** @return array<string, mixed>|null */
	private function pluginSnapshot( array $arguments ): ?array {
		$slug = isset( $arguments['slug'] ) ? (string) $arguments['slug'] : '';

		if ( '' === $slug ) {
			return null;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$file = null;
		foreach ( array_keys( get_plugins() ) as $candidate ) {
			if ( dirname( (string) $candidate ) === $slug ) {
				$file = (string) $candidate;
				break;
			}
		}

		return array(
			'slug'                    => $slug,
			'plugin_file'             => $file,
			'was_installed_before'    => null !== $file,
			'was_active_before'       => null !== $file && is_plugin_active( $file ),
		);
	}
}

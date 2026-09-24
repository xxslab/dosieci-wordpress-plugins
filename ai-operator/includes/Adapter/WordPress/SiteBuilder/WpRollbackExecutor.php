<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\SiteBuilder;

use DoSieci\AiOperator\Adapter\WordPress\Tools\OptionAllowlist;
use DoSieci\AiOperator\Domain\SiteBuilder\ActionState;
use DoSieci\AiOperator\Domain\SiteBuilder\PlanAction;
use DoSieci\AiOperator\Domain\SiteBuilder\RollbackExecutorInterface;
use DoSieci\AiOperator\Domain\Tools\CapabilityCheckerInterface;

/**
 * Performs the real WordPress undo for one step.
 *
 * ## Rollback is a write and is gated like one
 *
 * Every branch re-checks the capability the ORIGINAL action required
 * before touching anything. An administrator who has since been demoted
 * must not be able to trigger writes through the undo path just because
 * they were allowed to make them yesterday.
 *
 * Every value used comes from the snapshot this plugin captured before the
 * write, or from the step's own recorded result. Nothing is read from the
 * current request, which is what stops "roll back plan X" from becoming a
 * general-purpose "set any option to any value" primitive.
 *
 * ## What is deliberately NOT undone
 *
 * install_plugin / install_theme roll back to deactivated, never deleted.
 * Removing third-party code from disk unattended is a much larger blast
 * radius than leaving it inert, and a delete racing with another request
 * can leave WordPress pointing at files that vanished. Same discipline as
 * 1.1's refusal to offer permanent post deletion at all.
 */
final class WpRollbackExecutor implements RollbackExecutorInterface {

	public function __construct( private CapabilityCheckerInterface $capabilities ) {
	}

	public function undo( PlanAction $action, ActionState $state, int $userId ): bool {
		// The undo needs the same authority the original write needed.
		if ( ! $this->capabilities->currentUserCan( $action->requiredCapabilityForRollback() ) ) {
			return false;
		}

		$snapshot = $state->rollbackData ?? array();
		$result   = $state->result ?? array();

		switch ( $action->rollbackStrategy ) {
			case PlanAction::ROLLBACK_TRASH_POST:
				return $this->trashCreatedPost( $result );

			case PlanAction::ROLLBACK_RESTORE_POST:
				return $this->restorePost( $snapshot );

			case PlanAction::ROLLBACK_RESTORE_OPTION:
				return $this->restoreOption( $snapshot );

			case PlanAction::ROLLBACK_RESTORE_HOMEPAGE:
				return $this->restoreHomepage( $snapshot );

			case PlanAction::ROLLBACK_RESTORE_THEME:
				return $this->restoreTheme( $snapshot );

			case PlanAction::ROLLBACK_DEACTIVATE_PLUGIN:
			case PlanAction::ROLLBACK_RESTORE_PLUGIN_STATE:
				return $this->restorePluginState( $snapshot, $result );

			case PlanAction::ROLLBACK_DELETE_MENU:
				return $this->deleteMenu( $snapshot, $result );

			case PlanAction::ROLLBACK_DELETE_TERM:
				return $this->deleteTerm( $result );

			case PlanAction::ROLLBACK_TRASH_CONTACT_FORM:
				return $this->trashGeneratedForm( $result );

			case PlanAction::ROLLBACK_RESTORE_NAVIGATION:
				return $this->restoreNavigation( $snapshot, $result );

			case PlanAction::ROLLBACK_RESTORE_FEATURED_IMAGE:
				return $this->restoreFeaturedImage( $snapshot );

			case PlanAction::ROLLBACK_RESTORE_STORE_PAGES:
				return $this->restoreStorePages( $snapshot );

			case PlanAction::ROLLBACK_RESTORE_STORE_SETTINGS:
				return $this->restoreStoreSettings( $snapshot );

			case PlanAction::ROLLBACK_DELETE_PRODUCT_CATEGORY:
				return $this->deleteCreatedCategory( $result );

			case PlanAction::ROLLBACK_RESTORE_PRODUCT_CATEGORY:
				return $this->restoreCategory( $snapshot );

			case PlanAction::ROLLBACK_TRASH_PRODUCT:
				return $this->trashCreatedProduct( $result );

			case PlanAction::ROLLBACK_RESTORE_PRODUCT:
				return $this->restoreProduct( $snapshot );

			case PlanAction::ROLLBACK_NONE:
			default:
				return false;
		}
	}

	/** @param array<string, mixed> $result */
	private function trashCreatedPost( array $result ): bool {
		$postId = isset( $result['post_id'] ) ? (int) $result['post_id'] : 0;

		if ( $postId <= 0 || ! get_post( $postId ) instanceof \WP_Post ) {
			return false;
		}

		// Trash, never force-delete: the same recoverable discipline 1.1's
		// trash_post uses. An undo the user regrets must itself be undoable.
		return false !== wp_trash_post( $postId );
	}

	/** @param array<string, mixed> $snapshot */
	private function restorePost( array $snapshot ): bool {
		$postId = isset( $snapshot['post_id'] ) ? (int) $snapshot['post_id'] : 0;

		if ( $postId <= 0 || ! get_post( $postId ) instanceof \WP_Post ) {
			return false;
		}

		$restored = wp_update_post(
			array(
				'ID'           => $postId,
				'post_title'   => (string) ( $snapshot['post_title'] ?? '' ),
				'post_content' => (string) ( $snapshot['post_content'] ?? '' ),
				'post_excerpt' => (string) ( $snapshot['post_excerpt'] ?? '' ),
				'post_status'  => (string) ( $snapshot['post_status'] ?? 'draft' ),
				'post_parent'  => (int) ( $snapshot['post_parent'] ?? 0 ),
			),
			true
		);

		return ! is_wp_error( $restored );
	}

	/** @param array<string, mixed> $snapshot */
	private function restoreOption( array $snapshot ): bool {
		$option = isset( $snapshot['option'] ) ? (string) $snapshot['option'] : '';

		// Re-checked against the SAME allowlist the forward write used. A
		// rollback record naming an option outside it could only come from
		// tampering, and must not be honoured.
		if ( '' === $option || ! OptionAllowlist::has( $option ) ) {
			return false;
		}

		if ( true !== ( $snapshot['existed'] ?? false ) ) {
			delete_option( $option );

			return true;
		}

		$previous = $snapshot['previous'] ?? null;

		if ( ! is_scalar( $previous ) ) {
			return false;
		}

		update_option( $option, $previous );

		if ( 'permalink_structure' === $option ) {
			flush_rewrite_rules( false );
		}

		return true;
	}

	/** @param array<string, mixed> $snapshot */
	private function restoreHomepage( array $snapshot ): bool {
		if ( ! isset( $snapshot['show_on_front'] ) ) {
			return false;
		}

		update_option( 'show_on_front', (string) $snapshot['show_on_front'] );
		update_option( 'page_on_front', (int) ( $snapshot['page_on_front'] ?? 0 ) );
		update_option( 'page_for_posts', (int) ( $snapshot['page_for_posts'] ?? 0 ) );

		return true;
	}

	/** @param array<string, mixed> $snapshot */
	private function restoreTheme( array $snapshot ): bool {
		$previous = isset( $snapshot['previous_theme'] ) ? (string) $snapshot['previous_theme'] : '';

		if ( '' === $previous || ! wp_get_theme( $previous )->exists() ) {
			return false;
		}

		if ( wp_get_theme()->get_stylesheet() === $previous ) {
			return true; // Already where we wanted to be.
		}

		switch_theme( $previous );

		return true;
	}

	/**
	 * @param array<string, mixed> $snapshot
	 * @param array<string, mixed> $result
	 */
	private function restorePluginState( array $snapshot, array $result ): bool {
		$file = $snapshot['plugin_file'] ?? ( $result['plugin_file'] ?? null );

		if ( ! is_string( $file ) || '' === $file ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Never deactivate ourselves mid-rollback -- that kills the request
		// before it can report what happened. Same guard as 1.1's
		// deactivate_plugin handler.
		if ( str_contains( $file, 'dosieci-ai-operator' ) ) {
			return false;
		}

		if ( true === ( $snapshot['was_active_before'] ?? false ) ) {
			if ( ! is_plugin_active( $file ) ) {
				$activated = activate_plugin( $file );

				return ! is_wp_error( $activated );
			}

			return true;
		}

		// Was not active before: deactivate, do NOT delete the files.
		if ( is_plugin_active( $file ) ) {
			deactivate_plugins( $file );
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $snapshot
	 * @param array<string, mixed> $result
	 */
	private function deleteMenu( array $snapshot, array $result ): bool {
		// Only remove a menu this plan created. A menu that existed before
		// belongs to the user, not to us.
		if ( true === ( $snapshot['menu_existed_before'] ?? false ) ) {
			$this->restoreMenuLocations( $snapshot );

			return true;
		}

		$menuId = isset( $result['menu_id'] ) ? (int) $result['menu_id'] : 0;

		if ( $menuId <= 0 ) {
			return false;
		}

		wp_delete_nav_menu( $menuId );
		$this->restoreMenuLocations( $snapshot );

		return true;
	}

	/** @param array<string, mixed> $snapshot */
	private function restoreMenuLocations( array $snapshot ): void {
		if ( isset( $snapshot['previous_locations'] ) && is_array( $snapshot['previous_locations'] ) ) {
			set_theme_mod( 'nav_menu_locations', $snapshot['previous_locations'] );
		}
	}

	/**
	 * Restores navigation to its pre-run state.
	 *
	 * A navigation post that existed before gets its recorded content back.
	 * One this run created is trashed, never force-deleted -- and any OTHER
	 * navigation the user has is left completely alone.
	 *
	 * @param array<string, mixed> $snapshot
	 * @param array<string, mixed> $result
	 */
	private function restoreNavigation( array $snapshot, array $result ): bool {
		$navId = (int) ( $result['navigation_id'] ?? 0 );

		if ( $navId <= 0 || 'wp_navigation' !== get_post_type( $navId ) ) {
			return false;
		}

		$before = is_array( $snapshot['navigations'] ?? null ) ? $snapshot['navigations'] : array();

		if ( array_key_exists( (string) $navId, $before ) ) {
			$restored = wp_update_post(
				array( 'ID' => $navId, 'post_content' => (string) $before[ (string) $navId ] ),
				true
			);

			return ! is_wp_error( $restored );
		}

		// Did not exist before this run, so it is ours to remove.
		return false !== wp_trash_post( $navId );
	}

	/**
	 * Puts the page's featured image back the way it was.
	 *
	 * The attachment itself is never touched. The builder did not create it
	 * -- it is an image the user uploaded -- so deleting it during an undo
	 * would destroy something that predates the plan entirely.
	 *
	 * @param array<string, mixed> $snapshot
	 */
	private function restoreFeaturedImage( array $snapshot ): bool {
		$pageId   = isset( $snapshot['page_id'] ) ? (int) $snapshot['page_id'] : 0;
		$previous = isset( $snapshot['previous_attachment_id'] ) ? (int) $snapshot['previous_attachment_id'] : 0;

		if ( $pageId <= 0 || ! get_post( $pageId ) instanceof \WP_Post ) {
			return false;
		}

		// No image before the plan ran means the undo removes ours, rather
		// than leaving behind a change nobody approved.
		if ( $previous <= 0 ) {
			delete_post_thumbnail( $pageId );

			return true;
		}

		return false !== set_post_thumbnail( $pageId, $previous );
	}

	/**
	 * Puts WooCommerce's own page pointers back.
	 *
	 * Assignments only. The pages are never deleted: a merchant may have
	 * added content or shortcodes to a Shop page, and undoing OUR change
	 * to a pointer is not licence to destroy the thing it points at.
	 *
	 * @param array<string, mixed> $snapshot
	 */
	private function restoreStorePages( array $snapshot ): bool {
		$pages = is_array( $snapshot['store_pages'] ?? null ) ? $snapshot['store_pages'] : array();

		if ( array() === $pages ) {
			return false;
		}

		foreach ( $pages as $slug => $id ) {
			$option = 'woocommerce_' . (string) $slug . '_page_id';

			// A zero means the option was absent before this plan ran, so the
			// undo removes it rather than writing a 0 Woo would have to
			// interpret.
			if ( (int) $id > 0 ) {
				update_option( $option, (int) $id );
			} else {
				delete_option( $option );
			}
		}

		return true;
	}

	/** @param array<string, mixed> $snapshot */
	private function restoreStoreSettings( array $snapshot ): bool {
		$settings = is_array( $snapshot['store_settings'] ?? null ) ? $snapshot['store_settings'] : array();

		if ( array() === $settings ) {
			return false;
		}

		$options = \DoSieci\AiOperator\Adapter\WordPress\SiteBuilder\Commerce\WooCommerceAdapter::SETTING_OPTIONS;

		foreach ( $settings as $field => $value ) {
			// Restoring goes through the same closed allowlist the forward
			// write used. Otherwise "roll back plan X" would be a way to set
			// an arbitrary option to an arbitrary value.
			if ( ! isset( $options[ (string) $field ] ) ) {
				continue;
			}

			update_option( $options[ (string) $field ], (string) $value );
		}

		return true;
	}

	/**
	 * Deletes a category ONLY when doing so is demonstrably safe.
	 *
	 * Three ways this refuses: the term is gone already, something else has
	 * been filed under it since, or a merchant has edited it (in which case
	 * the plan would have reported a conflict and there is nothing of ours
	 * to remove). A partial rollback that says why beats a green badge
	 * bought with somebody's product data.
	 *
	 * @param array<string, mixed> $result
	 */
	private function deleteCreatedCategory( array $result ): bool {
		if ( true !== ( $result['created'] ?? false ) ) {
			return true; // Reused or updated, nothing of ours to remove.
		}

		$termId = (int) ( $result['term_id'] ?? 0 );

		if ( $termId <= 0 ) {
			return false;
		}

		$term = get_term( $termId, 'product_cat' );

		if ( ! $term instanceof \WP_Term ) {
			return true; // Already gone.
		}

		if ( (int) $term->count > 0 ) {
			// Products are filed here now -- possibly the merchant's own.
			// Deleting the term would silently uncategorise them.
			return false;
		}

		return true === wp_delete_term( $termId, 'product_cat' );
	}

	/** @param array<string, mixed> $snapshot */
	private function restoreCategory( array $snapshot ): bool {
		$termId = (int) ( $snapshot['term_id'] ?? 0 );

		if ( $termId <= 0 || ! get_term( $termId, 'product_cat' ) instanceof \WP_Term ) {
			return false;
		}

		$updated = wp_update_term(
			$termId,
			'product_cat',
			array(
				'name'        => (string) ( $snapshot['name'] ?? '' ),
				'description' => (string) ( $snapshot['description'] ?? '' ),
			)
		);

		return ! is_wp_error( $updated );
	}

	/**
	 * Trashes a product this plan created. Never a permanent delete, and
	 * never a product that existed beforehand -- a merchant's catalogue is
	 * not ours to remove.
	 *
	 * @param array<string, mixed> $result
	 */
	private function trashCreatedProduct( array $result ): bool {
		if ( true !== ( $result['created'] ?? false ) ) {
			return true;
		}

		$productId = (int) ( $result['product_id'] ?? 0 );

		if ( $productId <= 0 || 'product' !== get_post_type( $productId ) ) {
			return false;
		}

		return false !== wp_trash_post( $productId );
	}

	/**
	 * Restores the fields the builder itself wrote, and only those.
	 *
	 * Stock, tax class, attributes and everything else a merchant
	 * configured were never in the snapshot and are not touched here.
	 *
	 * @param array<string, mixed> $snapshot
	 */
	private function restoreProduct( array $snapshot ): bool {
		$product = is_array( $snapshot['product'] ?? null ) ? $snapshot['product'] : array();
		$id      = (int) ( $product['product_id'] ?? 0 );

		if ( $id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$live = wc_get_product( $id );

		if ( ! $live instanceof \WC_Product ) {
			return false;
		}

		$live->set_name( (string) ( $product['name'] ?? '' ) );
		$live->set_description( (string) ( $product['description'] ?? '' ) );
		$live->set_short_description( (string) ( $product['short'] ?? '' ) );
		$live->set_regular_price( (string) ( $product['price'] ?? '' ) );
		$live->set_category_ids( array_map( 'intval', (array) ( $product['categories'] ?? array() ) ) );
		$live->set_image_id( (int) ( $product['image_id'] ?? 0 ) );

		// The status is restored from the snapshot rather than forced to
		// draft: if a merchant had published this product before our update,
		// the undo must not unpublish their shop item.
		$live->set_status( (string) ( $product['status'] ?? 'draft' ) );

		return $live->save() > 0;
	}

	/**
	 * Trashes a contact form ONLY if this plan created it. A form that
	 * already existed and was reused belongs to the user -- undoing our
	 * reuse must not delete their form.
	 *
	 * @param array<string, mixed> $result
	 */
	private function trashGeneratedForm( array $result ): bool {
		if ( true !== ( $result['created'] ?? false ) ) {
			return true; // Reused, nothing of ours to undo.
		}

		$formId = (int) ( $result['form_id'] ?? 0 );

		if ( $formId <= 0 || 'wpcf7_contact_form' !== get_post_type( $formId ) ) {
			return false;
		}

		return false !== wp_trash_post( $formId );
	}

	/** @param array<string, mixed> $result */
	private function deleteTerm( array $result ): bool {
		$termId   = isset( $result['term_id'] ) ? (int) $result['term_id'] : 0;
		$taxonomy = isset( $result['taxonomy'] ) ? (string) $result['taxonomy'] : '';

		if ( $termId <= 0 || '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		// Refuse to remove a term that has since been used -- deleting it
		// would silently unfile somebody's content.
		$term = get_term( $termId, $taxonomy );

		if ( ! $term instanceof \WP_Term || $term->count > 0 ) {
			return false;
		}

		$deleted = wp_delete_term( $termId, $taxonomy );

		return true === $deleted;
	}
}

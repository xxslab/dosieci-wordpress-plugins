<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

use DoSieci\AiOperator\Domain\SiteBuilder\Gutenberg\BlockComposer;
use DoSieci\AiOperator\Domain\SiteBuilder\Gutenberg\PageContentFactory;
use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\CommerceAdapterInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\Commerce\ManagedCommerceRole;
use DoSieci\AiOperator\Domain\SiteBuilder\Media\MediaLibraryInterface;
use DoSieci\AiOperator\Domain\SiteBuilder\Media\MediaMatcher;
use DoSieci\AiOperator\Domain\Tools\ToolDefinition;

/**
 * Turns an approved blueprint into a concrete, ordered ActionPlan.
 *
 * ## Why the plan is generated in code, not by the model
 *
 * The model decides WHAT the site should be (that is the blueprint, and it
 * is genuinely a language problem). It does not decide the mechanics of
 * HOW: which tool installs a theme, that activation must follow
 * installation, that the menu can only be built once its pages exist, that
 * the homepage must be published before it can be assigned. Those are
 * invariants, and a planner that re-derives them from a prompt on every run
 * will eventually get the order wrong on a Tuesday.
 *
 * Generating the plan here also means every step is guaranteed to name a
 * real registered tool with schema-valid arguments before the human is ever
 * shown it -- so an approved plan cannot contain a step that was never
 * dispatchable in the first place.
 *
 * Dependencies are declared explicitly (`dependsOn`) rather than relying on
 * ordering alone, so PlanRecord::nextRunnableAction() refuses to add a page
 * to a menu whose page-creation step did not actually succeed.
 */
final class BlueprintPlanner {

	public function __construct(
		private PageContentFactory $content = new PageContentFactory( new BlockComposer() ),
		/**
		 * Consulted at PLAN time so "reuse", "update" and "conflict" are
		 * visible in what the human approves. Resolving at execution time
		 * would mean approving "create a page" and silently doing something
		 * else. Null (no resolver) means every page is planned as a fresh
		 * create -- the pre-reconciliation behaviour.
		 */
		private ?ManagedResourceResolverInterface $resources = null,
		/**
		 * Optional. Null means no illustration steps are planned at all --
		 * a site without pictures, not a site with wrong ones.
		 */
		private ?MediaLibraryInterface $media = null,
		private MediaMatcher $mediaMatcher = new MediaMatcher(),
		/**
		 * Null means commerce is simply unavailable, and a store blueprint
		 * plans no shop steps rather than failing. AI Operator must work
		 * normally on the majority of sites that never install WooCommerce.
		 */
		private ?CommerceAdapterInterface $commerce = null
	) {
	}

	/**
	 * @param array<string, mixed> $context available_theme etc., supplied by
	 *                                       the caller from real site state
	 *
	 * @throws PlanValidationException
	 */
	public function plan(
		SiteBlueprint $blueprint,
		string $planId,
		string $conversationId,
		int $ownerUserId,
		int $now,
		array $context = array(),
		string $projectId = 'default'
	): ActionPlan {
		$actions  = array();
		$sequence = 1;
		$conflicts = array();

		$themeSlug = isset( $context['theme_slug'] ) ? (string) $context['theme_slug'] : 'twentytwentyfour';

		// --- appearance -------------------------------------------------
		$installTheme = $this->action(
			$sequence++,
			'install_theme',
			array( 'slug' => $themeSlug, 'activate' => true ),
			ToolDefinition::RISK_REVERSIBLE_WRITE,
			sprintf(
				/* translators: %s: theme slug */
				__( 'Install and activate the theme “%s”.', 'dosieci-ai-operator' ),
				$themeSlug
			),
			array(),
			__( 'The theme is installed and active.', 'dosieci-ai-operator' ),
			array( 'tool' => 'inspect_theme', 'arguments' => array() ),
			PlanAction::ROLLBACK_RESTORE_THEME
		);
		$actions[] = $installTheme;

		// --- contact form plugin, only if the blueprint asked for one ----
		$formActionId = null;
		if ( $blueprint->hasFeature( 'contact_form' ) ) {
			$installPlugin = $this->action(
				$sequence++,
				'install_plugin',
				array( 'slug' => 'contact-form-7', 'activate' => true ),
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				__( 'Install and activate the contact form plugin (Contact Form 7).', 'dosieci-ai-operator' ),
				array(),
				__( 'The form plugin is active.', 'dosieci-ai-operator' ),
				array( 'tool' => 'inspect_plugins', 'arguments' => array() ),
				PlanAction::ROLLBACK_RESTORE_PLUGIN_STATE
			);
			$actions[]    = $installPlugin;
			$formActionId = $installPlugin->actionId;
		}

		// --- pages -------------------------------------------------------
		$pageActionIds   = array();
		$existingPageIds = array();

		foreach ( $blueprint->pages as $index => $pageTitle ) {
			$isHome   = 0 === $index;
			$content  = $this->content->forPage( $blueprint, $pageTitle, $isHome );
			$resource = ManagedResource::forRole( ManagedResource::TYPE_PAGE, $pageTitle );

			$resolution = null !== $this->resources
				? $this->resources->resolve( $resource, $projectId, $content )
				: ResourceResolution::create( $resource->key() );

			// A conflict is not planned around silently. The step is omitted
			// and the reason is carried into the plan so the human sees
			// exactly which page was left alone and why.
			if ( $resolution->isConflict() ) {
				$conflicts[ $pageTitle ] = $resolution;

				// The page still exists, so downstream steps (menu, homepage)
				// can point at it -- we simply do not write to it.
				if ( $resolution->hasExisting() ) {
					$existingPageIds[ $pageTitle ] = (int) $resolution->existingId;
				}

				continue;
			}

			if ( ResourceResolution::REUSE === $resolution->decision && $resolution->hasExisting() ) {
				// Already exactly what the blueprint wants. Planning a write
				// that would change nothing is noise in the approval list.
				$existingPageIds[ $pageTitle ] = (int) $resolution->existingId;

				continue;
			}

			if ( ResourceResolution::UPDATE_MANAGED === $resolution->decision && $resolution->hasExisting() ) {
				$action = $this->action(
					$sequence++,
					'update_post',
					array(
						'post_id' => (int) $resolution->existingId,
						'title'   => $pageTitle,
						'content' => $content,
						'status'  => 'publish',
					),
					ToolDefinition::RISK_REVERSIBLE_WRITE,
					$resolution->describe( $pageTitle ),
					array(),
					sprintf(
						/* translators: %s: page title */
						__( 'Page “%s” contains the updated content.', 'dosieci-ai-operator' ),
						$pageTitle
					),
					array( 'tool' => 'get_post', 'arguments' => array( 'post_id' => (int) $resolution->existingId ) ),
					// Restores the previous content, not a trash: we did not
					// create this page in this run.
					PlanAction::ROLLBACK_RESTORE_POST,
					array(),
					$resource->key()
				);

				$actions[]                   = $action;
				$pageActionIds[ $pageTitle ] = $action->actionId;
				$existingPageIds[ $pageTitle ] = (int) $resolution->existingId;

				continue;
			}

			$action = $this->action(
				$sequence++,
				'create_post',
				array(
					'title'     => $pageTitle,
					'content'   => $content,
					'post_type' => 'page',
					// Published, not draft: a menu pointing at drafts and a
					// homepage that 404s for logged-out visitors is not a
					// finished site. 1.1's create_post defaults to draft for
					// one-off calls, which is right there and wrong here --
					// so the plan is explicit, and the human sees "publish"
					// in the step description before approving.
					'status'    => 'publish',
				),
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				sprintf(
					/* translators: %s: page title */
					__( 'Create and publish the page “%s”.', 'dosieci-ai-operator' ),
					$pageTitle
				),
				array(),
				sprintf(
					/* translators: %s: page title */
					__( 'Page “%s” exists and is published.', 'dosieci-ai-operator' ),
					$pageTitle
				),
				array( 'tool' => 'search_posts', 'arguments' => array( 'query' => $pageTitle ) ),
				PlanAction::ROLLBACK_TRASH_POST,
				array(),
				$resource->key()
			);

			$actions[]                   = $action;
			$pageActionIds[ $pageTitle ] = $action->actionId;
		}

		// --- illustration -------------------------------------------------
		// Only from the library the site already has. The builder never
		// downloads anything: no stock provider is configured (see
		// StockImageProviderInterface), so the worst case here is a page
		// with no picture rather than a page with somebody else's.
		//
		// A conflicted page is skipped entirely. We declined to write its
		// content because a human owns it; quietly changing its featured
		// image would be the same intrusion through a smaller door.
		$candidates = null !== $this->media ? $this->media->all( 50 ) : array();

		if ( null !== $this->media ) {

			foreach ( $blueprint->pages as $pageTitle ) {
				if ( isset( $conflicts[ $pageTitle ] ) ) {
					continue;
				}

				$isPlanned  = isset( $pageActionIds[ $pageTitle ] );
				$existingId = $existingPageIds[ $pageTitle ] ?? 0;

				if ( ! $isPlanned && $existingId <= 0 ) {
					continue;
				}

				// A page that already has a picture is left alone -- ours or
				// not. Unlike page content, a swapped featured image leaves
				// no trace in the fingerprint, so a rebuild has no way to
				// tell "we set this" from "somebody chose this". Only ever
				// filling an empty slot is the rule that cannot revert a
				// choice a person made.
				if ( $existingId > 0 && $this->media->featuredImageId( $existingId ) > 0 ) {
					continue;
				}

				$image = $this->mediaMatcher->bestFor( $pageTitle, $candidates );

				if ( null === $image ) {
					continue;
				}

				$actions[] = $this->action(
					$sequence++,
					'set_featured_image',
					array(
						'page_id'       => $isPlanned ? 0 : $existingId,
						'attachment_id' => $image->attachmentId,
					),
					ToolDefinition::RISK_REVERSIBLE_WRITE,
					// Names the actual file, so a questionable match costs
					// the human one glance at the approval list.
					sprintf(
						/* translators: 1: page title, 2: image file label */
						__( 'Set the featured image of page “%1$s”: %2$s.', 'dosieci-ai-operator' ),
						$pageTitle,
						$image->label()
					),
					$isPlanned ? array( $pageActionIds[ $pageTitle ] ) : array(),
					sprintf(
						/* translators: %s: page title */
						__( 'Page “%s” has a featured image set.', 'dosieci-ai-operator' ),
						$pageTitle
					),
					array( 'tool' => 'get_post', 'arguments' => array() ),
					PlanAction::ROLLBACK_RESTORE_FEATURED_IMAGE,
					$isPlanned
						? array( 'page_id' => array( 'from_action' => $pageActionIds[ $pageTitle ], 'field' => 'post_id' ) )
						: array()
				);
			}
		}

		// --- navigation ---------------------------------------------------
		// Depends on every page: a menu built before its pages exist is a
		// menu of dead links.
		// The item list is RESOLVED at run time, not fixed here: a menu
		// entry needs the post id of a page that does not exist yet, and
		// WriteToolFactory::createMenu silently skips an item carrying
		// neither a page_id nor a url -- which is how a create_menu step can
		// report success and leave an empty menu behind. Found exactly that
		// way on a real WordPress build.
		// Two sources, because not every page is created by this run. A page
		// this plan creates or updates contributes a RESOLVER (its id exists
		// only at run time); a page that was reused or left alone in a
		// conflict contributes its already-known id directly.
		// ONE list, in blueprint order. Each entry either points at the step
		// that will create the page (id known only at run time) or carries
		// an id we already have. Keeping these in two lists and merging them
		// reordered the menu whenever a page was reused.
		$menuItemResolvers = array();

		foreach ( $blueprint->pages as $pageTitle ) {
			if ( isset( $pageActionIds[ $pageTitle ] ) ) {
				$menuItemResolvers[] = array(
					'title'       => $pageTitle,
					'from_action' => $pageActionIds[ $pageTitle ],
				);

				continue;
			}

			if ( isset( $existingPageIds[ $pageTitle ] ) ) {
				$menuItemResolvers[] = array(
					'title'   => $pageTitle,
					'page_id' => $existingPageIds[ $pageTitle ],
				);
			}
		}

		$menuAction = $this->action(
			$sequence++,
			'create_menu',
			array(
				'name'     => __( 'Main Menu', 'dosieci-ai-operator' ),
				'location' => 'primary',
				'items'    => array(),
			),
			ToolDefinition::RISK_REVERSIBLE_WRITE,
			sprintf(
				/* translators: %d: number of menu items */
				__( 'Create the main menu with %d item(s) and assign it to the theme.', 'dosieci-ai-operator' ),
				count( $menuItemResolvers )
			),
			array_values( $pageActionIds ),
			__( 'The menu exists and is assigned to the theme location.', 'dosieci-ai-operator' ),
			array( 'tool' => 'inspect_theme', 'arguments' => array() ),
			PlanAction::ROLLBACK_DELETE_MENU,
			array(
				'items' => array(
					'kind'  => 'menu_items',
					'items' => $menuItemResolvers,
				),
			)
		);
		$actions[] = $menuAction;

		// --- block-theme navigation ------------------------------------------
		// A classic menu is invisible on a block theme: its header renders a
		// core/navigation block, which does not read nav-menu terms. So on a
		// block theme the builder additionally writes a wp_navigation post,
		// which is what the theme actually renders. The classic menu is still
		// created above, because a later switch to a classic theme should
		// find navigation waiting for it.
		if ( true === ( $context['is_block_theme'] ?? false ) ) {
			$actions[] = $this->action(
				$sequence++,
				'set_block_navigation',
				array(
					'items'      => array(),
					'project_id' => $projectId,
				),
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				__( 'Set the block theme navigation so the menu is visible on the site.', 'dosieci-ai-operator' ),
				array_values( $pageActionIds ),
				__( 'The block theme renders navigation with links to the pages.', 'dosieci-ai-operator' ),
				array( 'tool' => 'inspect_theme', 'arguments' => array() ),
				PlanAction::ROLLBACK_RESTORE_NAVIGATION,
				array(
					'items' => array(
						'kind'  => 'menu_items',
						'items' => $menuItemResolvers,
					),
				),
				'navigation:primary'
			);
		}

		// --- homepage -------------------------------------------------------
		$homeTitle = $blueprint->pages[0];

		// If this run creates or updates the home page its id only exists at
		// run time, so a resolver fills it in. If the page was reused or left
		// alone, the id is already known and goes in literally.
		$homeIsPlanned = isset( $pageActionIds[ $homeTitle ] );
		$homeExistingId = $existingPageIds[ $homeTitle ] ?? 0;

		if ( $homeIsPlanned || $homeExistingId > 0 ) {
			$actions[] = $this->action(
				$sequence++,
				'set_homepage',
				array( 'page_id' => $homeIsPlanned ? 0 : $homeExistingId ),
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				sprintf(
					/* translators: %s: page title */
					__( 'Set page “%s” as the site’s homepage.', 'dosieci-ai-operator' ),
					$homeTitle
				),
				$homeIsPlanned ? array( $pageActionIds[ $homeTitle ] ) : array(),
				__( 'The site displays the chosen page as its homepage.', 'dosieci-ai-operator' ),
				array( 'tool' => 'get_site_info', 'arguments' => array() ),
				PlanAction::ROLLBACK_RESTORE_HOMEPAGE,
				$homeIsPlanned
					? array( 'page_id' => array( 'from_action' => $pageActionIds[ $homeTitle ], 'field' => 'post_id' ) )
					: array()
			);
		}

		// --- site identity ----------------------------------------------------
		$actions[] = $this->action(
			$sequence++,
			'set_site_option',
			array( 'option' => 'blogname', 'value' => $blueprint->businessName ),
			ToolDefinition::RISK_REVERSIBLE_WRITE,
			sprintf(
				/* translators: %s: business name */
				__( 'Set the site name to “%s”.', 'dosieci-ai-operator' ),
				$blueprint->businessName
			),
			array(),
			__( 'The site name is set.', 'dosieci-ai-operator' ),
			array( 'tool' => 'get_site_info', 'arguments' => array() ),
			PlanAction::ROLLBACK_RESTORE_OPTION
		);

		// --- contact form: create it, then put it on the contact page ------
		// Installing CF7 is not the same as having a contact form. Without
		// these two steps the site has the plugin active, no form, and a
		// contact page with nothing on it.
		if ( null !== $formActionId ) {
			$contactPageTitle = $this->contactPageTitle( $blueprint );

			$contactPageKnown = null !== $contactPageTitle
				&& ( isset( $pageActionIds[ $contactPageTitle ] ) || isset( $existingPageIds[ $contactPageTitle ] ) );

			if ( $contactPageKnown ) {
				$configure = $this->action(
					$sequence++,
					'configure_contact_form',
					array(
						'business_name' => $blueprint->businessName,
						'language'      => $blueprint->language,
						'plan_id'       => $planId,
					),
					ToolDefinition::RISK_REVERSIBLE_WRITE,
					__( 'Create the contact form.', 'dosieci-ai-operator' ),
					array( $formActionId ),
					__( 'The contact form exists.', 'dosieci-ai-operator' ),
					array( 'tool' => 'inspect_plugins', 'arguments' => array() ),
					PlanAction::ROLLBACK_TRASH_CONTACT_FORM
				);
				$actions[] = $configure;

				$actions[] = $this->action(
					$sequence++,
					'embed_contact_form',
					array(
						'page_id'   => $pageActionIds[ $contactPageTitle ] ?? null ? 0 : ( $existingPageIds[ $contactPageTitle ] ?? 0 ),
						'shortcode' => '',
					),
					ToolDefinition::RISK_REVERSIBLE_WRITE,
					sprintf(
						/* translators: %s: page title */
						__( 'Embed the contact form on page “%s”.', 'dosieci-ai-operator' ),
						$contactPageTitle
					),
					array_values( array_filter( array( $configure->actionId, $pageActionIds[ $contactPageTitle ] ?? null ) ) ),
					__( 'The contact page contains the form.', 'dosieci-ai-operator' ),
					array( 'tool' => 'get_post', 'arguments' => array() ),
					PlanAction::ROLLBACK_RESTORE_POST,
					isset( $pageActionIds[ $contactPageTitle ] )
						? array(
							'page_id'   => array( 'from_action' => $pageActionIds[ $contactPageTitle ], 'field' => 'post_id' ),
							'shortcode' => array( 'from_action' => $configure->actionId, 'field' => 'shortcode' ),
						)
						: array( 'shortcode' => array( 'from_action' => $configure->actionId, 'field' => 'shortcode' ) ),
					// Owns the contact page for fingerprint purposes. Without
					// this the builder appends the form, never re-stamps, and
					// the NEXT run sees its own edit as a human change and
					// reports a false conflict.
					ManagedResource::forRole( ManagedResource::TYPE_PAGE, $contactPageTitle )->key()
				);
			}
		}

		// --- store ----------------------------------------------------------
		// Everything above is an ordinary site. A blueprint without a store
		// reaches none of this, which is what keeps commerce from touching
		// the majority of builds.
		if ( $blueprint->hasStore() && null !== $this->commerce ) {
			$store = $blueprint->storeBlueprint;

			$installWoo = $this->action(
				$sequence++,
				'install_plugin',
				array( 'slug' => 'woocommerce', 'activate' => true ),
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				__( 'Install and activate WooCommerce.', 'dosieci-ai-operator' ),
				array(),
				__( 'WooCommerce is active.', 'dosieci-ai-operator' ),
				array( 'tool' => 'inspect_plugins', 'arguments' => array() ),
				PlanAction::ROLLBACK_RESTORE_PLUGIN_STATE
			);
			$actions[] = $installWoo;

			// WooCommerce creates its own core pages on activation, so this
			// step verifies and repairs rather than creating four pages.
			$actions[] = $this->action(
				$sequence++,
				'ensure_store_pages',
				array(),
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				__( 'Check the store pages (Shop, Cart, Checkout, My account) and recreate any that are missing.', 'dosieci-ai-operator' ),
				array( $installWoo->actionId ),
				__( 'All store pages exist and are assigned.', 'dosieci-ai-operator' ),
				array( 'tool' => 'inspect_plugins', 'arguments' => array() ),
				PlanAction::ROLLBACK_RESTORE_STORE_PAGES
			);

			$actions[] = $this->action(
				$sequence++,
				'set_store_basics',
				array(
					'country'        => $store->country,
					'currency'       => $store->currency,
					'weight_unit'    => $store->weightUnit,
					'dimension_unit' => $store->dimensionUnit,
				),
				ToolDefinition::RISK_REVERSIBLE_WRITE,
				sprintf(
					/* translators: 1: country code, 2: currency code, 3: weight unit, 4: dimension unit */
					__( 'Set the store basics — country: %1$s, currency: %2$s, units: %3$s / %4$s.', 'dosieci-ai-operator' ),
					$store->country,
					$store->currency,
					$store->weightUnit,
					$store->dimensionUnit
				),
				array( $installWoo->actionId ),
				__( 'The store’s country, currency and units are set.', 'dosieci-ai-operator' ),
				array( 'tool' => 'inspect_plugins', 'arguments' => array() ),
				PlanAction::ROLLBACK_RESTORE_STORE_SETTINGS
			);

			$categoryActions = array();
			// Which term id serves each category role, whether or not this
			// run creates a step for it. Populated for REUSE and
			// UPDATE_MANAGED alike, so a product step can always resolve its
			// categories -- a category reused on a recovery run gets no
			// step of its own, and a product resolver that only looked at
			// steps in THIS plan found nothing for it. Verified: a recovery
			// plan whose T-shirts category was reused put the surviving
			// product in WooCommerce's "Uncategorized" instead.
			$categoryEntryByRole = array();

			foreach ( $store->categories as $category ) {
				$resource   = ManagedCommerceRole::category( $category->role );
				$desired    = $this->categoryContent( $category->name, $category->description );
				$resolution = null !== $this->resources
					? $this->resources->resolve( $resource, $projectId, $desired )
					: ResourceResolution::create( $resource->key() );

				if ( $resolution->isConflict() ) {
					$conflicts[ $category->name ] = $resolution;

					if ( $resolution->hasExisting() ) {
						$categoryEntryByRole[ $resource->slug ] = array( 'term_id' => (int) $resolution->existingId );
					}

					continue;
				}

				if ( ResourceResolution::REUSE === $resolution->decision && $resolution->hasExisting() ) {
					$categoryEntryByRole[ $resource->slug ] = array( 'term_id' => (int) $resolution->existingId );

					continue;
				}

				$isUpdate = ResourceResolution::UPDATE_MANAGED === $resolution->decision && $resolution->hasExisting();

				$categoryActions[] = $this->action(
					$sequence++,
					$isUpdate ? 'update_product_category' : 'create_product_category',
					array(
						'name'         => $category->name,
						'description'  => $category->description,
						'term_id'      => $isUpdate ? (int) $resolution->existingId : 0,
						// Carried so the tool can re-check ownership at
						// execution: on a store build WooCommerce is
						// installed by an earlier step of THIS plan, so
						// plan-time reconciliation had no taxonomy to look at.
						'resource_key' => $resource->key(),
						'project_id'   => $projectId,
					),
					ToolDefinition::RISK_REVERSIBLE_WRITE,
					$isUpdate
						? sprintf(
							/* translators: %s: category name */
							__( 'Update the product category “%s”.', 'dosieci-ai-operator' ),
							$category->name
						)
						: sprintf(
							/* translators: %s: category name */
							__( 'Create the product category “%s”.', 'dosieci-ai-operator' ),
							$category->name
						),
					array( $installWoo->actionId ),
					sprintf(
						/* translators: %s: category name */
						__( 'Category “%s” exists.', 'dosieci-ai-operator' ),
						$category->name
					),
					array( 'tool' => 'inspect_plugins', 'arguments' => array() ),
					$isUpdate
						? PlanAction::ROLLBACK_RESTORE_PRODUCT_CATEGORY
						: PlanAction::ROLLBACK_DELETE_PRODUCT_CATEGORY,
					array(),
					$resource->key()
				);

				$categoryEntryByRole[ $resource->slug ] = array( 'from_action' => end( $categoryActions )->actionId );
			}

			$actions = array_merge( $actions, $categoryActions );

			$categoryStepIds = array_map(
				static fn( PlanAction $a ): string => $a->actionId,
				$categoryActions
			);

			foreach ( $store->products as $product ) {
				$imageId = $this->productImageId( $product, $candidates );

				$resolution = $this->commerce->resolveProduct( $product, $projectId, $imageId );

				if ( $resolution->isConflict() ) {
					$conflicts[ $product->name ] = $resolution;

					continue;
				}

				if ( ResourceResolution::REUSE === $resolution->decision ) {
					continue;
				}

				$isUpdate = ResourceResolution::UPDATE_MANAGED === $resolution->decision && $resolution->hasExisting();
				$resource = ManagedCommerceRole::product( $product->role );

				// ONE ordered list per product, each entry either naming the
				// step that creates its category (id known only at run time)
				// or already knowing the id (reused or conflicted). Missing
				// this distinction is exactly the bug the menu once had: a
				// reused category has no step, so a resolver that only
				// looked at "steps in this plan" found nothing and the
				// product landed in WooCommerce's Uncategorized instead.
				$categoryEntries = array();
				foreach ( $product->categoryRoles as $role ) {
					$slug = ManagedCommerceRole::category( $role )->slug;

					if ( isset( $categoryEntryByRole[ $slug ] ) ) {
						$categoryEntries[] = $categoryEntryByRole[ $slug ];
					}
				}

				$actions[] = $this->action(
					$sequence++,
					$isUpdate ? 'update_product_draft' : 'create_product_draft',
					array(
						'name'              => $product->name,
						'description'       => $product->description,
						'short_description' => $product->shortDescription,
						// Canonical decimal string, hash-covered, and shown in
						// the description below. A price the human approves is
						// a commercial commitment, not an opaque argument.
						'regular_price'     => $product->regularPrice,
						'category_ids'      => array(),
						'image_id'          => $imageId,
						'product_id'        => $isUpdate ? (int) $resolution->existingId : 0,
					),
					ToolDefinition::RISK_REVERSIBLE_WRITE,
					$isUpdate
						? sprintf(
							/* translators: 1: product name, 2: price, 3: currency code */
							__( 'Update the product draft “%1$s” — %2$s %3$s.', 'dosieci-ai-operator' ),
							$product->name,
							$product->regularPrice,
							$store->currency
						)
						: sprintf(
							/* translators: 1: product name, 2: price, 3: currency code */
							__( 'Create the product draft “%1$s” — %2$s %3$s.', 'dosieci-ai-operator' ),
							$product->name,
							$product->regularPrice,
							$store->currency
						),
					// Categories must exist before a product can be filed in
					// them, so every category step is a dependency.
					array_merge( array( $installWoo->actionId ), $categoryStepIds ),
					sprintf(
						/* translators: %s: product name */
						__( 'Product “%s” exists as a draft.', 'dosieci-ai-operator' ),
						$product->name
					),
					array( 'tool' => 'inspect_plugins', 'arguments' => array() ),
					$isUpdate ? PlanAction::ROLLBACK_RESTORE_PRODUCT : PlanAction::ROLLBACK_TRASH_PRODUCT,
					array(
						'category_ids' => array(
							'kind'  => 'product_categories',
							'items' => $categoryEntries,
						),
					),
					$resource->key()
				);
			}
		}

		$conflictReasons = array();
		foreach ( $conflicts as $resolution ) {
			$conflictReasons[ $resolution->resourceKey ] = $resolution->reason;
		}

		return ActionPlan::create(
			$planId,
			$conversationId,
			$ownerUserId,
			$blueprint,
			$actions,
			$now,
			1,
			ActionPlan::DEFAULT_TTL_SECONDS,
			$projectId,
			$conflictReasons
		);
	}

	/**
	 * The canonical string a category's fingerprint is taken over.
	 *
	 * Must match WpManagedResourceResolver::termContent() exactly. Two
	 * spellings of "name plus description" would mismatch forever, which
	 * is the bug the contact page already taught once.
	 */
	private function categoryContent( string $name, string $description ): string {
		return trim( $name ) . "\n" . trim( $description );
	}

	/**
	 * An existing library image for a product, or 0.
	 *
	 * Same rule as pages: the match must be earned. A product with no
	 * suitable photo ships without one rather than wearing an unrelated
	 * attachment, and nothing here can fetch an image from outside.
	 *
	 * @param \DoSieci\AiOperator\Domain\SiteBuilder\Media\MediaCandidate[] $candidates
	 */
	private function productImageId( \DoSieci\AiOperator\Domain\SiteBuilder\Commerce\StoreProduct $product, array $candidates ): int {
		if ( \DoSieci\AiOperator\Domain\SiteBuilder\Commerce\StoreProduct::IMAGE_LIBRARY !== $product->imageStrategy ) {
			return 0;
		}

		$match = $this->mediaMatcher->bestFor( $product->name, $candidates );

		return null === $match ? 0 : $match->attachmentId;
	}

	/** The blueprint page that should host the form, if there is one. */
	private function contactPageTitle( SiteBlueprint $blueprint ): ?string {
		foreach ( $blueprint->pages as $title ) {
			$lower = mb_strtolower( $title );

			if ( str_contains( $lower, 'kontakt' ) || str_contains( $lower, 'contact' ) ) {
				return $title;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed>      $arguments
	 * @param string[]                  $dependsOn
	 * @param array<string, mixed>|null $verification
	 * @param array<string, mixed>      $resolvers
	 */
	/**
	 * Tools the Site Builder planner is allowed to emit.
	 *
	 * Kept in step with the WordPress verifier: a plan step whose effect
	 * nobody can read back must not exist, because the executor now fails
	 * any step it cannot verify. Adding a tool here without adding its
	 * verifier turns every plan containing it into a guaranteed failure --
	 * which is the safe direction, and the parity test makes it loud.
	 *
	 * @var string[]
	 */
	public const VERIFIABLE_TOOLS = array(
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
	);

	private function action(
		int $sequence,
		string $tool,
		array $arguments,
		string $risk,
		string $description,
		array $dependsOn,
		string $expected,
		?array $verification,
		string $rollback,
		array $resolvers = array(),
		string $managedResourceKey = ''
	): PlanAction {
		if ( ! in_array( $tool, self::VERIFIABLE_TOOLS, true ) ) {
			throw new PlanValidationException(
				sprintf(
					/* translators: %s: internal tool name */
					esc_html__( 'Tool “%s” has no verifier and cannot enter the plan.', 'dosieci-ai-operator' ),
					esc_html( $tool )
				)
			);
		}

		if ( null === $verification ) {
			throw new PlanValidationException(
				sprintf(
					/* translators: %s: internal tool name */
					esc_html__( 'Step “%s” must declare a verification.', 'dosieci-ai-operator' ),
					esc_html( $tool )
				)
			);
		}

		return PlanAction::fromArray(
			array(
				'action_id'         => sprintf( 'step-%02d', $sequence ),
				'sequence'          => $sequence,
				'tool_name'         => $tool,
				'arguments'         => $arguments,
				'risk_level'        => $risk,
				'description'       => $description,
				'depends_on'        => $dependsOn,
				'expected_result'   => $expected,
				'verification'      => $verification,
				'rollback_strategy' => $rollback,
				'resolvers'         => $resolvers,
				'managed_resource'  => $managedResourceKey,
			)
		);
	}
}

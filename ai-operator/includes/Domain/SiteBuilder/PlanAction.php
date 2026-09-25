<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\SiteBuilder;

/**
 * One step of an approved plan: exactly the tool call that will be
 * dispatched, plus what the human was told it would do.
 *
 * $toolName and $arguments are the load-bearing fields. They are what the
 * human approved and -- byte for byte, after the plan hash is verified --
 * what ToolDispatcher is later handed. Nothing between approval and
 * execution may alter them; a change means a new plan version and a new
 * approval (see ActionPlan's docblock).
 *
 * $description is what the human actually read. It is stored alongside the
 * arguments rather than regenerated at display time so the approval record
 * shows the words that were on screen, not a later re-rendering of them.
 *
 * $verification names a READ-ONLY tool that proves the step really took
 * effect. A write handler returning success is the handler's own opinion;
 * verification is a second, independent read of actual WordPress state.
 *
 * $resolvers handle the one thing a plan genuinely cannot know up front:
 * ids of objects it is about to create. "Set page X as the homepage"
 * cannot name X's post id before X exists. A resolver declares
 * `page_id <= (result of step-05).post_id`, and the executor fills it in
 * from that step's OWN RECORDED RESULT at run time.
 *
 * That stays safe for two reasons. First, resolvers are part of the plan
 * and therefore part of the hash -- the human approved this exact wiring,
 * and it cannot be added or repointed afterwards. Second, the only values
 * a resolver can pull are ones this plugin itself wrote into a prior step's
 * result; there is no path from request input into a resolved argument.
 * And the resolved value still passes through ToolDispatcher's schema
 * validation like every other argument.
 */
final class PlanAction {

	/** @var string[] */
	public const ROLLBACK_STRATEGIES = array(
		self::ROLLBACK_NONE,
		self::ROLLBACK_TRASH_POST,
		self::ROLLBACK_RESTORE_POST,
		self::ROLLBACK_RESTORE_OPTION,
		self::ROLLBACK_RESTORE_HOMEPAGE,
		self::ROLLBACK_DELETE_MENU,
		self::ROLLBACK_RESTORE_THEME,
		self::ROLLBACK_DEACTIVATE_PLUGIN,
		self::ROLLBACK_RESTORE_PLUGIN_STATE,
		self::ROLLBACK_DELETE_TERM,
		self::ROLLBACK_TRASH_CONTACT_FORM,
		self::ROLLBACK_RESTORE_NAVIGATION,
		self::ROLLBACK_RESTORE_FEATURED_IMAGE,
		self::ROLLBACK_RESTORE_STORE_PAGES,
		self::ROLLBACK_RESTORE_STORE_SETTINGS,
		self::ROLLBACK_DELETE_PRODUCT_CATEGORY,
		self::ROLLBACK_RESTORE_PRODUCT_CATEGORY,
		self::ROLLBACK_TRASH_PRODUCT,
		self::ROLLBACK_RESTORE_PRODUCT,
	);

	public const ROLLBACK_NONE                 = 'none';
	public const ROLLBACK_TRASH_POST           = 'trash_post';
	public const ROLLBACK_RESTORE_POST         = 'restore_post';
	public const ROLLBACK_RESTORE_OPTION       = 'restore_option';
	public const ROLLBACK_RESTORE_HOMEPAGE     = 'restore_homepage';
	public const ROLLBACK_DELETE_MENU          = 'delete_menu';
	public const ROLLBACK_RESTORE_THEME        = 'restore_theme';
	public const ROLLBACK_DEACTIVATE_PLUGIN    = 'deactivate_plugin';
	public const ROLLBACK_RESTORE_PLUGIN_STATE = 'restore_plugin_state';
	public const ROLLBACK_DELETE_TERM          = 'delete_term';
	public const ROLLBACK_TRASH_CONTACT_FORM   = 'trash_contact_form';
	public const ROLLBACK_RESTORE_NAVIGATION   = 'restore_navigation';
	public const ROLLBACK_RESTORE_FEATURED_IMAGE = 'restore_featured_image';
	public const ROLLBACK_RESTORE_STORE_PAGES       = 'restore_store_pages';
	public const ROLLBACK_RESTORE_STORE_SETTINGS    = 'restore_store_settings';
	public const ROLLBACK_DELETE_PRODUCT_CATEGORY   = 'delete_product_category';
	public const ROLLBACK_RESTORE_PRODUCT_CATEGORY  = 'restore_product_category';
	public const ROLLBACK_TRASH_PRODUCT             = 'trash_product';
	public const ROLLBACK_RESTORE_PRODUCT           = 'restore_product';

	/**
	 * @param array<string, mixed>      $arguments
	 * @param string[]                  $dependsOn      action ids that must have SUCCEEDED first
	 * @param array<string, mixed>|null $verification   {tool: string, arguments: array}
	 * @param array<string, mixed>      $resolvers      argument name => {from_action, field}
	 */
	public function __construct(
		public readonly string $actionId,
		public readonly int $sequence,
		public readonly string $toolName,
		public readonly array $arguments,
		public readonly string $riskLevel,
		public readonly string $description,
		public readonly array $dependsOn = array(),
		public readonly string $expectedResult = '',
		public readonly ?array $verification = null,
		public readonly string $rollbackStrategy = self::ROLLBACK_NONE,
		public readonly array $resolvers = array(),
		/**
		 * The logical resource this step owns, e.g. "page:kontakt".
		 *
		 * Part of the hash, so which resource a step manages is something
		 * the human approved and cannot change afterwards.
		 */
		public readonly string $managedResourceKey = ''
	) {
		if ( '' === $actionId ) {
			throw new PlanValidationException( 'Plan action requires an action_id.' );
		}

		if ( '' === $toolName ) {
			throw new PlanValidationException( sprintf( 'Action “%s” has no tool name.', esc_html( $actionId ) ) );
		}

		if ( ! in_array( $rollbackStrategy, self::ROLLBACK_STRATEGIES, true ) ) {
			throw new PlanValidationException(
				sprintf( 'Action “%s” declares unknown rollback strategy “%s”.', esc_html( $actionId ), esc_html( $rollbackStrategy ) )
			);
		}
	}

	public function isReversible(): bool {
		return self::ROLLBACK_NONE !== $this->rollbackStrategy;
	}

	/**
	 * The WordPress capability an undo of this step requires.
	 *
	 * Derived from the rollback strategy rather than reusing the forward
	 * action's capability, because they are not always the same right: the
	 * forward step of a create_post needs publish_pages, but trashing what
	 * it created is a delete. Mapping each strategy to the capability its
	 * OWN operation needs is what keeps rollback from being a way to
	 * perform a write you could not perform directly.
	 */
	public function requiredCapabilityForRollback(): string {
		return match ( $this->rollbackStrategy ) {
			self::ROLLBACK_TRASH_POST           => 'delete_pages',
			self::ROLLBACK_RESTORE_POST         => 'edit_pages',
			self::ROLLBACK_RESTORE_OPTION,
			self::ROLLBACK_RESTORE_HOMEPAGE     => 'manage_options',
			self::ROLLBACK_RESTORE_THEME        => 'switch_themes',
			self::ROLLBACK_DEACTIVATE_PLUGIN,
			self::ROLLBACK_RESTORE_PLUGIN_STATE => 'activate_plugins',
			self::ROLLBACK_DELETE_MENU          => 'edit_theme_options',
			self::ROLLBACK_DELETE_TERM          => 'manage_categories',
			self::ROLLBACK_TRASH_CONTACT_FORM   => 'publish_pages',
			self::ROLLBACK_RESTORE_NAVIGATION   => 'edit_theme_options',
			// Undoing a featured image edits the page, nothing more: it never
			// deletes the attachment, which may well be the user's own photo.
			self::ROLLBACK_RESTORE_FEATURED_IMAGE => 'edit_pages',
			// Store undos need the authority their OWN operation needs, not
			// the one the forward step used. Trashing a product is a delete;
			// restoring a setting is an option write.
			self::ROLLBACK_RESTORE_STORE_PAGES,
			self::ROLLBACK_RESTORE_STORE_SETTINGS   => 'manage_woocommerce',
			self::ROLLBACK_DELETE_PRODUCT_CATEGORY,
			self::ROLLBACK_RESTORE_PRODUCT_CATEGORY => 'manage_product_terms',
			self::ROLLBACK_TRASH_PRODUCT            => 'delete_products',
			self::ROLLBACK_RESTORE_PRODUCT          => 'edit_products',
			// An unreversible action has no undo to authorise. Naming a
			// capability nobody has is the fail-closed answer.
			default                             => 'do_not_allow',
		};
	}

	/**
	 * Canonical form with FIXED key order -- this feeds the plan hash, so
	 * two structurally identical actions must serialise identically
	 * regardless of how they were constructed.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'action_id'         => $this->actionId,
			'arguments'         => self::canonicalise( $this->arguments ),
			'depends_on'        => $this->dependsOn,
			'description'       => $this->description,
			'expected_result'   => $this->expectedResult,
			'risk_level'        => $this->riskLevel,
			'managed_resource'  => $this->managedResourceKey,
			'resolvers'         => self::canonicalise( $this->resolvers ),
			'rollback_strategy' => $this->rollbackStrategy,
			'sequence'          => $this->sequence,
			'tool_name'         => $this->toolName,
			'verification'      => null === $this->verification ? null : self::canonicalise( $this->verification ),
		);
	}

	/** @param array<string, mixed> $raw */
	public static function fromArray( array $raw ): self {
		return new self(
			(string) ( $raw['action_id'] ?? '' ),
			(int) ( $raw['sequence'] ?? 0 ),
			(string) ( $raw['tool_name'] ?? '' ),
			is_array( $raw['arguments'] ?? null ) ? $raw['arguments'] : array(),
			(string) ( $raw['risk_level'] ?? '' ),
			(string) ( $raw['description'] ?? '' ),
			is_array( $raw['depends_on'] ?? null ) ? array_values( array_map( 'strval', $raw['depends_on'] ) ) : array(),
			(string) ( $raw['expected_result'] ?? '' ),
			is_array( $raw['verification'] ?? null ) ? $raw['verification'] : null,
			(string) ( $raw['rollback_strategy'] ?? self::ROLLBACK_NONE ),
			is_array( $raw['resolvers'] ?? null ) ? $raw['resolvers'] : array(),
			(string) ( $raw['managed_resource'] ?? '' )
		);
	}

	/**
	 * Recursively sorts associative keys so the hash depends on CONTENT, not
	 * on the order the model happened to emit JSON keys in. List arrays keep
	 * their order -- in a list, order IS content.
	 *
	 * @param array<mixed> $value
	 *
	 * @return array<mixed>
	 */
	public static function canonicalise( array $value ): array {
		$isList = array_keys( $value ) === range( 0, count( $value ) - 1 );

		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ $key ] = is_array( $item ) ? self::canonicalise( $item ) : $item;
		}

		if ( ! $isList ) {
			ksort( $out );
		}

		return $out;
	}
}

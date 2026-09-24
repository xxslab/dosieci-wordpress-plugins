<?php

declare(strict_types=1);

namespace DoSieci\Instant\Search;

use DoSieci\Instant\Search\Adapter\WpdbSearchRepository;
use DoSieci\Instant\Search\Domain\ResultRanker;
use DoSieci\Instant\Search\Domain\SearchQuery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DoSieci Instant Search.
 *
 * Private by construction: the search runs entirely against this site's own
 * database. No query is sent to any external service, so there is no API key
 * to configure and nothing to opt into.
 */
final class Plugin {

	public const REST_NAMESPACE   = 'dosieci-instant-search/v1';
	public const OPTION_POST_TYPE = 'dosieci_instant_search_post_type';
	public const OPTION_MIN_CHARS = 'dosieci_instant_search_min_chars';
	public const PAGE_SLUG        = 'dosieci-instant-search';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueFrontend' ) );
		add_action( 'admin_menu', array( $this, 'registerSettingsPage' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DOSIECI_INSTANT_SEARCH_FILE ), array( $this, 'actionLinks' ) );
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
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
				esc_html__( 'Settings', 'dosieci-instant-search' )
			)
		);

		return $links;
	}

	public function registerRoutes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/suggest',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handleSuggest' ),
				// Public by design: this is the storefront's search box. The
				// repository only ever returns content a visitor can already
				// see (published, not password-protected, not hidden).
				'permission_callback' => '__return_true',
				'args'                => array(
					'q' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public function handleSuggest( \WP_REST_Request $request ): \WP_REST_Response {
		$query = SearchQuery::fromString( (string) $request->get_param( 'q' ) );

		if ( ! $query->isSearchable() ) {
			return new \WP_REST_Response( array( 'results' => array() ), 200 );
		}

		$results = ( new ResultRanker() )->rank(
			$query,
			( new WpdbSearchRepository() )->search( $query, $this->postType() )
		);

		$response = new \WP_REST_Response(
			array(
				'query'   => $query->normalised,
				'results' => array_map( static fn( $result ): array => $result->toArray(), $results ),
			),
			200
		);

		// Short: enough to absorb a burst of keystrokes without serving stale
		// stock or prices for long.
		$response->header( 'Cache-Control', 'public, max-age=30' );

		return $response;
	}

	private function postType(): string {
		$postType = (string) get_option( self::OPTION_POST_TYPE, 'product' );

		if ( 'product' === $postType && ! post_type_exists( 'product' ) ) {
			return 'post';
		}

		return in_array( $postType, array( 'product', 'post', 'page' ), true ) ? $postType : 'post';
	}

	public function enqueueFrontend(): void {
		wp_enqueue_script(
			'dosieci-instant-search',
			plugins_url( 'assets/search.js', DOSIECI_INSTANT_SEARCH_FILE ),
			array(),
			DOSIECI_INSTANT_SEARCH_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_enqueue_style(
			'dosieci-instant-search',
			plugins_url( 'assets/search.css', DOSIECI_INSTANT_SEARCH_FILE ),
			array(),
			DOSIECI_INSTANT_SEARCH_VERSION
		);

		wp_add_inline_script(
			'dosieci-instant-search',
			'window.dosieciInstantSearch = ' . wp_json_encode(
				array(
					'endpoint' => rest_url( self::REST_NAMESPACE . '/suggest' ),
					'minChars' => max( 1, min( 5, (int) get_option( self::OPTION_MIN_CHARS, SearchQuery::MIN_LENGTH ) ) ),
					'label'    => __( 'Search suggestions', 'dosieci-instant-search' ),
				)
			) . ';',
			'before'
		);
	}

	public function registerSettingsPage(): void {
		add_options_page(
			__( 'DoSieci Instant Search', 'dosieci-instant-search' ),
			__( 'Instant Search', 'dosieci-instant-search' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'renderSettingsPage' )
		);
	}

	public function registerSettings(): void {
		register_setting(
			'dosieci_instant_search',
			self::OPTION_POST_TYPE,
			array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $value ): string => in_array( $value, array( 'product', 'post', 'page' ), true ) ? (string) $value : 'product',
				'default'           => 'product',
			)
		);

		register_setting(
			'dosieci_instant_search',
			self::OPTION_MIN_CHARS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => static fn( $value ): int => max( 1, min( 5, (int) $value ) ),
				'default'           => SearchQuery::MIN_LENGTH,
			)
		);
	}

	public function renderSettingsPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dosieci-instant-search' ), '', array( 'response' => 403 ) );
		}

		$types = array(
			'post' => __( 'Posts', 'dosieci-instant-search' ),
			'page' => __( 'Pages', 'dosieci-instant-search' ),
		);

		if ( post_type_exists( 'product' ) ) {
			$types = array( 'product' => __( 'WooCommerce products', 'dosieci-instant-search' ) ) + $types;
		}

		$current = $this->postType();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci Instant Search', 'dosieci-instant-search' ); ?></h1>
			<p><?php esc_html_e( 'Suggestions are computed on this site only: no search query leaves it, and there is no API key to set up.', 'dosieci-instant-search' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'dosieci_instant_search' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dosieci-is-post-type"><?php esc_html_e( 'Content to search', 'dosieci-instant-search' ); ?></label></th>
						<td>
							<select id="dosieci-is-post-type" name="<?php echo esc_attr( self::OPTION_POST_TYPE ); ?>">
								<?php foreach ( $types as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dosieci-is-min-chars"><?php esc_html_e( 'Minimum characters', 'dosieci-instant-search' ); ?></label></th>
						<td>
							<input type="number" min="1" max="5" id="dosieci-is-min-chars"
								name="<?php echo esc_attr( self::OPTION_MIN_CHARS ); ?>"
								value="<?php echo esc_attr( (string) get_option( self::OPTION_MIN_CHARS, SearchQuery::MIN_LENGTH ) ); ?>">
							<p class="description"><?php esc_html_e( 'Suggestions start after this many characters.', 'dosieci-instant-search' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'How it works', 'dosieci-instant-search' ); ?></h2>
			<p><?php esc_html_e( 'The plugin attaches itself to every standard WordPress search field (input name="s"), including the Search block and WooCommerce product search. Suggestions come from this endpoint:', 'dosieci-instant-search' ); ?></p>
			<p><code><?php echo esc_html( rest_url( self::REST_NAMESPACE . '/suggest' ) ); ?>?q=&hellip;</code></p>
		</div>
		<?php
	}
}

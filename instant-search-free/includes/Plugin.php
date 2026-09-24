<?php

declare(strict_types=1);

namespace DoSieci\Instant\Search;

use DoSieci\Instant\Search\Adapter\WpdbSearchRepository;
use DoSieci\Instant\Search\Domain\ResultRanker;
use DoSieci\Instant\Search\Domain\SearchQuery;

/**
 * DoSieci Instant Search — free tier.
 *
 * Privacy-first by construction: the search runs entirely against this
 * site's own database. No query is sent to any external service, so there
 * is no API key to configure and nothing to opt into.
 */
final class Plugin {

	public const REST_NAMESPACE = 'dosieci-instant-search/v1';
	public const OPTION_POST_TYPE = 'dosieci_instant_search_post_type';
	public const OPTION_MIN_CHARS = 'dosieci_instant_search_min_chars';

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
	}

	public function registerRoutes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/suggest',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handleSuggest' ),
				// Public by design: this is the storefront's search box.
				// Safety comes from the query only ever returning published
				// posts, never draft/private content.
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

		$postType = (string) get_option( self::OPTION_POST_TYPE, 'product' );
		if ( 'product' === $postType && ! class_exists( 'WooCommerce' ) ) {
			$postType = 'post';
		}

		$results = ( new ResultRanker() )->rank(
			$query,
			( new WpdbSearchRepository() )->search( $query, $postType )
		);

		$response = new \WP_REST_Response(
			array(
				'query'   => $query->normalised,
				'results' => array_map( static fn( $result ): array => $result->toArray(), $results ),
			),
			200
		);

		// Short cache: enough to absorb a burst of keystrokes from one
		// visitor without serving stale stock/price data for long.
		$response->header( 'Cache-Control', 'public, max-age=30' );

		return $response;
	}

	public function enqueueFrontend(): void {
		wp_enqueue_script(
			'dosieci-instant-search',
			DOSIECI_INSTANT_SEARCH_URL . 'assets/search.js',
			array(),
			DOSIECI_INSTANT_SEARCH_VERSION,
			true
		);

		wp_enqueue_style(
			'dosieci-instant-search',
			DOSIECI_INSTANT_SEARCH_URL . 'assets/search.css',
			array(),
			DOSIECI_INSTANT_SEARCH_VERSION
		);

		wp_localize_script(
			'dosieci-instant-search',
			'dosieciInstantSearch',
			array(
				'endpoint' => rest_url( self::REST_NAMESPACE . '/suggest' ),
				'minChars' => (int) get_option( self::OPTION_MIN_CHARS, SearchQuery::MIN_LENGTH ),
			)
		);
	}

	public function registerSettingsPage(): void {
		add_options_page(
			__( 'DoSieci Instant Search', 'dosieci-instant-search' ),
			__( 'Instant Search', 'dosieci-instant-search' ),
			'manage_options',
			'dosieci-instant-search',
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
			wp_die( esc_html__( 'Brak uprawnień.', 'dosieci-instant-search' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DoSieci Instant Search', 'dosieci-instant-search' ); ?></h1>
			<p><?php esc_html_e( 'Podpowiedzi wyszukiwania działają lokalnie — żadne zapytanie nie opuszcza tej witryny. Nie musisz podawać żadnego klucza API.', 'dosieci-instant-search' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'dosieci_instant_search' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dosieci-is-post-type"><?php esc_html_e( 'Przeszukiwany typ treści', 'dosieci-instant-search' ); ?></label></th>
						<td>
							<select id="dosieci-is-post-type" name="<?php echo esc_attr( self::OPTION_POST_TYPE ); ?>">
								<?php
								$current = (string) get_option( self::OPTION_POST_TYPE, 'product' );
								foreach ( array( 'product' => __( 'Produkty WooCommerce', 'dosieci-instant-search' ), 'post' => __( 'Wpisy', 'dosieci-instant-search' ), 'page' => __( 'Strony', 'dosieci-instant-search' ) ) as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dosieci-is-min-chars"><?php esc_html_e( 'Minimalna liczba znaków', 'dosieci-instant-search' ); ?></label></th>
						<td>
							<input type="number" min="1" max="5" id="dosieci-is-min-chars"
								name="<?php echo esc_attr( self::OPTION_MIN_CHARS ); ?>"
								value="<?php echo esc_attr( (string) get_option( self::OPTION_MIN_CHARS, SearchQuery::MIN_LENGTH ) ); ?>">
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Jak używać', 'dosieci-instant-search' ); ?></h2>
			<p><?php esc_html_e( 'Wtyczka podpina się automatycznie do standardowego pola wyszukiwania WordPressa (input[name="s"]). Endpoint podpowiedzi:', 'dosieci-instant-search' ); ?></p>
			<p><code><?php echo esc_html( rest_url( self::REST_NAMESPACE . '/suggest?q=...' ) ); ?></code></p>
		</div>
		<?php
	}
}

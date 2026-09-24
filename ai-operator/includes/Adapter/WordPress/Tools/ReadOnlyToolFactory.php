<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Adapter\WordPress\Tools;

use DoSieci\AiOperator\Domain\Tools\ToolDefinition;
use DoSieci\AiOperator\Domain\Tools\ToolRegistry;

/**
 * Builds Phase 1's read-only tool set: the concrete WordPress
 * implementations behind the tool names the Hub's Phase1ToolRegistry
 * advertises to the model.
 *
 * Every tool here is genuinely read-only -- there is no code path in this
 * file that writes an option, a post, a term, a user or a file. That is a
 * property to preserve deliberately, not an accident of the current
 * feature set: the whole security argument for running these without
 * per-action confirmation rests on it.
 *
 * Output shaping rules applied throughout:
 *  - Never return secrets (no option values by name, no DB credentials, no
 *    auth keys, no plugin API keys).
 *  - Bound every list (LIMIT/slice) so a huge site cannot produce a
 *    multi-megabyte tool result that blows up the next model call.
 *  - Return structured data, not rendered HTML.
 */
final class ReadOnlyToolFactory {

	private const DEFAULT_TIMEOUT  = 10;
	private const MAX_LIST_RESULTS = 20;

	public function register( ToolRegistry $registry ): void {
		foreach ( $this->definitions() as $definition ) {
			$registry->register( $definition );
		}
	}

	/** @return ToolDefinition[] */
	private function definitions(): array {
		$noArgs = array(
			'type'       => 'object',
			'properties' => array(),
		);

		return array(
			new ToolDefinition(
				'get_site_info',
				'Basic information about this WordPress site: name, URL, language, timezone, multisite flag.',
				$noArgs,
				'manage_options',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'getSiteInfo' )
			),
			new ToolDefinition(
				'get_site_health',
				'WordPress version, HTTPS status, debug flags, and whether the site is public to search engines.',
				$noArgs,
				'manage_options',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'getSiteHealth' )
			),
			new ToolDefinition(
				'inspect_php',
				'PHP version, memory limit, execution limits and loaded extensions relevant to WordPress.',
				$noArgs,
				'manage_options',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'inspectPhp' )
			),
			new ToolDefinition(
				'inspect_plugins',
				'Installed plugins with version and active/inactive state.',
				$noArgs,
				'activate_plugins',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'inspectPlugins' )
			),
			new ToolDefinition(
				'inspect_theme',
				'Active theme, its version, and whether it is a child theme.',
				$noArgs,
				'edit_theme_options',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'inspectTheme' )
			),
			new ToolDefinition(
				'inspect_cron',
				'Scheduled WP-Cron events with their next run time, and whether WP-Cron is disabled.',
				$noArgs,
				'manage_options',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'inspectCron' )
			),
			new ToolDefinition(
				'inspect_autoload',
				'Total size of autoloaded options and the largest autoloaded entries by size (names and sizes only, never values).',
				$noArgs,
				'manage_options',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'inspectAutoload' )
			),
			new ToolDefinition(
				'inspect_database_summary',
				'Row counts for core content tables and the total number of registered post types.',
				$noArgs,
				'manage_options',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'inspectDatabaseSummary' )
			),
			new ToolDefinition(
				'inspect_woocommerce',
				'Whether WooCommerce is active, its version, product/order counts and HPOS status.',
				$noArgs,
				'manage_woocommerce',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'inspectWooCommerce' )
			),
			new ToolDefinition(
				'search_posts',
				'Search posts/pages by keyword. Returns id, title, type, status and permalink.',
				array(
					'type'       => 'object',
					'properties' => array(
						'query'     => array( 'type' => 'string' ),
						'post_type' => array( 'type' => 'string' ),
						'limit'     => array( 'type' => 'integer' ),
					),
					'required'   => array( 'query' ),
				),
				'edit_posts',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'searchPosts' )
			),
			new ToolDefinition(
				'get_post',
				'Fetch one post by id: title, status, type, excerpt and a truncated content preview.',
				array(
					'type'       => 'object',
					'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'post_id' ),
				),
				'edit_posts',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'getPost' )
			),
			new ToolDefinition(
				'search_products',
				'Search WooCommerce products by keyword. Returns id, name, sku, price and stock status.',
				array(
					'type'       => 'object',
					'properties' => array(
						'query' => array( 'type' => 'string' ),
						'limit' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'query' ),
				),
				'edit_products',
				ToolDefinition::RISK_READ_ONLY,
				self::DEFAULT_TIMEOUT,
				array( $this, 'searchProducts' )
			),
		);
	}

	/** @return array<string, mixed> */
	public function getSiteInfo(): array {
		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url( '/' ),
			'admin_url'   => admin_url(),
			'language'    => get_bloginfo( 'language' ),
			'timezone'    => wp_timezone_string(),
			'is_multisite'=> is_multisite(),
		);
	}

	/** @return array<string, mixed> */
	public function getSiteHealth(): array {
		return array(
			'wordpress_version'  => get_bloginfo( 'version' ),
			'is_https'           => 0 === stripos( (string) home_url(), 'https://' ),
			'wp_debug'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_display'   => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'search_engines_discouraged' => '0' === get_option( 'blog_public' ),
			'permalink_structure' => get_option( 'permalink_structure' ) ?: 'plain',
			'active_theme'       => wp_get_theme()->get( 'Name' ),
		);
	}

	/** @return array<string, mixed> */
	public function inspectPhp(): array {
		$relevant = array( 'curl', 'gd', 'imagick', 'intl', 'json', 'mbstring', 'mysqli', 'openssl', 'zip' );

		return array(
			'php_version'         => PHP_VERSION,
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'extensions'          => array_values( array_filter( $relevant, 'extension_loaded' ) ),
		);
	}

	/** @return array<string, mixed> */
	public function inspectPlugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}

		return array(
			'count'   => count( $plugins ),
			'plugins' => $plugins,
		);
	}

	/** @return array<string, mixed> */
	public function inspectTheme(): array {
		$theme = wp_get_theme();

		return array(
			'name'         => $theme->get( 'Name' ),
			'version'      => $theme->get( 'Version' ),
			'is_child'     => (bool) $theme->parent(),
			'parent'       => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			'is_block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
		);
	}

	/** @return array<string, mixed> */
	public function inspectCron(): array {
		$crons  = _get_cron_array();
		$events = array();

		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( (array) $hooks as $hook => $_ ) {
					$events[] = array(
						'hook'     => (string) $hook,
						'next_run' => gmdate( 'c', (int) $timestamp ),
						'overdue'  => (int) $timestamp < time(),
					);
				}
			}
		}

		usort( $events, static fn( array $a, array $b ): int => strcmp( $a['next_run'], $b['next_run'] ) );

		return array(
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'total_events'     => count( $events ),
			'overdue_events'   => count( array_filter( $events, static fn( array $e ): bool => $e['overdue'] ) ),
			'events'           => array_slice( $events, 0, self::MAX_LIST_RESULTS ),
		);
	}

	/** @return array<string, mixed> */
	public function inspectAutoload(): array {
		global $wpdb;

		$totalBytes = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" );

		// Only names and sizes -- never option_value, which routinely holds
		// third-party API keys and session data.
		$largest = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS size_bytes
				 FROM {$wpdb->options}
				 WHERE autoload IN ('yes','on','auto','auto-on')
				 ORDER BY size_bytes DESC
				 LIMIT %d",
				self::MAX_LIST_RESULTS
			),
			ARRAY_A
		);

		return array(
			'total_autoloaded_bytes' => $totalBytes,
			'total_autoloaded_kb'    => (int) round( $totalBytes / 1024 ),
			'exceeds_recommended_800kb' => $totalBytes > 800 * 1024,
			'largest' => is_array( $largest ) ? $largest : array(),
		);
	}

	/** @return array<string, mixed> */
	public function inspectDatabaseSummary(): array {
		global $wpdb;

		return array(
			'posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			'published'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish'" ),
			'revisions'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'postmeta'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
			'comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ),
			'users'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
			'options'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ),
			'registered_post_types' => count( get_post_types() ),
		);
	}

	/** @return array<string, mixed> */
	public function inspectWooCommerce(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'active' => false );
		}

		$hpos = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			$hpos = (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		$counts = wp_count_posts( 'product' );

		return array(
			'active'            => true,
			'version'           => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'hpos_enabled'      => $hpos,
			'products_published'=> isset( $counts->publish ) ? (int) $counts->publish : 0,
			'products_draft'    => isset( $counts->draft ) ? (int) $counts->draft : 0,
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function searchPosts( array $args ): array {
		$limit = isset( $args['limit'] ) ? max( 1, min( self::MAX_LIST_RESULTS, (int) $args['limit'] ) ) : 10;

		$query = new \WP_Query(
			array(
				's'                      => (string) $args['query'],
				'post_type'              => isset( $args['post_type'] ) ? (string) $args['post_type'] : array( 'post', 'page' ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => $limit,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$results = array();
		foreach ( $query->posts as $post ) {
			$results[] = array(
				'id'        => $post->ID,
				'title'     => get_the_title( $post ),
				'type'      => $post->post_type,
				'status'    => $post->post_status,
				'permalink' => get_permalink( $post ),
			);
		}

		return array(
			'count'   => count( $results ),
			'results' => $results,
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function getPost( array $args ): array {
		$post = get_post( (int) $args['post_id'] );

		if ( ! $post instanceof \WP_Post ) {
			return array( 'found' => false );
		}

		// A capability check on the specific post, on top of the tool's own
		// edit_posts gate: edit_posts alone does not imply the right to read
		// somebody else's private draft.
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return array(
				'found' => false,
				'note'  => 'The current user is not allowed to read this post.',
			);
		}

		return array(
			'found'     => true,
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'excerpt'   => $post->post_excerpt,
			'permalink' => get_permalink( $post ),
			'modified'  => $post->post_modified_gmt,
			// Truncated: a full 20k-word post would dominate the next model
			// call's context window for no benefit.
			'content_preview' => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 2000 ),
			'content_truncated' => mb_strlen( wp_strip_all_tags( $post->post_content ) ) > 2000,
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function searchProducts( array $args ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'woocommerce_active' => false,
				'results'            => array(),
			);
		}

		$limit = isset( $args['limit'] ) ? max( 1, min( self::MAX_LIST_RESULTS, (int) $args['limit'] ) ) : 10;

		$query = new \WP_Query(
			array(
				's'                      => (string) $args['query'],
				'post_type'              => 'product',
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => $limit,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$results = array();
		foreach ( $query->posts as $post ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;

			$results[] = array(
				'id'           => $post->ID,
				'name'         => get_the_title( $post ),
				'status'       => $post->post_status,
				'sku'          => $product ? $product->get_sku() : null,
				'price'        => $product ? $product->get_price() : null,
				'stock_status' => $product ? $product->get_stock_status() : null,
			);
		}

		return array(
			'woocommerce_active' => true,
			'count'              => count( $results ),
			'results'            => $results,
		);
	}
}

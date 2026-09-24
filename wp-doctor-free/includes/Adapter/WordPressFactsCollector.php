<?php

declare(strict_types=1);

namespace DoSieci\WP\Doctor\Adapter;

use DoSieci\WP\Doctor\Domain\SiteFacts;

/**
 * The only WordPress-aware part of the scan: gathers facts, decides nothing.
 *
 * Strictly read-only -- every query here is a SELECT, and no option, post or
 * transient is written or deleted anywhere in this class.
 */
final class WordPressFactsCollector {

	public function collect(): SiteFacts {
		global $wpdb;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'name'    => (string) $data['Name'],
				'version' => (string) $data['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}

		$cronEvents = array();
		$crons      = _get_cron_array();
		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( (array) $hooks as $hook => $_ ) {
					$cronEvents[] = array(
						'hook'      => (string) $hook,
						'timestamp' => (int) $timestamp,
					);
				}
			}
		}

		return new SiteFacts(
			PHP_VERSION,
			(string) get_bloginfo( 'version' ),
			0 === stripos( (string) home_url(), 'https://' ),
			defined( 'WP_DEBUG' ) && WP_DEBUG,
			defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'0' === (string) get_option( 'blog_public' ),
			(string) get_option( 'permalink_structure', '' ),
			(int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','nav_menu_item')" ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%'" ),
			$plugins,
			$cronEvents,
			self::memoryLimitBytes(),
			time()
		);
	}

	private static function memoryLimitBytes(): int {
		$limit = (string) ini_get( 'memory_limit' );

		if ( '' === $limit || '-1' === $limit ) {
			return 0;
		}

		$unit  = strtolower( substr( $limit, -1 ) );
		$value = (int) $limit;

		return match ( $unit ) {
			'g'     => $value * 1024 * 1024 * 1024,
			'm'     => $value * 1024 * 1024,
			'k'     => $value * 1024,
			default => $value,
		};
	}
}

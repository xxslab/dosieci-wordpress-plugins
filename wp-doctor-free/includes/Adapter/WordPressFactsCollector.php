<?php

declare(strict_types=1);

namespace DoSieci\WP\Doctor\Adapter;

use DoSieci\WP\Doctor\Domain\SiteFacts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only WordPress-aware part of the scan: gathers facts, decides nothing.
 *
 * Strictly read-only: every query here is a SELECT, and no option, post or
 * transient is written or deleted anywhere in this class.
 */
final class WordPressFactsCollector {

	public function collect(): SiteFacts {
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
				foreach ( array_keys( (array) $hooks ) as $hook ) {
					$cronEvents[] = array(
						'hook'      => (string) $hook,
						'timestamp' => (int) $timestamp,
					);
				}
			}
		}

		$counts = $this->databaseCounts();

		return new SiteFacts(
			PHP_VERSION,
			(string) get_bloginfo( 'version' ),
			0 === stripos( (string) home_url(), 'https://' ),
			defined( 'WP_DEBUG' ) && WP_DEBUG,
			defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'0' === (string) get_option( 'blog_public' ),
			(string) get_option( 'permalink_structure', '' ),
			$counts['autoload_bytes'],
			$counts['revisions'],
			$counts['posts'],
			$counts['transients'],
			$plugins,
			$cronEvents,
			self::memoryLimitBytes(),
			time()
		);
	}

	/**
	 * Aggregate counts WordPress has no API for. They are computed on demand
	 * for a single administrator page view, so a cache would only ever serve
	 * stale numbers in a diagnostic report.
	 *
	 * @return array{autoload_bytes:int, revisions:int, posts:int, transients:int}
	 */
	private function databaseCounts(): array {
		global $wpdb;

		$autoloadValues = function_exists( 'wp_autoload_values_to_autoload' )
			? wp_autoload_values_to_autoload()
			: array( 'yes', 'on', 'auto-on', 'auto' );

		$placeholders = implode( ', ', array_fill( 0, count( $autoloadValues ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only aggregates for a one-off admin report, see docblock.
		$autoloadBytes = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of %s built above.
				"SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE autoload IN ({$placeholders})",
				$autoloadValues
			)
		);

		$revisions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$posts     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'nav_menu_item')" );

		// Timeout rows are bookkeeping for the transient next to them, not
		// transients of their own, so they are left out of the count.
		$transients = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_transient\\_timeout\\_%'"
		);
		// phpcs:enable

		return array(
			'autoload_bytes' => $autoloadBytes,
			'revisions'      => $revisions,
			'posts'          => $posts,
			'transients'     => $transients,
		);
	}

	private static function memoryLimitBytes(): int {
		$limit = (string) ini_get( 'memory_limit' );

		if ( '' === $limit || '-1' === $limit ) {
			return 0;
		}

		return function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( $limit ) : (int) $limit;
	}
}

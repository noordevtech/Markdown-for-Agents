<?php
/**
 * Page-cache compatibility layer.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

/**
 * The hard truth, stated up front: an `advanced-cache.php` drop-in runs on
 * cache HITs before any plugin loads, so nothing in this file executes on a
 * hit. This class covers the paths a plugin CAN cover:
 *
 *  - stop the page cache from STORING a Markdown response (the catastrophic
 *    failure mode — a stored Markdown body under the shared URL key would be
 *    served to browsers), and
 *  - detect which caching plugin is active so the README/settings page can say
 *    which layer must be configured by hand (nginx snippet / Cloudflare rule).
 *
 * What is documented vs. guessed (per-API notes below):
 *  - DONOTCACHEPAGE: documented and honoured by WP Super Cache, W3 Total
 *    Cache, WP Rocket, LiteSpeed Cache and Hummingbird.
 *  - litespeed_control_set_nocache: documented LiteSpeed Cache API.
 *  - cache_enabler_bypass_cache: documented Cache Enabler filter (>= 1.6).
 *  - wpo_can_cache_page: WP-Optimize filter — FLAGGED AS A GUESS; I have not
 *    read WP-Optimize's source. It is guarded so it is a no-op elsewhere.
 */
final class CacheCompat {

	/**
	 * Identify the active page-caching plugin(s) at runtime.
	 *
	 * @return string[] Human-readable names; empty when none detected.
	 */
	public static function detect(): array {
		$found = array();

		$signatures = array(
			'WP Rocket'        => defined( 'WP_ROCKET_VERSION' ),
			'W3 Total Cache'   => defined( 'W3TC' ),
			'WP Super Cache'   => defined( 'WPCACHEHOME' ) || function_exists( 'wp_cache_setting' ),
			'LiteSpeed Cache'  => defined( 'LSCWP_V' ),
			'WP Fastest Cache' => class_exists( 'WpFastestCache' ),
			'Cache Enabler'    => class_exists( 'Cache_Enabler' ),
			'WP-Optimize'      => class_exists( 'WP_Optimize' ) || function_exists( 'WP_Optimize' ),
			'SiteGround Optimizer' => defined( 'SiteGround_Optimizer\VERSION' ),
			'Breeze'           => defined( 'BREEZE_VERSION' ),
			'Hummingbird'      => defined( 'WPHB_VERSION' ),
			'Powered Cache'    => defined( 'POWERED_CACHE_VERSION' ) || function_exists( 'powered_cache_flush' ),
		);

		foreach ( $signatures as $name => $present ) {
			if ( $present ) {
				$found[] = $name;
			}
		}

		return $found;
	}

	/**
	 * Whether an advanced-cache.php drop-in is installed and active.
	 *
	 * @return bool
	 */
	public static function has_advanced_cache_dropin(): bool {
		return defined( 'WP_CACHE' ) && WP_CACHE
			&& file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	}

	/**
	 * First line of the drop-in, to help identify an unknown implementation
	 * (e.g. the custom drop-in emitting `x-cache-by: Advanced Cache`).
	 *
	 * @return string
	 */
	public static function dropin_signature(): string {
		$path = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return '';
		}
		$head = (string) fread( $handle, 512 );
		fclose( $handle );

		if ( preg_match( '/^\s*(?:<\?php)?\s*(?:\/\/|\/\*+|#)\s*(.+)$/m', $head, $m ) ) {
			return trim( $m[1] );
		}

		return '';
	}

	/**
	 * Mark the current (Markdown) response as not-cacheable for every page
	 * cache we can reach. Call before output starts; the constants/filters
	 * are read by the caching plugins at their own shutdown handlers.
	 */
	public static function mark_uncacheable(): void {
		// Documented: WP Super Cache, W3TC, WP Rocket, LiteSpeed, Hummingbird.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// Documented LiteSpeed Cache API. No-op unless LiteSpeed is active.
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_control_set_nocache', 'markdown-for-agents: per-Accept variant must not share the URL cache key' );
		}

		// Documented Cache Enabler filter (>= 1.6). Harmless elsewhere.
		add_filter( 'cache_enabler_bypass_cache', '__return_true' );

		// GUESS (flagged): believed to be WP-Optimize's page-cache gate; not
		// verified against WP-Optimize source. Guarded so it only runs when
		// WP-Optimize is present, and a false filter name is simply inert.
		if ( class_exists( 'WP_Optimize' ) || function_exists( 'WP_Optimize' ) ) {
			add_filter( 'wpo_can_cache_page', '__return_false' );
		}
	}
}

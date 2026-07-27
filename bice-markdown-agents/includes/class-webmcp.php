<?php
/**
 * WebMCP: expose site tools to AI agents via the browser.
 *
 * @package Bice\MarkdownAgents
 */

namespace Bice\MarkdownAgents;

/**
 * Enqueues a small front-end script that registers site tools with the
 * WebMCP API (`navigator.modelContext.provideContext()`, see
 * https://webmachinelearning.github.io/webmcp/). In browsers/agents without
 * WebMCP support the script is a silent no-op.
 *
 * Tools provided (see assets/webmcp.js):
 *  - search_content      — search the site via the WP REST API.
 *  - get_page_markdown   — fetch any same-origin page as Markdown, using
 *                          this plugin's content negotiation.
 */
final class WebMcp {

	/**
	 * Script handle.
	 */
	private const HANDLE = 'bice-mda-webmcp';

	/**
	 * Hook the script onto front-end page loads.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	public static function register( array $settings ): void {
		if ( empty( $settings['webmcp_enabled'] ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Enqueue the WebMCP registration script with its configuration.
	 */
	public static function enqueue(): void {
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/webmcp.js', BICE_MDA_FILE ),
			array(),
			BICE_MDA_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.bmaWebMcp = ' . wp_json_encode(
				array(
					'homeUrl'  => home_url( '/' ),
					'restUrl'  => rest_url(),
					'siteName' => get_bloginfo( 'name' ),
				)
			) . ';',
			'before'
		);
	}
}

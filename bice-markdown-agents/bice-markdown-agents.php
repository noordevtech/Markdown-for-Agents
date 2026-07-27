<?php
/**
 * Plugin Name:       Markdown for Agents
 * Plugin URI:        https://github.com/noordevtech/Markdown-for-Agents
 * Description:       HTTP content negotiation for AI agents: requests that genuinely prefer text/markdown receive a Markdown rendering of the page; every other request receives the normal HTML, byte-identical. Self-hosted replacement for Cloudflare's "Markdown for Agents".
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Bice
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bice-markdown-agents
 *
 * This file also works when required from a single loader file dropped into
 * wp-content/mu-plugins/ (see mu-loader-sample.php) — booting is idempotent
 * and nothing here depends on plugin activation having run.
 *
 * @package Bice\MarkdownAgents
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BICE_MDA_VERSION' ) ) {
	return; // Already loaded (plugin + mu-loader both present).
}

define( 'BICE_MDA_VERSION', '1.0.0' );
define( 'BICE_MDA_DIR', __DIR__ );
define( 'BICE_MDA_FILE', __FILE__ );

/**
 * Dependencies: the bundled Composer autoloader carries league/html-to-markdown
 * and this plugin's classes. Without it we bail out loudly in admin and
 * silently on the front end — never fatally.
 */
if ( ! file_exists( BICE_MDA_DIR . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Markdown for Agents: the bundled vendor/ directory is missing. Run "composer install --no-dev" inside the plugin directory.', 'bice-markdown-agents' );
			echo '</p></div>';
		}
	);
	return;
}

require_once BICE_MDA_DIR . '/vendor/autoload.php';

/**
 * Activation guard: refuse to activate on unsupported environments instead of
 * fataling later. Only meaningful in normal-plugin mode; in mu-plugins mode
 * activation never runs, and the PHP requirement is enforced by the header.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		global $wp_version;

		$php_ok = version_compare( PHP_VERSION, '8.1', '>=' );
		$wp_ok  = version_compare( $wp_version, '6.0', '>=' );

		if ( $php_ok && $wp_ok ) {
			return;
		}

		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html(
				sprintf(
					/* translators: 1: PHP version, 2: WordPress version */
					__( 'Markdown for Agents requires PHP 8.1+ and WordPress 6.0+. This site runs PHP %1$s and WordPress %2$s.', 'bice-markdown-agents' ),
					PHP_VERSION,
					$wp_version
				)
			),
			'',
			array( 'back_link' => true )
		);
	}
);

\Bice\MarkdownAgents\Plugin::boot();

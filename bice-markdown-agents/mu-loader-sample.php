<?php
/**
 * Sample mu-plugins loader.
 *
 * WordPress only loads single PHP files from wp-content/mu-plugins/, not
 * subdirectories. To deploy this plugin as a must-use plugin (the existing
 * deployment pattern on this site):
 *
 *   1. Copy the whole bice-markdown-agents/ directory into wp-content/mu-plugins/.
 *   2. Copy THIS file to wp-content/mu-plugins/bice-markdown-agents-loader.php.
 *
 * Settings still live under Settings → Markdown for Agents; uninstall cleanup
 * (uninstall.php) never runs for mu-plugins, so remove the option by hand if
 * you ever retire it: wp option delete bice_mda_settings
 *
 * @package Bice\MarkdownAgents
 */

defined( 'ABSPATH' ) || exit;

$bice_mda_plugin = __DIR__ . '/bice-markdown-agents/bice-markdown-agents.php';

if ( file_exists( $bice_mda_plugin ) ) {
	require_once $bice_mda_plugin;
}

unset( $bice_mda_plugin );

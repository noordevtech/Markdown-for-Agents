<?php
/**
 * Clean uninstall: remove everything this plugin stored.
 *
 * @package Bice\MarkdownAgents
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bice_mda_settings' );

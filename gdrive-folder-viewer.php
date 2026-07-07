<?php

/**
 * Plugin Name:       Google Drive Folder Viewer & Policy Click Tracker
 * Plugin URI:        https://primaryictsupport.co.uk/
 * Description:       Display Google Drive policy folders with styled file links, click tracking, alerts, IP logs, and simple admin reporting.
 * Version:           1.2.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Primary ICT Support Ltd
 * Author URI:        https://primaryictsupport.co.uk/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/primaryict/google-drive-policy-linker
 * Text Domain:       gdrive-folder-viewer
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

define('GDV_VERSION', '1.2.2');
define('GDV_PLUGIN_FILE', __FILE__);
define('GDV_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GDV_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GDV_TABLE_NAME', 'gdv_clicks');
define('GDV_PRIMARY_ICT_ICON_URL', GDV_PLUGIN_URL . 'assets/images/primary-ict-support-icon.png');
define('GDV_PRIMARY_ICT_LOGO_URL', GDV_PLUGIN_URL . 'assets/images/primary-ict-support-logo.svg');

// Core includes.
require_once GDV_PLUGIN_DIR . 'includes/class-primary-ict-support-admin-menu.php';
require_once GDV_PLUGIN_DIR . 'includes/class-gdv-activator.php';
require_once GDV_PLUGIN_DIR . 'includes/class-gdv-deactivator.php';
require_once GDV_PLUGIN_DIR . 'includes/class-gdv-drive-api.php';
require_once GDV_PLUGIN_DIR . 'includes/class-gdv-shortcode.php';
require_once GDV_PLUGIN_DIR . 'includes/class-gdv-click-tracker.php';
require_once GDV_PLUGIN_DIR . 'includes/class-gdv-gemini-analyzer.php';
require_once GDV_PLUGIN_DIR . 'includes/class-gdv-admin.php';
require_once GDV_PLUGIN_DIR . 'includes/class-gdv-github-updater.php';

register_activation_hook(__FILE__, array('GDV_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('GDV_Deactivator', 'deactivate'));

/**
 * Boots the plugin's components once all plugins are loaded.
 *
 * @return void
 */
function gdv_boot_plugin()
{
	new GDV_Shortcode();
	new GDV_Click_Tracker();

	if (is_admin()) {
		new GDV_Admin();
		new GDV_GitHub_Updater();
	}
}
add_action('plugins_loaded', 'gdv_boot_plugin');

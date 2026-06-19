<?php
/**
 * Fired during plugin deactivation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDV_Deactivator {

	/**
	 * Removes the scheduled cron event. Data (settings, click logs) is left
	 * intact so the plugin can be safely re-activated later. Permanent
	 * removal happens in uninstall.php if the relevant setting is enabled.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'gdv_check_click_threshold' );
	}
}

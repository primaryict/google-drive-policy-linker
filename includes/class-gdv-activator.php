<?php
/**
 * Fired during plugin activation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDV_Activator {

	/**
	 * Creates the clicks table, sets sensible default options, and schedules
	 * the hourly cron job that checks the click threshold.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		$table_name      = $wpdb->prefix . GDV_TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			folder_id varchar(100) NOT NULL DEFAULT '',
			file_id varchar(100) NOT NULL DEFAULT '',
			file_name varchar(255) NOT NULL DEFAULT '',
			ip_address varchar(45) NOT NULL DEFAULT '',
			clicked_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY folder_id (folder_id),
			KEY ip_address (ip_address),
			KEY clicked_at (clicked_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Sensible defaults. add_option() is a no-op if the option already exists,
		// so re-activating the plugin will not clobber existing settings.
		add_option( 'gdv_api_key', '' );
		add_option( 'gdv_cache_hours', 6 );
		add_option( 'gdv_click_threshold', 20 );
		add_option( 'gdv_threshold_window_hours', 24 );
		add_option( 'gdv_alert_cooldown_hours', 24 );
		add_option( 'gdv_alert_emails', get_option( 'admin_email' ) );
		add_option( 'gdv_gemini_api_key', '' );
		add_option( 'gdv_gemini_model', GDV_Gemini_Analyzer::DEFAULT_MODEL );
		add_option( 'gdv_gemini_confidence_threshold', 75 );
		add_option( 'gdv_delete_data_on_uninstall', 0 );
		add_option( 'gdv_known_folders', array() );
		add_option( 'gdv_last_alert_sent', '' );
		add_option( 'gdv_design_bg_color', '#ffffff' );
		add_option( 'gdv_design_hover_bg_color', '#f3f7fb' );
		add_option( 'gdv_design_text_color', '#1f2937' );
		add_option( 'gdv_design_hover_text_color', '#0b5cab' );
		add_option( 'gdv_design_meta_color', '#646970' );
		add_option( 'gdv_design_border_color', '#d9e2ec' );
		add_option( 'gdv_design_text_size', 16 );
		add_option( 'gdv_design_meta_size', 13 );
		add_option( 'gdv_design_icon_size', 38 );
		add_option( 'gdv_design_row_padding', 14 );
		add_option( 'gdv_design_border_radius', 8 );
		add_option( 'gdv_design_font_family', 'inherit' );

		if ( ! wp_next_scheduled( 'gdv_check_click_threshold' ) ) {
			wp_schedule_event( time(), 'hourly', 'gdv_check_click_threshold' );
		}
	}
}

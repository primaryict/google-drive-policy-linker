<?php
/**
 * Removes plugin data when the user has enabled deletion on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$delete_data = (int) get_option( 'gdv_delete_data_on_uninstall', 0 );

if ( 1 !== $delete_data ) {
	return;
}

global $wpdb;

$table = $wpdb->prefix . 'gdv_clicks';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$gemini_table = $wpdb->prefix . 'gdv_gemini_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$gemini_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$known_folders = get_option( 'gdv_known_folders', array() );
if ( is_array( $known_folders ) ) {
	foreach ( array_keys( $known_folders ) as $folder_id ) {
		delete_transient( 'gdv_folder_' . md5( $folder_id ) );
	}
}

delete_option( 'gdv_api_key' );
delete_option( 'gdv_cache_hours' );
delete_option( 'gdv_click_threshold' );
delete_option( 'gdv_threshold_window_hours' );
delete_option( 'gdv_alert_cooldown_hours' );
delete_option( 'gdv_alert_emails' );
delete_option( 'gdv_gemini_api_key' );
delete_option( 'gdv_gemini_model' );
delete_option( 'gdv_gemini_data_days' );
delete_option( 'gdv_gemini_confidence_threshold' );
delete_option( 'gdv_delete_data_on_uninstall' );
delete_option( 'gdv_known_folders' );
delete_option( 'gdv_last_alert_sent' );
delete_option( 'gdv_design_bg_color' );
delete_option( 'gdv_design_hover_bg_color' );
delete_option( 'gdv_design_text_color' );
delete_option( 'gdv_design_hover_text_color' );
delete_option( 'gdv_design_meta_color' );
delete_option( 'gdv_design_border_color' );
delete_option( 'gdv_design_text_size' );
delete_option( 'gdv_design_meta_size' );
delete_option( 'gdv_design_icon_size' );
delete_option( 'gdv_design_row_padding' );
delete_option( 'gdv_design_border_radius' );
delete_option( 'gdv_design_font_family' );

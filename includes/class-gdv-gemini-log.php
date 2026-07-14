<?php
/**
 * Stores Gemini review outcomes for admin reporting.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDV_Gemini_Log {

	/**
	 * Creates or updates the Gemini log table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table           = $wpdb->prefix . GDV_GEMINI_TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(30) NOT NULL DEFAULT '',
			status varchar(30) NOT NULL DEFAULT '',
			trigger_count int unsigned NOT NULL DEFAULT 0,
			trigger_window_hours int unsigned NOT NULL DEFAULT 0,
			data_window_days int unsigned NOT NULL DEFAULT 0,
			date_range_start datetime DEFAULT NULL,
			date_range_end datetime DEFAULT NULL,
			model varchar(100) NOT NULL DEFAULT '',
			inspection_likely tinyint(1) NOT NULL DEFAULT 0,
			confidence_score int unsigned NOT NULL DEFAULT 0,
			alert_sent tinyint(1) NOT NULL DEFAULT 0,
			triggering_user varchar(100) NOT NULL DEFAULT '',
			reason text NULL,
			recommended_action text NULL,
			error_message text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY source (source),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Inserts a Gemini review log row.
	 *
	 * @param array $data Log data.
	 * @return int Inserted row ID.
	 */
	public static function insert( $data ) {
		global $wpdb;

		self::create_table();

		$table    = $wpdb->prefix . GDV_GEMINI_TABLE_NAME;
		$analysis = isset( $data['analysis'] ) && is_array( $data['analysis'] ) ? $data['analysis'] : array();

		$row = array(
			'source'               => isset( $data['source'] ) ? sanitize_key( $data['source'] ) : '',
			'status'               => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : '',
			'trigger_count'        => isset( $data['trigger_count'] ) ? absint( $data['trigger_count'] ) : 0,
			'trigger_window_hours' => isset( $data['trigger_window_hours'] ) ? absint( $data['trigger_window_hours'] ) : 0,
			'data_window_days'     => isset( $data['data_window_days'] ) ? absint( $data['data_window_days'] ) : 0,
			'date_range_start'     => ! empty( $data['date_range_start'] ) ? sanitize_text_field( $data['date_range_start'] ) : null,
			'date_range_end'       => ! empty( $data['date_range_end'] ) ? sanitize_text_field( $data['date_range_end'] ) : null,
			'model'                => isset( $data['model'] ) ? sanitize_text_field( $data['model'] ) : '',
			'inspection_likely'    => ! empty( $analysis['inspection_likely'] ) ? 1 : 0,
			'confidence_score'     => isset( $analysis['confidence_score'] ) ? max( 0, min( 100, absint( $analysis['confidence_score'] ) ) ) : 0,
			'alert_sent'           => ! empty( $data['alert_sent'] ) ? 1 : 0,
			'triggering_user'      => isset( $analysis['triggering_user'] ) ? sanitize_text_field( $analysis['triggering_user'] ) : '',
			'reason'               => isset( $analysis['reason'] ) ? sanitize_textarea_field( $analysis['reason'] ) : '',
			'recommended_action'   => isset( $analysis['recommended_action'] ) ? sanitize_textarea_field( $analysis['recommended_action'] ) : '',
			'error_message'        => isset( $data['error_message'] ) ? sanitize_textarea_field( $data['error_message'] ) : '',
			'created_at'           => gmdate( 'Y-m-d H:i:s' ),
		);

		$inserted = $wpdb->insert(
			$table,
			$row,
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Fetches recent Gemini review log rows.
	 *
	 * @param int $limit Number of rows.
	 * @return array
	 */
	public static function get_recent( $limit = 50 ) {
		global $wpdb;

		self::create_table();

		$table = $wpdb->prefix . GDV_GEMINI_TABLE_NAME;
		$limit = max( 1, min( 200, (int) $limit ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			)
		);
	}
}

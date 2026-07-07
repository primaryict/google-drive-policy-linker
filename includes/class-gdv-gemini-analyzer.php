<?php
/**
 * Sends anonymized click activity to Gemini for pattern analysis.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDV_Gemini_Analyzer {

	const DEFAULT_MODEL = 'gemini-3.5-flash';

	/**
	 * Returns whether Gemini analysis has enough configuration to run.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->get_api_key();
	}

	/**
	 * Analyzes recent click activity.
	 *
	 * @param int $window_hours Lookback window.
	 * @return array|WP_Error
	 */
	public function analyze_recent_activity( $window_hours ) {
		$window_hours = max( 1, (int) $window_hours );
		$end_utc      = gmdate( 'Y-m-d H:i:s' );
		$start_utc    = gmdate( 'Y-m-d H:i:s', time() - ( $window_hours * HOUR_IN_SECONDS ) );

		return $this->analyze_activity_between(
			$start_utc,
			$end_utc,
			array(
				'window_hours' => $window_hours,
			)
		);
	}

	/**
	 * Analyzes click activity for a selected local date range.
	 *
	 * @param string $start_date Start date in Y-m-d format.
	 * @param string $end_date   End date in Y-m-d format.
	 * @return array|WP_Error
	 */
	public function analyze_date_range( $start_date, $end_date ) {
		$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start_date . ' 00:00:00', wp_timezone() );
		$end   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $end_date . ' 23:59:59', wp_timezone() );

		if ( false === $start || false === $end || $start->format( 'Y-m-d' ) !== $start_date || $end->format( 'Y-m-d' ) !== $end_date ) {
			return new WP_Error( 'gdv_gemini_invalid_dates', __( 'Please choose a valid date range.', 'gdrive-folder-viewer' ) );
		}

		if ( $end < $start ) {
			return new WP_Error( 'gdv_gemini_invalid_date_order', __( 'The end date must be on or after the start date.', 'gdrive-folder-viewer' ) );
		}

		$start_utc = $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$end_utc   = $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		return $this->analyze_activity_between(
			$start_utc,
			$end_utc,
			array(
				'date_range' => array(
					'start_date' => $start_date,
					'end_date'   => $end_date,
					'timezone'   => wp_timezone_string(),
				),
			)
		);
	}

	/**
	 * Sends an anonymized click data set to Gemini.
	 *
	 * @param string $start_utc Start datetime in UTC.
	 * @param string $end_utc   End datetime in UTC.
	 * @param array  $context   Extra context for Gemini.
	 * @return array|WP_Error
	 */
	private function analyze_activity_between( $start_utc, $end_utc, $context = array() ) {
		$api_key = $this->get_api_key();

		if ( '' === $api_key ) {
			return new WP_Error( 'gdv_gemini_missing_key', __( 'Gemini API key is not configured.', 'gdrive-folder-viewer' ) );
		}

		$click_data = $this->get_anonymized_click_data_between( $start_utc, $end_utc, $context );

		if ( empty( $click_data['users'] ) ) {
			return new WP_Error( 'gdv_gemini_no_data', __( 'No click data is available for Gemini analysis in this date range.', 'gdrive-folder-viewer' ) );
		}

		$response = wp_remote_post(
			$this->get_endpoint( $api_key ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $this->get_request_body( $click_data ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $response_code ) {
			return new WP_Error(
				'gdv_gemini_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: Gemini model name */
					__( 'Gemini API returned HTTP status %1$d for model %2$s.', 'gdrive-folder-viewer' ),
					$response_code,
					$this->get_model()
				)
			);
		}

		return $this->parse_response( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Returns whether an analysis result should trigger an email.
	 *
	 * @param array $analysis Analysis result.
	 * @return bool
	 */
	public function should_send_alert( $analysis ) {
		$threshold = max( 0, min( 100, (int) get_option( 'gdv_gemini_confidence_threshold', 75 ) ) );

		return ! empty( $analysis['inspection_likely'] ) && (int) $analysis['confidence_score'] >= $threshold;
	}

	/**
	 * Gets recent click data grouped by anonymized visitor.
	 *
	 * @param int $window_hours Lookback window.
	 * @return array
	 */
	private function get_anonymized_click_data_between( $start_utc, $end_utc, $context = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . GDV_TABLE_NAME;
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT file_name, ip_address, clicked_at FROM {$table} WHERE clicked_at BETWEEN %s AND %s ORDER BY clicked_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$start_utc,
				$end_utc
			)
		);

		$user_map = array();
		$users    = array();

		foreach ( $rows as $row ) {
			$ip_hash = hash( 'sha256', (string) $row->ip_address . wp_salt( 'nonce' ) );

			if ( ! isset( $user_map[ $ip_hash ] ) ) {
				$user_map[ $ip_hash ] = 'User_' . ( count( $user_map ) + 1 );
			}

			$user_key = $user_map[ $ip_hash ];

			if ( ! isset( $users[ $user_key ] ) ) {
				$users[ $user_key ] = array(
					'total_clicks' => 0,
					'documents'    => array(),
					'events'       => array(),
				);
			}

			$file_name = ! empty( $row->file_name ) ? $row->file_name : __( 'Unknown document', 'gdrive-folder-viewer' );
			$users[ $user_key ]['total_clicks']++;

			if ( ! isset( $users[ $user_key ]['documents'][ $file_name ] ) ) {
				$users[ $user_key ]['documents'][ $file_name ] = 0;
			}

			$users[ $user_key ]['documents'][ $file_name ]++;
			$users[ $user_key ]['events'][] = array(
				'time_utc' => $row->clicked_at,
				'document' => $file_name,
			);
		}

		return array_merge(
			array(
				'start_utc' => $start_utc,
				'end_utc'   => $end_utc,
				'users'     => $users,
			),
			$context
		);
	}

	/**
	 * Builds the Gemini API request body.
	 *
	 * @param array $click_data Anonymized click data.
	 * @return array
	 */
	private function get_request_body( $click_data ) {
		$prompt = "You are a data analysis bot for a UK school. Analyze document click logs for patterns that may indicate Ofsted inspection preparation. Look for: 1) one anonymized user clicking 15 or more statutory policies within an hour, 2) targeted clicks of SEND, Behaviour, Safeguarding, Attendance, Curriculum, Complaints, or similar statutory policies in quick succession, and 3) unusual breadth of policy review by a small number of users. Ignore obvious bot-like speeds faster than one click every five seconds. The input uses anonymized user labels only; no real IP addresses are provided.\n\n";
		$prompt .= "Return ONLY valid JSON using this exact schema: {\"inspection_likely\": boolean, \"confidence_score\": integer, \"triggering_user\": \"string\", \"reason\": \"string\", \"recommended_action\": \"string\"}.\n\n";
		$prompt .= "Click data:\n" . wp_json_encode( $click_data );

		return array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'response_mime_type' => 'application/json',
				'temperature'        => 0.1,
			),
		);
	}

	/**
	 * Parses Gemini's JSON response.
	 *
	 * @param string $body Response body.
	 * @return array|WP_Error
	 */
	private function parse_response( $body ) {
		$data = json_decode( $body, true );

		if ( empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error( 'gdv_gemini_invalid_response', __( 'Gemini API response did not include analysis text.', 'gdrive-folder-viewer' ) );
		}

		$analysis = json_decode( $data['candidates'][0]['content']['parts'][0]['text'], true );

		if ( ! is_array( $analysis ) ) {
			return new WP_Error( 'gdv_gemini_invalid_json', __( 'Gemini API response was not valid JSON.', 'gdrive-folder-viewer' ) );
		}

		return array(
			'inspection_likely'  => ! empty( $analysis['inspection_likely'] ),
			'confidence_score'   => isset( $analysis['confidence_score'] ) ? max( 0, min( 100, (int) $analysis['confidence_score'] ) ) : 0,
			'triggering_user'    => isset( $analysis['triggering_user'] ) ? sanitize_text_field( $analysis['triggering_user'] ) : '',
			'reason'             => isset( $analysis['reason'] ) ? sanitize_textarea_field( $analysis['reason'] ) : '',
			'recommended_action' => isset( $analysis['recommended_action'] ) ? sanitize_textarea_field( $analysis['recommended_action'] ) : '',
		);
	}

	/**
	 * Returns the Gemini endpoint for the configured model.
	 *
	 * @param string $api_key Gemini API key.
	 * @return string
	 */
	private function get_endpoint( $api_key ) {
		return add_query_arg(
			'key',
			$api_key,
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->get_model() ) . ':generateContent'
		);
	}

	/**
	 * Returns the configured Gemini model.
	 *
	 * @return string
	 */
	public function get_model() {
		$model = trim( (string) get_option( 'gdv_gemini_model', self::DEFAULT_MODEL ) );

		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * Returns the configured Gemini API key.
	 *
	 * @return string
	 */
	private function get_api_key() {
		return trim( (string) get_option( 'gdv_gemini_api_key', '' ) );
	}
}

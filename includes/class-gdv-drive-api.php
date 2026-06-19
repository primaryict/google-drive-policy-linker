<?php

/**
 * Handles all communication with the Google Drive API, with results cached
 * in WordPress transients so we stay well within Google's API quotas.
 */

if (! defined('ABSPATH')) {
	exit;
}

class GDV_Drive_API
{

	const API_BASE = 'https://www.googleapis.com/drive/v3/files';

	/**
	 * Returns the contents of a Google Drive folder, using a cached
	 * transient when available.
	 *
	 * Note: this uses a simple API key (not OAuth), so the target folder
	 * must be shared as "Anyone with the link can view" in Google Drive.
	 *
	 * @param string $folder_id   Google Drive folder ID.
	 * @param int    $cache_hours How many hours to cache the result for.
	 * @return array|WP_Error List of file arrays, or WP_Error on failure.
	 */
	public function get_folder_contents($folder_id, $cache_hours)
	{
		$folder_id = sanitize_text_field($folder_id);

		if (empty($folder_id)) {
			return new WP_Error('gdv_no_folder_id', __('No Google Drive folder ID was supplied.', 'gdrive-folder-viewer'));
		}

		$transient_key = $this->get_transient_key($folder_id);
		$cached        = get_transient($transient_key);

		if (false !== $cached) {
			return $cached;
		}

		$result = $this->fetch_from_api($folder_id);

		if (is_wp_error($result)) {
			return $result;
		}

		$cache_hours = max(1, (int) $cache_hours);
		set_transient($transient_key, $result, $cache_hours * HOUR_IN_SECONDS);

		return $result;
	}

	/**
	 * Forces a fresh fetch from Google Drive, bypassing and refreshing the cache.
	 *
	 * @param string $folder_id   Google Drive folder ID.
	 * @param int    $cache_hours How many hours to cache the refreshed result for.
	 * @return array|WP_Error
	 */
	public function refresh_folder_contents($folder_id, $cache_hours)
	{
		$this->clear_cache($folder_id);
		return $this->get_folder_contents($folder_id, $cache_hours);
	}

	/**
	 * Deletes the cached transient for a single folder.
	 *
	 * @param string $folder_id Google Drive folder ID.
	 * @return void
	 */
	public function clear_cache($folder_id)
	{
		delete_transient($this->get_transient_key($folder_id));
	}

	/**
	 * Builds the transient key used for a given folder ID.
	 *
	 * @param string $folder_id Google Drive folder ID.
	 * @return string
	 */
	private function get_transient_key($folder_id)
	{
		return 'gdv_folder_' . md5($folder_id);
	}

	/**
	 * Performs the actual HTTP request(s) to the Google Drive API, following
	 * pagination until all files have been retrieved.
	 *
	 * @param string $folder_id Google Drive folder ID.
	 * @return array|WP_Error
	 */
	private function fetch_from_api($folder_id)
	{
		$api_key = trim( (string) get_option( 'gdv_api_key', '' ) );

		if (empty($api_key)) {
			return new WP_Error(
				'gdv_no_api_key',
				__( 'A Google API key has not been configured yet. Add one under Primary ICT Support > Drive Folder Viewer.', 'gdrive-folder-viewer' )
			);
		}

		$files      = array();
		$page_token = '';
		$max_pages  = 20; // Safety limit: up to 2,000 files.
		$page       = 0;

		do {
			$page++;

			$query_args = array(
				'q'        => "'" . $folder_id . "' in parents and trashed = false",
				'key'      => $api_key,
				'fields'   => 'nextPageToken, files(id, name, mimeType, webViewLink, webContentLink, iconLink, modifiedTime, size)',
				'pageSize' => 100,
				'orderBy'  => 'folder,name',
			);

			if (! empty($page_token)) {
				$query_args['pageToken'] = $page_token;
			}

			$url = add_query_arg($query_args, self::API_BASE);

			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 15,
				)
			);

			if (is_wp_error($response)) {
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code($response);
			$body        = json_decode(wp_remote_retrieve_body($response), true);

			if (200 !== (int) $status_code) {
				$message = (is_array($body) && isset($body['error']['message']))
					? $body['error']['message']
					: __('Unknown error returned by the Google Drive API.', 'gdrive-folder-viewer');

				return new WP_Error('gdv_api_error', $message, array('status' => $status_code));
			}

			if (! empty($body['files']) && is_array($body['files'])) {
				$files = array_merge($files, $body['files']);
			}

			$page_token = isset($body['nextPageToken']) ? $body['nextPageToken'] : '';
		} while (! empty($page_token) && $page < $max_pages);

		return $files;
	}
}

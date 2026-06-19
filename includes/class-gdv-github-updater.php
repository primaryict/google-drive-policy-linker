<?php
/**
 * Adds GitHub Releases support to the WordPress plugin updater.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GDV_GitHub_Updater {

	const OWNER       = 'primaryict';
	const REPO        = 'google-drive-policy-linker';
	const API_URL     = 'https://api.github.com/repos/primaryict/google-drive-policy-linker/releases/latest';
	const CACHE_KEY   = 'gdv_github_latest_release';
	const CACHE_HOURS = 6;

	/**
	 * Plugin basename used by WordPress update responses.
	 *
	 * @var string
	 */
	private $plugin_basename;

	public function __construct() {
		$this->plugin_basename = plugin_basename( GDV_PLUGIN_FILE );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_source_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_release_cache' ), 10, 2 );
	}

	/**
	 * Adds this plugin to WordPress' update list when GitHub has a newer tag.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		$release = $this->get_latest_release();

		if ( empty( $release ) ) {
			return $transient;
		}

		$latest_version = $this->get_release_version( $release );

		if ( empty( $latest_version ) || ! version_compare( $latest_version, GDV_VERSION, '>' ) ) {
			return $transient;
		}

		$package_url = $this->get_package_url( $release );

		if ( empty( $package_url ) ) {
			return $transient;
		}

		$transient->response[ $this->plugin_basename ] = (object) array(
			'id'          => $this->plugin_basename,
			'slug'        => dirname( $this->plugin_basename ),
			'plugin'      => $this->plugin_basename,
			'new_version' => $latest_version,
			'url'         => 'https://github.com/' . self::OWNER . '/' . self::REPO,
			'package'     => $package_url,
			'tested'      => '',
			'requires'    => '5.8',
			'requires_php'=> '7.4',
		);

		return $transient;
	}

	/**
	 * Supplies the "View version details" modal content.
	 *
	 * @param false|object|array $result Current result.
	 * @param string             $action API action.
	 * @param object             $args   API args.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( $this->plugin_basename ) !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( empty( $release ) ) {
			return $result;
		}

		$version = $this->get_release_version( $release );
		$notes   = ! empty( $release['body'] ) ? wp_kses_post( wpautop( $release['body'] ) ) : esc_html__( 'No release notes were provided.', 'gdrive-folder-viewer' );

		return (object) array(
			'name'          => 'Google Drive Folder Viewer & Policy Click Tracker',
			'slug'          => dirname( $this->plugin_basename ),
			'version'       => $version,
			'author'        => '<a href="https://primaryictsupport.co.uk/">Primary ICT Support Ltd</a>',
			'homepage'      => 'https://github.com/' . self::OWNER . '/' . self::REPO,
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'download_link' => $this->get_package_url( $release ),
			'sections'      => array(
				'description' => esc_html__( 'Display Google Drive policy folders with styled file links, click tracking, alerts, IP logs, and simple admin reporting.', 'gdrive-folder-viewer' ),
				'changelog'   => $notes,
			),
		);
	}

	/**
	 * Clears cached release metadata after plugin updates.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Upgrade options.
	 * @return void
	 */
	public function clear_release_cache( $upgrader, $options ) {
		if ( empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}

		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Keeps GitHub source zips installed in the expected plugin directory.
	 *
	 * @param string      $source        Extracted source path.
	 * @param string      $remote_source Upgrade temp directory.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Upgrade context.
	 * @return string
	 */
	public function normalize_source_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {
			return $source;
		}

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			return $source;
		}

		$expected_folder = dirname( $this->plugin_basename );
		$current_folder  = basename( untrailingslashit( $source ) );

		if ( $expected_folder === $current_folder ) {
			return $source;
		}

		$destination = trailingslashit( $remote_source ) . $expected_folder;

		if ( $wp_filesystem->exists( $destination ) ) {
			$wp_filesystem->delete( $destination, true );
		}

		if ( $wp_filesystem->move( $source, $destination, true ) ) {
			return trailingslashit( $destination );
		}

		return $source;
	}

	/**
	 * Gets the latest GitHub release, cached to avoid rate limits.
	 *
	 * @return array
	 */
	private function get_latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::API_URL,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Primary-ICT-Support-WordPress-Updater',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return array();
		}

		set_site_transient( self::CACHE_KEY, $release, self::CACHE_HOURS * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Extracts a semantic version from a GitHub release tag.
	 *
	 * @param array $release GitHub release data.
	 * @return string
	 */
	private function get_release_version( $release ) {
		return ltrim( (string) $release['tag_name'], 'vV' );
	}

	/**
	 * Finds the best installable zip for the release.
	 *
	 * @param array $release GitHub release data.
	 * @return string
	 */
	private function get_package_url( $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
					continue;
				}

				if ( false !== strpos( $asset['name'], self::REPO ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
					return $asset['browser_download_url'];
				}
			}

			foreach ( $release['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && ! empty( $asset['name'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
					return $asset['browser_download_url'];
				}
			}
		}

		return ! empty( $release['zipball_url'] ) ? $release['zipball_url'] : '';
	}
}

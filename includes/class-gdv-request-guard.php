<?php
/** Atomic, expiring reservations shared by concurrent WordPress requests. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class GDV_Request_Guard {
	public static function acquire( $name, $seconds ) {
		global $wpdb;
		$old = get_option( $name, '' );
		if ( $old && (int) $old < time() ) {
			// Compare and delete: never remove a reservation another worker replaced.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $old ) );
			wp_cache_delete( $name, 'options' );
		}
		$token = ( time() + $seconds ) . ':' . wp_generate_uuid4();
		return add_option( $name, $token, '', false ) ? $token : false;
	}

	public static function release( $name, $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $token ) );
		wp_cache_delete( $name, 'options' );
	}
}

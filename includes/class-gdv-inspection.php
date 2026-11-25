<?php
/** Public Ofsted data and conservative inspection-window eligibility. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class GDV_Inspection {
	const SOURCE = 'https://www.gov.uk/government/statistical-data-sets/monthly-management-information-ofsteds-school-inspections-outcomes';
	public static function enabled() { return 1 === (int) get_option( 'gdv_inspection_enabled', 0 ); }
	public static function urn( $value ) { return is_scalar( $value ) && preg_match( '/^\d{6}$/D', trim( (string) $value ) ) ? trim( (string) $value ) : ''; }
	public static function date( $value, $format = 'Y-m-d' ) {
		if ( ! is_string( $value ) ) { return false; }
		$date = DateTimeImmutable::createFromFormat( '!' . $format, $value, new DateTimeZone( 'Europe/London' ) );
		return $date && $date->format( $format ) === $value ? $date : false;
	}
	public static function sanitize_date( $value ) { return self::date( $value ) ? $value : ''; }
	public static function links( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) { return array(); }
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( $html ?: '<html></html>', LIBXML_NONET );
		libxml_clear_errors(); libxml_use_internal_errors( $previous );
		$links = array();
		foreach ( $doc->getElementsByTagName( 'a' ) as $a ) { $links[] = array( 'url' => $a->getAttribute( 'href' ), 'text' => trim( $a->textContent ) ); }
		return $links;
	}
	/** Only official school-specific links are evidence; never guess from a name. */
	public static function detect_html( $html ) {
		$urns = array();
		foreach ( self::links( $html ) as $link ) {
			$p = parse_url( $link['url'] ); $urn = '';
			$host = strtolower( $p['host'] ?? '' );
			if ( 'reports.ofsted.gov.uk' === $host && preg_match( '~^/provider/21/(\d{6})/?$~', $p['path'] ?? '', $m ) ) { $urn = $m[1]; }
			if ( in_array( $host, array( 'get-information-schools.service.gov.uk', 'www.get-information-schools.service.gov.uk' ), true ) && '/Establishments/Establishment/Details' === ( $p['path'] ?? '' ) ) {
				parse_str( $p['query'] ?? '', $q ); $urn = self::urn( $q['urn'] ?? '' );
			}
			if ( $urn ) { $urns[ $urn ] = $urn; }
		}
		return count( $urns ) === 1 ? (string) reset( $urns ) : new WP_Error( 'match', 'No unique school link was found on the homepage. Enter the school URN manually.' );
	}
	public static function detect() {
		$r = wp_safe_remote_get( home_url( '/' ), array( 'timeout' => 15, 'limit_response_size' => 2097152 ) );
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) { return new WP_Error( 'detect', 'The public homepage could not be read. Use the manual URN field, especially on local or staging sites.' ); }
		return self::detect_html( wp_remote_retrieve_body( $r ) );
	}
	public static function attachment( $html ) {
		$found = array();
		foreach ( self::links( $html ) as $link ) {
			$p = parse_url( $link['url'] );
			if ( 'https' !== ( $p['scheme'] ?? '' ) || 'assets.publishing.service.gov.uk' !== ( $p['host'] ?? '' ) || ! preg_match( '/\.csv$/i', $p['path'] ?? '' ) ) { continue; }
			if ( preg_match( '/state-funded schools\s*-\s*latest inspections as at (\d{1,2} [a-z]+ 20\d{2})/i', $link['text'], $m ) ) {
				$time = strtotime( $m[1] ); if ( $time ) { $found[ $time ] = array( 'url' => $link['url'], 'as_at' => gmdate( 'Y-m-d', $time ) ); }
			}
		}
		if ( ! $found ) { return new WP_Error( 'source', 'The latest Ofsted CSV could not be located. Inspection alerts are paused.' ); }
		krsort( $found ); return reset( $found );
	}
	public static function parse_csv( $stream, $urn ) {
		$header = fgetcsv( $stream );
		if ( ! $header ) { return new WP_Error( 'csv', 'The Ofsted download is empty.' ); }
		$header[0] = ltrim( $header[0], "\xEF\xBB\xBF" );
		foreach ( array( 'URN', 'School name', 'Inspection start date', 'Date of latest ungraded inspection' ) as $key ) {
			if ( ! in_array( $key, $header, true ) ) { return new WP_Error( 'schema', 'The Ofsted data columns have changed. Inspection alerts are paused.' ); }
		}
		$index = array_search( 'URN', $header, true ); $match = null;
		while ( false !== ( $values = fgetcsv( $stream ) ) ) {
			if ( ( $values[ $index ] ?? '' ) !== $urn ) { continue; }
			if ( $match || count( $values ) !== count( $header ) ) { return new WP_Error( 'csv', 'The school record is ambiguous or incomplete.' ); }
			$match = array_combine( $header, $values );
		}
		return $match ?: new WP_Error( 'school', 'URN not found in the public state-funded schools dataset. Check the URN or use a verified expected-date override.' );
	}
	public static function clear_cache() { delete_transient( 'gdv_ofsted_record' ); }
	public static function record( $urn, $force = false ) {
		$cache = get_transient( 'gdv_ofsted_record' );
		if ( ! $force && is_array( $cache ) && $urn === $cache['urn'] ) { return $cache['result']; }
		$lock = GDV_Request_Guard::acquire( 'gdv_ofsted_lock', 120 );
		if ( ! $lock ) { return new WP_Error( 'busy', 'Ofsted data is being refreshed. Please try again shortly.' ); }
		try {
			$result = self::download_record( $urn );
			set_transient( 'gdv_ofsted_record', array( 'urn' => $urn, 'result' => $result ), is_wp_error( $result ) ? 300 : 86400 );
			return $result;
		} finally { GDV_Request_Guard::release( 'gdv_ofsted_lock', $lock ); }
	}
	private static function download_record( $urn ) {
		if ( ! self::urn( $urn ) ) { return new WP_Error( 'urn', 'Enter and save a valid six-digit school URN.' ); }
		$r = wp_safe_remote_get( self::SOURCE, array( 'timeout' => 20, 'limit_response_size' => 2097152 ) );
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) { return new WP_Error( 'source', 'Ofsted public data is unavailable. Inspection alerts are paused.' ); }
		$attachment = self::attachment( wp_remote_retrieve_body( $r ) );
		if ( is_wp_error( $attachment ) ) { return $attachment; }
		if ( ! function_exists( 'wp_tempnam' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
		$file = wp_tempnam( 'gdv-ofsted.csv' );
		if ( ! $file ) { return new WP_Error( 'temp', 'Cannot create a temporary Ofsted download.' ); }
		try {
			$r = wp_safe_remote_get( $attachment['url'], array( 'timeout' => 60, 'stream' => true, 'filename' => $file, 'limit_response_size' => 41943040 ) );
			if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) || filesize( $file ) >= 41943040 ) { return new WP_Error( 'download', 'The Ofsted CSV download failed or exceeded the size limit. Inspection alerts are paused.' ); }
			$f = fopen( $file, 'r' );
			if ( ! $f ) { return new WP_Error( 'read', 'Cannot read the Ofsted download.' ); }
			try { $row = self::parse_csv( $f, $urn ); } finally { fclose( $f ); }
			if ( ! is_wp_error( $row ) ) { $row['_as_at'] = $attachment['as_at']; $row['_source'] = $attachment['url']; }
			return $row;
		} finally { wp_delete_file( $file ); }
	}
	private static function shift_months( $date, $months ) {
		$first = $date->modify( 'first day of this month' )->modify( sprintf( '%+d months', $months ) );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'm' ), min( (int) $date->format( 'd' ), (int) $first->format( 't' ) ) );
	}
	public static function evaluate( $row, $today = null, $override = '' ) {
		$today = $today ?: new DateTimeImmutable( 'today', new DateTimeZone( 'Europe/London' ) );
		$unknown = array( 'status' => 'unknown', 'reason' => 'No safe four-year estimate is available for this record. Verify the school and set an expected-date override. Inspection alerts are paused.' );
		$due = self::date( $override ); $latest = null;
		if ( ! $due ) {
			if ( is_wp_error( $row ) ) { return array( 'status' => 'unknown', 'reason' => $row->get_error_message() ); }
			$as_at = self::date( $row['_as_at'] ?? '' );
			if ( ! $as_at || $as_at < $today->modify( '-100 days' ) ) { return array( 'status' => 'unknown', 'reason' => 'Ofsted data is missing a date or is over 100 days old. Inspection alerts are paused.' ); }
			$types = array(
				array( 'Inspection start date', 'Does the latest full inspection relate to the URN of the current school?', 'full' ),
				array( 'Inspection start date of latest OEIF graded inspection', 'Does the latest OEIF graded inspection relate to the URN of the current school?', 'graded' ),
				array( 'Date of latest ungraded inspection', 'Does the ungraded inspection relate to the URN of the current school?', 'ungraded' ),
			);
			$selected = null;
			foreach ( $types as $type ) {
				$date = self::date( $row[ $type[0] ] ?? '', 'd/m/Y' );
				if ( $date && ( ! $latest || $date > $latest ) ) { $latest = $date; $selected = $type; }
			}
			if ( ! $latest || $latest > $today || 'Yes' !== ( $row[ $selected[1] ] ?? '' ) ) { return $unknown; }
			$type_field = 'full' === $selected[2] ? 'Inspection type' : 'Inspection type of latest OEIF graded inspection';
			if ( 'ungraded' !== $selected[2] && preg_match( '/monitor/i', $row[ $type_field ] ?? '' ) ) { return $unknown; }
			$concern = trim( $row['Most recent category of concern'] ?? '' );
			if ( ! in_array( $concern, array( '', 'NULL', 'None', 'Not applicable' ), true ) ) { return $unknown; }
			if ( 'graded' === $selected[2] && ! in_array( $row['Latest OEIF overall effectiveness'] ?? '', array( '1', '2' ), true ) ) { return $unknown; }
			if ( 'ungraded' === $selected[2] && ! in_array( $row['Ungraded inspection overall outcome'] ?? '', array( 'School remains Good', 'School remains Outstanding', 'Standards maintained', 'Improved significantly' ), true ) ) { return $unknown; }
			if ( 'full' === $selected[2] ) {
				if ( 'Met' !== ( $row['Safeguarding standards'] ?? '' ) ) { return $unknown; }
				foreach ( array( 'Early years (where applicable)', 'Post-16 provision (where applicable)' ) as $field ) {
					if ( in_array( $row[ $field ] ?? '', array( 'Needs attention', 'Urgent improvement' ), true ) ) { return $unknown; }
				}
				foreach ( array( 'Inclusion', 'Curriculum and teaching', 'Achievement', 'Attendance and behaviour', 'Personal development and wellbeing', 'Leadership and governance' ) as $field ) {
					if ( ! in_array( $row[ $field ] ?? '', array( 'Expected standard', 'Strong standard', 'Exceptional' ), true ) ) { return $unknown; }
				}
			}
			$due = self::shift_months( $latest, 48 );
		}
		$from = $due->modify( '-6 weeks' );
		return array( 'status' => $today >= $from ? 'eligible' : 'outside', 'reason' => $today >= $from ? 'Within six weeks of the estimated inspection date, or overdue.' : 'Outside the six-week inspection lead-in period.', 'expected' => $due->format( 'j F Y' ), 'from' => $from->format( 'j F Y' ), 'latest' => $latest ? $latest->format( 'j F Y' ) : 'Manual expected-date override', 'window' => $due->format( 'j F Y' ) );
	}
	public static function check() {
		if ( ! self::enabled() ) { return array( 'status' => 'disabled', 'reason' => 'Inspection window check is disabled.' ); }
		$urn = self::urn( get_option( 'gdv_inspection_urn', '' ) );
		if ( ! $urn ) { return array( 'status' => 'unknown', 'reason' => 'Save a school URN before enabling inspection alerts.' ); }
		$override = get_option( 'gdv_inspection_expected', '' );
		$row = $override ? array() : self::record( $urn );
		return self::evaluate( $row, null, $override );
	}
}

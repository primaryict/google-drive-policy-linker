<?php
/** Isolated tests: no database, email or network access. */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'ABSPATH', __DIR__ );
$options = array(); $transients = array(); $events = array(); $logs = array(); $requests = 0;
class WP_Error {
	private $message;
	public function __construct( $code, $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $k, $d = false ) { return $GLOBALS['options'][$k] ?? $d; }
function update_option( $k, $v, ...$args ) { $GLOBALS['options'][$k] = $v; }
function delete_option( $k ) { unset( $GLOBALS['options'][$k] ); }
function add_option( $k, $v, ...$args ) { if ( isset( $GLOBALS['options'][$k] ) ) { return false; } update_option( $k, $v ); return true; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['transients'][$k] = $v; }
function get_transient( $k ) { return $GLOBALS['transients'][$k] ?? false; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][$k] ); }
function wp_cache_delete( ...$args ) {}
function wp_generate_uuid4() { return uniqid(); }
function add_action( ...$args ) {}
function wp_rand( $a, $b ) { return $a; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_clear_scheduled_hook( $k ) { unset( $GLOBALS['events'][$k] ); }
function wp_schedule_single_event( $t, $k ) { $GLOBALS['events'][$k] = $t; }
function wp_next_scheduled( $k ) { return $GLOBALS['events'][$k] ?? false; }
function wp_safe_remote_get( $url, $args ) { $GLOBALS['requests']++; return new WP_Error( 'network', 'Simulated outage' ); }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
class Test_DB {
	public $options = 'options';
	public function prepare( $q, ...$args ) { return $args; }
	public function query( $args ) { if ( get_option( $args[0] ) === $args[1] ) { delete_option( $args[0] ); } }
}
$wpdb = new Test_DB();
class GDV_Gemini_Log { public static function insert( $r ) { $GLOBALS['logs'][] = $r; } }
require dirname( __DIR__ ) . '/includes/class-gdv-inspection.php';
require dirname( __DIR__ ) . '/includes/class-gdv-request-guard.php';
require dirname( __DIR__ ) . '/includes/class-gdv-gemini-analyzer.php';
require dirname( __DIR__ ) . '/includes/class-gdv-click-tracker.php';
class Inspection_Test_Tracker extends GDV_Click_Tracker {
	public $calls = 0; public $mails = 0; public $result = array( 'send_alert' => true );
	public function get_click_count( $hours ) { return 20; }
	public function get_gemini_analysis_for_alert( $w, $s = 'automatic', $c = 0 ) { $this->calls++; return $this->result; }
	public function send_alert_email( $c, $w, $t = false, $a = array() ) { $this->mails++; return true; }
}
function check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS: $message\n"; }
function school( $date = '06/01/2023' ) {
	return array( 'URN' => '123456', 'School name' => 'Example School', '_as_at' => '2026-10-31', 'Inspection start date' => '', 'Date of latest ungraded inspection' => '', 'Inspection start date of latest OEIF graded inspection' => $date, 'Does the latest OEIF graded inspection relate to the URN of the current school?' => 'Yes', 'Latest OEIF overall effectiveness' => '2', 'Most recent category of concern' => 'NULL' );
}
$today = new DateTimeImmutable( '2026-11-25', new DateTimeZone( 'Europe/London' ) );
check( 'eligible' === GDV_Inspection::evaluate( school(), $today )['status'], 'Exact six-week boundary eligible' );
check( 'outside' === GDV_Inspection::evaluate( school(), $today->modify( '-1 day' ) )['status'], 'Day before boundary excluded' );
check( 'outside' === GDV_Inspection::evaluate( school( '01/06/2026' ), $today )['status'], 'Recent inspection excluded' );
check( 'eligible' === GDV_Inspection::evaluate( school( '01/06/2020' ), $today )['status'], 'Overdue remains eligible' );
check( '20 July 2027' === GDV_Inspection::evaluate( school( '31/08/2023' ), $today )['from'], 'Six weeks across month boundary' );
check( 'outside' === GDV_Inspection::evaluate( school( '25/05/2023' ), $today )['status'], 'Old six-month boundary now excluded' );
check( 'eligible' === GDV_Inspection::evaluate( array(), $today, '2027-01-06' )['status'], 'Override uses same six-week boundary' );
check( 'outside' === GDV_Inspection::evaluate( array(), $today->modify( '-1 day' ), '2027-01-06' )['status'], 'Override excludes day before six-week boundary' );
foreach ( array( 'Latest OEIF overall effectiveness' => '3', 'Most recent category of concern' => 'SM', 'Does the latest OEIF graded inspection relate to the URN of the current school?' => 'No', '_as_at' => '2025-01-01' ) as $key => $v ) {
	$r = school(); $r[$key] = $v; check( 'unknown' === GDV_Inspection::evaluate( $r, $today )['status'], 'Conservative handling: ' . $key );
}
check( 'unknown' === GDV_Inspection::evaluate( school( '31/02/2023' ), $today )['status'], 'Invalid dates rejected' );
check( 'outside' === GDV_Inspection::evaluate( new WP_Error( 'offline', 'Offline' ), $today, '2030-01-01' )['status'], 'Explicit override takes precedence over outage' );
$r = school(); $r['Date of latest ungraded inspection'] = '01/06/2026'; $r['Does the ungraded inspection relate to the URN of the current school?'] = 'Yes'; $r['Ungraded inspection overall outcome'] = 'School remains Good';
check( '1 June 2030' === GDV_Inspection::evaluate( $r, $today )['expected'], 'Newest routine inspection wins' );
$r['Ungraded inspection overall outcome'] = 'Some aspects not as strong';
check( 'unknown' === GDV_Inspection::evaluate( $r, $today )['status'], 'Concern ungraded does not use old graded date' );
$r = school(); $r['Inspection start date'] = '01/06/2026'; $r['Does the latest full inspection relate to the URN of the current school?'] = 'Yes'; $r['Safeguarding standards'] = 'Met';
foreach ( array( 'Inclusion', 'Curriculum and teaching', 'Achievement', 'Attendance and behaviour', 'Personal development and wellbeing', 'Leadership and governance' ) as $field ) { $r[$field] = 'Expected standard'; }
check( '1 June 2030' === GDV_Inspection::evaluate( $r, $today )['expected'], 'New report-card routine inspection supported' );
$r['Early years (where applicable)'] = 'Needs attention';
check( 'unknown' === GDV_Inspection::evaluate( $r, $today )['status'], 'Early years concern prevents blanket four-year estimate' );
$r['Early years (where applicable)'] = 'Expected standard'; $r['Inspection type'] = 'Monitoring';
check( 'unknown' === GDV_Inspection::evaluate( $r, $today )['status'], 'Monitoring never substitutes for routine date' );
check( '123456' === GDV_Inspection::detect_html( '<a href="https://reports.ofsted.gov.uk/provider/21/123456">Ofsted</a>' ), 'Homepage official report URN detected' );
check( '123456' === GDV_Inspection::detect_html( '<a href="https://www.get-information-schools.service.gov.uk/Establishments/Establishment/Details?urn=123456">School</a>' ), 'GIAS link detected' );
check( is_wp_error( GDV_Inspection::detect_html( '<a href="https://reports.ofsted.gov.uk.evil.test/provider/21/123456">Fake</a>' ) ), 'Lookalike host rejected' );
check( is_wp_error( GDV_Inspection::detect_html( '<a href="https://reports.ofsted.gov.uk/provider/21/123456">One</a><a href="https://reports.ofsted.gov.uk/provider/21/654321">Two</a>' ) ), 'Ambiguous match rejected' );
check( '' === GDV_Inspection::urn( array() ) && '' === GDV_Inspection::sanitize_date( '2026-02-30' ), 'Malformed settings rejected' );
$html = '<a href="https://assets.publishing.service.gov.uk/media/a/data.csv">Management information - state-funded schools - latest inspections as at 31 October 2026</a>';
check( '2026-10-31' === GDV_Inspection::attachment( $html )['as_at'], 'Official CSV discovered by label' );
check( is_wp_error( GDV_Inspection::attachment( str_replace( 'assets.publishing.service.gov.uk', 'evil.test', $html ) ) ), 'Untrusted CSV host rejected' );
$f = fopen( 'php://temp', 'w+' ); $r = school(); fputcsv( $f, array_keys( $r ) ); fputcsv( $f, array_values( $r ) ); rewind( $f );
check( 'Example School' === GDV_Inspection::parse_csv( $f, '123456' )['School name'], 'CSV exact URN match' );
rewind( $f ); check( is_wp_error( GDV_Inspection::parse_csv( $f, '654321' ) ), 'Missing URN rejected' ); fclose( $f );
check( 'disabled' === GDV_Inspection::check()['status'], 'Filter defaults disabled' );
$options = array( 'gdv_inspection_enabled' => 1, 'gdv_inspection_urn' => '123456', 'gdv_gemini_api_key' => 'test' );
GDV_Inspection::record( '123456' ); $initial = $requests; GDV_Inspection::record( '123456' );
check( $requests === $initial, 'Failures cached' );
GDV_Inspection::record( '123456', true ); check( $requests === $initial + 1, 'Refresh bypasses cache' );
$tracker = new Inspection_Test_Tracker(); $tracker->check_threshold();
check( 0 === $tracker->calls && 0 === $tracker->mails && count( $logs ) === 1, 'Outage suppresses Gemini and email, logs reason' );
$tracker->check_threshold(); check( count( $logs ) === 1, 'Repeated suppression logs deduplicated' );
$options['gdv_inspection_expected'] = '2090-01-01';
$options['gdv_pending_review'] = array( 'next' => time()-1, 'attempts' => 1, 'count' => 20, 'window' => 24 ); $events['gdv_retry_review'] = time();
$tracker->check_threshold(); check( ! get_option( 'gdv_pending_review' ) && ! $events && 0 === $tracker->calls, 'Outside window cancels pending retry' );
$options['gdv_inspection_expected'] = '2020-01-01'; $tracker->check_threshold();
check( 1 === $tracker->calls && 1 === $tracker->mails, 'Eligible positive review emails' );
delete_option( 'gdv_last_alert_sent' ); $tracker->result = array( 'send_alert' => false ); $tracker->check_threshold();
check( 2 === $tracker->calls && 1 === $tracker->mails, 'Low confidence does not email' );
delete_option( 'gdv_last_alert_sent' ); delete_option( 'gdv_gemini_api_key' ); $tracker->check_threshold();
check( 2 === $tracker->calls, 'Enabled filter requires Gemini configuration' );
$options['gdv_inspection_enabled'] = 0; $tracker->result = array( 'send_alert' => true ); $tracker->check_threshold();
check( 3 === $tracker->calls && 2 === $tracker->mails, 'Disabled filter preserves original behaviour' );
function current_user_can( $cap ) { return $GLOBALS['can_manage'] ?? true; }
function wp_die( $m ) { throw new RuntimeException( $m ); }
function check_admin_referer( $action ) { wp_die( 'Invalid nonce' ); }
require dirname( __DIR__ ) . '/includes/class-gdv-inspection-admin.php';
$admin = new GDV_Inspection_Admin();
foreach ( array( 'detect', 'refresh' ) as $action ) {
	$GLOBALS['can_manage'] = false;
	try { $admin->$action(); check( false, 'Must reject non-admin' ); } catch ( RuntimeException $e ) { check( 'Administrator access is required.' === $e->getMessage(), $action . ' requires administrator' ); }
	$GLOBALS['can_manage'] = true;
	try { $admin->$action(); check( false, 'Must reject invalid nonce' ); } catch ( RuntimeException $e ) { check( 'Invalid nonce' === $e->getMessage(), $action . ' requires nonce' ); }
}

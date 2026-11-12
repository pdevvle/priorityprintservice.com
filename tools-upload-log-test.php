<?php
/**
 * Upload failures are recorded (owner 2026-11-12: "log failed uploads").
 *
 * Runs pps-upload-log.php against stubs: the list is bounded, newest first, autoload off;
 * every field is cut to length and stripped; unknown codes become "refused"; the public
 * report endpoint takes at most PPS_UPLOAD_LOG_IP_HOUR rows an hour from one IP and always
 * answers the same; the IP is never stored. Then reads pps-calculators.php and checks every
 * refusal in the upload handler goes through pps_upload_refuse(), the stale-nonce answer is
 * unchanged (-1, 403), and the file is loaded.
 *
 *   php tools-upload-log-test.php [pps-upload-log.php] [pps-calculators.php]
 */
define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'PPS_UPLOAD_LOG_MAX', 5 );
define( 'PPS_UPLOAD_LOG_IP_HOUR', 3 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "ok   $m\n"; } else { $fail++; echo "FAIL $m\n"; } }

$OPT = array(); $AUTOLOAD = array(); $TR = array(); $SENT = array();
function get_option( $k, $d = false ) { global $OPT; return array_key_exists( $k, $OPT ) ? $OPT[ $k ] : $d; }
function update_option( $k, $v, $a = null ) { global $OPT, $AUTOLOAD; $OPT[ $k ] = $v; $AUTOLOAD[ $k ] = $a; return true; }
function get_transient( $k ) { global $TR; return $TR[ $k ] ?? false; }
function set_transient( $k, $v, $t ) { global $TR; $TR[ $k ] = $v; return true; }
function wp_salt( $s ) { return 'salt'; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_unslash( $s ) { return stripslashes( $s ); }
function wp_send_json_success( $d = null ) { global $SENT; $SENT[] = 'success'; }
function add_action() {} function add_submenu_page() {}

$log_src = $argv[1] ?? __DIR__ . '/pps-upload-log.php';
require $log_src;

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

pps_upload_log( array( 'source' => 'server', 'code' => 'refused', 'reason' => 'File type not allowed: .heic', 'file' => 'IMG_0001.heic', 'size' => 1234 ) );
$l = get_option( 'pps_upload_failures' );
ok( is_array( $l ) && count( $l ) === 1 && $l[0]['code'] === 'refused' && $l[0]['file'] === 'IMG_0001.heic' && $l[0]['size'] === 1234, 'a refusal is recorded with its file and size' );
ok( $AUTOLOAD['pps_upload_failures'] === false, 'the list is not autoloaded' );
ok( $l[0]['ua'] === 'iPhone Safari', 'device recorded as a family, not the raw user agent' );
ok( strpos( json_encode( $l ), '203.0.113.9' ) === false && strlen( $l[0]['who'] ) === 8, 'the IP address itself is never stored' );

pps_upload_log( array( 'code' => 'not-a-code', 'reason' => "<b>x</b>\nline2" . str_repeat( 'y', 500 ) ) );
$l = get_option( 'pps_upload_failures' );
ok( $l[0]['code'] === 'refused', 'an unknown code becomes "refused"' );
ok( strpos( $l[0]['reason'], '<b>' ) === false && strpos( $l[0]['reason'], "\n" ) === false && strlen( $l[0]['reason'] ) <= 300, 'text is stripped, one line, cut to length' );
ok( $l[0]['source'] === 'server', 'anything not "browser" is recorded as server' );

for ( $i = 0; $i < 8; $i++ ) pps_upload_log( array( 'code' => 'network', 'reason' => "n$i" ) );
$l = get_option( 'pps_upload_failures' );
ok( count( $l ) === 5 && $l[0]['reason'] === 'n7', 'the list is capped and newest first' );

// The browser's report.
$OPT = array(); $TR = array(); $SENT = array();
$_POST['data'] = addslashes( json_encode( array( 'code' => 'challenge', 'reason' => 'bot check', 'file' => 'book.pdf', 'size' => 99, 'status' => 200, 'page' => '/product/x/', 'calc' => 'product 5', 'final' => true ) ) );
pps_ajax_upload_failure();
$l = get_option( 'pps_upload_failures' );
ok( count( $l ) === 1 && $l[0]['source'] === 'browser' && $l[0]['code'] === 'challenge' && $l[0]['final'] === true && $l[0]['page'] === '/product/x/', 'a browser report is recorded as one' );
for ( $i = 0; $i < 6; $i++ ) pps_ajax_upload_failure();
ok( count( get_option( 'pps_upload_failures' ) ) === 3, 'one IP adds at most the hourly allowance' );
ok( count( $SENT ) === 7 && count( array_unique( $SENT ) ) === 1, 'the endpoint always answers the same' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$_POST['data'] = 'not json';
pps_ajax_upload_failure();
ok( count( get_option( 'pps_upload_failures' ) ) === 3, 'a malformed report adds nothing' );

// The handler in pps-calculators.php.
$src = file_get_contents( $argv[2] ?? __DIR__ . '/pps-calculators.php' );
preg_match( '/\nfunction pps_ajax_upload_artwork\(\) \{.*?\n}\n/s', $src, $m );
$h = $m[0] ?? '';
ok( $h !== '' && strpos( $h, 'wp_send_json_error' ) === false, 'every refusal in the upload handler goes through pps_upload_refuse()' );
ok( substr_count( $h, 'pps_upload_refuse(' ) >= 7, 'all seven refusals are recorded (found ' . substr_count( $h, 'pps_upload_refuse(' ) . ')' );
ok( strpos( $h, "check_ajax_referer( 'pps_upload_artwork', 'nonce', false )" ) !== false && strpos( $h, "wp_die( '-1', 403 )" ) !== false, 'a stale nonce still answers -1 / 403, and is recorded' );
ok( strpos( $src, "require_once PPS_CALC_DIR . 'pps-upload-log.php';" ) !== false, 'the log is loaded by the plugin' );
preg_match( '/\nfunction pps_upload_refuse\(.*?\n}\n/s', $src, $r );
ok( isset( $r[0] ) && strpos( $r[0], "function_exists( 'pps_upload_log' )" ) !== false && strpos( $r[0], 'wp_send_json_error( $message )' ) !== false, 'pps_upload_refuse() records when it can and always answers as before' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );

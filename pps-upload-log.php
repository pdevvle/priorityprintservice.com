<?php
/**
 * Upload failures, recorded (owner 2026-11-12: "log failed uploads").
 *
 * Customers wrote in that artwork "would not upload" and nothing on the site said so: the
 * upload endpoint wrote only PHP-level codes to the error log, and the failures that never
 * reach WordPress (the host's bot check, a 413 or 403 from in front of it, a dropped
 * connection, a file over the size limit refused in the browser) left no trace at all.
 *
 * Two sources, one list — wp_options['pps_upload_failures'], newest first, at most
 * PPS_UPLOAD_LOG_MAX, autoload off:
 *   - server: every refusal from pps_ajax_upload_artwork() (pps_upload_log()).
 *   - browser: the calculators report what the server could not see through the public
 *     admin-ajax action pps_upload_failure (no nonce — a stale nonce is one of the things
 *     being reported), rate-limited per IP, every field bounded.
 * Shown at PPS Calculators → Upload Failures. Nothing here can stop an order: every write
 * is wrapped, and the browser's report is fire-and-forget.
 *
 * tools-upload-log-test.php is the gate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'PPS_UPLOAD_LOG_MAX' ) )      define( 'PPS_UPLOAD_LOG_MAX', 200 );
if ( ! defined( 'PPS_UPLOAD_LOG_IP_HOUR' ) )  define( 'PPS_UPLOAD_LOG_IP_HOUR', 30 );

/** What each code means, in the words the screen uses. */
function pps_upload_log_codes() {
    return array(
        'challenge' => "Host's bot check answered instead of the site",
        'blocked'   => 'Blocked before the site (403 — firewall, VPN, security software)',
        'size'      => 'Too large for the server (413 or PHP limit)',
        'toolarge'  => 'Over the size limit — stopped in the browser, never sent',
        'nonce'     => 'Page session expired and could not be renewed',
        'network'   => 'Connection dropped during the upload',
        'invalid'   => 'Server answered with something unreadable',
        'refused'   => 'Refused by the upload check',
        'php'       => 'PHP refused the upload',
        'cart'      => 'Add to Order failed after uploads',
    );
}

/** One line of text, bounded. */
function pps_upload_log_clean( $v, $max = 200 ) {
    $v = is_scalar( $v ) ? (string) $v : '';
    $v = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $v );
    $v = trim( wp_strip_all_tags( $v ) );
    if ( function_exists( 'mb_substr' ) ) return mb_substr( $v, 0, $max );
    return substr( $v, 0, $max );
}

/** Add one failure to the list. Never throws. */
function pps_upload_log( array $e ) {
    try {
        $codes = pps_upload_log_codes();
        $code  = isset( $codes[ $e['code'] ?? '' ] ) ? $e['code'] : 'refused';
        $row = array(
            't'      => time(),
            'source' => ( $e['source'] ?? '' ) === 'browser' ? 'browser' : 'server',
            'code'   => $code,
            'reason' => pps_upload_log_clean( $e['reason'] ?? '', 300 ),
            'file'   => pps_upload_log_clean( $e['file'] ?? '', 120 ),
            'size'   => max( 0, (int) ( $e['size'] ?? 0 ) ),
            'status' => max( 0, min( 999, (int) ( $e['status'] ?? 0 ) ) ),
            'page'   => pps_upload_log_clean( $e['page'] ?? '', 160 ),
            'calc'   => pps_upload_log_clean( $e['calc'] ?? '', 60 ),
            'final'  => ! empty( $e['final'] ),
            'who'    => substr( md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'auth' ) ), 0, 8 ),
            'ua'     => pps_upload_log_ua( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
        );
        $list = get_option( 'pps_upload_failures', array() );
        if ( ! is_array( $list ) ) $list = array();
        array_unshift( $list, $row );
        $list = array_slice( $list, 0, PPS_UPLOAD_LOG_MAX );
        update_option( 'pps_upload_failures', $list, false );
    } catch ( \Throwable $x ) {
        error_log( '[pps] upload log: ' . $x->getMessage() );
    }
}

/** "iPhone Safari", "Windows Chrome" — enough to see a pattern, nothing more. */
function pps_upload_log_ua( $ua ) {
    $os = 'Other';
    foreach ( array( 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Macintosh' => 'Mac', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux' ) as $k => $v ) {
        if ( stripos( $ua, $k ) !== false ) { $os = $v; break; }
    }
    $br = 'other';
    foreach ( array( 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari' ) as $k => $v ) {
        if ( stripos( $ua, $k ) !== false ) { $br = $v; break; }
    }
    return $os . ' ' . $br;
}

/**
 * The browser's report. Public (the visitor is a guest), no nonce on purpose, so it is
 * bounded instead: one IP may add PPS_UPLOAD_LOG_IP_HOUR rows an hour, every field is cut
 * to length, unknown codes become "refused", and the answer is always the same.
 */
function pps_ajax_upload_failure() {
    try {
        $ip  = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        $key = 'pps_ulf_' . substr( md5( $ip ), 0, 16 );
        $n   = (int) get_transient( $key );
        if ( $n < PPS_UPLOAD_LOG_IP_HOUR ) {
            set_transient( $key, $n + 1, HOUR_IN_SECONDS );
            $d = json_decode( wp_unslash( (string) ( $_POST['data'] ?? '' ) ), true );
            if ( is_array( $d ) ) {
                pps_upload_log( array(
                    'source' => 'browser',
                    'code'   => (string) ( $d['code'] ?? '' ),
                    'reason' => $d['reason'] ?? '',
                    'file'   => $d['file'] ?? '',
                    'size'   => $d['size'] ?? 0,
                    'status' => $d['status'] ?? 0,
                    'page'   => $d['page'] ?? '',
                    'calc'   => $d['calc'] ?? '',
                    'final'  => ! empty( $d['final'] ),
                ) );
            }
        }
    } catch ( \Throwable $x ) {}
    wp_send_json_success();
}
add_action( 'wp_ajax_pps_upload_failure', 'pps_ajax_upload_failure' );
add_action( 'wp_ajax_nopriv_pps_upload_failure', 'pps_ajax_upload_failure' );

// ── PPS Calculators → Upload Failures ─────────────────────────────────
add_action( 'admin_menu', function () {
    add_submenu_page( 'pps-calculators', 'Upload Failures', '⚠️ Upload Failures', 'manage_woocommerce', 'pps-upload-failures', 'pps_upload_log_render' );
}, 30 );

function pps_upload_log_render() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    if ( isset( $_POST['pps_ulf_clear'] ) && check_admin_referer( 'pps_ulf_clear' ) ) {
        update_option( 'pps_upload_failures', array(), false );
        echo '<div class="notice notice-success"><p>Cleared.</p></div>';
    }
    $list  = get_option( 'pps_upload_failures', array() );
    $list  = is_array( $list ) ? $list : array();
    $codes = pps_upload_log_codes();
    $tz    = function_exists( 'pps_shop_timezone' ) ? pps_shop_timezone() : 'America/Phoenix';

    $week = array(); $cut = time() - 7 * DAY_IN_SECONDS;
    foreach ( $list as $r ) { if ( ( $r['t'] ?? 0 ) >= $cut ) $week[ $r['code'] ?? 'refused' ] = ( $week[ $r['code'] ?? 'refused' ] ?? 0 ) + 1; }

    echo '<div class="wrap"><h1>Upload Failures</h1>';
    echo '<p>Every artwork upload that did not go through: refused by the site, or failed in the customer\'s browser before it reached the site. '
       . '<strong>Gave up</strong> means the customer was shown an error and the order stopped; the others were retried or were a generated file the order can do without. '
       . 'Newest first, the last ' . (int) PPS_UPLOAD_LOG_MAX . ' kept.</p>';
    if ( $week ) {
        echo '<p><strong>Last 7 days:</strong> ';
        $bits = array();
        foreach ( $week as $c => $n ) $bits[] = esc_html( ( $codes[ $c ] ?? $c ) . ': ' . $n );
        echo implode( ' · ', $bits ) . '</p>';
    }
    if ( ! $list ) { echo '<p><em>No upload failures recorded yet.</em></p></div>'; return; }
    echo '<table class="widefat striped"><thead><tr><th>When</th><th>What</th><th>Gave up</th><th>File</th><th>Size</th><th>Detail</th><th>Page</th><th>Device</th><th>Visitor</th></tr></thead><tbody>';
    foreach ( $list as $r ) {
        try { $d = new DateTime( '@' . (int) $r['t'] ); $d->setTimezone( new DateTimeZone( $tz ) ); $when = $d->format( 'M j, g:ia' ); } catch ( \Throwable $x ) { $when = ''; }
        $size = ! empty( $r['size'] ) ? size_format( (int) $r['size'], 1 ) : '';
        $detail = trim( ( ! empty( $r['status'] ) ? 'HTTP ' . (int) $r['status'] . ' · ' : '' ) . ( $r['reason'] ?? '' ) . ' (' . ( $r['source'] ?? '' ) . ')' );
        echo '<tr><td>' . esc_html( $when ) . '</td><td>' . esc_html( $codes[ $r['code'] ] ?? $r['code'] ) . '</td><td>' . ( ! empty( $r['final'] ) ? '<strong>yes</strong>' : '' ) . '</td><td>'
            . esc_html( $r['file'] ?? '' ) . '</td><td>' . esc_html( $size ) . '</td><td>' . esc_html( $detail ) . '</td><td>'
            . esc_html( trim( ( $r['calc'] ?? '' ) . ' ' . ( $r['page'] ?? '' ) ) ) . '</td><td>' . esc_html( $r['ua'] ?? '' ) . '</td><td><code>' . esc_html( $r['who'] ?? '' ) . '</code></td></tr>';
    }
    echo '</tbody></table>';
    echo '<form method="post" style="margin-top:12px">';
    wp_nonce_field( 'pps_ulf_clear' );
    echo '<button class="button" name="pps_ulf_clear" value="1" onclick="return confirm(\'Clear the list?\')">Clear the list</button></form>';
    echo '<p class="description">"Visitor" is a short fingerprint of the IP address (not the address), so repeated attempts by one customer can be told apart from many customers.</p></div>';
}

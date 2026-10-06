<?php
/**
 * The intake forms (pps-intake.php) must not mail strangers what a bot typed.
 *
 * October 2026: bots filled the quote form with a Russian "you have a transfer of N
 * rubles, collect it here https://…" line as the NAME and a victim's address as EMAIL.
 * The confirmation greeted the submitter by name, so the shop's mail server delivered
 * the phishing link to the victim. This drives the real file against stubs with the
 * submissions on record, spam and genuine, and checks:
 *   - every spam sample is recorded as a draft with a reason, and nobody is mailed;
 *   - every genuine sample is published, the office is mailed, and the customer gets a
 *     confirmation that contains nothing they typed;
 *   - confirmations stop at the hourly cap; staff mail does not.
 *
 * Run: php tools-intake-spam-test.php   (PPS_INTAKE_PHP points at another copy)
 * Against 5f48e1e (live until this fix) it fails: spam is published, mailed, and the
 * confirmation carries the link.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['mail'] = array(); $GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['tr'] = array();
function add_action() {} function add_filter() {} function add_shortcode() {} function register_post_type() {}
function wp_mail( $to, $s, $b, $h = array() ) { $GLOBALS['mail'][] = compact( 'to', 's', 'b' ); return true; }
function wp_insert_post( $a ) { $id = 1000 + count( $GLOBALS['posts'] ); $GLOBALS['posts'][ $id ] = $a; return $id; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; }
function is_wp_error( $x ) { return false; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function get_option( $k, $d = '' ) { return $k === 'admin_email' ? 'admin@example.com' : $d; }
function get_bloginfo() { return 'Priority Print Service'; }
function wp_specialchars_decode( $s ) { return $s; }
function admin_url( $p ) { return 'https://x/wp-admin/' . $p; }
function current_time() { return 'Oct 6, 2026'; }
function error_log_( ) {}
function pps_get_config() { return array( 'pcf' => array( 'question_recipient_email' => 'office@example.com' ) ); }

require getenv( 'PPS_INTAKE_PHP' ) ?: __DIR__ . '/pps-intake.php';

$forms = pps_intake_forms();
$fail = 0; $n = 0;
function check( $ok, $what ) { global $fail, $n; $n++; if ( ! $ok ) { $fail++; echo "FAIL: $what\n"; } }
function submit( $values ) {
    global $forms;
    $GLOBALS['mail'] = array();
    $id = pps_intake_record( 'quote', $forms['quote'], $values, array() );
    pps_intake_notify( 'quote', $forms['quote'], $values, array(), $id );
    return $id;
}

// Spam actually received on production, 2026-10-05/06 (Calc Questions 87278–87362).
$spam = array(
    array( 'name' => 'Вам перевод 167382 руб. забрать тут https://sekalubanik.buzz/gRWq5FO4d#7H0PPB', 'email' => 'victim@example.org', 'message' => 'CKId0vK tdtj L3TrnEK VFoxTSv I7gd kRI5cZO' ),
    array( 'name' => 'Вам перевод 177127 руб. забрать тут https://peskartyhrt.buzz/eKqekoxT JUYEGRT17575JUYEGRT', 'email' => 'v2@example.org', 'message' => 'GdCNHwg tsKX KP7WNaE' ),
    array( 'name' => 'Вам перевод 176350 руб. забрать тут https://5a48ca64.sslip.io/TQexcp6J MTGJNF24417JUYEGRT', 'email' => 'v3@example.org', 'message' => 'dWaEzpe onm5 lCbXPZz' ),
    array( 'name' => 'Oreseeriz', 'email' => 'v4@example.org', 'message' => 'Радостная новость! Твой эксклюзивный приз в ожидании! Посмотрите детали по ссылке https://sekalubanik.buzz/Px4fS66B NFDAW17575SVWVE' ),
    array( 'name' => 'Oreseeriz', 'email' => 'v5@example.org', 'message' => 'Вам перевод 138575 руб. получить тут https://peskartyhrt.buzz/S5LjQqQ9 TUJE17575MTGJNF' ),
    array( 'name' => 'NAERTERHTE229026NERTHRRTH', 'email' => 'v6@example.org', 'message' => 'MERTHYTJTJ229026MARTHHDF' ),
);
foreach ( $spam as $i => $v ) {
    $id = submit( $v );
    check( ( $GLOBALS['posts'][ $id ]['post_status'] ?? '' ) === 'draft', "spam $i kept as a draft" );
    check( (string) get_post_meta( $id, '_pps_q_spam' ) !== '', "spam $i carries a reason" );
    check( count( $GLOBALS['mail'] ) === 0, "spam $i mails nobody (sent " . count( $GLOBALS['mail'] ) . ')' );
}

// Genuine requests on record, including a link in the message (Canva/Drive are normal).
$real = array(
    array( 'name' => 'Ronald Reid', 'email' => 'r@example.com', 'message' => "I'd like quotes for a saddle-stitch booklet: 3.5 × 5.5 in, 12 pages, 80 lb matte text, 250, 500, 1,000 copies." ),
    array( 'name' => 'Paula VanTyle-Smith', 'email' => 'p@example.com', 'message' => 'This must reach us by Oct 9th. Does your company proof, trim and fold the brochures?' ),
    array( 'name' => 'Juanita M Scheyett-Cheng', 'email' => 'j@example.com', 'phone' => '(480) 555-0199', 'message' => 'We have a P.O. Box and a street address.' ),
    array( 'name' => "Mary O'Neil", 'email' => 'm@example.com', 'message' => 'Design is here: https://www.canva.com/design/DAF123/view — 500 postcards please.' ),
    array( 'name' => 'José Núñez', 'email' => 'jn@example.com', 'message' => '1000 bookmarks 2x6, double sided.' ),
    array( 'name' => 'Megan Baloun', 'email' => 'mb@example.com', 'order_ref' => 'Order Entry form #2044', 'message' => 'Last ordered around June 16th 2026.' ),
);
foreach ( $real as $i => $v ) {
    $id = submit( $v );
    check( ( $GLOBALS['posts'][ $id ]['post_status'] ?? '' ) === 'publish', "real $i ({$v['name']}) published" );
    check( (string) get_post_meta( $id, '_pps_q_spam' ) === '', "real $i not flagged (" . get_post_meta( $id, '_pps_q_spam' ) . ')' );
    $to = array_column( $GLOBALS['mail'], 'to' );
    check( in_array( 'office@example.com', $to, true ), "real $i reaches the office" );
    $conf = array_values( array_filter( $GLOBALS['mail'], function ( $m ) use ( $v ) { return $m['to'] === $v['email']; } ) );
    check( count( $conf ) === 1, "real $i gets one confirmation" );
    if ( $conf ) {
        $body = $conf[0]['s'] . "\n" . $conf[0]['b'];
        foreach ( $v as $f => $typed ) {
            if ( $f === 'email' ) continue;
            check( strpos( $body, $typed ) === false, "real $i confirmation does not echo the $f" );
        }
    }
}

// The hourly cap: a bot that gets past every rule still cannot make the form mail more
// than 30 strangers an hour. The office still hears about every one.
$GLOBALS['tr'] = array();
$confirms = 0; $office = 0;
for ( $i = 0; $i < 40; $i++ ) {
    submit( array( 'name' => 'Pat Lee', 'email' => "p$i@example.com", 'message' => 'Quote for flyers.' ) );
    foreach ( $GLOBALS['mail'] as $m ) { if ( $m['to'] === 'office@example.com' ) $office++; else $confirms++; }
}
check( $confirms === 30, "confirmations capped at 30 an hour (sent $confirms)" );
check( $office === 40, "every request still reaches the office ($office)" );

echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> INTAKE SPAM OK ($n checks)\n";
exit( $fail ? 1 : 0 );

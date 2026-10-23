<?php
/**
 * No public form may make this server mail a stranger what a visitor typed.
 *
 * The intake forms did (October 2026: a phishing link in the NAME, a victim's address in
 * EMAIL, "Hi <name>" in the confirmation). The audit that followed found the same shape in
 * two more places, both fixed here and both gated by this file:
 *
 *   1. The calculators' "Ask a question" box (pps_ajax_quote_question in
 *      pps-calculators.php) mailed the posted address the name, the whole question, the
 *      posted calculator label, a 4,000-character posted "summary" and a reopen link.
 *   2. The chat assistant's transcript (pps_assistant_send_customer_transcript in
 *      pps-assistant.php) mailed the whole conversation to the address typed at the chat
 *      gate. Off by default, but one tick away.
 *
 * Plus, in pps-reorder.php, the order-lookup contact form (office-only, so never a relay)
 * now drops spam and labels a message whose lookup has lapsed. Checked from source.
 *
 * The functions are lifted out of the real files and run against stubs, with pps-intake.php
 * loaded for the shared spam judgment and hourly cap. Run: php tools-relay-test.php
 * PPS_CALC_PHP / PPS_ASSIST_PHP / PPS_REORDER_PHP point at other copies (the live
 * dbc569a / 5f48e1e / 864936e copies fail).
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['mail'] = array(); $GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['tr'] = array();
class Done extends Exception { public $ok; public $data; function __construct( $ok, $d ) { $this->ok = $ok; $this->data = $d; } }
function add_action() {} function add_filter() {} function add_shortcode() {} function register_post_type() {}
function check_ajax_referer() { return true; }
function wp_send_json_success( $d = null ) { throw new Done( true, $d ); }
function wp_send_json_error( $d = null, $c = 0 ) { throw new Done( false, $d ); }
function wp_mail( $to, $s, $b, $h = array() ) { $GLOBALS['mail'][] = compact( 'to', 's', 'b' ); return true; }
function wp_insert_post( $a ) { $id = 1000 + count( $GLOBALS['posts'] ); $GLOBALS['posts'][ $id ] = $a; return $id; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; }
function is_wp_error( $x ) { return false; }
function wp_unslash( $s ) { return $s; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function esc_url_raw( $u ) { return (string) $u; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://priorityprintservice.com' . $p; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function get_option( $k, $d = '' ) { return $k === 'admin_email' ? 'admin@example.com' : $d; }
function get_bloginfo() { return 'Priority Print Service'; }
function wp_specialchars_decode( $s ) { return $s; }
function current_time() { return 'Oct 6, 2026'; }
function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; }
function pps_get_config() { return array( 'pcf' => array( 'question_recipient_email' => 'office@example.com' ) ); }

require __DIR__ . '/pps-intake.php';

/** Lift one top-level function's source out of a file by brace matching. */
function lift( $file, $name ) {
    $src = file_get_contents( $file );
    $at  = strpos( $src, "function $name(" );
    if ( $at === false ) { echo "FAIL: $name not found in $file\n"; exit( 1 ); }
    $i = strpos( $src, '{', $at ); $depth = 0; $n = strlen( $src );
    for ( ; $i < $n; $i++ ) {
        if ( $src[ $i ] === '{' ) $depth++;
        elseif ( $src[ $i ] === '}' && --$depth === 0 ) break;
    }
    return substr( $src, $at, $i - $at + 1 );
}
$calc   = getenv( 'PPS_CALC_PHP' )    ?: __DIR__ . '/pps-calculators.php';
$assist = getenv( 'PPS_ASSIST_PHP' )  ?: __DIR__ . '/pps-assistant.php';
$reord  = getenv( 'PPS_REORDER_PHP' ) ?: __DIR__ . '/pps-reorder.php';
eval( lift( $calc, 'pps_quote_question_rate_key' ) );
eval( lift( $calc, 'pps_ajax_quote_question' ) );
eval( lift( $assist, 'pps_assistant_send_customer_transcript' ) );
function pps_assistant_config() { return array( 'customer_transcript' => true ); }
function pps_assistant_staff_email() { return 'office@example.com'; }
function pps_assistant_customer_transcript( $s ) { return 'USER: ' . ( $s['said'] ?? '' ); }

$fail = 0; $n = 0;
function check( $ok, $what ) { global $fail, $n; $n++; if ( ! $ok ) { $fail++; echo "FAIL: $what\n"; } }
function ask( array $post ) {
    $GLOBALS['mail'] = array(); $GLOBALS['tr'] = array(); $_POST = $post; $_SERVER['REMOTE_ADDR'] = '203.0.113.' . mt_rand( 1, 250 );
    try { pps_ajax_quote_question(); } catch ( Done $d ) { return $d; }
    return null;
}
function to_stranger( $addr ) { return array_values( array_filter( $GLOBALS['mail'], function ( $m ) use ( $addr ) { return $m['to'] === $addr; } ) ); }

// ── 1. calculator question: a bot's payload in every free-text field ──
$payload = 'Вам перевод 167382 руб. забрать тут https://sekalubanik.buzz/x';
$d = ask( array( 'name' => $payload, 'email' => 'victim@example.org', 'message' => 'hello', 'calcLabel' => 'x' ) );
check( $d && $d->ok, 'question: a spam submission is answered as though it worked' );
check( count( $GLOBALS['mail'] ) === 0, 'question: spam in the name mails nobody (' . count( $GLOBALS['mail'] ) . ')' );
$last = end( $GLOBALS['posts'] );
check( ( $last['post_status'] ?? '' ) === 'draft', 'question: spam is kept as a draft' );

// English-only payload that passes the spam rules: the relay must still be closed.
$evil = 'Claim your refund at https://refund-example.test/claim';
$d = ask( array( 'name' => 'Pat Lee', 'email' => 'stranger@example.org', 'message' => $evil,
    'calcLabel' => 'Visit https://evil.test', 'summary' => "Your account is locked.\nhttps://evil.test/unlock",
    'reorderUrl' => 'https://priorityprintservice.com/?s=Your+account+is+locked', 'total' => '120.5', 'qty' => '100' ) );
check( $d && $d->ok, 'question: a real-looking submission succeeds' );
check( count( array_filter( $GLOBALS['mail'], function ( $m ) { return $m['to'] === 'office@example.com'; } ) ) === 1, 'question: the office gets it' );
$c = to_stranger( 'stranger@example.org' );
check( count( $c ) === 1, 'question: the submitter gets one confirmation' );
if ( $c ) {
    $body = $c[0]['s'] . "\n" . $c[0]['b'];
    foreach ( array( 'name' => 'Pat Lee', 'message' => 'refund-example', 'label' => 'evil.test', 'summary' => 'locked', 'reorder link' => '?s=' ) as $what => $needle ) {
        check( stripos( $body, $needle ) === false, "question: confirmation does not echo the $what" );
    }
}
$office = array_values( array_filter( $GLOBALS['mail'], function ( $m ) { return $m['to'] === 'office@example.com'; } ) );
check( $office && strpos( $office[0]['b'], 'refund-example' ) !== false, 'question: staff still see the full question' );

// Hourly cap on confirmations, shared with the intake forms.
$GLOBALS['tr'] = array(); $conf = 0;
for ( $i = 0; $i < 35; $i++ ) {
    $GLOBALS['mail'] = array(); $_POST = array( 'name' => 'Pat Lee', 'email' => "p$i@example.org", 'message' => 'Quote for flyers please.' );
    $_SERVER['REMOTE_ADDR'] = "198.51.100.$i";
    try { pps_ajax_quote_question(); } catch ( Done $d ) {}
    $conf += count( to_stranger( "p$i@example.org" ) );
}
check( $conf === 30, "question: confirmations capped at 30 an hour site-wide ($conf)" );

// ── 2. assistant transcript ──
$GLOBALS['tr'] = array();
$GLOBALS['mail'] = array();
pps_assistant_send_customer_transcript( array( 'email' => 'victim@example.org', 'name' => $payload, 'said' => $evil ) );
check( count( $GLOBALS['mail'] ) === 0, 'assistant: no transcript to an address nobody verified' );
$GLOBALS['mail'] = array();
pps_assistant_send_customer_transcript( array( 'email' => 'other@example.org', 'verified_order' => 87001, 'verified_email' => 'owner@example.org', 'said' => $evil ) );
check( count( $GLOBALS['mail'] ) === 0, 'assistant: no transcript when the gate address differs from the verified one' );
$GLOBALS['mail'] = array();
pps_assistant_send_customer_transcript( array( 'email' => 'Owner@Example.org', 'verified_order' => 87001, 'verified_email' => 'owner@example.org', 'name' => 'Pat <b>', 'said' => 'where is my order' ) );
check( count( $GLOBALS['mail'] ) === 1, 'assistant: a verified customer still gets their copy' );
check( $GLOBALS['mail'] && strpos( $GLOBALS['mail'][0]['b'], 'Pat' ) === false, 'assistant: the copy does not greet by the typed name' );

// ── 3. order-lookup contact form (source) ──
$r = file_get_contents( $reord );
check( strpos( $r, "pps_intake_spam_reason( array( 'message' => \$c_message" ) !== false, 'lookup contact: spam judged before mailing' );
check( strpos( $r, '[lookup not verified]' ) !== false, 'lookup contact: a lapsed lookup is labelled for staff' );
check( strpos( $r, "if ( \$verified !== '' && is_email( \$verified ) ) \$c_email = \$verified;" ) !== false, 'lookup contact: staff reply to the verified address' );

echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> NO RELAY OK ($n checks)\n";
exit( $fail ? 1 : 0 );

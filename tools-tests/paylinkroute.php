<?php
/**
 * pps-pay-link.php — the REST handler, end to end, against the shipped file.
 *
 * WHY THIS SUITE EXISTS
 *
 * paylink.php tests the parser and it tests pps_paylink_create(). Both were
 * correct on 2026-10-07 and a multi-product command still stored one line item
 * named after the first bracket: the handler builds its argument array from an
 * explicit allow-list, and 'lines' was not on it. The total was right, because
 * the parser sums the lines into 'price', so nothing looked wrong until someone
 * read the payment page.
 *
 * A seam between two tested units is not tested by testing the units. So this
 * drives pps_paylink_handle_request() with a stub request and asserts on what
 * actually reaches the quote -- and, last, that EVERY field the parser produces
 * is forwarded, so the next field added cannot be silently dropped.
 *
 * Run: php tools-tests/paylinkroute.php
 */
define('ABSPATH', '/tmp/');
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['opts'] = array();
$GLOBALS['tr'] = array();
$GLOBALS['captured'] = array();
$GLOBALS['notes'] = array();

function add_action($h, $f = null, ...$r) {
    // shutdown-queued Missive notes: run them so a queued note is observable.
    if ('shutdown' === $h && is_callable($f)) { $GLOBALS['shutdown'][] = $f; }
}
function add_filter(...$a) {}
function register_rest_route(...$a) {}
function get_option($k, $d = '') { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $a = true) { $GLOBALS['opts'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['tr'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['tr'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['tr'][$k]); return true; }
function absint($v) { return abs(intval($v)); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_title($v) { return trim(strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $v)), '-'); }
function wp_generate_password($n, $s = true, $x = true) { return str_repeat('x', $n); }
function current_time($f) { return date($f); }
function wp_json_encode($v) { return json_encode($v); }
function wp_remote_post(...$a) { return array('response' => array('code' => 200), 'body' => '{}'); }
function wp_remote_retrieve_response_code($r) { return 200; }
function wp_remote_retrieve_body($r) { return '{}'; }
function wp_trim_words($t, $n = 12) { return $t; }
function wp_parse_url($u, $c = -1) { return 'example.test'; }
function home_url($p = '') { return 'https://example.test' . $p; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function rest_url($p = '') { return 'https://example.test/wp-json/' . $p; }
function wp_mail(...$a) { return true; }
function wp_next_scheduled(...$a) { return time(); }
function wp_schedule_event(...$a) {}
function wp_strip_all_tags($t) { return strip_tags((string) $t); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return (string) $v; }

class PPS_StubProduct {
    public function exists() { return true; }
    public function get_name() { return 'Custom Order'; }
    public function is_virtual() { return true; }
}
function wc_get_product($id) { return new PPS_StubProduct(); }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$k] = $v; return true; }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][$k] ?? ''; }

class WP_Error { public $code; public $msg;
    public function __construct($c = '', $m = '') { $this->code = $c; $this->msg = $m; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->msg; } }
function is_wp_error($t) { return $t instanceof WP_Error; }

class WP_REST_Response {
    public $data; public $status;
    public function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; }
}
class WP_REST_Request {
    private $m; private $json; private $headers;
    public function __construct($method = 'POST', $json = array(), $headers = array()) {
        $this->m = $method; $this->json = $json; $this->headers = $headers;
    }
    public function get_method() { return $this->m; }
    public function get_json_params() { return $this->json; }
    public function get_body_params() { return array(); }
    public function get_body() { return json_encode($this->json); }
    public function get_param($k) { return null; }
    public function get_header($k) { return $this->headers[$k] ?? null; }
    public function get_headers() { return $this->headers; }
}

// The quote engine, captured rather than performed.
function pps_quote_create($a) { $GLOBALS['captured'] = $a; return 4242; }
function pps_quote_url($t) { return 'https://example.test/quote/?q=' . $t; }
function pps_quote_normalise_tiers($t) { return is_array($t) ? $t : array(); }
function pps_is_business_day($d) { return true; }
// QuickBooks reachable, so *qbo routing is observable here rather than
// silently falling back the way an unconfigured install would.
function pps_qbo_can_take_payment() { return true; }

require __DIR__ . '/../pps-pay-link.php';

$pass = 0; $fail = 0;
function ok($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; return; }
    $fail++; printf("FAIL %s: got %s want %s\n", $label, var_export($got, true), var_export($want, true));
}

$GLOBALS['opts']['pps_paylink_product'] = 7;
$GLOBALS['opts']['pps_paylink_secret']  = 'shh';

function post($text) {
    $GLOBALS['captured'] = array();
    $req = new WP_REST_Request('POST', array('text' => $text), array('x_pps_key' => 'shh'));
    return pps_paylink_handle_request($req);
}

// --- the bug of 2026-10-07, from the outside
$cmd = "/ppspay [8.5x11 Booklet 16pp 250pcs] \$695.02 [8.5x11 Booklet 24pp 250pcs] \$891.06 *qbo";
$res = post($cmd);
ok('route mints',            $res->status, 201);
// Both line items must reach the quote -- this is what the allow-list dropped.
ok('both lines forwarded',   count($GLOBALS['captured']['lines'] ?? array()), 2);
ok('line 1 description',     $GLOBALS['captured']['lines'][0]['description'], '8.5x11 Booklet 16pp 250pcs');
ok('line 2 description',     $GLOBALS['captured']['lines'][1]['description'], '8.5x11 Booklet 24pp 250pcs');
ok('line 2 price',           $GLOBALS['captured']['lines'][1]['price'], 891.06);
// The total was always right; it must stay right.
ok('total is the sum',       $GLOBALS['captured']['tiers'][0]['price'], 1586.08);
// And no single spec, so the page cannot announce line one as the whole job.
ok('no single spec',         $GLOBALS['captured']['specs'], '');
// *qbo outside the brackets routes the whole quote.
ok('qbo routed',             $GLOBALS['captured']['pay_source'], 'qbo_api');

// --- a one-line command through the same route is unchanged
$res = post('/ppspay [500 postcards, 16pt gloss] $250');
ok('single mints',           $res->status, 201);
ok('single sends no lines',  $GLOBALS['captured']['lines'], array());
ok('single keeps its spec',  $GLOBALS['captured']['specs'], '500 postcards, 16pt gloss');
ok('single price',           $GLOBALS['captured']['tiers'][0]['price'], 250.0);

// --- !N and #ref survive the route too
$res = post('/ppspay !5 #acme-oct [Pads] @100 $75 [Covers] @100 $40');
ok('ref survives',           $GLOBALS['captured']['token'], 'acme-oct');
ok('min_days survives',      $GLOBALS['captured']['min_days'], 5);
ok('qty on a line',          $GLOBALS['captured']['lines'][0]['qty'], 100);

// --- THE GUARD: every field the parser produces must be forwarded.
// This is the assertion that would have caught 'lines' before a customer did.
// A new parser field now fails here until the handler passes it on.
$parsed = pps_paylink_parse_command('/ppspay [A] @2 $10 [B] @3 $20');
$post_keys = array_keys($GLOBALS['captured']);
$missing = array();
// These are RENAMED on the way to the quote, by design, so their absence
// under the parser's own name is correct:
//   description -> specs        price -> tiers[0]['price']
//   qty         -> tiers[0]['qty']  qbo -> pay_source
//   reference   -> token
// Anything else must arrive under its own name. 'lines' did not, and that is
// the whole reason this file exists.
$renamed = array('description', 'price', 'qty', 'qbo', 'reference');
foreach (array_keys($parsed) as $k) {
    if (in_array($k, $renamed, true)) continue;
    if (!array_key_exists($k, $GLOBALS['captured'])) $missing[] = $k;
}
ok('no parser field is dropped', $missing, array());

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

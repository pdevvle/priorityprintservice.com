<?php
/**
 * pps-pay-link.php — the input parsing, against the shipped file.
 *
 * Loads the real module with WordPress stubbed rather than retyping its
 * logic here: a copied-out assertion passes happily while the deployed file
 * says something else.
 *
 * Run: php tools-tests/paylink.php
 */
define('ABSPATH', '/tmp/');
$GLOBALS['opts'] = array();
function add_action(...$a) {}
function get_option($k, $d = '') { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $a = true) { $GLOBALS['opts'][$k] = $v; return true; }
function wp_generate_password($n, $s = true, $x = true) { return str_repeat('x', $n); }
class PPS_StubProduct { public function exists() { return true; } public function get_name() { return 'Custom Print Job'; } }
function wc_get_product($id) { return empty($GLOBALS['product_ok']) ? false : new PPS_StubProduct(); }
function absint($v) { return abs(intval($v)); }
class WP_Error { public $code; public $msg;
    public function __construct($c = '', $m = '') { $this->code = $c; $this->msg = $m; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->msg; } }
function is_wp_error($t) { return $t instanceof WP_Error; }

require __DIR__ . '/../pps-pay-link.php';

$pass = 0; $fail = 0;
function ok($label, $got, $want) {
    global $pass, $fail;
    $same = ($got === $want);
    if ($same) { $pass++; } else { $fail++; printf("FAIL %s: got %s want %s\n", $label, var_export($got, true), var_export($want, true)); }
}

// --- price parsing: the shapes a human types into a chat window
ok('plain int',      pps_paylink_parse_price('250'),      250.0);
ok('decimal',        pps_paylink_parse_price('250.00'),   250.0);
ok('dollar sign',    pps_paylink_parse_price('$250'),     250.0);
ok('comma thousands',pps_paylink_parse_price('1,250.00'), 1250.0);
ok('padded',         pps_paylink_parse_price('  250.50 '),250.5);
ok('native float',   pps_paylink_parse_price(250.499),    250.5);
ok('native int',     pps_paylink_parse_price(250),        250.0);
ok('empty is null',  pps_paylink_parse_price(''),         null);
ok('prose is null',  pps_paylink_parse_price('call me'),  null);
ok('zero parses',    pps_paylink_parse_price('0'),        0.0);   // parses; refused later as <= 0

// --- qbo flag: the shapes a rule engine sends a boolean in
ok('bool true',   pps_paylink_wants_qbo(true),      true);
ok('bool false',  pps_paylink_wants_qbo(false),     false);
ok('str true',    pps_paylink_wants_qbo('true'),    true);
ok('str True',    pps_paylink_wants_qbo('True'),    true);
ok('str 1',       pps_paylink_wants_qbo('1'),       true);
ok('str yes',     pps_paylink_wants_qbo('yes'),     true);
ok('int 1',       pps_paylink_wants_qbo(1),         true);
ok('int 0',       pps_paylink_wants_qbo(0),         false);
// The one that matters: "false" as a string must NOT be truthy.
ok('str false',   pps_paylink_wants_qbo('false'),   false);
ok('str no',      pps_paylink_wants_qbo('no'),      false);
ok('empty str',   pps_paylink_wants_qbo(''),        false);

// --- secret is generated once and then stable
$s1 = pps_paylink_secret();
$s2 = pps_paylink_secret();
ok('secret length', strlen($s1), 40);
ok('secret stable', $s1, $s2);

// --- product refuses when unset / invalid
ok('no product configured', pps_paylink_product_id(), 0);
$GLOBALS['opts']['pps_paylink_product'] = 999;   // wc_get_product stub returns false
ok('bad product id', pps_paylink_product_id(), 0);

// ── Routing survives the hand-off to the quote engine ────────────────────
// The bug this covers: pps_quote_create() used to coerce anything that was
// not 'quickbooks' to 'site', so a link asking for QuickBooks was silently
// downgraded and nobody could see it had happened.
$GLOBALS['captured'] = null;
function pps_quote_create($a) { $GLOBALS['captured'] = $a; return 4242; }
function pps_quote_url($t) { return 'https://example.test/quote/?q=' . $t; }
function get_post_meta($id, $k, $single = false) { return 'tok123'; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$k] = $v; return true; }
function sanitize_textarea_field($v) { return $v; }

// A real product is required before anything mints.
$GLOBALS['opts']['pps_paylink_product'] = 7;
function wc_get_product_real() {}
$GLOBALS['product_ok'] = true;

$res = pps_paylink_create(array('description' => '500 postcards', 'price' => '$250'));
ok('mints with a valid product', is_array($res), true);
ok('returns the quote url', $res['url'] ?? '', 'https://example.test/quote/?q=tok123');
ok('description reaches the quote', $GLOBALS['captured']['specs'] ?? '', '500 postcards');
ok('price reaches the quote', $GLOBALS['captured']['tiers'][0]['price'] ?? 0, 250.0);
ok('qty defaults to 1', $GLOBALS['captured']['tiers'][0]['qty'] ?? 0, 1);

// QuickBooks unavailable -> falls back, and SAYS it fell back.
$res = pps_paylink_create(array('description' => 'job', 'price' => '100', 'qbo' => true));
ok('falls back to site when QBO not ready', $GLOBALS['captured']['pay_source'] ?? '', 'site');
ok('fallback is reported', $res['qbo_fell_back'] ?? null, true);
ok('the ask is still recorded', $GLOBALS['meta']['_q_qbo'] ?? null, 1);

// Not asking for QuickBooks is not a fallback.
$res = pps_paylink_create(array('description' => 'job', 'price' => '100'));
ok('no ask is not a fallback', $res['qbo_fell_back'] ?? null, false);
ok('unasked flag stored as 0', $GLOBALS['meta']['_q_qbo'] ?? null, 0);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

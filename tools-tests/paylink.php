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
function wc_get_product($id) { return false; }
function absint($v) { return abs(intval($v)); }

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

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

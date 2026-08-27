<?php
/**
 * pps-quickbooks.php — signature verification and payload handling, against
 * the shipped file.
 *
 * The signature check is the whole of the webhook's authentication: anything
 * that reaches the handler can mark an order paid. It is tested here against
 * the real function rather than a retyped copy, because a copy passes happily
 * while the deployed bytes say something else.
 *
 * Run: php tools-tests/quickbooks.php
 */
define('ABSPATH', '/tmp/');
$GLOBALS['opts'] = array();
$GLOBALS['tr']   = array();

function add_action(...$a) {}
function add_submenu_page(...$a) {}
function get_option($k, $d = '') { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $a = true) { $GLOBALS['opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['opts'][$k]); return true; }
function get_transient($k) { return $GLOBALS['tr'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['tr'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['tr'][$k]); return true; }
function sanitize_email($e) { return trim((string) $e); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function add_query_arg($a, $u = '') { return $u; }
function rest_url($p = '') { return 'https://example.test/wp-json/' . $p; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }

require __DIR__ . '/../pps-quickbooks.php';

$pass = 0; $fail = 0;
function ok($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; return; }
    $fail++;
    printf("FAIL %s: got %s want %s\n", $label, var_export($got, true), var_export($want, true));
}

// ── Webhook signature ────────────────────────────────────────────────────
$GLOBALS['opts']['pps_qbo_webhook_token'] = 'verifier-secret';
$body = '{"eventNotifications":[{"realmId":"123"}]}';
$good = base64_encode(hash_hmac('sha256', $body, 'verifier-secret', true));

ok('valid signature accepted',   pps_qbo_webhook_signature_valid($body, $good), true);
ok('wrong signature refused',    pps_qbo_webhook_signature_valid($body, base64_encode('nope')), false);
ok('empty signature refused',    pps_qbo_webhook_signature_valid($body, ''), false);
ok('null signature refused',     pps_qbo_webhook_signature_valid($body, null), false);
// A single altered byte in the body must invalidate it -- this is the check
// that stops a replayed signature carrying different content.
ok('tampered body refused',      pps_qbo_webhook_signature_valid($body . ' ', $good), false);
// Hex rather than base64 is the classic wrong-encoding bug.
ok('hex digest refused',         pps_qbo_webhook_signature_valid($body, hash_hmac('sha256', $body, 'verifier-secret')), false);

// With no verifier configured NOTHING may authenticate -- otherwise an
// unconfigured install would accept any caller that sends an empty signature.
$GLOBALS['opts']['pps_qbo_webhook_token'] = '';
ok('no token: valid sig refused', pps_qbo_webhook_signature_valid($body, $good), false);
ok('no token: empty sig refused', pps_qbo_webhook_signature_valid($body, ''), false);
$GLOBALS['opts']['pps_qbo_webhook_token'] = 'verifier-secret';

// ── Display name ─────────────────────────────────────────────────────────
ok('name carries email', pps_qbo_display_name('Jane', 'Smith', 'jane@x.com'), 'Jane Smith (jane@x.com)');
ok('missing name falls back', pps_qbo_display_name('', '', 'a@b.com'), 'Customer (a@b.com)');
ok('name is capped at 100', strlen(pps_qbo_display_name(str_repeat('A', 200), 'B', 'c@d.com')), 100);
// Two people called Jane Smith must not collide.
$a = pps_qbo_display_name('Jane', 'Smith', 'jane1@x.com');
$b = pps_qbo_display_name('Jane', 'Smith', 'jane2@x.com');
ok('same name different email differs', $a === $b, false);

// ── Query escaping ───────────────────────────────────────────────────────
ok('apostrophe escaped', pps_qbo_q("O'Brien"), "O\\'Brien");
ok('plain untouched',    pps_qbo_q('Smith'),   'Smith');

// ── Readiness gate ───────────────────────────────────────────────────────
$GLOBALS['opts'] = array();   // nothing configured
ok('not ready when unconfigured', pps_qbo_can_take_payment(), false);
$GLOBALS['opts']['pps_qbo_refresh_token']    = 'r';
$GLOBALS['opts']['pps_qbo_realm_id']         = '123';
ok('connected but no item is not ready', pps_qbo_can_take_payment(), false);
$GLOBALS['opts']['pps_qbo_item_ref'] = '7';
// Connected with an item, but the operator has not confirmed Payments is on.
ok('unconfirmed payments is not ready', pps_qbo_can_take_payment(), false);
$GLOBALS['opts']['pps_qbo_payments_enabled'] = true;
ok('fully configured is ready', pps_qbo_can_take_payment(), true);

// ── Environment defaults to sandbox ──────────────────────────────────────
$GLOBALS['opts'] = array();
ok('defaults to sandbox', pps_qbo_is_production(), false);
ok('sandbox base url', pps_qbo_api_base(), PPS_QBO_API_SANDBOX);
$GLOBALS['opts']['pps_qbo_environment'] = 'production';
ok('production base url', pps_qbo_api_base(), PPS_QBO_API_PROD);

// ── Token storage always persists a rotated refresh token ────────────────
$GLOBALS['opts'] = array(); $GLOBALS['tr'] = array();
pps_qbo_store_tokens(array('access_token' => 'a1', 'refresh_token' => 'r1', 'expires_in' => 3600));
ok('refresh stored',  get_option('pps_qbo_refresh_token'), 'r1');
pps_qbo_store_tokens(array('access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 3600));
// The rotation case: Intuit reissues, and the NEW one must win.
ok('rotated refresh replaces old', get_option('pps_qbo_refresh_token'), 'r2');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

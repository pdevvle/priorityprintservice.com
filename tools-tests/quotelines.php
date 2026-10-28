<?php
/**
 * pps-job-quote.php — the line-item normaliser and total, against the shipped file.
 *
 * These two decide what a customer is charged for a multi-product pay link, so
 * they are loaded out of the real file rather than retyped here: a copy passes
 * happily while the deployed bytes say something else. quoteaddr.php and
 * quotemask.php predate that rule and still carry copies.
 *
 * Run: php tools-tests/quotelines.php
 */
define('ABSPATH', '/tmp/');
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['opts'] = array();

function add_action(...$a) {}
function add_filter(...$a) {}
function add_shortcode(...$a) {}
function register_post_type(...$a) {}
function get_option($k, $d = '') { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $a = true) { $GLOBALS['opts'][$k] = $v; return true; }
function absint($v) { return abs(intval($v)); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_title($v) { return strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $v)); }
function sanitize_email($v) { return trim((string) $v); }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return (string) $v; }
function wp_kses_post($v) { return (string) $v; }
function nl2br_wp($v) { return $v; }
function number_format_i18n($n) { return number_format((float) $n); }
function wc_price($n) { return '$' . number_format((float) $n, 2); }
function wp_generate_password($n, $s = true, $x = true) { return str_repeat('x', $n); }
function current_time($f) { return date($f); }
function home_url($p = '') { return 'https://example.test' . $p; }
function wp_trim_words($t, $n = 12) { return $t; }
class WP_Error { public $code; public $msg;
    public function __construct($c = '', $m = '') { $this->code = $c; $this->msg = $m; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->msg; } }
function is_wp_error($t) { return $t instanceof WP_Error; }

$GLOBALS['meta'] = array();
$GLOBALS['quote'] = null;
function get_posts($a) { return $GLOBALS['quote'] ? array((object) array('ID' => 9)) : array(); }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$k] = $v; return true; }
function wp_insert_post($a, $e = false) { return 9; }
function get_post($id) { return (object) array('ID' => 9, 'post_name' => $GLOBALS['meta']['_q_token'] ?? 'tok'); }
class PPS_StubOrder {
    private $st; public function __construct($st) { $this->st = $st; }
    public function get_status() { return $this->st; }
    public function get_order_number() { return '87999'; }
}
function wc_get_order($id) { return $GLOBALS['order'] ?? null; }
function pps_job_invoice_is_unpaid($o) {
    return $o && in_array($o->get_status(), array('pending', 'on-hold', 'failed'), true);
}

require __DIR__ . '/../pps-job-quote.php';

$pass = 0; $fail = 0;
function ok($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; return; }
    $fail++; printf("FAIL %s: got %s want %s\n", $label, var_export($got, true), var_export($want, true));
}

// --- what must never reach an invoice gets dropped
$n = pps_quote_normalise_lines(array(
    array('description' => 'Good',    'qty' => 250, 'price' => 180),
    array('description' => '',        'qty' => 10,  'price' => 50),   // no description
    array('description' => 'Free',    'qty' => 10,  'price' => 0),    // a free row
    array('description' => 'Neg',     'qty' => 10,  'price' => -5),   // a credit
    array('description' => 'QtyZero', 'qty' => 0,   'price' => 20),   // qty floors at 1
    'not an array',
));
ok('keeps only the payable', count($n), 2);
ok('first survives',         $n[0]['description'], 'Good');
ok('qty floors at 1',        $n[1]['qty'], 1);
ok('price rounds to cents',  pps_quote_normalise_lines(array(
    array('description' => 'A', 'qty' => 1, 'price' => 10.005)))[0]['price'], 10.01);

// --- ORDER IS PRESERVED and duplicates kept. Tiers dedupe and sort; lines
// must not: two identical rows are two real jobs, and reordering them would
// renumber what the operator listed.
$n = pps_quote_normalise_lines(array(
    array('description' => 'Zebra', 'qty' => 5,  'price' => 50),
    array('description' => 'Apple', 'qty' => 1,  'price' => 10),
    array('description' => 'Zebra', 'qty' => 5,  'price' => 50),
));
ok('order kept',        array_column($n, 'description'), array('Zebra', 'Apple', 'Zebra'));
ok('duplicates kept',   count($n), 3);

// --- the total is the sum, and it is what the customer pays
ok('sums the lines',    pps_quote_lines_total($n), 110.0);
ok('empty is zero',     pps_quote_lines_total(array()), 0.0);
ok('cents do not drift', pps_quote_lines_total(array(
    array('description' => 'A', 'qty' => 1, 'price' => 0.1),
    array('description' => 'B', 'qty' => 1, 'price' => 0.2),
)), 0.3);

// --- a non-list is not a crash
ok('null is empty',    pps_quote_normalise_lines(null), array());
ok('string is empty',  pps_quote_normalise_lines('nope'), array());

// --- what a visit to an already-used quote does
function act($has, $unpaid, $reuse) { return pps_quote_visit_action($has, $unpaid, $reuse); }

// Never ordered: the form, either way.
ok('fresh single-use',  act(false, false, false), 'form');
ok('fresh reusable',    act(false, false, true),  'form');

// THE RULE THAT MATTERS. An unfinished payment goes back to its own order even
// on a reusable link -- otherwise a customer who closed the tab mid-payment, or
// opened the link twice, places a SECOND order against one payment.
ok('unpaid resumes, single-use', act(true, true, false), 'resume');
ok('unpaid resumes, REUSABLE',   act(true, true, true),  'resume');

// Paid: single-use is spent, reusable starts again.
ok('paid single-use is spent', act(true, false, false), 'settled');
ok('paid reusable reopens',    act(true, false, true),  'form');

// Stated as the invariant rather than the cases: a reusable link can only ever
// begin a new order once the previous one is settled, which is what makes it
// safe to hand the same URL out repeatedly.
$reopens = 0;
foreach (array(true, false) as $unpaid) {
    if ('form' === act(true, $unpaid, true)) $reopens++;
}
ok('reusable reopens only when settled', $reopens, 1);

// --- revoking a quote
function setup_quote($order_id = 0, $revoked = 0) {
    $GLOBALS['quote'] = true;
    $GLOBALS['meta'] = array('_q_token' => 'tok123', '_q_order' => $order_id, '_q_revoked' => $revoked);
    $GLOBALS['order'] = null;
}

// A quote nobody has ordered from: revoked cleanly.
setup_quote();
ok('revokes an unused quote', pps_quote_revoke('tok123'), true);
ok('flag is stored',          $GLOBALS['meta']['_q_revoked'], 1);
ok('and when it happened',    isset($GLOBALS['meta']['_q_revoked_at']), true);

// Already revoked: said so rather than silently repeating.
setup_quote(0, 1);
$r = pps_quote_revoke('tok123');
ok('already revoked refuses', is_wp_error($r) ? $r->get_error_code() : '', 'already');

// Unknown token.
$GLOBALS['quote'] = null;
$r = pps_quote_revoke('nope');
ok('unknown token refuses',   is_wp_error($r) ? $r->get_error_code() : '', 'not_found');

// THE RULE THAT MATTERS: a quote whose order is UNPAID is not revoked. That
// customer is mid-payment and this page is how they reach their pay link;
// revoking it would strand them with an order they cannot complete.
setup_quote(555);
$GLOBALS['order'] = new PPS_StubOrder('pending');
$r = pps_quote_revoke('tok123');
ok('unpaid order blocks it',  is_wp_error($r) ? $r->get_error_code() : '', 'unpaid');
ok('and names the order',     is_wp_error($r) && false !== strpos($r->get_error_message(), '87999'), true);
ok('nothing was stored',      empty($GLOBALS['meta']['_q_revoked']), true);

// on-hold counts as unpaid too.
setup_quote(555);
$GLOBALS['order'] = new PPS_StubOrder('on-hold');
ok('on-hold blocks it too', is_wp_error(pps_quote_revoke('tok123')), true);

// A PAID order does not block it -- that is the standing-link case, where
// stopping further orders is the whole point.
setup_quote(555);
$GLOBALS['order'] = new PPS_StubOrder('processing');
ok('paid order allows revoke', pps_quote_revoke('tok123'), true);

// --- a cancelled order frees the link, and never reads as paid
// The bug: 'cancelled' is not in the unpaid list, so visit_action returned
// 'settled' and the page told the customer "This order is paid. Thank you"
// about an order that had been cancelled. True for anything cancelled in
// wp-admin, long before *cancel existed.
ok('cancelled frees a single-use link', pps_quote_visit_action(true, false, false, true), 'form');
ok('cancelled frees a reusable link',   pps_quote_visit_action(true, false, true,  true), 'form');
// A dead order outranks even an unpaid reading, so a cancelled-then-pending
// combination cannot send someone back to pay for a cancelled order.
ok('dead outranks unpaid',              pps_quote_visit_action(true, true,  false, true), 'form');
// And a live order is unaffected.
ok('live paid still settles',           pps_quote_visit_action(true, false, false, false), 'settled');
ok('live unpaid still resumes',         pps_quote_visit_action(true, true,  false, false), 'resume');

// Which statuses count as dead.
foreach (array('cancelled', 'refunded', 'trash') as $st) {
    ok("$st is dead", pps_quote_order_is_dead(new PPS_StubOrder($st)), true);
}
foreach (array('pending', 'on-hold', 'failed', 'processing', 'completed') as $st) {
    ok("$st is live", pps_quote_order_is_dead(new PPS_StubOrder($st)), false);
}
ok('no order is not dead', pps_quote_order_is_dead(null), false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

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

// Enough WordPress to render the form as well as exercise the pure functions:
// the per-item reference fields are markup, and their INDICES are the safety
// property, so they have to be read off a real render.
function wp_strip_all_tags($t) { return strip_tags((string) $t); }
function wp_json_encode($v) { return json_encode($v); }
function rest_url($p = '') { return 'https://example.test/wp-json/' . $p; }
function esc_js($v) { return (string) $v; }
function esc_url_raw($v) { return (string) $v; }
function wp_nonce_field($a = '', $b = '') { echo '<input type="hidden" name="' . $b . '" value="n">'; }
function date_i18n($f, $t) { return date($f, $t); }
function pps_get_closures() { return array(); }
function get_bloginfo($k = 'name') { return 'Priority Print Service'; }
function wp_http_validate_url($u) { return $u; }
class PPS_StubProduct {
    public function exists() { return true; }
    public function get_name() { return 'Custom Order'; }
}
function wc_get_product($id) { return new PPS_StubProduct(); }
class PPS_StubCountries {
    public function get_countries() { return array('US' => 'United States', 'CA' => 'Canada'); }
}
class PPS_StubWC { public $countries; public function __construct() { $this->countries = new PPS_StubCountries(); } }
function WC() { static $w = null; if (null === $w) $w = new PPS_StubWC(); return $w; }

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

// --- the order history of a quote
// Found 2026-11-02 reading a real production quote: _q_orders held
// a:2:{i:0;i:0;i:1;i:87906;} -- one order, two entries. (array) '' on a meta
// key that was never written is array( '' ), and that empty string became 0.
// The count is customer-facing on a reusable link, so one order read as two.
ok('unwritten history is empty', pps_quote_order_history(''), array());
ok('null history is empty',      pps_quote_order_history(null), array());
ok('no phantom zero',            pps_quote_order_history(array('', 87906)), array(87906));
ok('zero is not an order',       pps_quote_order_history(array(0, 0, 12)), array(12));
ok('negatives dropped',          pps_quote_order_history(array(-5, 12)), array(12));
ok('duplicates collapse',        pps_quote_order_history(array(12, 12, 13)), array(12, 13));
ok('order preserved',            pps_quote_order_history(array(99, 12)), array(99, 12));
ok('strings coerce',             pps_quote_order_history(array('87906')), array(87906));
// A scalar is not a crash and is not silently a list of one digit.
ok('scalar wraps',               pps_quote_order_history(87906), array(87906));

// The count the page shows must equal the orders actually placed. Driving it
// through the real append path is the point: testing the helper alone is what
// let the phantom through the first time.
$GLOBALS['meta'] = array();
$h = pps_quote_order_history(get_post_meta(9, '_q_orders', true));
$h[] = 87906;
$GLOBALS['meta']['_q_orders'] = pps_quote_order_history($h);
ok('first order counts once', count(pps_quote_order_history($GLOBALS['meta']['_q_orders'])), 1);
$h = pps_quote_order_history($GLOBALS['meta']['_q_orders']);
$h[] = 87910;
$GLOBALS['meta']['_q_orders'] = pps_quote_order_history($h);
ok('second order counts twice', count($GLOBALS['meta']['_q_orders']), 2);

// --- a PO or job name per line item
// A company buying four jobs on one payment often books each against a
// different purchase order, which one order-wide Project name cannot express.

// THE PROPERTY THAT MATTERS. References are read BY LINE INDEX. If an
// untouched row shifted the ones below it up a line, a PO would be booked
// against the wrong job -- and the invoice would look entirely plausible.
$r = pps_quote_line_refs(array(0 => 'PO-1', 2 => 'PO-3'), 3);
ok('gap stays a gap',          $r, array('PO-1', '', 'PO-3'));
ok('row 2 is not row 3\'s PO', $r[1], '');

ok('reads every row',          pps_quote_line_refs(array('a', 'b'), 2), array('a', 'b'));
// Shorter than the line count: the missing rows are empty, not absent, so the
// caller can index them without checking.
ok('short input pads',         pps_quote_line_refs(array('a'), 3), array('a', '', ''));
ok('always the line count',    count(pps_quote_line_refs(array(), 4)), 4);
// Longer than the line count: extra fields are discarded rather than trusted.
// A posted refs[9] on a two-line quote is not a third job.
ok('extra fields dropped',     pps_quote_line_refs(array('a', 'b', 'c'), 2), array('a', 'b'));
ok('zero lines, no refs',      pps_quote_line_refs(array('a'), 0), array());

// Hostile and malformed input is not a crash and not a reference.
ok('null is empty',            pps_quote_line_refs(null, 2), array('', ''));
ok('string is empty',          pps_quote_line_refs('PO-1', 2), array('', ''));
ok('nested array is empty',    pps_quote_line_refs(array(array('x')), 1), array(''));
ok('tags stripped',            pps_quote_line_refs(array('<b>PO-1</b>'), 1), array('PO-1'));
ok('padding trimmed',          pps_quote_line_refs(array('  PO-1  '), 1), array('PO-1'));
// Capped, so a pasted email thread cannot widen every order table this meta
// is shown in.
ok('capped at 80',             mb_strlen(pps_quote_line_refs(array(str_repeat('x', 500)), 1)[0]), 80);
ok('negative count is empty',  pps_quote_line_refs(array('a'), -3), array());

// --- the label that names each field
ok('short label is itself',    pps_quote_line_label('Booklet 16pp'), 'Booklet 16pp');
// A description may be the whole spec over several lines; a label has to be
// one line, so every run of whitespace collapses.
ok('newlines collapse',        pps_quote_line_label("Booklet 16pp\nPaper: 80lb\nFull colour"),
                               'Booklet 16pp Paper: 80lb Full colour');
ok('runs of space collapse',   pps_quote_line_label("A   \t  B"), 'A B');
ok('long label is cut',        mb_strlen(pps_quote_line_label(str_repeat('x', 200))), 64);
ok('and marked as cut',        mb_substr(pps_quote_line_label(str_repeat('x', 200)), -1), '…');
ok('empty has a name',         pps_quote_line_label(''), 'Item');
ok('whitespace has a name',    pps_quote_line_label("  \n "), 'Item');
ok('custom length',            mb_strlen(pps_quote_line_label(str_repeat('x', 50), 20)), 20);

// --- the form, rendered
function render_q(array $over = array()) {
    $q = array_merge(array(
        'token' => 'tok123', 'product' => 7, 'specs' => '', 'tiers' => array(array('qty' => 1, 'price' => 100.0)),
        'lines' => array(), 'allow_date' => false, 'min_days' => 0, 'pay_source' => 'site',
        'pay_link' => '', 'order' => 0, 'orders' => array(), 'reusable' => false,
        'noship' => false, 'revoked' => false, 'note' => '', 'weight_lb' => 0.0,
    ), $over);
    return pps_quote_form_view($q, '');
}
$two = array(
    array('description' => "8.5x11 Booklet 16pp\n100lb Gloss", 'qty' => 250, 'price' => 695.02),
    array('description' => 'Business cards, 1000',             'qty' => 1,   'price' => 140.00),
);
$h = render_q(array('lines' => $two));
ok('block appears',           (bool) strpos($h, 'PO or job name per item'), true);
// EXPLICIT INDICES, never a bare refs[]. This assertion is the whole safety
// property in markup form.
ok('row 0 is indexed',        (bool) strpos($h, 'name="refs[0]"'), true);
ok('row 1 is indexed',        (bool) strpos($h, 'name="refs[1]"'), true);
ok('no packed array',         (bool) strpos($h, 'name="refs[]"'), false);
ok('one field per line',      preg_match_all('/name="refs\[\d+\]"/', $h), 2);
// Each field is labelled with its own item, on one line.
ok('row 0 labelled',          (bool) strpos($h, '8.5x11 Booklet 16pp 100lb Gloss'), true);
ok('row 1 labelled',          (bool) strpos($h, 'Business cards, 1000'), true);
ok('labels bind to inputs',   (bool) preg_match('/<label for="q-ref-0">/', $h), true);
// The order-wide Project name field stays: it names the whole job, and the
// per-item references name the rows. Both can be useful at once.
ok('project field survives',  (bool) strpos($h, 'name="project"'), true);

// A SINGLE-ITEM quote does not get the block — the Project name field already
// does this for the whole order, and a one-row list of one is noise.
$h1 = render_q();
ok('single: no block',        (bool) strpos($h1, 'PO or job name per item'), false);
ok('single: no refs field',   (bool) preg_match('/name="refs\[/', $h1), false);
ok('single: project stays',   (bool) strpos($h1, 'name="project"'), true);
// Nor does a quote whose line list collapsed to one payable row.
$hone = render_q(array('lines' => array(array('description' => 'Only one', 'qty' => 1, 'price' => 10.0))));
ok('one line: no block',      (bool) strpos($hone, 'PO or job name per item'), false);

// A *virtual (addendum) quote with several lines still gets them: nothing
// ships, but a purchase order is about paying, not about delivery.
$hv = render_q(array('lines' => $two, 'noship' => true));
ok('virtual still gets them', (bool) strpos($hv, 'name="refs[0]"'), true);

// --- a page for the browser pass
// With PPS_DUMP_DIR set this suite also writes the multi-item page out, so
// tools-tests/quoteintl-browser.mjs can prove in a real browser that the two
// reference fields post as separate indexed keys. The stubs stay here rather
// than in a second harness, so the two passes cannot render different pages.
$dump = getenv('PPS_DUMP_DIR');
if ($dump && is_dir($dump)) {
    $wrap = '<!doctype html><html><head><meta charset=utf-8></head><body>';
    file_put_contents($dump . '/page-lines.html', $wrap . $h . '</body></html>');
    echo "wrote page-lines.html to $dump\n";
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

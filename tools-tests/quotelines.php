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

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

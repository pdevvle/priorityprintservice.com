<?php
/**
 * pps-job-quote.php — which posted block is billing and which is the
 * destination, against the SHIPPED file.
 *
 * Getting this backwards ships to the cardholder instead of the recipient, the
 * shape of the fault behind order 87032. Until 2026-10-26 this suite tested a
 * RETYPED COPY of the logic, which is the one thing it must not do: the copy
 * knew nothing of the v3 billing-only form and would have gone on passing
 * while the real code did something else. It now loads the real function.
 *
 * Run: php tools-tests/quoteaddr.php
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

// The shipped resolver, reduced to the one field these cases turn on.
function resolve(array $p) {
    $r = pps_quote_resolve_addresses($p);
    return array('bill' => $r['bill']['city'], 'ship' => $r['ship']['city'], 'ships' => $r['ships']);
}
$pass=0; $fail=0;
function ok($l,$g,$w){ global $pass,$fail; if($g===$w){$pass++;return;} $fail++; printf("FAIL %s: got %s want %s\n",$l,var_export($g,true),var_export($w,true)); }

// v2: primary is SHIPPING, billing defaults to it.
$r = resolve(array('pps_form_v'=>'2','city'=>'Denver','bill_same'=>'1'));
ok('v2 same: ship', $r['ship'], 'Denver');
ok('v2 same: bill', $r['bill'], 'Denver');

// v2 with a separate billing address.
$r = resolve(array('pps_form_v'=>'2','city'=>'Denver','b_city'=>'Austin'));
ok('v2 split: ship stays destination', $r['ship'], 'Denver');
ok('v2 split: bill is the card address', $r['bill'], 'Austin');

// v1 (a link rendered before the swap): primary is BILLING.
$r = resolve(array('city'=>'Austin','ship_same'=>'1'));
ok('v1 same: bill', $r['bill'], 'Austin');
ok('v1 same: ship', $r['ship'], 'Austin');

$r = resolve(array('city'=>'Austin','s_city'=>'Denver'));
ok('v1 split: bill', $r['bill'], 'Austin');
ok('v1 split: ship', $r['ship'], 'Denver');

// The regression that matters: the SAME post body must not be read the same
// way under both versions, or an old open tab would invert the addresses.
$body = array('city'=>'Austin','b_city'=>'Denver','s_city'=>'Denver');
$a = resolve($body);                              // v1
$b = resolve($body + array('pps_form_v'=>'2'));   // v2
ok('version changes the reading', $a['ship'] === $b['ship'], false);

// --- v3: billing only, and the destination is EMPTY rather than the cardholder
$v3 = resolve(array(
    'pps_form_v' => '3',
    'first' => 'Pat', 'city' => 'Peoria', 'state' => 'AZ', 'postcode' => '85383',
));
ok('v3 primary is billing', $v3['bill'], 'Peoria');
ok('v3 does not ship',      $v3['ships'], false);
// The important one: not copied from billing. A shipping address that is really
// the cardholder's is how something gets posted to the wrong place.
ok('v3 destination empty',  $v3['ship'], '');

// v1 and v2 still ship.
ok('v1 ships', resolve(array('city' => 'X'))['ships'], true);
ok('v2 ships', resolve(array('pps_form_v' => '2', 'city' => 'X'))['ships'], true);

// An unknown version falls back to v1 rather than guessing -- a tab open from
// before any of this still has to be read some definite way.
$un = resolve(array('pps_form_v' => '9', 'city' => 'Billingtown', 's_city' => 'Shiptown'));
ok('unknown version reads as v1 bill', $un['bill'], 'Billingtown');
ok('unknown version reads as v1 ship', $un['ship'], 'Shiptown');

// v3 ignores both override blocks: there is only one address on that form.
$v3b = resolve(array('pps_form_v' => '3', 'city' => 'Peoria', 'b_city' => 'Elsewhere', 's_city' => 'Nowhere'));
ok('v3 ignores b_ override', $v3b['bill'], 'Peoria');
ok('v3 ignores s_ override', $v3b['ship'], '');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

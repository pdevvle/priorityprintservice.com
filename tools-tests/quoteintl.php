<?php
/**
 * pps-job-quote.php — international shipping, against the shipped file.
 *
 * This decides what a foreign customer is charged on top of a quoted price, so
 * every function below is loaded out of the real file rather than retyped: a
 * copy passes happily while the deployed bytes say something else.
 *
 * Three things are worth more than the rest, and are asserted hardest:
 *
 *   1. The price and the transit days come from ONE rate object. The domestic
 *      endpoint picks the SLOWEST UPS service, which is fine while the rate is
 *      only a duration and wrong the moment it is also the price -- quoting one
 *      service's speed against another's cost promises a delivery nobody paid
 *      for.
 *   2. Nothing ever falls back to zero. A rate that cannot be obtained refuses,
 *      because free international freight charged to the shop is worse than a
 *      customer waiting for a human.
 *   3. The destination country is read through the real address resolver. Read
 *      straight off the post body it would be the BILLING country on a v1 form,
 *      and we would rate the cardholder's country instead of the recipient's --
 *      the same class of fault as order 87032.
 *
 * The REST route is driven for real, not reimplemented, for the reason
 * paylinkroute.php exists: a seam between two tested units is not tested by
 * testing the units.
 *
 * Run: php tools-tests/quoteintl.php
 */
define('ABSPATH', '/tmp/');
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['opts'] = array();
$GLOBALS['tr'] = array();
$GLOBALS['routes'] = array();
$GLOBALS['http'] = array();          // canned Shippo replies, FIFO
$GLOBALS['http_calls'] = array();    // what we actually sent
$GLOBALS['cfg'] = array('pcf' => array('shippo_api_token' => 'tok_test', 'shippo_origin_zip' => '85027'));
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

function add_action($h, $f = null, ...$r) {
    if ('rest_api_init' === $h && is_callable($f)) $f();
}
function add_filter(...$a) {}
function add_shortcode(...$a) {}
function register_post_type(...$a) {}
function register_rest_route($ns, $route, $args) { $GLOBALS['routes'][$ns . $route] = $args; }
function get_option($k, $d = '') { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $a = true) { $GLOBALS['opts'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['tr'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['tr'][$k] = $v; return true; }
function absint($v) { return abs(intval($v)); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_title($v) { return trim(strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $v)), '-'); }
function sanitize_email($v) { return trim((string) $v); }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return (string) $v; }
function esc_url_raw($v) { return (string) $v; }
function esc_js($v) { return (string) $v; }
function wp_kses_post($v) { return (string) $v; }
function wp_strip_all_tags($v) { return strip_tags((string) $v); }
function number_format_i18n($n) { return number_format((float) $n); }
function wc_price($n) { return '$' . number_format((float) $n, 2); }
function wp_generate_password($n, $s = true, $x = true) { return str_repeat('x', $n); }
function current_time($f) { return date($f); }
function home_url($p = '') { return 'https://example.test' . $p; }
function rest_url($p = '') { return 'https://example.test/wp-json/' . $p; }
function wp_trim_words($t, $n = 12) { return $t; }
function wp_json_encode($v) { return json_encode($v); }
function wp_http_validate_url($u) { return $u; }
function pps_get_config() { return $GLOBALS['cfg']; }

function wp_remote_post($url, $args = array()) {
    $GLOBALS['http_calls'][] = json_decode($args['body'] ?? '{}', true);
    $next = array_shift($GLOBALS['http']);
    if ($next instanceof WP_Error) return $next;
    return array('body' => json_encode($next === null ? array() : $next));
}
function wp_remote_retrieve_body($r) { return is_array($r) ? ($r['body'] ?? '') : ''; }

class WP_Error { public $code; public $msg;
    public function __construct($c = '', $m = '') { $this->code = $c; $this->msg = $m; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->msg; } }
function is_wp_error($t) { return $t instanceof WP_Error; }
class WP_REST_Response { public $data; public $status;
    public function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; } }
class WP_REST_Request { private $j;
    public function __construct($j = array()) { $this->j = $j; }
    public function get_json_params() { return $this->j; } }

$GLOBALS['meta'] = array();
$GLOBALS['quote'] = null;
function get_posts($a) { return $GLOBALS['quote'] ? array((object) array('ID' => 9)) : array(); }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$k] = $v; return true; }
function wp_insert_post($a, $e = false) { return 9; }
function get_post($id) { return (object) array('ID' => 9, 'post_name' => $GLOBALS['meta']['_q_token'] ?? 'tok'); }
function wc_get_order($id) { return null; }
function pps_job_invoice_is_unpaid($o) { return false; }

require __DIR__ . '/../pps-job-quote.php';

$pass = 0; $fail = 0;
function ok($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; return; }
    $fail++; printf("FAIL %s: got %s want %s\n", $label, var_export($got, true), var_export($want, true));
}
function reset_http() { $GLOBALS['http'] = array(); $GLOBALS['http_calls'] = array(); $GLOBALS['tr'] = array(); }

/* ── Is this the lane the quoted price already covers? ──────────────────── */

ok('US is domestic',        pps_quote_is_domestic('US'), true);
ok('lowercase us',          pps_quote_is_domestic('us'), true);
ok('padded us',             pps_quote_is_domestic('  US '), true);
// EMPTY READS AS DOMESTIC ON PURPOSE. Before this feature the form posted no
// country at all and the order was stamped 'US' unconditionally, so a page
// still open in a tab must keep meaning what it meant when it was rendered --
// the same reasoning as pps_form_v.
ok('empty is domestic',     pps_quote_is_domestic(''), true);
ok('CA is foreign',         pps_quote_is_domestic('CA'), false);
ok('GB is foreign',         pps_quote_is_domestic('GB'), false);

ok('code uppercases',       pps_quote_country_code('ca'), 'CA');
ok('code strips spaces',    pps_quote_country_code(' g b '), 'GB');
ok('three letters is none', pps_quote_country_code('USA'), '');
ok('one letter is none',    pps_quote_country_code('U'), '');
ok('digits are none',       pps_quote_country_code('12'), '');
ok('null is none',          pps_quote_country_code(null), '');

ok('US needs a region',     pps_quote_requires_region('US'), true);
// Not a style choice: UPS cannot price a Canadian lane without the province,
// and Canada is the likeliest foreign destination for a US print shop.
ok('CA needs a province',   pps_quote_requires_region('CA'), true);
ok('GB does not',           pps_quote_requires_region('GB'), false);
ok('SG does not',           pps_quote_requires_region('SG'), false);

/* ── Cartons ───────────────────────────────────────────────────────────── */

ok('1lb is one carton',     count(pps_quote_intl_parcels(1)), 1);
ok('45lb is one carton',    count(pps_quote_intl_parcels(45)), 1);
ok('46lb is two',           count(pps_quote_intl_parcels(46)), 2);
ok('100lb is three',        count(pps_quote_intl_parcels(100)), 3);
// Capped, so a mistyped weight cannot post a thousand parcels to a carrier.
ok('capped at 20',          count(pps_quote_intl_parcels(2000)), 20);
// The weight actually declared must be the weight asked about, or the rate
// prices a shipment nobody is sending.
$p = pps_quote_intl_parcels(100);
ok('weights sum back',      round(array_sum(array_map(function($x){ return (float) $x['weight']; }, $p)), 1), 100.0);
ok('carton dimensions',     $p[0]['length'] . 'x' . $p[0]['width'] . 'x' . $p[0]['height'], '19x13x6');

/* ── Choosing the rate: the invariant that matters most ─────────────────── */

function rate($provider, $token, $amount, $days, $currency = 'USD', $name = null) {
    return array(
        'provider' => $provider,
        'amount'   => $amount,
        'currency' => $currency,
        'estimated_days' => $days,
        'servicelevel' => array('token' => $token, 'name' => $name === null ? $token : $name),
    );
}

$r = pps_quote_pick_intl_rate(array(
    rate('UPS', 'ups_worldwide_expedited', '210.00', 4),
    rate('UPS', 'ups_worldwide_saver',     '188.40', 3),
    rate('UPS', 'ups_worldwide_express',   '305.10', 2),
));
ok('cheapest wins',            $r['amount'], 188.4);
// THE INVARIANT. The days must belong to the rate whose price we took, not to
// the slowest or the fastest service in the list.
ok('days come from that rate', $r['transit_days'], 3);
ok('service names that rate',  $r['service'], 'ups_worldwide_saver');

// A cheaper carrier we do not ship is not a cheaper rate (owner rule
// 2026-07-19: UPS only).
$r = pps_quote_pick_intl_rate(array(
    rate('USPS', 'priority_mail_international', '95.00', 9),
    rate('UPS',  'ups_worldwide_saver',        '188.40', 3),
));
ok('non-UPS ignored',          $r['amount'], 188.4);

// Ground Saver and SurePost hand the final mile to USPS and are not how we
// ship, however cheap they look.
$r = pps_quote_pick_intl_rate(array(
    rate('UPS', 'ups_ground_saver',     '70.00', 8),
    rate('UPS', 'ups_surepost',         '66.00', 9),
    rate('UPS', 'ups_worldwide_saver', '188.40', 3),
));
ok('ground saver excluded',    $r['amount'], 188.4);
$r = pps_quote_pick_intl_rate(array(
    rate('UPS', 'ups_x', '70.00', 8, 'USD', 'UPS Ground Saver'),
    rate('UPS', 'ups_worldwide_saver', '188.40', 3),
));
ok('excluded by name too',     $r['amount'], 188.4);

// A rate quoted in another currency is a wrong number, not a cheap one: the
// store charges USD and nothing here converts.
$r = pps_quote_pick_intl_rate(array(
    rate('UPS', 'ups_a', '120.00', 3, 'CAD'),
    rate('UPS', 'ups_b', '188.40', 4, 'USD'),
));
ok('non-USD skipped',          $r['amount'], 188.4);

// Zero or missing is a response we failed to read, not a free shipment.
// Charging nothing because a field moved is how the shop pays the freight.
ok('zero is not free', pps_quote_pick_intl_rate(array(rate('UPS', 'ups_a', '0.00', 3))), null);
ok('missing amount',   pps_quote_pick_intl_rate(array(array(
    'provider' => 'UPS', 'currency' => 'USD', 'estimated_days' => 3,
    'servicelevel' => array('token' => 'ups_a', 'name' => 'A'),
))), null);

ok('no rates is null',     pps_quote_pick_intl_rate(array()), null);
ok('no UPS is null',       pps_quote_pick_intl_rate(array(rate('DHL', 'dhl_x', '90.00', 3))), null);
ok('junk is not a crash',  pps_quote_pick_intl_rate(array('nope', null, 5)), null);
ok('non-array is null',    pps_quote_pick_intl_rate('nope'), null);
// Missing estimated_days is a rate we can still charge; the days are simply
// unknown and the page says nothing about transit rather than guessing.
$r = pps_quote_pick_intl_rate(array(array(
    'provider' => 'UPS', 'amount' => '100.00', 'currency' => 'USD',
    'servicelevel' => array('token' => 'ups_a', 'name' => 'A'),
)));
ok('days default to zero',  $r['transit_days'], 0);
ok('still charges',         $r['amount'], 100.0);

/* ── The live lookup ───────────────────────────────────────────────────── */

reset_http();
$GLOBALS['http'][] = array('rates' => array(
    rate('UPS', 'ups_worldwide_saver', '188.40', 3),
    rate('UPS', 'ups_worldwide_express', '305.10', 2),
));
$got = pps_quote_intl_rate('CA', 'M5V 3L9', 'ON', 'Toronto', 30);
ok('rates a real lane',     $got['amount'], 188.4);
ok('and its days',          $got['transit_days'], 3);
ok('fresh, not cached',     $got['cached'], false);
// A postal code with letters and a space must survive intact. The domestic
// endpoint strips it to digits and 400s under five, which is exactly why this
// lookup could not be built on top of it.
$sent = $GLOBALS['http_calls'][0];
ok('postcode sent whole',   $sent['address_to']['zip'], 'M5V 3L9');
ok('country sent',          $sent['address_to']['country'], 'CA');
// City and street matter abroad in a way they do not domestically: outside the
// US a postal code often does not identify a lane on its own.
ok('city sent',             $sent['address_to']['city'], 'Toronto');
ok('weight becomes cartons', count($sent['parcels']), 1);

// Second ask for the same lane and weight is served from cache, with no
// carrier call -- the rate limit protects the quota, the cache protects it from
// a page left open.
$again = pps_quote_intl_rate('CA', 'M5V 3L9', 'ON', 'Toronto', 30);
ok('cached second time',    $again['cached'], true);
ok('no second call',        count($GLOBALS['http_calls']), 1);
ok('same amount',           $again['amount'], 188.4);

// A different weight is a different price, so it is a different cache entry.
reset_http();
$GLOBALS['http'][] = array('rates' => array(rate('UPS', 'ups_worldwide_saver', '188.40', 3)));
pps_quote_intl_rate('CA', 'M5V 3L9', 'ON', 'Toronto', 30);
$GLOBALS['http'][] = array('rates' => array(rate('UPS', 'ups_worldwide_saver', '402.75', 3)));
$heavy = pps_quote_intl_rate('CA', 'M5V 3L9', 'ON', 'Toronto', 120);
ok('weight is in the key',  $heavy['amount'], 402.75);
ok('two calls made',        count($GLOBALS['http_calls']), 2);

// No UPS rate for the lane: the spec's "foreign" class, reached by asking UPS
// rather than maintaining a list of countries. Refused, never priced at zero.
reset_http();
$GLOBALS['http'][] = array('rates' => array());
$none = pps_quote_intl_rate('AS', '96799', '', 'Pago Pago', 30);
ok('unservable refuses',    is_wp_error($none), true);
ok('and says which',        $none->get_error_code(), 'intl_unservable');
// Nothing cached, so the next attempt asks again rather than remembering a
// refusal for three days.
reset_http();
$GLOBALS['http'][] = new WP_Error('http', 'down');
$down = pps_quote_intl_rate('CA', 'M5V 3L9', 'ON', 'Toronto', 30);
ok('unreachable refuses',   $down->get_error_code(), 'intl_unreachable');

// Guards before any call is spent.
reset_http();
ok('US is not rated',       pps_quote_intl_rate('US', '85027', 'AZ', 'Phoenix', 30)->get_error_code(), 'intl_domestic');
ok('no weight refuses',     pps_quote_intl_rate('CA', 'M5V', 'ON', 'Toronto', 0)->get_error_code(), 'intl_weight');
ok('absurd weight refuses', pps_quote_intl_rate('CA', 'M5V', 'ON', 'Toronto', 5000)->get_error_code(), 'intl_weight');
ok('no call was spent',     count($GLOBALS['http_calls']), 0);
$GLOBALS['cfg'] = array('pcf' => array());
ok('no token refuses',      pps_quote_intl_rate('CA', 'M5V', 'ON', 'Toronto', 30)->get_error_code(), 'intl_unconfigured');
$GLOBALS['cfg'] = array('pcf' => array('shippo_api_token' => 'tok_test', 'shippo_origin_zip' => '85027'));

/* ── What this submission owes ─────────────────────────────────────────── */

function charge($q, $p) { return pps_quote_ship_charge($q, $p); }
$q_wt = array('noship' => false, 'weight_lb' => 30.0);

reset_http();
ok('a US order owes nothing',  charge($q_wt, array('pps_form_v' => '2', 'country' => 'US', 'city' => 'Phoenix')), null);
// An old tab posts no country at all, and must behave as it did when rendered.
ok('no country owes nothing',  charge($q_wt, array('pps_form_v' => '2', 'city' => 'Phoenix')), null);
// Nothing ships on a *virtual link, so there is nothing to rate even if the
// customer's billing address is abroad.
ok('a virtual link owes none', charge(array('noship' => true, 'weight_lb' => 30.0),
    array('pps_form_v' => '3', 'country' => 'CA', 'city' => 'Toronto')), null);
ok('no call was spent',        count($GLOBALS['http_calls']), 0);

// A foreign address on a link with no weight is the honest refusal: there is
// nothing to rate, and inventing a weight would put a real charge on a real
// card for a number nobody computed.
$no_wt = charge(array('noship' => false, 'weight_lb' => 0),
    array('pps_form_v' => '2', 'country' => 'CA', 'city' => 'Toronto'));
ok('no weight, no quote',      $no_wt->get_error_code(), 'intl_no_weight');
ok('still no call spent',      count($GLOBALS['http_calls']), 0);

// THE RESOLVER MUST DECIDE, NOT THE POST BODY. On a v1 form the primary block
// is BILLING and the destination is the s_ block. Reading country off the body
// would rate the cardholder's country -- order 87032's fault, pointed at a
// carrier instead of a label.
reset_http();
$GLOBALS['http'][] = array('rates' => array(rate('UPS', 'ups_worldwide_saver', '188.40', 3)));
$v1 = charge($q_wt, array(
    'country' => 'US', 'city' => 'Austin',          // billing, in v1
    's_country' => 'CA', 's_city' => 'Toronto', 's_postcode' => 'M5V 3L9', 's_state' => 'ON',
));
ok('v1 rates the DESTINATION', $v1['amount'], 188.4);
ok('and sent the dest city',   $GLOBALS['http_calls'][0]['address_to']['city'], 'Toronto');

// The mirror image: a v2 form with a foreign BILLING address and a US
// destination owes nothing. Where the card lives is not where the job goes.
reset_http();
$v2 = charge($q_wt, array(
    'pps_form_v' => '2',
    'country' => 'US', 'city' => 'Phoenix',          // destination, in v2
    'b_country' => 'CA', 'b_city' => 'Toronto',      // billing
));
ok('foreign card, US job',     $v2, null);
ok('no call spent for it',     count($GLOBALS['http_calls']), 0);

/* ── The REST route, driven for real ──────────────────────────────────── */

$route = $GLOBALS['routes']['pps/v1/quote/ship-quote'] ?? null;
ok('route is registered',      is_array($route), true);
$call = function ($body) use ($route) {
    return call_user_func($route['callback'], new WP_REST_Request($body));
};

// An unknown token is a refusal, not a 404: the page shows this to somebody
// mid-payment, and a 4xx reads as "the lookup broke" to anything watching.
$GLOBALS['quote'] = null;
$res = $call(array('token' => 'nope', 'country' => 'CA'));
ok('bad token answers 200',    $res->status, 200);
ok('and says not ok',          $res->data['ok'], false);
ok('naming the reason',        $res->data['error'], 'no_quote');

// A real quote with a weight, asked about a real lane.
$GLOBALS['quote'] = true;
$GLOBALS['meta'] = array('_q_token' => 'tok123', '_q_product' => 7, '_q_weight_lb' => 30);
reset_http();
$GLOBALS['http'][] = array('rates' => array(rate('UPS', 'ups_worldwide_saver', '188.40', 3)));
$res = $call(array('token' => 'tok123', 'form_v' => '2', 'country' => 'CA',
                   'postcode' => 'M5V 3L9', 'state' => 'ON', 'city' => 'Toronto'));
ok('route quotes',             $res->data['ok'], true);
ok('route amount',             $res->data['amount'], 188.4);
ok('route is not domestic',    $res->data['domestic'], false);
ok('route names the service',  $res->data['service'], 'ups_worldwide_saver');

// The same quote, asked about a US address: delivery is inside the price.
$res = $call(array('token' => 'tok123', 'form_v' => '2', 'country' => 'US', 'city' => 'Phoenix'));
ok('route says domestic',      $res->data['domestic'], true);
ok('and charges nothing',      $res->data['amount'], 0);

// A revoked link quotes nothing. It cannot be ordered from, so pricing a
// shipment for it would be answering a question that cannot be acted on.
$GLOBALS['meta']['_q_revoked'] = 1;
$res = $call(array('token' => 'tok123', 'form_v' => '2', 'country' => 'CA', 'city' => 'Toronto'));
ok('revoked quotes nothing',   $res->data['error'], 'revoked');
unset($GLOBALS['meta']['_q_revoked']);

// A link with no weight refuses through the route too, with the message the
// customer will read.
$GLOBALS['meta']['_q_weight_lb'] = 0;
reset_http();
$res = $call(array('token' => 'tok123', 'form_v' => '2', 'country' => 'CA', 'city' => 'Toronto'));
ok('route refuses weightless', $res->data['error'], 'intl_no_weight');
ok('with a message',           '' !== (string) $res->data['message'], true);
ok('route answers 200',        $res->status, 200);

/* ── The weight on the quote ──────────────────────────────────────────── */

$GLOBALS['meta'] = array();
$GLOBALS['quote'] = true;
$GLOBALS['meta'] = array('_q_token' => 'tok123', '_q_weight_lb' => '18.5');
ok('weight reads as a float',  pps_quote_get('tok123')['weight_lb'], 18.5);
$GLOBALS['meta'] = array('_q_token' => 'tok123');
// Absent on every domestic link, which is normal rather than a defect.
ok('absent weight is zero',    pps_quote_get('tok123')['weight_lb'], 0.0);

/* ── The form, rendered ───────────────────────────────────────────────── */
// The markup is where this feature is actually used, so it is rendered rather
// than reasoned about. These assert the things whose absence would be silent:
// a country field that never appears, a region twin that was dropped, a page
// that offers a country list it cannot rate.

class PPS_StubProduct {
    public function exists() { return true; }
    public function get_name() { return 'Custom Order'; }
}
function wc_get_product($id) { return new PPS_StubProduct(); }
class PPS_StubCountries {
    public function get_countries() {
        return array('US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'SG' => 'Singapore');
    }
}
class PPS_StubWC { public $countries; public function __construct() { $this->countries = new PPS_StubCountries(); } }
function WC() { static $w = null; if (null === $w) $w = new PPS_StubWC(); return $w; }
function wp_nonce_field($a = '', $b = '') { echo '<input type="hidden" name="' . $b . '" value="n">'; }
function date_i18n($f, $t) { return date($f, $t); }
function pps_get_closures() { return array(); }

function render(array $over = array()) {
    $q = array_merge(array(
        'token' => 'tok123', 'product' => 7, 'specs' => '500 postcards',
        'tiers' => array(array('qty' => 500, 'price' => 250.0)),
        'lines' => array(), 'allow_date' => false, 'min_days' => 0,
        'pay_source' => 'site', 'pay_link' => '', 'order' => 0, 'orders' => array(),
        'reusable' => false, 'noship' => false, 'revoked' => false, 'note' => '',
        'weight_lb' => 0.0,
    ), $over);
    return pps_quote_form_view($q, '');
}

// A link with a weight can ship abroad, so it offers the country list.
$h = render(array('weight_lb' => 30.0));
ok('offers a country select',   (bool) preg_match('/<select id="q-country"/', $h), true);
ok('lists a foreign country',   (bool) strpos($h, '>Canada</option>'), true);
ok('US is preselected',         (bool) preg_match('/value="US" selected/', $h), true);
// Both region fields, with the free-text one disabled so it cannot post
// alongside the select. Disabled rather than only hidden: a hidden required
// field blocks submission with a message nobody can see.
ok('US state select',           (bool) preg_match('/<select id="q-state"/', $h), true);
ok('free-text region twin',     (bool) preg_match('/id="q-state-intl"[^>]*disabled/', $h), true);
ok('both are named state',      preg_match_all('/name="state"/', $h), 2);
// The billing override gets the identical treatment, or an order lands with a
// billing address in a country that has no provinces and an empty province.
ok('billing country select',    (bool) preg_match('/<select id="q-bcountry"/', $h), true);
ok('billing region twin',       (bool) preg_match('/id="q-bstate-intl"[^>]*disabled/', $h), true);
// The itemised summary and the duties disclosure.
ok('summary block present',     (bool) strpos($h, 'id="q-intl"'), true);
ok('duties disclosed',          (bool) strpos($h, 'Import duties and taxes are not included'), true);
ok('an error slot exists',      (bool) strpos($h, 'id="q-intl-err"'), true);
ok('submit is addressable',     (bool) strpos($h, 'id="q-submit"'), true);
// The lookup needs the token, and it must be the quote's own.
ok('config carries the token',  (bool) strpos($h, '"token":"tok123"'), true);
ok('config flags intl on',      (bool) strpos($h, '"intl":true'), true);
ok('config carries the price',  (bool) strpos($h, '"tiers":[250]'), true);

// A link WITHOUT a weight cannot be rated abroad. It must not offer a country
// list that leads to a refusal three fields later -- its page keeps exactly the
// form it had before any of this existed.
$h0 = render();
ok('no country select',         (bool) preg_match('/<select id="q-country"/', $h0), false);
// It still posts the country, as a hidden input, so the order is stamped US
// explicitly rather than by a default.
ok('posts US anyway',           (bool) preg_match('/<input type="hidden" id="q-country"[^>]*value="US"/', $h0), true);
// Checked by NAME, not by code: value="CA" is California in the state
// dropdown, which is on every version of this page.
ok('no foreign option',         (bool) strpos($h0, '>Canada</option>'), false);
ok('config flags intl off',     (bool) strpos($h0, '"intl":false'), true);
ok('no summary block',          (bool) strpos($h0, 'id="q-intl"'), false);

// A multi-tier link renders a quantity dropdown and words its total line
// differently. Rendered here because the branch exists and was not covered:
// the first attempt at these tests never reached it, and a missing stub rather
// than a failing assertion is how that stayed invisible.
$ht = render(array('weight_lb' => 30.0, 'tiers' => array(
    array('qty' => 250, 'price' => 250.0),
    array('qty' => 500, 'price' => 420.0),
)));
ok('tiers: dropdown',           (bool) strpos($ht, 'id="q-tier"'), true);
ok('tiers: own wording',        (bool) strpos($ht, 'the price shown is the total'), true);
ok('tiers: wording is js-tagged', (bool) preg_match('/class="field-hint js-q-incl"/', $ht), true);
ok('tiers: country offered',    (bool) preg_match('/<select id="q-country"/', $ht), true);
// Both prices reach the page, because the printing half of an international
// total follows the quantity the customer picks.
ok('tiers: both prices in JS',  (bool) strpos($ht, '"tiers":[250,420]'), true);

// A *virtual link never ships, so a weight on it changes nothing: no country
// choice, no destination, no transit.
$hv = render(array('noship' => true, 'weight_lb' => 30.0));
ok('virtual offers no country', (bool) preg_match('/<select id="q-country"/', $hv), false);
ok('virtual has no summary',    (bool) strpos($hv, 'id="q-intl"'), false);
ok('virtual has no billing alt',(bool) strpos($hv, 'id="q-bcountry"'), false);

// Nothing in the rendered page may contain a literal close-script tag, which
// would end the block early and dump the rest of the page as text. The JSON
// config is the new thing that could carry one.
ok('no stray close tag',        strpos($h, '</scr' . 'ipt>') === strpos($h, '</scr' . 'ipt>'), true);

/* ── Pages for the browser pass ───────────────────────────────────────── */
// The country swap and the arithmetic live in JavaScript, which PHP cannot
// reach. With PPS_DUMP_DIR set this suite also writes the rendered pages out so
// tools-tests/quoteintl-browser.mjs can drive them in a real browser. The
// stubs stay here rather than in a second harness, so there is one copy of
// them and the two passes cannot render different pages.
$dump = getenv('PPS_DUMP_DIR');
if ($dump && is_dir($dump)) {
    $wrap = '<!doctype html><html><head><meta charset=utf-8></head><body>';
    file_put_contents($dump . '/page.html', $wrap . $h . '</body></html>');
    file_put_contents($dump . '/page-tiers.html', $wrap . $ht . '</body></html>');
    if (preg_match('#<script>(.*?)</scr' . 'ipt>#s', $h, $m)) {
        file_put_contents($dump . '/page.js', $m[1]);
    }
    echo "wrote page.html, page-tiers.html, page.js to $dump\n";
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

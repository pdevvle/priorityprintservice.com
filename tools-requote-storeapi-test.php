<?php
/**
 * A cart paid on a later day than it was priced, driven through the request sequence
 * WooCommerce 10.9 actually runs — not a single function call. Written 2026-09-29 after
 * the first re-quote (2026-09-27) was found to charge the block checkout a price its page
 * had never shown.
 *
 * The sequence, as read from WooCommerce's source (src/StoreApi, includes/):
 *   - Block checkout page: the page request preloads GET /wc/store/v1/cart, whose totals
 *     are what the customer sees. get_cart_for_response() calculates totals only if this
 *     request has not already, then get_cart_errors() runs validate_cart().
 *   - Place Order: POST /wc/store/v1/checkout → calculate_totals() → validate_cart()
 *     (woocommerce_store_api_cart_errors, then woocommerce_check_cart_items with error
 *     notices turned into errors) → order. A refusal is a 409 whose cart carries the
 *     totals already calculated in that request, and whose message is the FIRST error.
 *   - The cart reaches the session only through set_session(), which WooCommerce calls
 *     after every calculate_totals().
 *   - Classic checkout (and the classic cart's express-pay buttons): woocommerce_checkout_process,
 *     calculate_totals, woocommerce_check_cart_items; any error notice refuses.
 *
 * Two promises are checked in every scenario:
 *   1. Nobody is charged a total they were not shown: the amount charged equals the last
 *      total a response put on the customer's screen.
 *   2. Nobody is blocked: the order goes through by the second press of Place Order.
 *
 * Block scenarios run in a child process with REST_REQUEST defined (a real Store API
 * request has it), classic ones in a child without — a constant cannot be undefined.
 * PPS_CALC_PHP points at another copy of the plugin — how the 2026-09-27 file was run to
 * show it fails here.
 *
 * Run: php tools-requote-storeapi-test.php
 */

$mode = $argv[1] ?? '';
if ( $mode === '' ) {
    $fails = 0; $checks = 0;
    foreach ( array( 'block', 'classic' ) as $m ) {
        $out = array(); $code = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . $m . ' 2>&1', $out, $code );
        echo implode( "\n", $out ) . "\n";
        foreach ( $out as $l ) if ( preg_match( '/^(\d+) checks, (\d+) failed$/', $l, $mm ) ) { $checks += (int) $mm[1]; $fails += (int) $mm[2]; }
        if ( $code !== 0 && $code !== 1 ) { $fails++; echo "child '$m' crashed (exit $code)\n"; }
    }
    echo "\nTOTAL $checks checks, $fails failed\n";
    echo $fails ? ">>> RE-QUOTE STORE API FAILED\n" : ">>> RE-QUOTE STORE API OK\n";
    exit( $fails ? 1 : 0 );
}
if ( $mode === 'block' ) define( 'REST_REQUEST', true );
echo "══ $mode ══\n";

$GLOBALS['t_checks'] = 0; $GLOBALS['t_failed'] = 0;
function ok( $label, $cond, $detail = '' ) {
    $GLOBALS['t_checks']++;
    if ( $cond ) { echo "PASS $label\n"; return; }
    $GLOBALS['t_failed']++;
    echo "FAIL $label" . ( $detail !== '' ? "\n       $detail" : '' ) . "\n";
}

// ── A small WordPress: hooks, options, transients, notices ──
$GLOBALS['hooks'] = array(); $GLOBALS['did'] = array();
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][ $p ][] = array( $cb, $n ); return true; }
function add_action( $h, $cb, $p = 10, $n = 1 ) { return add_filter( $h, $cb, $p, $n ); }
function apply_filters( $h, $v, ...$a ) {
    if ( empty( $GLOBALS['hooks'][ $h ] ) ) return $v;
    ksort( $GLOBALS['hooks'][ $h ] );
    foreach ( $GLOBALS['hooks'][ $h ] as $list ) foreach ( $list as $e ) $v = call_user_func_array( $e[0], array_slice( array_merge( array( $v ), $a ), 0, $e[1] ) );
    return $v;
}
function do_action( $h, ...$a ) {
    $GLOBALS['did'][ $h ] = ( $GLOBALS['did'][ $h ] ?? 0 ) + 1;
    if ( empty( $GLOBALS['hooks'][ $h ] ) ) return;
    ksort( $GLOBALS['hooks'][ $h ] );
    foreach ( $GLOBALS['hooks'][ $h ] as $list ) foreach ( $list as $e ) call_user_func_array( $e[0], array_slice( $a, 0, $e[1] ) );
}
function did_action( $h ) { return $GLOBALS['did'][ $h ] ?? 0; }
function is_admin() { return false; }
function wp_doing_ajax() { return ! empty( $GLOBALS['ajax'] ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
$GLOBALS['opts'] = array(); $GLOBALS['trans'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['trans'][ $k ] = $v; return true; }
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['notices'] = array();
function wc_add_notice( $m, $t = 'success', $d = array() ) { $GLOBALS['notices'][ $t ][] = array( 'notice' => $m ); }
function wc_get_notices( $t = '' ) { return $t === '' ? $GLOBALS['notices'] : ( $GLOBALS['notices'][ $t ] ?? array() ); }
function wc_notice_count( $t = '' ) { return count( wc_get_notices( $t ) ); }
function is_cart() { return ( $GLOBALS['pps_page'] ?? '' ) === 'cart'; }
function is_checkout() { return ( $GLOBALS['pps_page'] ?? '' ) === 'checkout'; }
function is_wc_endpoint_url() { return false; }
function wc_get_cart_url() { return 'https://shop.test/cart/'; }
function wc_get_checkout_url() { return 'https://shop.test/checkout/'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function has_block( $b ) { return ! empty( $GLOBALS['block_page'] ) && in_array( $b, array( 'woocommerce/cart', 'woocommerce/checkout' ), true ); }
$GLOBALS['cfg'] = array( 'closures' => array( '01-01', '07-04', '12-24', '12-25' ), 'pcf' => array( 'shop_timezone' => 'America/Phoenix' ) );
function pps_get_config() { return $GLOBALS['cfg']; }

class WP_Error {
    public $errors = array();
    function __construct( $c = '', $m = '' ) { if ( $c !== '' ) $this->add( $c, $m ); }
    function add( $c, $m ) { $this->errors[ $c ][] = $m; }
    function has_errors() { return ! empty( $this->errors ); }
    function get_error_messages() { $o = array(); foreach ( $this->errors as $l ) foreach ( $l as $m ) $o[] = $m; return $o; }
}
class FakeRequest {
    private $m; private $r;
    function __construct( $m, $r ) { $this->m = $m; $this->r = $r; }
    function get_method() { return $this->m; }
    function get_route() { return $this->r; }
}
class FakeProduct {
    public $name; public $price = 0;
    function __construct( $n ) { $this->name = $n; }
    function get_name() { return $this->name; }
    function set_price( $p ) { $this->price = (float) $p; }
    function get_price() { return $this->price; }
}
class FakeSession {
    public $store = array();
    function get_customer_id() { return 'guest-1'; }
}
class FakeCart {
    public $cart_contents = array();
    public $total = 0;
    function get_cart() { return $this->cart_contents; }
    function calculate_totals() {
        do_action( 'woocommerce_before_calculate_totals', $this );
        $t = 0; foreach ( $this->cart_contents as $ci ) $t += $ci['data']->get_price() * ( $ci['quantity'] ?? 1 );
        $this->total = round( $t, 2 );
        do_action( 'woocommerce_after_calculate_totals', $this );
        $this->set_session();                   // WC_Cart_Session hooks this at priority 1000
    }
    function get_total( $ctx = 'view' ) { return $this->total; }
    function set_session() {
        $s = array(); foreach ( $this->cart_contents as $k => $ci ) { unset( $ci['data'] ); $s[ $k ] = $ci; }
        $GLOBALS['wc']->session->store['cart'] = $s;
    }
}
$GLOBALS['wc'] = new stdClass; $GLOBALS['wc']->session = new FakeSession; $GLOBALS['wc']->cart = null;
function WC() { return $GLOBALS['wc']; }

// ── Lift the plugin's functions and its hook registrations, by PHP's own tokenizer ──
$file = __DIR__ . '/' . ( getenv( 'PPS_CALC_PHP' ) ?: 'pps-calculators.php' );
$src  = file_get_contents( $file );
function lift_span( $src, $at ) {
    // From $at, the whole statement: tokens until the parenthesis/brace depth returns to
    // zero and a ';' (or the function's closing brace) ends it.
    $toks = token_get_all( '<?php ' . substr( $src, $at ) );
    $out = ''; $depth = 0; $started = false;
    foreach ( $toks as $i => $t ) {
        if ( $i === 0 ) continue;
        $s = is_array( $t ) ? $t[1] : $t;
        $out .= $s;
        if ( $s === '(' || $s === '{' || ( is_array( $t ) && ( $t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES ) ) ) { $depth++; $started = true; }
        elseif ( $s === ')' || $s === '}' ) { $depth--; if ( $depth === 0 && $started && strpos( $out, 'function' ) === 0 && $s === '}' ) return $out; }
        elseif ( $s === ';' && $depth === 0 && $started ) return $out;
    }
    return '';
}
function lift_fn( $src, $name ) {
    if ( ! preg_match( '/^function ' . preg_quote( $name, '/' ) . '\s*\(/m', $src, $m, PREG_OFFSET_CAPTURE ) ) return '';
    return lift_span( $src, $m[0][1] );
}
function lift_hooks( $src, $hook, $first_only = false ) {
    $out = array();
    if ( ! preg_match_all( "/^add_(?:action|filter)\( '" . preg_quote( $hook, '/' ) . "'/m", $src, $m, PREG_OFFSET_CAPTURE ) ) return $out;
    foreach ( $m[0] as $hit ) { $out[] = lift_span( $src, $hit[1] ); if ( $first_only ) break; }
    return $out;
}
$have = array();
foreach ( array( 'pps_get_closures', 'pps_shop_timezone', 'pps_is_business_day', 'pps_add_business_days', 'pps_shop_start_day', 'pps_business_days_between',
    'pps_quote_is_stale', 'pps_requote_line', 'pps_requote_summary', 'pps_requote_describe', 'pps_rest_stack', 'pps_is_placing_order',
    'pps_is_cart_page_render', 'pps_referer_is_cart_page', 'pps_request_shows_cart', 'pps_checkout_is_submitting', 'pps_requote_cart', 'pps_requote_stop_message', 'pps_quoted_delivery_date' ) as $fn ) {
    $code = lift_fn( $src, $fn );
    if ( $code !== '' ) { eval( $code ); $have[ $fn ] = true; }
}
foreach ( array( 'rest_request_before_callbacks', 'rest_request_after_callbacks', 'woocommerce_before_calculate_totals',
    'woocommerce_store_api_cart_errors', 'woocommerce_check_cart_items', 'woocommerce_cart_loaded_from_session' ) as $h ) {
    foreach ( lift_hooks( $src, $h ) as $code ) eval( $code );
}
foreach ( lift_hooks( $src, 'woocommerce_get_item_data', true ) as $code ) eval( $code );
echo 'lifted from ' . basename( $file ) . ': ' . implode( ', ', array_keys( $have ) ) . "\n";

// ── Requests ──
// Each starts fresh (hooks-run counters, notices, the cart rebuilt from the session),
// exactly as a new PHP request would.
function begin_request( $opts = array() ) {
    $GLOBALS['did'] = array(); $GLOBALS['notices'] = array();
    $GLOBALS['pps_page'] = $opts['page'] ?? ''; $GLOBALS['block_page'] = ! empty( $opts['block'] );
    $GLOBALS['ajax'] = ! empty( $opts['ajax'] ); $_REQUEST['wc-ajax'] = $opts['wc_ajax'] ?? '';
    $_SERVER['REQUEST_METHOD'] = $opts['http'] ?? 'GET';
    $_SERVER['REQUEST_URI'] = $opts['uri'] ?? '/';
    if ( isset( $opts['ref'] ) ) $_SERVER['HTTP_REFERER'] = $opts['ref']; else unset( $_SERVER['HTTP_REFERER'] );
    if ( function_exists( 'pps_rest_stack' ) ) pps_rest_stack( 'reset' );
    $cart = new FakeCart;
    foreach ( ( WC()->session->store['cart'] ?? array() ) as $k => $ci ) { $ci['data'] = new FakeProduct( $ci['name'] ); $cart->cart_contents[ $k ] = $ci; }
    WC()->cart = $cart;
    do_action( 'woocommerce_cart_loaded_from_session', $cart );
    if ( ! empty( $opts['page'] ) || ! empty( $opts['ajax'] ) ) do_action( 'wp' );
    return $cart;
}
function rest( $method, $route, callable $cb ) {
    $req = new FakeRequest( $method, $route );
    apply_filters( 'rest_request_before_callbacks', null, array(), $req );
    try { $r = $cb(); } finally { apply_filters( 'rest_request_after_callbacks', null, array(), $req ); }
    return $r;
}
function item_rows( $cart ) {
    $rows = array();
    foreach ( $cart->cart_contents as $ci ) foreach ( apply_filters( 'woocommerce_get_item_data', array(), $ci ) as $r ) $rows[] = $r['key'] . ': ' . $r['value'];
    return implode( "\n", $rows );
}
/** CartController::validate_cart(), as WooCommerce runs it. Returns the WP_Error or null. */
function store_validate_cart( $cart ) {
    $e = new WP_Error;
    // CartController::validate_cart_item(): a sold-individually product at quantity > 1
    // (every calculator product is sold individually since 2026-09-27).
    foreach ( $cart->cart_contents as $ci ) {
        if ( ( $ci['quantity'] ?? 1 ) > 1 ) { $e->add( 'woocommerce_rest_cart_product_sold_individually', 'There are too many "' . $ci['name'] . '" in the cart. Only 1 can be purchased. Please reduce the quantity in your cart.' ); return $e; }
    }
    do_action( 'woocommerce_store_api_cart_errors', $e, $cart );
    if ( $e->has_errors() ) return $e;
    $prev = $GLOBALS['notices']; $GLOBALS['notices'] = array();
    do_action( 'woocommerce_check_cart_items' );
    $errs = $GLOBALS['notices']['error'] ?? array();
    $GLOBALS['notices'] = $prev;
    if ( ! $errs ) return null;
    $e = new WP_Error; foreach ( $errs as $n ) $e->add( 'woocommerce_cart_error', $n['notice'] );
    return $e;
}
/** The cart as a Store API cart response puts it on screen. */
function cart_response( $cart ) {
    if ( ! did_action( 'woocommerce_after_calculate_totals' ) ) $cart->calculate_totals();   // get_cart_for_response()
    store_validate_cart( $cart );                                                              // get_cart_errors()
    return array( 'total' => $cart->total, 'rows' => item_rows( $cart ) );
}
/** The block checkout page: the preloaded cart is what the customer sees. */
function block_checkout_page() {
    // In a real page request the preload runs without REST_REQUEST; the block child has it
    // defined, so here the preload stands for the requests the checkout page itself makes.
    begin_request( array( 'page' => 'checkout', 'block' => true, 'uri' => '/checkout/', 'ref' => 'https://shop.test/checkout/' ) );
    return rest( 'GET', '/wc/store/v1/cart', function() { return cart_response( WC()->cart ); } );
}
/** Place Order on the block checkout. */
function block_place_order( $uri = '/wp-json/wc/store/v1/checkout' ) {
    begin_request( array( 'http' => 'POST', 'uri' => $uri, 'ref' => 'https://shop.test/checkout/' ) );
    return rest( 'POST', '/wc/store/v1/checkout', function() {
        $cart = WC()->cart;
        $cart->calculate_totals();                                   // Checkout.php:551
        $e = store_validate_cart( $cart );                           // Checkout.php:562
        if ( $e ) {
            $msgs = $e->get_error_messages();
            $shown = cart_response( $cart );                         // the 409 carries the cart
            return array( 'status' => 409, 'message' => $msgs[0], 'others' => array_slice( $msgs, 1 ), 'total' => $shown['total'], 'rows' => $shown['rows'] );
        }
        return array( 'status' => 200, 'charged' => $cart->total );
    } );
}
/** A checkout update (order notes, payment method): PUT, sent as POST with an override. */
function block_checkout_put() {
    begin_request( array( 'http' => 'POST', 'uri' => '/wp-json/wc/store/v1/checkout', 'ref' => 'https://shop.test/checkout/' ) );
    return rest( 'PUT', '/wc/store/v1/checkout', function() { WC()->cart->calculate_totals(); return array( 'status' => 200 ); } );
}
/** A mini-cart refresh on some other page: totals computed, nothing about this job shown. */
function mini_cart() { begin_request( array( 'ajax' => true, 'wc_ajax' => 'get_refreshed_fragments' ) ); WC()->cart->calculate_totals(); }
/** A mini-cart block reading the cart on a product page: the total is not on screen. */
function product_page_cart_read() {
    begin_request( array( 'uri' => '/wp-json/wc/store/v1/cart', 'ref' => 'https://shop.test/product/booklets/' ) );
    return rest( 'GET', '/wc/store/v1/cart', function() { return cart_response( WC()->cart ); } );
}
/** The block checkout page as it really renders: its preload runs inside the page request. */
function block_checkout_page_render() {
    begin_request( array( 'page' => 'checkout', 'block' => true, 'uri' => '/checkout/' ) );
    return rest( 'GET', '/wc/store/v1/cart', function() { return cart_response( WC()->cart ); } );
}
/** The classic cart page (the site's cart page is the classic shortcode). */
function classic_cart_page() {
    $cart = begin_request( array( 'page' => 'cart', 'uri' => '/cart/' ) );
    do_action( 'woocommerce_check_cart_items' );
    $cart->calculate_totals();
    return array( 'total' => $cart->total, 'notices' => $GLOBALS['notices'], 'rows' => item_rows( $cart ) );
}
/** Classic checkout submission (also what the classic cart's express-pay buttons run). */
function classic_place_order() {
    $cart = begin_request( array( 'ajax' => true, 'wc_ajax' => 'checkout', 'http' => 'POST', 'uri' => '/?wc-ajax=checkout' ) );
    do_action( 'woocommerce_before_checkout_process' );
    do_action( 'woocommerce_checkout_process' );
    $cart->calculate_totals();                                        // update_session()
    do_action( 'woocommerce_check_cart_items' );                      // validate_checkout()
    $errs = $GLOBALS['notices']['error'] ?? array();
    if ( $errs ) { $cart->calculate_totals(); return array( 'status' => 'failure', 'message' => $errs[0]['notice'], 'others' => array_slice( $errs, 1 ), 'total' => $cart->total ); }
    return array( 'status' => 'success', 'charged' => $cart->total );
}

// ── Cart lines, built against the real today so the plugin's own clock is used ──
$phx   = new DateTimeZone( 'America/Phoenix' );
$now   = new DateTime( 'now', $phx );
$start = pps_shop_start_day( $now );
$prev  = clone $start; do { $prev->modify( '-1 day' ); } while ( ! pps_is_business_day( $prev ) );
$ymd   = function( DateTime $d ) { return $d->format( 'Y-m-d' ); };
$after = function( DateTime $d, $n ) { return pps_add_business_days( clone $d, $n ); };
/** A rush job priced on the previous working day for 4 working days out: base 300 + rush 150. */
function rush_line( $name, $quoted_on, $deliver, $extra = array() ) {
    return array_merge( array( 'name' => $name, 'pps_price' => 450, 'pps_rush' => 150, 'pps_biz_days' => 4, 'pps_summary' => "x\nRush: 4 business days",
        'pps_metadata' => json_encode( array( 'quotedOn' => $quoted_on, 'estimatedDeliveryDate' => $deliver, 'productionBizDays' => 1,
            'freeDeliveryBizDays' => 6, 'transitDays' => 3, 'rushCost' => 150, 'baseTotal' => 300, 'total' => 450 ) ) ), $extra );
}
/** A free-delivery job priced on the previous working day: 4 production + 3 transit. */
function free_line( $name, $quoted_on, $deliver ) {
    return array( 'name' => $name, 'pps_price' => 200, 'pps_rush' => 0, 'pps_biz_days' => 7, 'pps_summary' => "x\nStandard delivery: 7 business days",
        'pps_metadata' => json_encode( array( 'quotedOn' => $quoted_on, 'estimatedDeliveryDate' => $deliver, 'productionBizDays' => 4,
            'freeDeliveryBizDays' => 7, 'transitDays' => 3, 'rushCost' => 0, 'baseTotal' => 200, 'total' => 200 ) ) );
}
function set_cart( array $lines ) {
    WC()->session->store = array( 'cart' => array() ); $GLOBALS['trans'] = array(); $GLOBALS['opts'] = array();
    $i = 0; foreach ( $lines as $l ) WC()->session->store['cart'][ 'k' . ( ++$i ) ] = $l;
}
/** Press Place Order until it goes through (at most three times). Returns [attempts, charged, last shown total, messages]. */
function pay( callable $place, $shown ) {
    $msgs = array();
    for ( $n = 1; $n <= 3; $n++ ) {
        $r = $place();
        if ( in_array( $r['status'], array( 200, 'success' ), true ) ) return array( $n, $r['charged'], $shown, $msgs );
        $msgs[] = $r; $shown = $r['total'];
    }
    return array( 99, null, $shown, $msgs );
}
$money = function( $v ) { return '$' . number_format( (float) $v, 2 ); };
$P = $ymd( $prev ); $was = $ymd( $after( $prev, 4 ) );

if ( $mode === 'block' ) {
    echo "\n── the block checkout (every checkout on this site) ──\n";

    // A. The page was loaded yesterday, showing $450; Place Order is pressed today.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ) ) );
    list( $n, $charged, $shown, $msgs ) = pay( 'block_place_order', 450 );
    ok( 'A. paid a day after it was priced: goes through by the second press', $n <= 2, "attempts=$n" );
    ok( 'A. and is charged exactly the total the customer was last shown', $charged !== null && abs( $charged - $shown ) < 0.005, "charged=$charged shown=$shown" );
    ok( 'A. the stop says what changed and states the new order total', $n === 2 && strpos( $msgs[0]['message'], 'Saddle Stitch Booklet' ) !== false && strpos( $msgs[0]['message'], $money( $charged ) ) !== false,
        $msgs ? $msgs[0]['message'] : '(no stop)' );
    ok( 'A. the rush was re-priced by the calculator rule (300 × 6/3 − 300 = $300)', abs( (float) $charged - 600 ) < 0.005, "charged=$charged" );

    // B. The checkout page is loaded today, then Place Order.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ) ) );
    $page = block_checkout_page();
    list( $n, $charged, $shown ) = pay( 'block_place_order', $page['total'] );
    ok( 'B. checkout loaded today: the page already shows the re-quoted total', abs( $page['total'] - 600 ) < 0.005, 'page showed ' . $page['total'] );
    ok( 'B. and the line says why, on the page', stripos( $page['rows'], 'Quote updated' ) !== false, $page['rows'] );
    ok( 'B. Place Order then goes through first time, at the total on the page', $n === 1 && abs( $charged - $page['total'] ) < 0.005, "attempts=$n charged=$charged page={$page['total']}" );

    // C. A free-delivery job: the date moves, the price does not, and the customer is told.
    set_cart( array( free_line( 'Brochure', $P, $ymd( $after( $prev, 7 ) ) ) ) );
    $page = block_checkout_page();
    ok( 'C. a free-delivery job paid a day late: the page says its delivery date moved', stripos( $page['rows'], 'Quote updated' ) !== false && strpos( $page['rows'], 'price is unchanged' ) !== false, $page['rows'] );
    list( $n, $charged ) = pay( 'block_place_order', $page['total'] );
    ok( 'C. and it is paid first time at the same price', $n === 1 && abs( $charged - 200 ) < 0.005, "attempts=$n charged=$charged" );

    // D. Two late jobs, Place Order pressed from yesterday's page: one message names both.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ), free_line( 'Brochure', $P, $ymd( $after( $prev, 7 ) ) ) ) );
    list( $n, $charged, $shown, $msgs ) = pay( 'block_place_order', 650 );
    $m = $msgs ? $msgs[0]['message'] . ' ' . implode( ' ', $msgs[0]['others'] ) : '';
    ok( 'D. two late jobs: one stop, and the message shown names both', $n === 2 && strpos( $msgs[0]['message'], 'Saddle Stitch Booklet' ) !== false && strpos( $msgs[0]['message'], 'Brochure' ) !== false, $m );
    ok( 'D. charged what was shown', $charged !== null && abs( $charged - $shown ) < 0.005, "charged=$charged shown=$shown" );

    // E. A checkout update (PUT) re-quotes first; nothing it returns shows the cart.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ) ) );
    block_checkout_put();
    list( $n, $charged, $shown, $msgs ) = pay( 'block_place_order', 450 );
    ok( 'E. re-quoted by a checkout update, not shown: stopped once, then paid at the total shown', $n === 2 && abs( $charged - $shown ) < 0.005, "attempts=$n charged=$charged shown=$shown" );

    // F. Re-quoted by a mini-cart refresh elsewhere, then the checkout page is opened.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ) ) );
    mini_cart();
    $page = block_checkout_page();
    list( $n, $charged ) = pay( 'block_place_order', $page['total'] );
    ok( 'F. re-quoted on another page, then seen on the checkout: no stop, charged the page total', $n === 1 && abs( $charged - $page['total'] ) < 0.005, "attempts=$n charged=$charged page={$page['total']}" );

    // G. A rush date that can no longer be made at all.
    $gone = $ymd( $after( $prev, 1 ) );
    set_cart( array( rush_line( 'Perfect Bound Book', $P, $gone, array( 'pps_metadata' => json_encode( array( 'quotedOn' => $P, 'estimatedDeliveryDate' => $gone,
        'productionBizDays' => 3, 'freeDeliveryBizDays' => 6, 'transitDays' => 3, 'rushCost' => 150, 'baseTotal' => 300, 'total' => 450 ) ) ) ) ) );
    list( $n, $charged, $shown, $msgs ) = pay( 'block_place_order', 450 );
    ok( 'G. a rush date that can no longer be made never blocks the order', $n <= 2, "attempts=$n " . ( $msgs ? $msgs[0]['message'] : '' ) );
    ok( 'G. it moves to the earliest date and is charged what was shown', $charged !== null && abs( $charged - $shown ) < 0.005, "charged=$charged shown=$shown" );

    // H. A line from a build before quotedOn existed, whose date can no longer be made.
    $legacy = rush_line( 'Coupon Book', '', $gone );
    $lm = json_decode( $legacy['pps_metadata'], true ); unset( $lm['quotedOn'] ); $lm['productionBizDays'] = 3; $legacy['pps_metadata'] = json_encode( $lm );
    set_cart( array( $legacy ) );
    list( $n, $charged, $shown ) = pay( 'block_place_order', 450 );
    ok( 'H. an older build\'s line that can no longer make its date is moved, not refused', $n <= 2 && abs( (float) $charged - 450 ) < 0.005, "attempts=$n charged=$charged" );

    // I. A rush line whose numbers disagree keeps its price and is never refused.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was, array( 'pps_price' => 470 ) ) ) );
    list( $n, $charged ) = pay( 'block_place_order', 470 );
    ok( 'I. a line whose own numbers disagree is paid first time at its quoted price', $n === 1 && abs( (float) $charged - 470 ) < 0.005, "attempts=$n charged=$charged" );

    // J. The stop happens once even if the session did not keep the change.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ) ) );
    $before = WC()->session->store;
    $r1 = block_place_order();
    WC()->session->store = $before;                                  // the session write was lost
    $r2 = block_place_order();
    ok( 'J. at most one stop per customer per day, even if the session write is lost', $r1['status'] === 409 ? $r2['status'] === 200 : true, "first={$r1['status']} second={$r2['status']}" );

    // K. The same job, but Place Order arrives at /?rest_route=%2Fwc%2Fstore%2Fv1%2Fcheckout.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ) ) );
    list( $n, $charged, $shown ) = pay( function() { return block_place_order( '/?rest_route=%2Fwc%2Fstore%2Fv1%2Fcheckout' ); }, 450 );
    ok( 'K. recognised as Place Order however the URL is spelled: charged what was shown', $charged !== null && abs( $charged - $shown ) < 0.005, "charged=$charged shown=$shown" );

    // F2. A mini-cart read on a product page, then Place Order from yesterday's checkout tab.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ) ) );
    product_page_cart_read();
    list( $n, $charged, $shown ) = pay( 'block_place_order', 450 );
    ok( 'F2. a cart read on a product page does not count as seen: one stop, then charged the total shown', $n === 2 && abs( $charged - $shown ) < 0.005, "attempts=$n charged=$charged shown=$shown" );

    // R. A cart saved before 2026-09-27 where two identical jobs merged into quantity 2.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $ymd( $start ), $ymd( $after( $start, 4 ) ), array( 'quantity' => 2 ) ) ) );
    list( $n, $charged, $shown, $msgs ) = pay( 'block_place_order', 900 );
    ok( 'R. a line merged to quantity 2 before the deploy is paid first time, both jobs, same total', $n === 1 && abs( (float) $charged - 900 ) < 0.005 && count( WC()->session->store['cart'] ) === 2,
        "attempts=$n charged=$charged lines=" . count( WC()->session->store['cart'] ) . ( $msgs ? ' ' . $msgs[0]['message'] : '' ) );

    // L. Nothing late: nothing changes and nothing is said.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $ymd( $start ), $ymd( $after( $start, 4 ) ) ) ) );
    $page = block_checkout_page();
    list( $n, $charged ) = pay( 'block_place_order', $page['total'] );
    ok( 'L. a cart priced today: no row, no stop, charged the quote', $n === 1 && abs( $charged - 450 ) < 0.005 && stripos( $page['rows'], 'Quote updated' ) === false, "attempts=$n charged=$charged" );

    // M. A plain cart read never carries the stop, so the block cart never shows an error.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ) ) );
    begin_request( array( 'uri' => '/wp-json/wc/store/v1/cart' ) );
    $e = rest( 'GET', '/wc/store/v1/cart', function() { WC()->cart->calculate_totals(); return store_validate_cart( WC()->cart ); } );
    ok( 'M. reading the cart is never refused', $e === null, $e ? implode( ' ', $e->get_error_messages() ) : '' );
}

if ( $mode === 'classic' ) {
    echo "\n── the classic cart page and classic checkout ──\n";

    // N. The classic cart page opened today: re-quoted, told, and nothing stops the payment.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ) ) );
    $page = classic_cart_page();
    $told = implode( ' ', array_map( function( $n ) { return $n['notice']; }, $page['notices']['notice'] ?? array() ) );
    ok( 'N. the classic cart page shows the re-quoted total and says why', abs( $page['total'] - 600 ) < 0.005 && stripos( $told, 'Saddle Stitch Booklet' ) !== false, "total={$page['total']} notices=$told" );
    list( $n, $charged ) = pay( 'classic_place_order', $page['total'] );
    ok( 'N. then paid first time at that total', $n === 1 && abs( $charged - $page['total'] ) < 0.005, "attempts=$n charged=$charged" );

    // O. Express pay from a cart page loaded yesterday: one stop naming the new total.
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ) ) );
    list( $n, $charged, $shown, $msgs ) = pay( 'classic_place_order', 450 );
    ok( 'O. classic checkout from yesterday\'s page: goes through by the second press', $n <= 2, "attempts=$n" );
    ok( 'O. and the stop states the total then charged', $n === 2 && strpos( $msgs[0]['message'], $money( $charged ) ) !== false, $msgs ? $msgs[0]['message'] : '(no stop)' );

    // Q. The block checkout page rendering, its preload inside the page request (this child
    //    has no REST_REQUEST, which is how that preload really runs).
    set_cart( array( rush_line( 'Saddle Stitch Booklet', $P, $was ) ) );
    $pg = block_checkout_page_render();
    list( $n, $charged ) = pay( 'block_place_order', $pg['total'] );
    ok( 'Q. the block checkout page\'s own preload shows the re-quote; Place Order then goes through first time', abs( $pg['total'] - 600 ) < 0.005 && $n === 1 && abs( $charged - 600 ) < 0.005,
        "page={$pg['total']} attempts=$n charged=$charged" );

    // P. A rush date that can no longer be made, through the classic path.
    $gone = $ymd( $after( $prev, 1 ) );
    set_cart( array( rush_line( 'Perfect Bound Book', $P, $gone, array( 'pps_metadata' => json_encode( array( 'quotedOn' => $P, 'estimatedDeliveryDate' => $gone,
        'productionBizDays' => 3, 'freeDeliveryBizDays' => 6, 'transitDays' => 3, 'rushCost' => 150, 'baseTotal' => 300, 'total' => 450 ) ) ) ) ) );
    list( $n ) = pay( 'classic_place_order', 450 );
    ok( 'P. classic checkout is never blocked by a date that can no longer be made', $n <= 2, "attempts=$n" );
}

echo "\n{$GLOBALS['t_checks']} checks, {$GLOBALS['t_failed']} failed\n";
exit( $GLOBALS['t_failed'] ? 1 : 0 );

<?php
/**
 * A reorder of a quote / pay-link order lands in the cart (owner 2026-10-26: "When I go to reorder
 * the cart is empty", order 87533).
 *
 * Quote and pay-link jobs are sold on the "Custom Order" product, which carries a WCPA form
 * ("Custom Order - TFP") with a REQUIRED Project Price. The quote never went through that form,
 * so the line has no WCPA answers; WCPA refused the reorder's add ("Field Project Price is
 * required"), the handler's "Could not add" notice is not shown by the block cart, and the cart
 * was empty. Even an add that slipped through would have been refused at checkout with "Addon
 * data missing".
 *
 * Runs the REAL pps_handle_single_item_reorder() in a child process per scenario (it ends in
 * wp_safe_redirect + exit) against a stand-in WCPA that behaves like the live 5.5.0: its
 * order-again filter, its add-to-cart check (refuses a missing required field), its add-cart-item
 * filter (skips wcpaIgnore) and its checkout check (has_form() from the cached product list).
 *
 *   php tools-reorder-wcpa-test.php
 */

if ( ( $argv[1] ?? '' ) === 'child' ) { child( $argv[2], $argv[3] ?? '' ); exit; }

$checks = 0; $failed = 0;
function ok( $c, $what, $got = null ) {
    global $checks, $failed; $checks++;
    if ( $c ) { echo "ok   $what\n"; return; }
    $failed++; echo "FAIL $what" . ( $got !== null ? ' — ' . json_encode( $got ) : '' ) . "\n";
}
function run( $scenario, $src = '' ) {
    $out = shell_exec( 'php ' . escapeshellarg( __FILE__ ) . ' child ' . escapeshellarg( $scenario ) . ' ' . escapeshellarg( $src ) . ' 2>&1' );
    $j = json_decode( (string) $out, true );
    if ( ! is_array( $j ) ) { echo $out; return array( 'cart' => array(), 'notices' => array(), 'redirect' => '', 'checkout' => array() ); }
    return $j;
}
$src = getenv( 'PPS_REORDER_PHP' ) ?: __DIR__ . '/pps-reorder.php';

// 87533's shape: a quote job on "Custom Order" (WCPA form with a required Project Price), no WCPA answers.
$r = run( 'quote', $src );
ok( count( $r['cart'] ) === 1, 'a quote/pay-link order\'s job is in the cart after the reorder link', $r );
$line = $r['cart'][0] ?? array();
ok( ( $line['product_id'] ?? 0 ) === 83664 && abs( ( $line['pps_legacy_unit_price'] ?? 0 ) - 90.63 ) < 0.001 && ! empty( $line['wcpaIgnore'] ), 'as the same product at the price it was sold at, marked for WCPA to leave alone', $line );
ok( ! array_filter( $r['notices'], function ( $n ) { return $n[1] === 'error'; } ) && strpos( implode( ' ', array_column( $r['notices'], 0 ) ), 'Added to your cart from order #87533' ) !== false, 'no WCPA "required" or "options changed" error, and the cart says where it came from', $r['notices'] );
ok( $r['checkout'] === array(), 'WCPA\'s checkout check lets it through (no "Addon data missing")', $r['checkout'] );
ok( $r['wcpa_hooked'] === true, 'WCPA\'s add-to-cart check is back in place after the add', $r );

// A line sold THROUGH the WCPA form still goes through WCPA exactly as before.
$r = run( 'wcpa', $src );
ok( count( $r['cart'] ) === 1 && ! empty( $r['cart'][0]['wcpa_data'] ) && empty( $r['cart'][0]['wcpaIgnore'] ) && $r['order_again_called'] === true, 'a line sold through the WCPA form is restored BY WCPA, with its answers', $r['cart'] );
ok( $r['checkout'] === array(), 'and passes WCPA\'s checkout check on its own answers', $r['checkout'] );

// A plain WCPA add with no answers is still refused, by WCPA's own checks.
$r = run( 'plain', $src );
ok( count( $r['cart'] ) === 0 && in_array( array( 'Field Project Price is required', 'error' ), $r['notices'], true ), 'an ordinary add of the form product with no answers is still refused by WCPA (nothing loosened for anyone else)', $r );

// Checkout with a reorder line AND another form product's line with no answers: WCPA still refuses that one.
$r = run( 'mixed', $src );
ok( $r['checkout'] === array( 'Addon data missing for product Other form product' ), 'another form product with no answers in the same cart is still refused by WCPA — only products held by reorder lines are hidden', $r );
ok( $r['hide_after'] === null, 'nothing is left hidden after the check', $r );

// A refused link no longer lands silently on an empty cart: the reason rides on the redirect and is printed above the cart.
$r = run( 'stale', $src );
ok( count( $r['cart'] ) === 0 && $r['redirect'] === 'https://x/cart/?pps_reorder_msg=access', 'a reorder link used more than 30 minutes after the lookup is refused, with the reason on the redirect', $r );
$page = json_decode( (string) shell_exec( 'php ' . escapeshellarg( __FILE__ ) . ' child page access 2>&1' ), true );
ok( strpos( $page['page'] ?? '', 'reorder access ends 30 minutes after you look up your order' ) !== false && strpos( $page['page'], 'https://x/reorders/' ) !== false && substr( $page['page'], -7 ) === '<cart/>', 'the cart page says why, with a link back to the order lookup, above the cart', $page );
$page = json_decode( (string) shell_exec( 'php ' . escapeshellarg( __FILE__ ) . ' child page ' . escapeshellarg( '<script>alert(1)</script>' ) . ' 2>&1' ), true );
ok( ( $page['page'] ?? '' ) === '<cart/>', 'an unknown code prints nothing — only the fixed sentences can appear', $page );

// The lookup page must never be page-cached: a cached copy carries a nonce WordPress stops accepting (2026-10-26).
$c = json_decode( (string) shell_exec( 'php ' . escapeshellarg( __FILE__ ) . ' child cache ' . escapeshellarg( $src ) . ' 2>&1' ), true );
$o = json_decode( (string) shell_exec( 'php ' . escapeshellarg( __FILE__ ) . ' child cache-other ' . escapeshellarg( $src ) . ' 2>&1' ), true );
ok( ( $c['nocache'] ?? false ) === true && ( $o['nocache'] ?? true ) === false, 'the page holding the order lookup is never page-cached; other pages are untouched', array( $c, $o ) );
$code = file_get_contents( $src );
ok( strpos( $code, "hash_equals( \$local_date, \$date_raw )" ) !== false && strpos( $code, "setTimezone( new DateTimeZone(" ) !== false, 'the lookup accepts the order\'s Arizona date as well as its UTC date (an evening order matches the date on the receipt)' );
ok( (bool) preg_match( "/function pps_order_lookup_grant\\( \\\$email \\) \\{[\\s\\S]{0,600}set_customer_session_cookie\\( true \\)[\\s\\S]{0,120}'pps_lookup_email'/", $code ), 'the lookup starts the guest\'s session cookie when it grants access, so the reorder button works with an empty cart' );
ok( (bool) preg_match( "/\\} elseif \\( isset\\( \\\$_POST\\['pps_lookup_submit'\\] \\) \\) \\{[\\s\\S]{0,400}\\\$error_kind = 'expired';/", $code ) && strpos( $code, 'so your lookup didn&rsquo;t go through' ) !== false, 'a lookup sent from a stale form says so, instead of coming back blank' );

echo "\n$checks checks, $failed failed\n";
echo $failed ? ">>> REORDER WCPA FAILED\n" : ">>> REORDER WCPA OK\n";
exit( $failed ? 1 : 0 );

/* ── child: stubs, a stand-in WCPA, then the real handler ── */
function child( $scenario, $src ) {
    $code = '';
    if ( $scenario === 'page' ) { $code = $src; $src = getenv( 'PPS_REORDER_PHP' ) ?: __DIR__ . '/pps-reorder.php'; }
    // Minimal WordPress hooks with WP_Hook-shaped storage (the handler inspects ->callbacks).
    $GLOBALS['wp_filter'] = array();
    eval( 'function add_filter( $t, $f, $p = 10, $a = 1 ) { if ( ! isset( $GLOBALS["wp_filter"][ $t ] ) ) $GLOBALS["wp_filter"][ $t ] = (object) array( "callbacks" => array() ); $GLOBALS["wp_filter"][ $t ]->callbacks[ $p ][] = array( "function" => $f, "accepted_args" => $a ); ksort( $GLOBALS["wp_filter"][ $t ]->callbacks ); return true; }' );
    eval( 'function add_action( $t, $f, $p = 10, $a = 1 ) { return add_filter( $t, $f, $p, $a ); }' );
    eval( 'function remove_filter( $t, $f, $p = 10 ) { if ( empty( $GLOBALS["wp_filter"][ $t ]->callbacks[ $p ] ) ) return false; foreach ( $GLOBALS["wp_filter"][ $t ]->callbacks[ $p ] as $i => $cb ) if ( $cb["function"] === $f ) { unset( $GLOBALS["wp_filter"][ $t ]->callbacks[ $p ][ $i ] ); return true; } return false; }' );
    eval( 'function apply_filters( $t, $v, ...$args ) { if ( empty( $GLOBALS["wp_filter"][ $t ] ) ) return $v; foreach ( $GLOBALS["wp_filter"][ $t ]->callbacks as $cbs ) foreach ( $cbs as $cb ) $v = call_user_func_array( $cb["function"], array_slice( array_merge( array( $v ), $args ), 0, max( 1, $cb["accepted_args"] ) ) ); return $v; }' );
    eval( 'function do_action( $t, ...$args ) { if ( empty( $GLOBALS["wp_filter"][ $t ] ) ) return; foreach ( $GLOBALS["wp_filter"][ $t ]->callbacks as $cbs ) foreach ( $cbs as $cb ) call_user_func_array( $cb["function"], array_slice( $args, 0, $cb["accepted_args"] ) ); }' );
    foreach ( array( 'add_shortcode', 'register_activation_hook', 'wp_register_style', 'wp_enqueue_style', 'wp_add_inline_style' ) as $f ) eval( "function $f() {}" );
    function absint( $v ) { return abs( (int) $v ); }
    function sanitize_text_field( $v ) { return trim( (string) $v ); }
    function wp_unslash( $v ) { return $v; }
    function wc_get_cart_url() { return 'https://x/cart/'; }
    function add_query_arg( $k, $v, $u ) { return $u . ( strpos( $u, '?' ) === false ? '?' : '&' ) . $k . '=' . rawurlencode( $v ); }
    function is_cart() { return true; } function in_the_loop() { return true; }
    function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
    function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } function esc_url( $u ) { return (string) $u; }
    function home_url( $p = '' ) { return 'https://x' . $p; }

    function wp_verify_nonce() { return 1; }
    function is_user_logged_in() { return false; }
    function get_current_user_id() { return 0; }
    define( 'MINUTE_IN_SECONDS', 60 );
    function taxonomy_is_product_attribute() { return false; }
    function meta_is_product_attribute() { return false; }
    function wc_add_notice( $m, $t = 'success' ) { $GLOBALS['notices'][] = array( $m, $t ); }
    function wp_safe_redirect( $u ) { $GLOBALS['redirect'] = $u; }
    function get_transient( $k ) { return apply_filters( 'transient_' . $k, $GLOBALS['transients'][ $k ] ?? false, $k ); }
    function is_admin() { return false; }
    function is_singular() { return true; }
    function get_post() { return (object) array( 'post_content' => $GLOBALS['page_content'] ?? '' ); }
    function has_shortcode( $c, $t ) { return strpos( $c, '[' . $t ) !== false; }
    function nocache_headers() { $GLOBALS['nocache_sent'] = true; }
    $GLOBALS['page_content'] = $scenario === 'cache' ? '<p>[pps_order_lookup]</p>' : '<p>About us</p>';
    $GLOBALS['notices'] = array(); $GLOBALS['transients']['wcpa_products_transient_ver_3'] = array( 'full' => array( 83664, 999 ), 'direct_purchasable' => array() );
    define( 'WCPA_ORDER_META_KEY', '_WCPA_order_meta_data' );

    // Products: 83664 "Custom Order" (WCPA form, required Project Price).
    eval( 'class WC_Product { public $id, $name; function __construct( $i, $n ) { $this->id = $i; $this->name = $n; } function exists() { return true; } function get_id() { return $this->id; } function get_name() { return $this->name; } function get_title() { return $this->name; } function is_sold_individually() { return false; } function set_price( $p ) { $this->price = $p; } }' );
    eval( 'class WC_Order_Item_Product { public $meta; function __construct( $m ) { $this->meta = $m; } function get_product_id() { return 83664; } function get_variation_id() { return 0; } function get_quantity() { return 1; } function get_subtotal() { return 90.63; } function get_meta( $k ) { return $this->meta[ $k ] ?? ""; } function get_meta_data() { return array(); } }' );
    $item = new WC_Order_Item_Product( $scenario === 'wcpa' ? array( '_WCPA_order_meta_data' => array( array( 'fields' => array( array( 'name' => 'number_7266671348', 'value' => '90.63' ) ) ) ) ) : array() );
    $GLOBALS['order'] = new class( $item ) {
        public $item; function __construct( $i ) { $this->item = $i; }
        function get_customer_id() { return 0; } function get_billing_email() { return 'nspielman@bellsouth.net'; }
        function get_item( $id ) { return $id === 44995 ? $this->item : false; } function get_order_number() { return '87533'; } function get_meta() { return array(); }
    };
    function wc_get_order( $id ) { return $id === 87533 ? $GLOBALS['order'] : false; }
    function wc_get_product( $id ) { return new WC_Product( $id, 'Custom Order' ); }

    // The cart: add_to_cart runs the validation filter, then the cart-item-data filter, as WooCommerce does.
    $GLOBALS['cart'] = new class {
        public $contents = array();
        function add_to_cart( $pid, $qty = 1, $vid = 0, $var = array(), $data = array() ) {
            if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, $qty, $vid, $var, $data ) ) return false;
            $data = apply_filters( 'woocommerce_add_cart_item_data', $data, $pid, $vid, $qty );
            $k = md5( json_encode( array( $pid, $data, count( $this->contents ) ) ) );
            $this->contents[ $k ] = array_merge( $data, array( 'product_id' => $pid, 'variation_id' => $vid, 'quantity' => $qty, 'data' => wc_get_product( $pid ) ) );
            return $k;
        }
        function get_cart() { return $this->contents; }
    };
    // The order-lookup session that granted access to 87533 a minute ago.
    $GLOBALS['session'] = new class { function get( $k ) { return array( 'pps_lookup_email' => 'nspielman@bellsouth.net', 'pps_lookup_granted_at' => time() - ( $GLOBALS['stale'] ?? false ? 31 * 60 : 60 ) )[ $k ] ?? null; } function set() {} function __unset( $k ) {} };
    function WC() { return (object) array( 'cart' => $GLOBALS['cart'], 'session' => $GLOBALS['session'] ); }

    // Stand-in WCPA 5.5.0 (includes/process/process.php + cart.php): the behaviour that matters.
    eval( 'namespace Acowebs\WCPA; function has_form( $pid ) { $l = \get_transient( "wcpa_products_transient_ver_3" ); return in_array( $pid, $l["full"] ?? array() ); }
    class Process {
        function add_to_cart_validation( $passed, $pid ) { if ( $passed !== true ) return $passed; if ( has_form( $pid ) || $pid === 83664 ) { if ( empty( $_POST["number_7266671348"] ) && empty( $GLOBALS["wcpa_restoring"] ) ) { \wc_add_notice( "Field Project Price is required", "error" ); return false; } } return $passed; }
        function add_cart_item_data( $d, $pid ) { if ( isset( $d["wcpaIgnore"] ) ) return $d; if ( ! empty( $GLOBALS["wcpa_restoring"] ) ) $d["wcpa_data"] = $GLOBALS["wcpa_restoring"]; return $d; }
        function order_again_cart_item_data( $d, $item, $order ) { $GLOBALS["order_again_called"] = true; $m = $item->get_meta( WCPA_ORDER_META_KEY ); if ( ! $m ) { \wc_add_notice( "Addon options of product Custom Order has been changed, Cannot proceed with older data.", "error" ); return $d; } $GLOBALS["wcpa_restoring"] = $m; return $d; }
        static function check_cart_items() { foreach ( \WC()->cart->get_cart() as $ci ) { $flag = true; if ( ! empty( $ci["wcpa_data"] ) ) $flag = false; if ( has_form( $ci["data"]->get_id() ) && $flag ) { $GLOBALS["checkout"][] = "Addon data missing for product " . $ci["data"]->get_title(); return; } } }
    }' );
    $p = new Acowebs\WCPA\Process();
    add_filter( 'woocommerce_add_cart_item_data', array( $p, 'add_cart_item_data' ), 10, 4 );
    add_filter( 'woocommerce_add_to_cart_validation', array( $p, 'add_to_cart_validation' ), 10, 4 );
    add_filter( 'woocommerce_order_again_cart_item_data', array( $p, 'order_again_cart_item_data' ), 50, 3 );
    add_action( 'woocommerce_check_cart_items', array( 'Acowebs\WCPA\Cart', 'check_cart_items' ) );
    eval( 'namespace Acowebs\WCPA; class Cart { static function check_cart_items() { Process::check_cart_items(); } }' );
    if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/' );
    require $src;

    register_shutdown_function( function () use ( $scenario, $p ) {
        $GLOBALS['checkout'] = array();
        if ( $scenario === 'mixed' ) $GLOBALS['cart']->contents['other'] = array( 'product_id' => 999, 'variation_id' => 0, 'quantity' => 1, 'data' => new WC_Product( 999, 'Other form product' ) );
        do_action( 'woocommerce_check_cart_items' );
        $hooked = false;
        foreach ( $GLOBALS['wp_filter']['woocommerce_add_to_cart_validation']->callbacks as $cbs ) foreach ( $cbs as $cb ) if ( $cb['function'] === array( $p, 'add_to_cart_validation' ) ) $hooked = true;
        if ( isset( $GLOBALS['done_page'] ) ) return;
        echo json_encode( array(
            'cart' => array_values( array_map( function ( $c ) { unset( $c['data'] ); return $c; }, array_filter( $GLOBALS['cart']->contents, function ( $k ) { return $k !== 'other'; }, ARRAY_FILTER_USE_KEY ) ) ),
            'notices' => $GLOBALS['notices'], 'redirect' => $GLOBALS['redirect'] ?? '', 'checkout' => $GLOBALS['checkout'],
            'wcpa_hooked' => $hooked, 'order_again_called' => ! empty( $GLOBALS['order_again_called'] ), 'hide_after' => $GLOBALS['pps_reorder_wcpa_hide'] ?? null,
        ) );
    } );

    if ( $scenario === 'stale' ) $GLOBALS['stale'] = true;
    if ( $scenario === 'cache' || $scenario === 'cache-other' ) {
        $GLOBALS['done_page'] = 1;
        do_action( 'template_redirect' );
        echo json_encode( array( 'nocache' => defined( 'DONOTCACHEPAGE' ) && ! empty( $GLOBALS['nocache_sent'] ) ) ); exit;
    }
    if ( $scenario === 'page' ) { $GLOBALS['done_page'] = 1; $_GET = array( 'pps_reorder_msg' => $code ); echo json_encode( array( 'page' => apply_filters( 'the_content', '<cart/>' ) ) ); exit; }
    if ( $scenario === 'plain' ) { $GLOBALS['cart']->add_to_cart( 83664, 1 ); exit; }
    $_GET = array( 'pps_reorder_order' => '87533', 'pps_reorder_item' => '44995', 'pps_reorder_tier' => '0', '_wpnonce' => 'n' );
    pps_handle_single_item_reorder();
}

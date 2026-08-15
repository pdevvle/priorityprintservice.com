<?php
/**
 * tools-test-seo.php — behavioural tests for the catalog primitive, the
 * Merchant Center feed and the Business Profile rating sync.
 *
 *   php tools-test-seo.php        # exit 0 = all green
 *
 * There is no WordPress here. The stub below is the smallest surface the three
 * files touch, which is the point: it makes the tests runnable in a sandbox,
 * in CI, or on a laptop with nothing installed but PHP.
 *
 * What it is really guarding:
 *
 *   §3  The feed publishes the PAGE price, never the quoted one. Merchant
 *       Center crawls the landing page and compares; a mismatch disapproves
 *       the item and a pattern of them warns the account.
 *   §7  A failed rating fetch changes NOTHING. The schema only emits
 *       aggregateRating when both numbers are non-zero, so a naive "fetch and
 *       store" would strip the rating from every page on a timeout, silently.
 *
 * Both are the kind of bug that looks like nothing in review and shows up
 * weeks later as an email from Google.
 */


define( 'ABSPATH', '/tmp/wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ENT_XML1_FALLBACK', 16 );
define( 'PPS_CALC_DIR', __DIR__ . '/' );
define( 'PPS_CALC_OPTION', 'pps_calculators_registry' );
define( 'PPS_CONFIG_OPTION', 'pps_calc_config' );

$GLOBALS['_opts']       = array();
$GLOBALS['_transients'] = array();
$GLOBALS['_posts']      = array();
$GLOBALS['_meta']       = array();
$GLOBALS['_terms']      = array();
$GLOBALS['_http']       = null;   // canned wp_remote_get result
$GLOBALS['_purges']     = 0;

function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function apply_filters( $tag, $val ) { return $val; }
function register_deactivation_hook( ...$a ) {}
function wp_next_scheduled( $h ) { return time() + 3600; }
function wp_schedule_event( ...$a ) {}
function wp_unschedule_event( ...$a ) {}
function current_user_can( $c ) { return true; }
function get_current_screen() { return null; }
function check_admin_referer( ...$a ) {}
function wp_die( $m ) { throw new Exception( $m ); }
function wp_safe_redirect( $u ) {}
function wp_get_referer() { return ''; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_url( $u, $a ) { return $u . '&_wpnonce=x'; }
function add_query_arg( $k, $v, $u ) { return $u . ( strpos( $u, '?' ) === false ? '?' : '&' ) . $k . '=' . $v; }
function home_url( $p = '/' ) { return 'https://priorityprintservice.com' . $p; }
function get_bloginfo( $k ) { return $k === 'name' ? 'Priority Print Service' : 'Phoenix print shop'; }
function current_time( $f ) { return '2026-08-14 22:00:00'; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s, $p = null ) { return (string) $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_strip_all_tags( $s, $br = false ) { return trim( strip_tags( (string) $s ) ); }
function strip_shortcodes( $s ) { return preg_replace( '/\[[^\]]*\]/', '', (string) $s ); }
function wp_unslash( $v ) { return $v; }
function size_format( $b ) { return $b . 'B'; }

function get_option( $k, $d = false ) { return $GLOBALS['_opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['_opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['_transients'][ $k ] ); return true; }

function get_post( $id ) { return $GLOBALS['_posts'][ $id ] ?? null; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['_meta'][ $id ][ $k ] ?? ''; }
function get_permalink( $id ) { return 'https://priorityprintservice.com/product/p' . $id . '/'; }
function get_the_post_thumbnail_url( $id, $sz = 'full' ) { return $GLOBALS['_meta'][ $id ]['_thumb'] ?? ''; }
function wp_get_attachment_image_url( $id, $sz ) { return 'https://cdn.test/img' . $id . '.jpg'; }
function get_the_terms( $id, $tax ) { return $GLOBALS['_terms'][ $id ] ?? array(); }

function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
    private $m;
    public function __construct( $c = '', $m = '' ) { $this->m = $m; }
    public function get_error_message() { return $this->m; }
}
function wp_remote_get( $url, $args = array() ) { return $GLOBALS['_http']; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

function pps_purge_page_cache() { $GLOBALS['_purges']++; }

// ── Plugin functions the new files depend on ──
function pps_get_registry() { return $GLOBALS['_opts'][ PPS_CALC_OPTION ] ?? array(); }
function pps_get_calc_type_for_filename( $f ) {
    $m = array(
        'calc-preview-test.html'  => 'saddle',
        'calc-perfect-bound.html' => 'perfect-bound',
        'calc-brochure.html'      => 'brochure',
        'calc-coupon-book.html'   => 'coupon',
        'calc-letterhead.html'    => 'letterhead',
    );
    return $m[ $f ] ?? '';
}
function pps_get_config() { return $GLOBALS['_opts'][ PPS_CONFIG_OPTION ] ?? array(); }

// ── Fixture helpers ──
class FakeProduct {
    public $id, $price, $virtual, $gallery, $sale;
    public function __construct( $id, $price, $virtual = true, $gallery = array(), $sale = '' ) {
        $this->id = $id; $this->price = $price; $this->virtual = $virtual;
        $this->gallery = $gallery; $this->sale = $sale;
    }
    public function get_price() { return $this->sale !== '' ? $this->sale : $this->price; }
    public function get_regular_price() { return $this->price; }
    public function get_sale_price() { return $this->sale; }
    public function is_on_sale() { return $this->sale !== '' && (float) $this->sale > 0; }
    public function is_virtual() { return $this->virtual; }
    public function get_gallery_image_ids() { return $this->gallery; }
}

/**
 * Declared in pps-calculators.php — the single price answer shared by the
 * Product schema and the feed. Mirrored here because the tests exercise
 * pps-catalog.php without loading the whole plugin.
 */
function pps_product_price_facts( $product_id ) {
    $out = array( 'quoted' => null, 'regular' => null, 'sale' => null,
                  'effective' => null, 'agrees' => false, 'publishable' => false );
    $product_id = (int) $product_id;
    if ( ! $product_id ) return $out;

    $q = get_post_meta( $product_id, '_pps_defaults_price', true );
    if ( $q !== '' && $q !== null ) {
        $q = (float) $q;
        if ( $q > 0 && $q < 1000000 ) $out['quoted'] = round( $q, 2 );
    }
    $product = wc_get_product( $product_id );
    if ( $product ) {
        $r = $product->get_regular_price();
        if ( $r !== '' && $r !== null && (float) $r > 0 ) $out['regular'] = round( (float) $r, 2 );
        if ( $product->is_on_sale() ) {
            $sp = $product->get_sale_price();
            if ( $sp !== '' && $sp !== null && (float) $sp > 0 ) $out['sale'] = round( (float) $sp, 2 );
        }
    }
    $out['effective']   = $out['sale'] !== null ? $out['sale'] : $out['regular'];
    $out['agrees']      = ( $out['quoted'] !== null && $out['regular'] !== null
                            && abs( $out['quoted'] - $out['regular'] ) < 0.01 );
    $out['publishable'] = ( $out['agrees'] && $out['effective'] !== null && $out['effective'] > 0 );
    return $out;
}
$GLOBALS['_products'] = array();
function wc_get_product( $id ) { return $GLOBALS['_products'][ $id ] ?? false; }

function mkproduct( $id, $args = array() ) {
    $a = array_merge( array(
        'title' => 'Product ' . $id, 'status' => 'publish', 'price' => '100.00',
        'quoted' => '100.00', 'virtual' => true, 'thumb' => 'https://cdn.test/t' . $id . '.jpg',
        'content' => 'Body copy.', 'excerpt' => '', 'gallery' => array(), 'defaults' => array(), 'sale' => '',
        'cats' => array( 'Booklets' ),
    ), $args );

    $p = new stdClass();
    $p->ID = $id; $p->post_type = 'product'; $p->post_status = $a['status'];
    $p->post_title = $a['title']; $p->post_content = $a['content'];
    $p->post_excerpt = $a['excerpt']; $p->post_modified_gmt = '2026-08-14 00:00:00';
    $GLOBALS['_posts'][ $id ] = $p;

    $GLOBALS['_products'][ $id ] = new FakeProduct( $id, $a['price'], $a['virtual'], $a['gallery'], $a['sale'] );
    $GLOBALS['_meta'][ $id ] = array(
        '_pps_defaults_price' => $a['quoted'],
        '_pps_defaults'       => $a['defaults'],
        '_thumb'              => $a['thumb'],
    );
    $GLOBALS['_terms'][ $id ] = array_map( function ( $n ) {
        $t = new stdClass(); $t->name = $n; return $t;
    }, $a['cats'] );
}

// Declared in pps-calculators.php (core), not in pps-catalog.php — three core
// call sites need it and pps-catalog.php loads behind a file_exists guard.
function pps_registry_product_ids( $products_str ) {
    $ids = array_map( 'intval', preg_split( '/[\s,]+/', (string) $products_str ) );
    $ids = array_filter( $ids, function ( $id ) { return $id > 0; } );
    return array_values( array_unique( $ids ) );
}

// ─────────────────────────── tests ───────────────────────────

$ROOT = __DIR__ . '/';
require $ROOT . 'pps-catalog.php';
require $ROOT . 'pps-product-feed.php';
require $ROOT . 'pps-gbp-sync.php';


$pass = 0; $fail = 0;
function ok( $cond, $label, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ( $detail !== '' ? "  [$detail]" : '' ) . "\n"; }
}

echo "\n── 1. Registry ID parsing ─────────────────────────────\n";
ok( pps_registry_product_ids( '10, 11  12,,13,' ) === array( 10, 11, 12, 13 ), 'messy separators' );
ok( pps_registry_product_ids( '10,10,10' ) === array( 10 ), 'dedupes' );
ok( pps_registry_product_ids( '' ) === array(), 'empty string' );
ok( pps_registry_product_ids( '0, -5, abc, 7' ) === array( 7 ), 'drops zero/negative/garbage' );

echo "\n── 2. Catalog: inclusion and skip reasons ─────────────\n";
mkproduct( 101, array( 'title' => 'Saddle Booklets', 'price' => '109.04', 'quoted' => '109.04',
                       'defaults' => array( 'sizeLabel' => '5.5×8.5', 'pages' => 24, 'qty' => 250, 'insideColor' => 'color' ) ) );
mkproduct( 102, array( 'title' => 'No Quoted Price', 'price' => '55.00', 'quoted' => '' ) );
mkproduct( 103, array( 'title' => 'No Image', 'thumb' => '' ) );
mkproduct( 104, array( 'title' => 'Draft One', 'status' => 'draft' ) );
mkproduct( 105, array( 'title' => 'Not Virtual', 'virtual' => false ) );
mkproduct( 106, array( 'title' => 'Price Drift', 'price' => '80.00', 'quoted' => '109.04' ) );
// 107 deliberately absent -> stale registry entry

$GLOBALS['_opts'][ PPS_CALC_OPTION ] = array(
    'calc-preview-test.html' => array( 'products' => '101, 102, 103, 104, 105, 106, 107' ),
    'calc-brochure.html'     => array( 'products' => '101' ),   // collision with saddle
);

$rep = pps_catalog_report( array( 'require_price' => true, 'require_image' => true, 'require_virtual' => true ) );
$reasons = array();
foreach ( $rep['skipped'] as $s ) $reasons[ $s['id'] ] = $s['reason'];

ok( isset( $rep['rows'][101] ), '101 included' );
ok( ! isset( $rep['rows'][106] ), '106 excluded — its quote and its product price disagree' );
ok( ! isset( $rep['rows'][102] ), '102 excluded — no quoted price' );
ok( strpos( $reasons[102] ?? '', 'Price at these defaults' ) !== false, '  ...with an actionable reason', $reasons[102] ?? '' );
ok( ( $reasons[103] ?? '' ) === 'no featured image', '103 excluded — no image', $reasons[103] ?? '' );
ok( strpos( $reasons[104] ?? '', 'not published' ) !== false, '104 excluded — draft', $reasons[104] ?? '' );
ok( strpos( $reasons[105] ?? '', 'virtual' ) !== false, '105 excluded — not virtual', $reasons[105] ?? '' );
ok( strpos( $reasons[107] ?? '', 'stale registry' ) !== false, '107 excluded — stale registry entry', $reasons[107] ?? '' );
ok( count( $rep['collisions'] ) === 1 && $rep['collisions'][0]['id'] === 101, 'collision detected for 101' );
// The drift flag itself is visible on the unfiltered catalog, where the row
// survives so the operator can be told what is wrong with it.
$rep_all = pps_catalog_report();
ok( $rep_all['rows'][106]['price_drift'] === true, 'drift flagged on 106 in the unfiltered catalog' );
ok( $rep_all['rows'][106]['price_ok'] === false, '  ...and it is not publishable' );
ok( $rep_all['rows'][101]['price_drift'] === false, 'no false drift on 101' );
ok( $rep['rows'][101]['calc'] === 'saddle', 'calc type resolved' );

echo "\n── 3. THE parity rule: feed must agree with the SCHEMA ─\n";
// Google does not fill in the calculator. It reads the page's structured data,
// so the comparison is feed vs. Product schema — and the schema's price comes
// from pps_product_defaults_low_price(), i.e. _pps_defaults_price, NOT from
// WooCommerce's price element. A product where those two disagree cannot be
// published safely under either number.
$xml = pps_product_feed_render();
ok( strpos( $xml, '<g:price>109.04 USD</g:price>' ) !== false, '101 publishes 109.04, matching its schema' );
ok( strpos( $xml, '<g:id>106</g:id>' ) === false,
    '106 WITHHELD — quoted 109.04, product priced 80.00; publishing either would mismatch' );
ok( substr_count( $xml, '<g:price>' ) === 1, 'exactly 1 item priced', substr_count( $xml, '<g:price>' ) . ' found' );
ok( strpos( $xml, '<g:id>102</g:id>' ) === false, 'priceless product never reaches the XML' );

$dreason = '';
foreach ( $rep['skipped'] as $sk ) if ( $sk['id'] === 106 ) $dreason = $sk['reason'];
ok( strpos( $dreason, 'price conflict' ) !== false, '  ...and the drift reason names the conflict', $dreason );
ok( strpos( $dreason, '109.04' ) !== false && strpos( $dreason, '80.00' ) !== false,
    '  ...quoting both numbers so it is actionable', $dreason );

echo "\n── 3b. Sale prices ────────────────────────────────────\n";
mkproduct( 110, array( 'title' => 'On Sale', 'price' => '200.00', 'quoted' => '200.00', 'sale' => '150.00' ) );
$keep = $GLOBALS['_opts'][ PPS_CALC_OPTION ];
$GLOBALS['_opts'][ PPS_CALC_OPTION ] = array( 'calc-preview-test.html' => array( 'products' => '110' ) );
$xs = pps_product_feed_render();
ok( strpos( $xs, '<g:price>200.00 USD</g:price>' ) !== false, 'g:price carries the LIST price' );
ok( strpos( $xs, '<g:sale_price>150.00 USD</g:sale_price>' ) !== false, 'g:sale_price carries the discount' );
$pf110 = pps_product_price_facts( 110 );
ok( $pf110['effective'] === 150.0, 'effective price is the sale price — what the schema will publish' );
ok( $pf110['publishable'] === true, 'a sale does not make the product unpublishable' );
$GLOBALS['_opts'][ PPS_CALC_OPTION ] = $keep;
$xml = pps_product_feed_render();

echo "\n── 4. Feed correctness ────────────────────────────────\n";
ok( strpos( $xml, 'xmlns:g="http://base.google.com/ns/1.0"' ) !== false, 'g: namespace declared' );
$items = substr_count( $xml, '<item>' );
ok( $items > 0 && substr_count( $xml, '<g:identifier_exists>no</g:identifier_exists>' ) === $items,
    'identifier_exists on every item', "$items item(s)" );
ok( $items > 0 && substr_count( $xml, '<g:availability>in_stock</g:availability>' ) === $items,
    'availability on every item', "$items item(s)" );
ok( strpos( $xml, '<g:custom_label_0>saddle</g:custom_label_0>' ) !== false, 'calc type as custom label' );
ok( strpos( $xml, '<g:google_product_category>' ) === false, 'GPC omitted when unset (no wrong guess)' );
$prev = libxml_use_internal_errors( true );
$doc = simplexml_load_string( preg_replace( '/<!--.*?-->/s', '', $xml ) );
ok( $doc !== false, 'output is well-formed XML', implode( '; ', array_map( function ( $e ) { return trim( $e->message ); }, libxml_get_errors() ) ) );
libxml_clear_errors(); libxml_use_internal_errors( $prev );

echo "\n── 5. XML escaping (a title with & and <) ─────────────\n";
mkproduct( 108, array( 'title' => 'Booklets & <Flyers> "quoted"', 'price' => '10.00', 'quoted' => '10.00' ) );
$GLOBALS['_opts'][ PPS_CALC_OPTION ] = array( 'calc-preview-test.html' => array( 'products' => '108' ) );
$xml2 = pps_product_feed_render();
ok( strpos( $xml2, '&amp;' ) !== false && strpos( $xml2, '&lt;Flyers&gt;' ) !== false, 'entities escaped' );
$prev = libxml_use_internal_errors( true );
ok( simplexml_load_string( preg_replace( '/<!--.*?-->/s', '', $xml2 ) ) !== false, 'still well-formed with hostile title' );
libxml_clear_errors(); libxml_use_internal_errors( $prev );

echo "\n── 6. Spec line from defaults ─────────────────────────\n";
$sl = pps_catalog_spec_line( array( 'sizeLabel' => '5.5×8.5', 'pages' => 24, 'qty' => 250, 'insideColor' => 'color' ) );
ok( strpos( $sl, '5.5×8.5' ) !== false && strpos( $sl, '24 pages' ) !== false && strpos( $sl, 'full color' ) !== false,
    'booklet spec line', $sl );
ok( pps_catalog_spec_line( array( 'insideColor' => 'greyscale' ) ) === 'greyscale', 'greyscale detected' );
ok( pps_catalog_spec_line( array() ) === '', 'empty defaults -> empty string' );

echo "\n── 7. GBP sync: a FAILED fetch must change nothing ────\n";
$GLOBALS['_opts'][ PPS_CONFIG_OPTION ] = array( 'seo' => array(
    'places_api_key' => 'AIzaTEST', 'place_id' => 'ChIJTEST',
    'gbp_rating_value' => 4.9, 'gbp_review_count' => 127,
) );

// 7a — network error
$GLOBALS['_http'] = new WP_Error( 'http', 'connection timed out' );
$r = pps_gbp_sync_rating();
$seo = $GLOBALS['_opts'][ PPS_CONFIG_OPTION ]['seo'];
ok( $r['ok'] === false, 'network error reported as failure' );
ok( $seo['gbp_rating_value'] === 4.9, 'rating PRESERVED on network error', var_export( $seo['gbp_rating_value'], true ) );
ok( $seo['gbp_review_count'] === 127, 'count PRESERVED on network error', var_export( $seo['gbp_review_count'], true ) );
ok( ! empty( $seo['gbp_sync_error'] ), 'error recorded' );

// 7b — HTTP 403 (the classic restricted-key failure)
$GLOBALS['_http'] = array( 'response' => array( 'code' => 403 ), 'body' => '{"error":{"message":"API key not valid"}}' );
pps_gbp_sync_rating();
$seo = $GLOBALS['_opts'][ PPS_CONFIG_OPTION ]['seo'];
ok( $seo['gbp_rating_value'] === 4.9 && $seo['gbp_review_count'] === 127, 'values PRESERVED on HTTP 403' );
ok( strpos( $seo['gbp_sync_error'], '403' ) !== false && strpos( $seo['gbp_sync_error'], 'API key' ) !== false,
    'error carries the readable reason', $seo['gbp_sync_error'] );

// 7c — 200 OK but empty profile (no reviews yet) — must NOT zero the stored values
$GLOBALS['_http'] = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
pps_gbp_sync_rating();
$seo = $GLOBALS['_opts'][ PPS_CONFIG_OPTION ]['seo'];
ok( $seo['gbp_rating_value'] === 4.9 && $seo['gbp_review_count'] === 127,
    'values PRESERVED on a 200 with no rating in it' );

// 7d — success
$GLOBALS['_purges'] = 0;
$GLOBALS['_http'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"rating":4.8,"userRatingCount":131}' );
$r = pps_gbp_sync_rating();
$seo = $GLOBALS['_opts'][ PPS_CONFIG_OPTION ]['seo'];
ok( $r['ok'] === true && $r['changed'] === true, 'success reported as changed' );
ok( $seo['gbp_rating_value'] === 4.8 && $seo['gbp_review_count'] === 131, 'new values stored' );
ok( empty( $seo['gbp_sync_error'] ), 'prior error cleared on success' );
ok( ! empty( $seo['gbp_synced_at'] ), 'synced_at stamped' );
ok( $GLOBALS['_purges'] === 1, 'cache purged on a real change', $GLOBALS['_purges'] );

// 7e — identical values must NOT purge the cache
$GLOBALS['_purges'] = 0;
$r = pps_gbp_sync_rating();
ok( $r['changed'] === false, 'unchanged run reported as unchanged' );
ok( $GLOBALS['_purges'] === 0, 'no cache purge when nothing changed', $GLOBALS['_purges'] );

// 7f — no credentials configured
$GLOBALS['_opts'][ PPS_CONFIG_OPTION ] = array( 'seo' => array( 'gbp_rating_value' => 5.0, 'gbp_review_count' => 9 ) );
$r = pps_gbp_sync_rating();
$seo = $GLOBALS['_opts'][ PPS_CONFIG_OPTION ]['seo'];
ok( $r['ok'] === false, 'no credentials -> failure, not a crash' );
ok( $seo['gbp_rating_value'] === 5.0 && $seo['gbp_review_count'] === 9, 'manual values PRESERVED with no key' );

echo "\n── 8. Rating bounds ───────────────────────────────────\n";
$GLOBALS['_opts'][ PPS_CONFIG_OPTION ] = array( 'seo' => array(
    'places_api_key' => 'k', 'place_id' => 'p', 'gbp_rating_value' => 4.9, 'gbp_review_count' => 127 ) );
$GLOBALS['_http'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"rating":7.5,"userRatingCount":10}' );
pps_gbp_sync_rating();
ok( $GLOBALS['_opts'][ PPS_CONFIG_OPTION ]['seo']['gbp_rating_value'] === 4.9, 'out-of-range rating rejected' );

echo "\n" . str_repeat( '─', 56 ) . "\n";
printf( "  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail === 0 ? 0 : 1 );

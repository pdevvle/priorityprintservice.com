<?php
/**
 * The ChatGPT (OpenAI) product feed: every row meets the spec, and every price
 * is the Google feed's.
 *
 *   php tools-openai-feed-test.php
 *       Stub WordPress; a fake catalog (list price, live sale, no price, no image,
 *       draft, HTML + shortcode body, empty body, two calculators claiming one id)
 *       and fake presets. Lifts the REAL pps_product_price_facts() and the preset
 *       helpers out of pps-calculators.php, loads the real pps-catalog.php and
 *       pps-product-feed.php, renders BOTH feeds and compares them.
 *
 *   php tools-openai-feed-test.php --live <openai.jsonl> <google.xml> [openai.csv] [contract-ids]
 *       The same lint and parity on feeds fetched from a site. contract-ids (e.g. 35765) are
 *       the contract products the OpenAI feed leaves out on purpose; they must be missing.
 */
error_reporting( E_ALL );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "ok   $m\n"; } else { $fail++; echo "FAIL $m\n"; } }

/** g:id => [price, sale] from a Google feed document. */
function google_prices( $xml ) {
    $doc = simplexml_load_string( $xml );
    $out = array();
    foreach ( $doc->channel->item as $it ) {
        $g = $it->children( 'http://base.google.com/ns/1.0' );
        $out[ (string) $g->id ] = array( (string) $g->price, isset( $g->sale_price ) ? (string) $g->sale_price : null );
    }
    return $out;
}

/** The parity rule, shared by both modes. */
function check_parity( array $items, array $google, $label, array $contract = array() ) {
    foreach ( $contract as $c ) {
        ok( ! in_array( 'pps-' . $c, array_column( $items, 'item_id' ), true ), "$label: contract product #$c not in the OpenAI feed" );
        unset( $google[ (string) $c ], $google[ (int) $c ] );
    }
    $products = array();
    foreach ( $items as $it ) {
        if ( preg_match( '/^pps-(\d+)$/', $it['item_id'], $m ) ) $products[ $m[1] ] = $it;
    }
    ok( array_keys( $products ) == array_keys( $google ) || ( sort_keys( $products ) === sort_keys( $google ) ),
        "$label: same products as the Google feed (" . count( $products ) . ' vs ' . count( $google ) . ')' );
    foreach ( array_diff( array_keys( $google ), array_keys( $products ) ) as $id ) ok( false, "$label: #$id is in the Google feed only" );
    foreach ( array_diff( array_keys( $products ), array_keys( $google ) ) as $id ) ok( false, "$label: #$id is in the OpenAI feed only" );
    $bad = 0;
    foreach ( $products as $id => $it ) {
        if ( ! isset( $google[ $id ] ) ) continue;
        list( $gp, $gs ) = $google[ $id ];
        if ( $it['price'] !== $gp ) { $bad++; ok( false, "$label: #$id price {$it['price']} vs Google $gp" ); }
        if ( ( $it['sale_price'] ?? null ) !== $gs ) { $bad++; ok( false, "$label: #$id sale_price " . ( $it['sale_price'] ?? 'none' ) . ' vs Google ' . ( $gs ?? 'none' ) ); }
    }
    ok( $bad === 0, "$label: every price and sale price equals the Google feed's" );
}
function sort_keys( $a ) { $k = array_map( 'strval', array_keys( $a ) ); sort( $k ); return $k; }

/* ════════════════════════ live mode ════════════════════════ */
if ( ( $argv[1] ?? '' ) === '--live' ) {
    define( 'ABSPATH', '/' );
    // Only the pure parts of the module are needed: lint and nothing that touches WP.
    // the stubs below are declared at load time (PHP hoists top-level functions)
    require __DIR__ . '/pps-openai-feed.php';
    $items = array();
    foreach ( file( $argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $n => $line ) {
        $it = json_decode( $line, true );
        ok( is_array( $it ), 'line ' . ( $n + 1 ) . ' is a JSON object' );
        if ( is_array( $it ) ) $items[] = $it;
    }
    $ids = array_column( $items, 'item_id' );
    ok( count( $ids ) === count( array_unique( $ids ) ), count( $items ) . ' rows, item_ids unique' );
    foreach ( $items as $it ) {
        $p = pps_openai_feed_lint( $it );
        ok( ! $p, $it['item_id'] . ' lints clean' . ( $p ? ': ' . implode( '; ', $p ) : '' ) );
    }
    $contract = array_filter( array_map( 'intval', explode( ',', (string) ( $argv[5] ?? '' ) ) ) );
    check_parity( $items, google_prices( file_get_contents( $argv[3] ) ), 'live', $contract );
    if ( ! empty( $argv[4] ) ) {
        $fh = fopen( $argv[4], 'r' ); $head = fgetcsv( $fh, 0, ',', '"', '' ); $rows = 0;
        while ( ( $r = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
            $row = array_combine( $head, $r );
            $j = null; foreach ( $items as $it ) if ( $it['item_id'] === $row['item_id'] ) $j = $it;
            ok( $j && $j['price'] === $row['price'] && $j['url'] === $row['url'], "csv {$row['item_id']} matches the jsonl row" );
            $rows++;
        }
        ok( $rows === count( $items ), "csv has the same $rows rows" );
    }
    echo "\n$pass passed, $fail failed\n";
    exit( $fail ? 1 : 0 );
}

/* ════════════════════════ stub WordPress ════════════════════════ */
define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'PPS_CALC_OPTION', 'pps_calculators' );
$GLOBALS['opts'] = array(); $GLOBALS['hooks'] = array(); $GLOBALS['transients'] = array();
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function apply_filters( $h, $v ) { return $v; }
function add_rewrite_rule() {} function flush_rewrite_rules() {}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['transients'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); }
function home_url( $p = '' ) { return 'https://priorityprintservice.com' . $p; }
function get_bloginfo( $k ) { return $k === 'name' ? 'Priority Print Service' : 'Your Printing; Our Priority.'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $s ) ) ); }
function strip_shortcodes( $s ) { return preg_replace( '/\[[^\]]+\]/', '', $s ); }
function get_woocommerce_currency() { return 'USD'; }
function current_user_can() { return false; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function pps_get_config() { return array( 'seo' => array( 'brand_name' => 'Priority Print Service' ) ); }

class FakeProduct {
    public $d; function __construct( $d ) { $this->d = $d; }
    function get_regular_price() { return $this->d['regular'] ?? ''; }
    function get_sale_price() { return $this->d['sale'] ?? ''; }
    function is_on_sale() { return isset( $this->d['sale'] ) && empty( $this->d['sale_scheduled'] ); }
    function get_sku() { return $this->d['sku'] ?? ''; }
    function is_virtual() { return true; }
    function get_gallery_image_ids() { return $this->d['gallery'] ?? array(); }
    function get_status() { return $this->d['status'] ?? 'publish'; }
}
$P = array(
    101 => array( 'title' => 'Saddle Stitch Booklets', 'regular' => '109.04', 'image' => 'https://priorityprintservice.com/a.jpg',
                  'short' => '<p>Booklets stapled on the spine.</p>', 'content' => 'Long body.', 'cats' => array( 'Booklets' ), 'sku' => 'SS-1', 'gallery' => array( 9001 ) ),
    102 => array( 'title' => 'Perfect Bound Books', 'regular' => '200', 'sale' => '150', 'image' => 'https://priorityprintservice.com/b.jpg',
                  'short' => '', 'content' => 'Glued spine books [pps_calc] for &amp; catalogs.', 'cats' => array( 'Booklets' ) ),
    103 => array( 'title' => 'No Price Thing', 'regular' => '', 'image' => 'https://priorityprintservice.com/c.jpg', 'short' => 'x', 'content' => '' ),
    104 => array( 'title' => 'No Image Thing', 'regular' => '20', 'image' => '', 'short' => 'y', 'content' => '' ),
    105 => array( 'title' => 'Draft Thing', 'regular' => '20', 'image' => 'https://priorityprintservice.com/e.jpg', 'status' => 'draft', 'short' => '', 'content' => '' ),
    106 => array( 'title' => 'Tri-Fold Brochures', 'regular' => '89.5', 'sale' => '95', 'image' => 'https://priorityprintservice.com/f.jpg',
                  'short' => '', 'content' => "<h2>Brochures</h2>\n<script>x()</script><p>Folded   three ways.</p>", 'cats' => array( 'Uncategorized', 'Brochures > Folded' ) ),
    107 => array( 'title' => 'Postcards', 'regular' => '45', 'sale' => '30', 'sale_scheduled' => true, 'image' => 'https://priorityprintservice.com/g.jpg',
                  'short' => '', 'content' => '', 'defaults' => array( 'sizeLabel' => '4×6', 'qty' => 100, 'frontColor' => 'color' ) ),
    108 => array( 'title' => 'Acme 8p Booklets', 'regular' => '1', 'image' => 'https://priorityprintservice.com/h.jpg', 'short' => 'One customer.', 'content' => '',
                  'contract' => '{"name":"Acme","lock":true,"prices":{"500":1}}' ),
);
function get_post( $id ) {
    $d = $GLOBALS['P'][ $id ] ?? null; if ( ! $d ) return null;
    return (object) array( 'ID' => $id, 'post_type' => 'product', 'post_status' => $d['status'] ?? 'publish', 'post_title' => $d['title'],
                           'post_content' => $d['content'], 'post_excerpt' => $d['short'], 'post_modified_gmt' => '2026-11-11 00:00:00' );
}
function wc_get_product( $id ) { return isset( $GLOBALS['P'][ $id ] ) ? new FakeProduct( $GLOBALS['P'][ $id ] ) : null; }
function get_post_meta( $id, $k, $single = false ) {
    if ( $k === '_pps_defaults' ) return $GLOBALS['P'][ $id ]['defaults'] ?? '';
    if ( $k === '_pps_defaults_price' ) return $GLOBALS['P'][ $id ]['quoted'] ?? '';
    if ( $k === '_pps_contract' ) return $GLOBALS['P'][ $id ]['contract'] ?? '';
    return '';
}
function get_the_post_thumbnail_url( $id ) { return $GLOBALS['P'][ $id ]['image'] ?? ''; }
function wp_get_attachment_image_url( $a ) { return $GLOBALS['gal'][ $a ] ?? 'https://priorityprintservice.com/gal-' . $a . '.jpg'; }
function get_the_terms( $id ) { return array_map( function ( $n ) { return (object) array( 'name' => $n ); }, $GLOBALS['P'][ $id ]['cats'] ?? array() ); }
function get_permalink( $id ) { return $GLOBALS['links'][ $id ] ?? 'https://priorityprintservice.com/product/p' . $id . '/'; }

/* ═══════════ the real code ═══════════ */
$calc = file_get_contents( __DIR__ . '/pps-calculators.php' );
function lift( $src, $name ) {
    if ( ! preg_match( '/\nfunction ' . $name . '\s*\(.*?\n}\n/s', $src, $m ) ) { ok( false, "$name() found in pps-calculators.php" ); return ''; }
    return $m[0];
}
foreach ( array( 'pps_get_registry', 'pps_registry_product_ids', 'pps_product_price_facts', 'pps_get_calc_type_for_filename',
                 'pps_get_filename_for_calc_type', 'pps_get_presets', 'pps_preset_product_id', 'pps_get_preset_url' ) as $fn ) {
    eval( lift( $calc, $fn ) );
}
define( 'PPS_PRESETS_OPTION', 'pps_presets' );
require __DIR__ . '/pps-catalog.php';
require __DIR__ . '/pps-product-feed.php';
require __DIR__ . '/pps-openai-feed.php';

// The loader: the new file is required after the two it depends on, behind the same guard.
$a = strpos( $calc, "require_once PPS_CALC_DIR . 'pps-product-feed.php';" );
$b = strpos( $calc, "require_once PPS_CALC_DIR . 'pps-openai-feed.php';" );
ok( $a !== false && $b !== false && $b > $a, 'pps-calculators.php loads pps-openai-feed.php after pps-product-feed.php' );
ok( preg_match( "/file_exists\\( PPS_CALC_DIR \\. 'pps-openai-feed\\.php' \\)\\s*&& file_exists\\( PPS_CALC_DIR \\. 'pps-product-feed\\.php' \\)\\s*&& file_exists\\( PPS_CALC_DIR \\. 'pps-catalog\\.php' \\)/", $calc ) === 1,
    '…guarded on all three files existing' );

// The preset page's schema price: offer_price override, else price_from. Pin the emitter's order.
ok( strpos( $calc, "\$offer_low_raw  = \$ovr_get( 'offer_price', \$preset_price );" ) !== false
    && preg_match( "/\\\$preset_price = \\( isset\\( \\\$preset\\['price_from'\\] \\)/", $calc ) === 1,
    'preset head emitter still resolves offer_price → price_from (the order the feed copies)' );

$GLOBALS['opts']['pps_calculators'] = array(
    'calc-preview-test.html'  => array( 'products' => '101, 102,103, 108' ),
    'calc-brochure.html'      => array( 'products' => '104 105 106 107' ),
    'calc-perfect-bound.html' => array( 'products' => '102' ),   // collision: first claim wins
);
$GLOBALS['opts']['pps_presets'] = array(
    'bulk-booklet-printing' => array( 'slug' => 'bulk-booklet-printing', 'calc' => 'saddle', 'title' => 'Bulk Booklet Printing',
        'description' => 'Bulk booklet printing with volume pricing.', 'image' => '', 'price_from' => 109.04,
        'overrides' => array( 'schema_name' => 'Bulk Booklet Printing (Volume)' ), 'defaults' => array( 'qty' => 100 ) ),
    'letterhead' => array( 'slug' => 'letterhead', 'calc' => 'letterhead', 'title' => 'Letterhead Printing', 'description' => 'Letterhead.',
        'image' => '', 'price_from' => '', 'overrides' => array() ),
    'overridden' => array( 'slug' => 'overridden', 'calc' => 'brochure', 'title' => 'Menus', 'description' => '', 'image' => 'https://priorityprintservice.com/menu.jpg',
        'price_from' => '50', 'overrides' => array( 'offer_price' => '64.5' ), 'product_id' => 106 ),
    'mystery' => array( 'slug' => 'mystery', 'calc' => 'nope', 'title' => 'Mystery', 'price_from' => 10 ),
);

/* ═══════════ snapshot mode: the real catalog, read-only ═══════════
 * php tools-openai-feed-test.php --snapshot <snapshot.json> <live google.xml> [out-dir]
 * snapshot.json = {products: Store API products by id, registry, presets}; the live
 * Google feed is the reference. Writes the would-be feed to out-dir to look at. */
if ( ( $argv[1] ?? '' ) === '--snapshot' ) {
    $snap = json_decode( file_get_contents( $argv[2] ), true );
    $GLOBALS['P'] = array(); $GLOBALS['gal'] = array(); $GLOBALS['links'] = array();
    foreach ( $snap['products'] as $id => $p ) {
        $p['gallery'] = array();
        foreach ( $p['gallery_urls'] as $u ) { $k = count( $GLOBALS['gal'] ) + 1; $GLOBALS['gal'][ $k ] = $u; $p['gallery'][] = $k; }
        $GLOBALS['links'][ $id ] = $p['permalink'];
        $GLOBALS['P'][ (int) $id ] = $p;
    }
    $GLOBALS['opts']['pps_calculators'] = $snap['registry'];
    $GLOBALS['opts']['pps_presets']     = $snap['presets'];
    foreach ( $snap['contract_ids'] ?? array() as $c ) $GLOBALS['P'][ (int) $c ]['contract'] = array( 'name' => 'contract', 'prices' => array( '500' => 1 ) );
    $live   = google_prices( file_get_contents( $argv[3] ) );
    $mine   = google_prices( pps_product_feed_render() );
    ok( sort_keys( $mine ) === sort_keys( $live ), 'the snapshot reproduces the live Google feed\'s item set (' . count( $mine ) . ' vs ' . count( $live ) . ')' );
    $built = pps_openai_feed_build();
    foreach ( $built['items'] as $it ) {
        $p = pps_openai_feed_lint( $it );
        ok( ! $p, $it['item_id'] . ' lints clean' . ( $p ? ': ' . implode( '; ', $p ) : '' ) );
    }
    check_parity( $built['items'], $live, 'live catalog vs live Google feed', $snap['contract_ids'] ?? array() );
    foreach ( $built['skipped'] as $sk ) echo "     left out: {$sk['id']} {$sk['title']} — {$sk['reason']}\n";
    if ( ! empty( $argv[4] ) ) {
        file_put_contents( $argv[4] . '/pps-openai-feed.jsonl', pps_openai_feed_jsonl( $built['items'] ) );
        file_put_contents( $argv[4] . '/pps-openai-feed.csv', pps_openai_feed_csv( $built['items'] ) );
    }
    echo "\n" . count( $built['items'] ) . " rows. $pass passed, $fail failed\n";
    exit( $fail ? 1 : 0 );
}

/* ═══════════ the two feeds ═══════════ */
$google = google_prices( pps_product_feed_render() );
$def    = pps_openai_feed_build();   // no option saved: the owner's default
ok( count( $def['items'] ) === 4 && ! preg_grep( '/preset/', array_column( $def['items'], 'item_id' ) ), 'default: products only, no presets (owner 2026-11-11)' );
check_parity( $def['items'], $google, 'default', array( 108 ) );
$GLOBALS['opts']['pps_openai_feed'] = array( 'presets' => true );   // the switch, for the preset checks below
$built  = pps_openai_feed_build();
$items  = $built['items'];
$byId   = array_column( $items, null, 'item_id' );

ok( sort_keys( $google ) === array( '101', '102', '106', '107', '108' ), 'Google feed (control): 101, 102, 106, 107, 108' );
check_parity( $items, $google, 'fake catalog', array( 108 ) );
ok( stripos( array_column( $built['skipped'], 'reason', 'id' )['pps-108'] ?? '', 'contract' ) !== false, 'contract product left out and named' );

ok( $byId['pps-101']['price'] === '109.04 USD' && ! isset( $byId['pps-101']['sale_price'] ), 'list price, no sale' );
ok( $byId['pps-102']['price'] === '200.00 USD' && $byId['pps-102']['sale_price'] === '150.00 USD', 'live sale: list in price, sale in sale_price' );
ok( ! isset( $byId['pps-106']['sale_price'] ), 'a "sale" above the list price is not sent (as Google)' );
ok( $byId['pps-107']['price'] === '45.00 USD' && ! isset( $byId['pps-107']['sale_price'] ), 'a scheduled sale not yet open is not sent' );

$skippedIds = array_column( $built['skipped'], 'reason', 'id' );
foreach ( array( 'pps-103' => 'price', 'pps-104' => 'image', 'pps-105' => 'not published' ) as $id => $why ) {
    ok( isset( $skippedIds[ $id ] ) && stripos( $skippedIds[ $id ], $why ) !== false, "$id left out and named ($why)" );
}

/* Rows */
ok( count( $items ) === 6, '6 rows: 4 products + 2 priced presets (' . count( $items ) . ')' );
$ids = array_column( $items, 'item_id' );
ok( count( $ids ) === count( array_unique( $ids ) ), 'item_ids unique' );
foreach ( $items as $it ) {
    $p = pps_openai_feed_lint( $it );
    ok( ! $p, $it['item_id'] . ' lints clean' . ( $p ? ': ' . implode( '; ', $p ) : '' ) );
    foreach ( pps_openai_feed_required_fields() as $f ) {
        if ( ! is_string( $it[ $f ] ?? null ) || $it[ $f ] === '' ) ok( false, "{$it['item_id']} $f is a non-empty string" );
    }
    ok( $it['brand'] === 'Priority Print Service' && $it['seller_name'] === 'Priority Print Service', "{$it['item_id']} brand + seller_name" );
    ok( $it['availability'] === 'in_stock' && $it['is_eligible_search'] === true && $it['is_eligible_checkout'] === false, "{$it['item_id']} in stock, search on, checkout off" );
    ok( $it['shipping_price'] === '0.00 USD' && ! isset( $it['shipping'] ), "{$it['item_id']} free shipping said once" );
    ok( $it['accepts_returns'] === false && $it['return_policy'] === 'https://priorityprintservice.com/return-reprint-revision-and-cancellation-policy/', "{$it['item_id']} all sales final, policy linked" );
    ok( stripos( $it['description'], 'starting price' ) !== false && ! preg_match( '/\$\d/', $it['description'] ), "{$it['item_id']} says \"starting price\", names no dollar figure" );
    ok( ! isset( $it['group_id'] ), "{$it['item_id']} no group_id" );
    ok( ! preg_match( '/<|\[|&amp;|\s{2}/', $it['description'] ), "{$it['item_id']} description is plain text" );
}
ok( $byId['pps-101']['mpn'] === 'SS-1' && ! isset( $byId['pps-102']['mpn'] ), 'mpn only from a real SKU' );
ok( $byId['pps-101']['additional_image_urls'] === 'https://priorityprintservice.com/gal-9001.jpg', 'gallery → additional_image_urls' );
ok( $byId['pps-101']['product_category'] === 'Printing Services > Booklets', 'product_category from the WooCommerce category' );
ok( $byId['pps-106']['product_category'] === 'Printing Services > Brochures - Folded', '…Uncategorized skipped, ">" inside a name neutralised' );
ok( $byId['pps-107']['product_category'] === 'Printing Services', '…root alone when there is none' );
ok( strpos( $byId['pps-106']['description'], 'x()' ) === false && strpos( $byId['pps-106']['description'], 'Brochures. Folded three ways.' ) === 0, 'body text: script dropped, heading and paragraph become sentences' );
ok( strpos( $byId['pps-102']['description'], 'for & catalogs' ) !== false, 'shortcode removed, entity decoded' );
ok( strpos( $byId['pps-107']['description'], 'Postcards — 4×6, from 100 copies, full color.' ) === 0, 'empty body → built from the defaults' );
ok( pps_openai_feed_text( '<ul><li>Minimum Order of 25 Pieces</li><li>Text and Cardstock</li></ul><p>Done.</p>' ) === 'Minimum Order of 25 Pieces. Text and Cardstock. Done.', 'list items become sentences, no doubled stops' );
ok( pps_openai_feed_text( "Custom letterhead.\r\n\r\nSize: <strong>8.5x11</strong>\r\n\r\nShipping: <strong>Free</strong>\r\n\r\n&nbsp;" ) === 'Custom letterhead. Size: 8.5x11. Shipping: Free.', 'blank-line paragraphs become sentences, trailing &nbsp; dropped' );
ok( pps_openai_feed_text( '11&#215;25.5 Trifold Brochure &#8211; 3 Panel' ) === '11×25.5 Trifold Brochure – 3 Panel', 'entities in a title decoded' );

/* Presets */
$bulk = $byId['pps-preset-bulk-booklet-printing'] ?? null;
ok( $bulk && $bulk['price'] === '109.04 USD', 'preset priced by its page price (price_from)' );
ok( $bulk && $bulk['url'] === 'https://priorityprintservice.com/bulk-booklet-printing/', 'preset url is its landing page' );
ok( $bulk && $bulk['image_url'] === 'https://priorityprintservice.com/a.jpg', 'preset without an image → its product\'s image (not the site logo)' );
ok( $bulk && $bulk['title'] === 'Bulk Booklet Printing (Volume)', 'preset title = its schema name' );
ok( ( $byId['pps-preset-overridden']['price'] ?? '' ) === '64.50 USD', 'offer_price override wins over price_from, as on the page' );
ok( ( $byId['pps-preset-overridden']['product_category'] ?? '' ) === 'Printing Services > Brochures - Folded', 'preset category from the product it sells through' );
ok( stripos( $skippedIds['pps-preset-letterhead'] ?? '', 'no price' ) !== false, 'preset with no page price left out and named' );
ok( stripos( $skippedIds['pps-preset-mystery'] ?? '', 'unknown calculator' ) !== false, 'preset on an unknown calculator left out and named' );

$GLOBALS['opts']['pps_openai_feed'] = array( 'presets' => false );
$off = pps_openai_feed_build();
ok( count( $off['items'] ) === 4 && ! preg_grep( '/preset/', array_column( $off['items'], 'item_id' ) ), 'presets switched off explicitly: products only' );
check_parity( $off['items'], $google, 'presets off', array( 108 ) );
unset( $GLOBALS['opts']['pps_openai_feed'] );

/* Lint discriminates */
$good = $byId['pps-101'];
foreach ( array(
    'missing price'          => array( 'price' => '' ),
    'bad price format'       => array( 'price' => '$109.04' ),
    'http image'             => array( 'image_url' => 'http://x/a.jpg' ),
    'bad availability'       => array( 'availability' => 'preorder' ),
    'checkout on'            => array( 'is_eligible_checkout' => true ),
    'sale not below'         => array( 'sale_price' => '120.00 USD' ),
    'html description'       => array( 'description' => '<b>x</b>' ),
    'both shipping fields'   => array( 'shipping' => 'US::Standard:0.00 USD' ),
    'placeholder value'      => array( 'brand' => 'N/A' ),
    'numeric item_id'        => array( 'item_id' => 101 ),
) as $what => $patch ) {
    ok( pps_openai_feed_lint( array_merge( $good, $patch ) ) !== array(), "lint refuses: $what" );
}

/* Output */
$jsonl = pps_openai_feed_jsonl( $items );
$lines = explode( "\n", trim( $jsonl ) );
ok( count( $lines ) === 6 && json_decode( $lines[0], true ) == $items[0], 'jsonl: one object per line, round-trips' );
ok( strpos( $jsonl, '\/' ) === false && strpos( $jsonl, '4×6' ) !== false, 'jsonl: slashes and UTF-8 unescaped' );
$csv = pps_openai_feed_csv( $items );
$fh = fopen( 'php://temp', 'w+' ); fwrite( $fh, $csv ); rewind( $fh );
$head = fgetcsv( $fh, 0, ',', '"', '' ); $r = array_combine( $head, fgetcsv( $fh, 0, ',', '"', '' ) );
ok( $r['item_id'] === 'pps-101' && $r['price'] === '109.04 USD' && $r['is_eligible_checkout'] === 'false' && $r['accepts_returns'] === 'false', 'csv: header + row, booleans lowercase strings' );
ok( count( $head ) === count( array_unique( $head ) ) && in_array( 'sale_price', $head, true ), 'csv: one column per field across all rows' );

/* Cache + robots */
ok( pps_openai_feed_cached_rows() === $def['items'] && isset( $GLOBALS['transients']['pps_openai_feed_rows'] ), 'rows cached' );
pps_openai_feed_flush_cache();
ok( ! isset( $GLOBALS['transients']['pps_openai_feed_rows'] ), 'cache flushed' );
ok( isset( $GLOBALS['hooks']['update_option_pps_presets'] ), 'a preset save flushes the cache' );
$robots = '';
foreach ( $GLOBALS['hooks']['robots_txt'] as $cb ) $robots = $cb( $robots );
ok( strpos( $robots, 'Allow: /pps-openai-feed.jsonl' ) !== false && strpos( $robots, 'Allow: /pps-openai-feed.csv' ) !== false, 'robots.txt allows both feed URLs' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );

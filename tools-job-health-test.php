<?php
/**
 * pps-job-health.php, exercised rather than eyeballed.
 *
 * The print file is measured, not trusted: a PDF built here with 300 DPI page
 * images passes, one with 144 DPI page images (order 87171's shape) is flagged,
 * a vector PDF is named as such, a non-PDF is refused. The line-item hook writes
 * the check onto the order and onto the end of the Job Ticket, and the note lands
 * exactly once from the order-processed hook. The digest groups every kind of
 * exception and mails the office only when there is something to say.
 *
 * Run: php tools-job-health-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_checks'] = 0; $GLOBALS['t_failed'] = 0;
function ok( $label, $cond, $detail = '' ) {
    $GLOBALS['t_checks']++;
    if ( $cond ) { echo "PASS $label" . ( $detail ? "  $detail" : '' ) . "\n"; return; }
    $GLOBALS['t_failed']++;
    echo "FAIL $label" . ( $detail ? "\n       $detail" : '' ) . "\n";
}

// ── the smallest WordPress that will hold this file up ───────────────────────
$GLOBALS['hooks'] = array(); $GLOBALS['options'] = array(); $GLOBALS['mail'] = array(); $GLOBALS['fake_orders'] = array();
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][$h][] = array( $cb, $p, $a ); }
function fire( $hook, ...$args ) { foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as $h ) call_user_func_array( $h[0], $args ); }
function wc_get_order( $id ) { return $GLOBALS['fake_orders'][ $id ] ?? false; }
function wc_get_orders( $args ) { return array_values( $GLOBALS['fake_orders'] ); }
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function wp_mail( $to, $subject, $body, $headers = array() ) { $GLOBALS['mail'][] = compact( 'to', 'subject', 'body' ); return true; }
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['scheduled'][] = array( $r, $h ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function home_url() { return 'https://example.test'; }
$GLOBALS['tmp'] = sys_get_temp_dir() . '/pps-jh-' . getmypid(); @mkdir( $GLOBALS['tmp'] . '/pps-artwork', 0777, true );
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['tmp'] ); }
function pps_is_business_day( DateTime $d ): bool { return (int) $d->format( 'N' ) < 6; }
function pps_reorder_contact_recipient() { return 'Office@priorityprintservice.com'; }
function pps_get_config() { return array( 'pcf' => array( 'shop_timezone' => 'America/Phoenix' ) ); }

class FakeItem {
    public $meta = array(); public $name;
    function __construct( $name = 'Brochure', $meta = array() ) { $this->name = $name; $this->meta = $meta; }
    function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
    function add_meta_data( $k, $v, $unique = false ) { $this->meta[ $k ] = $v; }
    function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
    function get_name() { return $this->name; }
}
class FakeOrder {
    public $notes = array(); public $meta = array(); public $items = array(); private $id; public $first; public $last;
    function __construct( $id, $items = array(), $first = 'Pat', $last = 'Customer' ) { $this->id = $id; $this->items = $items; $this->first = $first; $this->last = $last; }
    function get_id() { return $this->id; }
    function add_order_note( $n ) { if ( ! $this->id ) return 0; $this->notes[] = $n; return count( $this->notes ); }
    function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
    function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
    function save() { return $this->id; }
    function get_items() { return $this->items; }
    function get_billing_first_name() { return $this->first; }
    function get_billing_last_name() { return $this->last; }
}

require __DIR__ . '/pps-job-health.php';

// ── a PDF by hand: N pages of a given MediaBox, each with one image XObject ──
function make_pdf( $pages, $box, $img_w, $img_h, $with_images = true ) {
    $objs = array();
    $objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = array(); for ( $i = 0; $i < $pages; $i++ ) $kids[] = ( 3 + $i * 2 ) . ' 0 R';
    $objs[] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $pages . ' >>';
    for ( $i = 0; $i < $pages; $i++ ) {
        $pg = 3 + $i * 2; $im = $pg + 1;
        $objs[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $box[0] . ' ' . $box[1] . '] ' . ( $with_images ? '/Resources << /XObject << /I' . $i . ' ' . $im . ' 0 R >> >> ' : '' ) . '>>';
        $objs[] = $with_images ? '<< /Type /XObject /Subtype /Image /Width ' . $img_w . ' /Height ' . $img_h . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length 3 >>stream' . "\n" . 'abc' . "\nendstream" : '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    }
    $s = "%PDF-1.4\n"; $n = 1;
    foreach ( $objs as $o ) { $s .= $n . " 0 obj\n" . $o . "\nendobj\n"; $n++; }
    return $s . "trailer\n<< /Root 1 0 R >>\n%%EOF\n";
}
$T = $GLOBALS['tmp'];
file_put_contents( "$T/pps-artwork/good_print-ready.pdf", make_pdf( 2, array( 1530, 522 ), 6375, 2175 ) );   // 21.25×7.25 in @ 300
file_put_contents( "$T/pps-artwork/soft_print-ready.pdf", make_pdf( 2, array( 1530, 522 ), 3060, 1044 ) );   // the same sheet @ 144
file_put_contents( "$T/pps-artwork/vector.pdf", make_pdf( 12, array( 324, 324 ), 0, 0, false ) );          // 4.5×4.5 in, no images
file_put_contents( "$T/pps-artwork/notes.txt", 'hello' );
// Order 87272's shape: a customer's 12-page InDesign export, 8×11 in, a
// sheet-proportioned photo on each page plus dozens of other placed images. Its
// sheet-shaped images happen to count ≥ pages; the old rule called that "raster,
// 62 DPI" and put a false alarm on a correct order.
$indd = make_pdf( 12, array( 576, 792 ), 496, 682 );
$extra = ''; for ( $k = 0; $k < 30; $k++ ) $extra .= ( 100 + $k ) . " 0 obj\n<< /Type /XObject /Subtype /Image /Width 1200 /Height 300 /Length 3 >>stream\nabc\nendstream\nendobj\n";
file_put_contents( "$T/pps-artwork/indesign.pdf", str_replace( 'trailer', $extra . 'trailer', $indd ) );
// The calculator's print-ready file as it is really stored: a random upload name.
@mkdir( "$T/pps-artwork/2026/09", 0777, true );
copy( "$T/pps-artwork/good_print-ready.pdf", "$T/pps-artwork/2026/09/20260925-010843-1e929ab7.pdf" );
copy( "$T/pps-artwork/indesign.pdf", "$T/pps-artwork/2026/09/20260925-010840-aa11bb22.pdf" );

echo "── the print file, measured ──\n";
$g = pps_print_file_check( "$T/pps-artwork/good_print-ready.pdf" );
ok( 'a 300 DPI raster print file reads as raster, 2 pages, 21.25×7.25 in, 300 DPI, no warning',
    $g['kind'] === 'raster' && $g['pages'] === 2 && $g['min_dpi'] === 300 && $g['warn'] === '' && $g['summary'] === 'raster · 2 pages · 21.25×7.25 in · 6375×2175 px · 300 DPI', $g['summary'] . ' | ' . $g['warn'] );
$s = pps_print_file_check( "$T/pps-artwork/soft_print-ready.pdf" );
ok( 'order 87171\'s shape — 144 DPI page images on a 300 DPI claim — is flagged',
    $s['kind'] === 'raster' && $s['min_dpi'] === 144 && strpos( $s['warn'], 'BELOW PRINT RESOLUTION: 144 DPI' ) === 0, $s['summary'] . ' | ' . $s['warn'] );
$v = pps_print_file_check( "$T/pps-artwork/vector.pdf" );
ok( 'a vector PDF is named as such with its page count and size, no DPI claim, no warning',
    $v['kind'] === 'vector' && $v['pages'] === 12 && $v['summary'] === 'vector PDF · 12 pages · 4.5×4.5 in' && $v['warn'] === '', $v['summary'] );
$x = pps_print_file_check( "$T/pps-artwork/notes.txt" );
ok( 'a non-PDF is refused, not guessed', $x['ok'] === false && $x['summary'] === 'not a PDF' );
ok( 'a missing file is reported, not fatal', pps_print_file_check( "$T/nope.pdf" )['summary'] === 'print file not readable' );
$id = pps_print_file_check( "$T/pps-artwork/indesign.pdf" );
ok( 'order 87272\'s shape — a layout PDF with a photo per page and 30 more images — is NOT called raster and raises no warning',
    $id['kind'] === 'mixed' && $id['warn'] === '' && $id['min_dpi'] === null, $id['kind'] . ' | ' . $id['summary'] . ' | ' . $id['warn'] );

echo "\n── on the order ──\n";
$item = new FakeItem( 'Square Accordion Brochure', array( 'Job Ticket' => "Product: Brochure / Flat Print\nQuantity: 250" ) );
$values = array( 'pps_metadata' => '{}', 'pps_artwork_files' => array( array( 'path' => 'pps-artwork/raw.pdf', 'name' => 'raw.pdf' ), array( 'path' => 'pps-artwork/soft_print-ready.pdf', 'name' => 'x_print-ready.pdf' ) ) );
fire( 'woocommerce_checkout_create_order_line_item', $item, 'k1', $values, new FakeOrder( 0 ) );
ok( 'the print-ready file is the one measured, and the check is a visible line', strpos( (string) $item->get_meta( 'Print File Check' ), 'raster · 2 pages' ) === 0 && strpos( $item->get_meta( 'Print File Check' ), '144 DPI' ) !== false, $item->get_meta( 'Print File Check' ) );
ok( 'the warning is kept as its own meta for the digest', strpos( (string) $item->get_meta( '_pps_print_check_warn' ), 'BELOW PRINT RESOLUTION' ) === 0 );
ok( 'the Job Ticket ends with the print file line', preg_match( '/\nPrint file: raster · 2 pages .* — BELOW PRINT RESOLUTION: 144 DPI/s', $item->get_meta( 'Job Ticket' ) ) === 1, $item->get_meta( 'Job Ticket' ) );
$o = new FakeOrder( 90001 ); $GLOBALS['fake_orders'][90001] = $o;
fire( 'woocommerce_checkout_order_processed', 90001 );
fire( 'woocommerce_store_api_checkout_order_processed', $o );
ok( 'the note lands exactly once across both order-processed hooks', count( $o->notes ) === 1 && strpos( $o->notes[0], 'PRINT FILE CHECK: BELOW PRINT RESOLUTION: 144 DPI' ) === 0, implode( ' || ', $o->notes ) );

$item2 = new FakeItem( 'Booklet', array( 'Job Ticket' => 'Product: Saddle Stitch Booklet' ) );
fire( 'woocommerce_checkout_create_order_line_item', $item2, 'k2', array( 'pps_metadata' => '{}', 'pps_artwork_path' => 'pps-artwork/vector.pdf' ), new FakeOrder( 0 ) );
ok( 'with no print-ready file, the raw PDF is measured and named as the shipped file', strpos( (string) $item2->get_meta( 'Print File Check' ), 'raw upload shipped as the print file · vector PDF · 12 pages' ) === 0, $item2->get_meta( 'Print File Check' ) );
ok( 'a good file raises no warning meta and no note', $item2->get_meta( '_pps_print_check_warn' ) === '' && empty( $GLOBALS['pps_job_health_warns'] ) );
$item3 = new FakeItem( 'Postcard' );
fire( 'woocommerce_checkout_create_order_line_item', $item3, 'k3', array( 'pps_metadata' => '{}', 'pps_artwork_path' => 'pps-artwork/photo.jpg' ), new FakeOrder( 0 ) );
ok( 'an image upload is left alone', $item3->get_meta( 'Print File Check' ) === '' );
$item4 = new FakeItem( 'Flyer' );
fire( 'woocommerce_checkout_create_order_line_item', $item4, 'k4', array( 'pps_metadata' => '{}', 'pps_artwork_files' => array( array( 'path' => 'pps-artwork/gone_print-ready.pdf', 'name' => 'g' ) ) ), new FakeOrder( 0 ) );
ok( 'a file already moved to Drive is reported as not checked, not as an error', $item4->get_meta( 'Print File Check' ) === 'not checked — file already moved off the server' );
$item5 = new FakeItem( 'Booklet', array( 'Job Ticket' => 'Product: Saddle Stitch Booklet' ) );
fire( 'woocommerce_checkout_create_order_line_item', $item5, 'k5', array( 'pps_metadata' => '{}',
    'pps_artwork_path'  => 'pps-artwork/2026/09/20260925-010840-aa11bb22.pdf',
    'pps_artwork_files' => array(
        array( 'path' => 'pps-artwork/2026/09/20260925-010840-aa11bb22.pdf', 'name' => 'KFA 2025 Journal.pdf' ),
        array( 'path' => 'pps-artwork/2026/09/20260925-010843-1e929ab7.pdf', 'name' => 'KFA 2025 Journal_print-ready.pdf' ),
    ) ), new FakeOrder( 0 ) );
ok( 'on a real order the print-ready file is found by its NAME (stored paths are random) — not the raw upload beside it',
    strpos( (string) $item5->get_meta( 'Print File Check' ), 'raster · 2 pages · 21.25×7.25 in' ) === 0 && $item5->get_meta( '_pps_print_check_warn' ) === '', $item5->get_meta( 'Print File Check' ) );

echo "\n── the digest ──\n";
$mk = function( $id, $name, $meta, $itemmeta = array(), $first = 'Pat', $last = 'Customer' ) {
    return new FakeOrder( $id, array( new FakeItem( $name, array_merge( array( '_pps_metadata' => json_encode( $meta ) ), $itemmeta ) ) ), $first, $last );
};
$orders = array(
    $mk( 101, 'Booklet',  array( 'proof' => 0 ), array( 'PPS-Prepress-Review' => 'yes' ), 'Ana', 'Lee' ),
    $mk( 102, 'Brochure', array( 'proof' => 0 ), array( '_pps_print_check_warn' => 'BELOW PRINT RESOLUTION: 144 DPI effective — re-render' ) ),
    $mk( 103, 'Booklet',  array( 'proof' => 3.01 ), array(), 'Kelley', 'Curry' ),                                            // older build: no proofAddrSame key
    $mk( 104, 'Postcard', array( 'proof' => 3.01, 'proofAddrSame' => false, 'proofAddr' => array( 'street' => '' ) ) ),
    $mk( 105, 'Flyer',    array( 'proof' => 0.01 ) ),
    $mk( 106, 'Booklet',  array( 'proof' => 0 ), array( '_pps_delivery_date' => '2026-09-26' ) ),                              // a Saturday
    $mk( 107, 'Booklet',  array( 'proof' => 3.01, 'proofAddrSame' => false, 'proofAddr' => array( 'street' => '1 Main St' ) ) ),
    new FakeOrder( 108, array( new FakeItem( 'Legacy WCPA item', array() ) ) ),
    $mk( 109, 'Coupon Book', array( 'proof' => 0 ), array( '_pps_drive_missing' => '7.jpg, 8.jpg' ), 'Parker', 'Jones' ),
    $mk( 110, 'Booklet', array( 'proof' => 0 ), array( '_pps_drive_missing' => 'old-art.pdf', '_pps_artwork_on_drive' => 'yes' ) ),   // a reorder: not a loss
);
$o111 = $mk( 111, 'Postcard', array( 'proof' => 0 ) ); $o111->meta = array( '_pps_drive_failed' => '2026-09-20 10:00:00', '_pps_drive_attempts' => 10 );
$o112 = $mk( 112, 'Postcard', array( 'proof' => 0 ) ); $o112->meta = array( '_pps_drive_attempts' => 4 );
$o113 = $mk( 113, 'Postcard', array( 'proof' => 0 ) ); $o113->meta = array( '_pps_drive_attempts' => 5, '_pps_artwork_processed' => '2026-09-20 11:00:00' );   // got there in the end
array_push( $orders, $o111, $o112, $o113 );
$refusals = array(
    array( 'time' => '2026-09-21 10:00:00', 'products' => array( 22754 ), 'errors' => array( 'Addon data missing for product <b>9x9</b>' ) ),
    array( 'time' => '2026-09-01 10:00:00', 'products' => array( 1 ), 'errors' => array( 'old' ) ),
);
$since = strtotime( '2026-09-15 00:00:00' );
$sections = pps_job_health_collect( $orders, $refusals, $since );
$titles = array_map( function( $s ) { return $s['title']; }, $sections );
ok( 'seven sections, in reading order', count( $sections ) === 7 && strpos( $titles[0], 'NOT approved' ) !== false && strpos( $titles[5], 'Checkout refused' ) !== false && strpos( $titles[6], 'did not reach Google Drive' ) !== false, implode( ' | ', $titles ) );
$flat = pps_job_health_render( $sections, 'example.test' );
ok( 'prepress review names the order and the customer', strpos( $flat, '#101 Ana Lee — Booklet' ) !== false );
ok( 'the low-resolution file carries its reason', strpos( $flat, '#102 Pat Customer — Brochure — BELOW PRINT RESOLUTION: 144 DPI' ) !== false );
ok( 'staff proofs are listed as digital or hardcopy', strpos( $flat, '#105 Pat Customer — Flyer — digital proof' ) !== false && strpos( $flat, '#103 Kelley Curry — Booklet — hardcopy proof' ) !== false );
ok( 'an older-build hardcopy proof says the address was never captured', strpos( $flat, '#103 Kelley Curry — Booklet — address was never captured' ) !== false );
ok( 'a chosen-but-blank address is called out; a filled one is not', strpos( $flat, '#104 Pat Customer — Postcard — a different address was chosen but left blank' ) !== false && strpos( $flat, '#107' ) === false || ( strpos( $flat, '#107' ) !== false && strpos( $flat, '#107 Pat Customer — Booklet — hardcopy proof' ) !== false && substr_count( $flat, '#107' ) === 1 ) );
ok( 'a Saturday delivery is flagged with its weekday', strpos( $flat, '#106 Pat Customer — Booklet — Saturday, Sep 26' ) !== false );
ok( 'only refusals since the last digest appear, with tags stripped', strpos( $flat, 'product 22754 — Addon data missing for product 9x9' ) !== false && strpos( $flat, 'product 1 —' ) === false );
ok( 'a non-calculator line is ignored', strpos( $flat, '#108' ) === false );
ok( 'a customer file that never reached the server is named, with what to do', strpos( $flat, '#109 Parker Jones — Coupon Book — never reached the server: 7.jpg, 8.jpg (ask the customer to resend)' ) !== false );
ok( 'artwork reused from an earlier order is not reported as missing', strpos( $flat, '#110' ) === false );
ok( 'an upload that gave up is listed, and one still retrying after three attempts', strpos( $flat, '#111 Pat Customer — upload stopped after 10 attempts' ) !== false && strpos( $flat, '#112 Pat Customer — still retrying (4 attempts so far)' ) !== false );
ok( 'an upload that got there in the end is not', strpos( $flat, '#113' ) === false );
// 1 prepress + 1 low-res + 4 staff proofs (#103 #104 #105 #107) + 2 addresses + 1 Saturday + 1 refusal + 3 Drive (#109 #111 #112)
ok( 'the header counts every item', strpos( $flat, 'PPS exceptions — 13 items need a look (example.test)' ) === 0, substr( $flat, 0, 60 ) );

$GLOBALS['fake_orders'] = array(); foreach ( $orders as $o2 ) $GLOBALS['fake_orders'][ $o2->get_id() ] = $o2;
$GLOBALS['options']['pps_checkout_refusals'] = $refusals; $GLOBALS['options']['pps_job_health_last_ts'] = $since;
$text = pps_job_health_digest( true );
ok( 'the digest is mailed to the office, and only the office', count( $GLOBALS['mail'] ) === 1 && $GLOBALS['mail'][0]['to'] === 'Office@priorityprintservice.com' && strpos( $GLOBALS['mail'][0]['subject'], 'PPS exceptions: 13 items' ) === 0, json_encode( array_map( function( $m ) { return $m['to'] . ' / ' . $m['subject']; }, $GLOBALS['mail'] ) ) );
ok( 'the last digest is kept where wp_get_option can read it', ( $GLOBALS['options']['pps_job_health_last']['count'] ?? 0 ) === 13 && $GLOBALS['options']['pps_job_health_last']['text'] === $text );
$GLOBALS['options']['pps_job_health_last_ts'] = 0; $GLOBALS['mail'] = array(); $GLOBALS['fake_orders'] = array();
$GLOBALS['options']['pps_checkout_refusals'] = array( array( 'time' => date( 'Y-m-d H:i:s', time() - 30 * 86400 ), 'products' => array( 5 ), 'errors' => array( 'ancient' ) ) );
pps_job_health_digest( true );
ok( 'a first run does not replay the whole refusal log, only the last week', count( $GLOBALS['mail'] ) === 0 );
$GLOBALS['mail'] = array(); $GLOBALS['fake_orders'] = array(); $GLOBALS['options']['pps_checkout_refusals'] = array(); $GLOBALS['options']['pps_job_health_last_ts'] = time();
pps_job_health_digest( true );
ok( 'nothing to say → no mail', count( $GLOBALS['mail'] ) === 0 );
ok( 'the daily cron is scheduled once on init', ( fire( 'init' ) === null ) && count( array_filter( $GLOBALS['scheduled'] ?? array(), function( $s ) { return $s[0] === 'daily' && $s[1] === 'pps_job_health_digest'; } ) ) === 1 );

// tidy
foreach ( glob( "$T/pps-artwork/2026/09/*" ) as $f ) @unlink( $f ); @rmdir( "$T/pps-artwork/2026/09" ); @rmdir( "$T/pps-artwork/2026" );
foreach ( glob( "$T/pps-artwork/*" ) as $f ) @unlink( $f ); @rmdir( "$T/pps-artwork" ); @rmdir( $T );

echo "\n{$GLOBALS['t_checks']} checks, {$GLOBALS['t_failed']} failed\n";
echo $GLOBALS['t_failed'] ? ">>> JOB HEALTH FAILED\n" : ">>> JOB HEALTH OK\n";
exit( $GLOBALS['t_failed'] ? 1 : 0 );

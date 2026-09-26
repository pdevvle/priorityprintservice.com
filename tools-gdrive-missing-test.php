<?php
/**
 * The Drive uploader, driven against a fake Drive: what happens to a file the
 * order lists and the server does not have.
 *
 * Until 2026-09-26 pps_process_artwork_upload() created the order folder before
 * it looked for any file, treated a missing FIRST file as an error to retry ten
 * times, and assumed any other missing file had already gone up. So a customer
 * file that never reached the server was skipped without a word, a reorder that
 * reused old artwork produced an empty "Order #…" folder and ten silent retries,
 * and an upload that failed ten times simply stopped. Order 87273's empty-looking
 * folder is the shape staff meet when this goes wrong.
 *
 * The real functions are lifted out of pps-gdrive.php (it cannot be required
 * whole — it defines the Drive client these stubs replace) and run here.
 *
 * Run: php tools-gdrive-missing-test.php
 */

$GLOBALS['t_checks'] = 0; $GLOBALS['t_failed'] = 0;
function ok( $label, $cond, $detail = '' ) {
    $GLOBALS['t_checks']++;
    if ( $cond ) { echo "PASS $label" . ( $detail ? "  $detail" : '' ) . "\n"; return; }
    $GLOBALS['t_failed']++;
    echo "FAIL $label" . ( $detail ? "\n       $detail" : '' ) . "\n";
}

// ── lift the two functions out of the plugin ─────────────────────────────────
$src = file_get_contents( __DIR__ . '/' . ( getenv( 'PPS_GDRIVE_FILE' ) ?: 'pps-gdrive.php' ) );
function lift( $src, $name ) {
    $at = strpos( $src, 'function ' . $name . '(' );
    if ( $at === false ) return '';
    $open = strpos( $src, '{', $at ); $depth = 0;
    for ( $i = $open; $i < strlen( $src ); $i++ ) {
        if ( $src[$i] === '{' ) $depth++;
        elseif ( $src[$i] === '}' && --$depth === 0 ) return substr( $src, $at, $i - $at + 1 );
    }
    return '';
}
$code = lift( $src, 'pps_process_artwork_upload' ) . "\n" . lift( $src, 'pps_gdrive_missing_is_loss' );
if ( strpos( $code, 'function pps_gdrive_missing_is_loss' ) === false ) {
    // The pre-fix file has no helper; give it a stand-in so the old behaviour can be run.
    $code .= "\nfunction pps_gdrive_missing_is_loss() { return false; }";
}

// ── the smallest WordPress and Drive that will hold it up ────────────────────
$GLOBALS['tmp'] = sys_get_temp_dir() . '/pps-gd-' . getmypid();
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['tmp'] ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function sanitize_file_name( $s ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( $t ) { return '2026-09-26 10:00:00'; }
function error_log_( $m ) {}
function pps_gdrive_parent_folder() { return 'PARENT'; }
function pps_gdrive_is_connected() { return true; }
function pps_generate_thumbnail( $a, $b ) { return false; }
function pps_gdrive_create_folder( $name, $parent ) { $GLOBALS['drive']['folders'][] = $name; return 'F' . count( $GLOBALS['drive']['folders'] ); }
function pps_gdrive_upload_file( $full, $name, $folder ) {
    if ( in_array( $name, $GLOBALS['drive']['refuse'], true ) ) return false;
    $GLOBALS['drive']['files'][] = $name; return 'D' . count( $GLOBALS['drive']['files'] );
}
function as_schedule_single_action( $t, $h, $a, $g ) { $GLOBALS['drive']['retries']++; }
function wc_get_order( $id ) { return $GLOBALS['orders'][ $id ] ?? false; }

class FakeProduct { function get_name() { return 'Coupon Book'; } }
class FakeItem {
    public $meta = array();
    function __construct( $meta ) { $this->meta = $meta; }
    function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
    function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
    function add_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
    function save() {}
    function get_product() { return new FakeProduct(); }
}
class FakeOrder {
    public $meta = array(); public $notes = array(); public $items;
    function __construct( $items ) { $this->items = $items; }
    function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
    function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
    function add_order_note( $n ) { $this->notes[] = $n; }
    function save() {}
    function get_items() { return $this->items; }
    function get_billing_first_name() { return 'Parker'; }
    function get_billing_last_name() { return 'Jones'; }
}

eval( str_replace( 'error_log(', 'error_log_(', $code ) );

function reset_drive( $refuse = array() ) { $GLOBALS['drive'] = array( 'folders' => array(), 'files' => array(), 'retries' => 0, 'refuse' => $refuse ); }
function put( $rel ) { $f = $GLOBALS['tmp'] . '/' . $rel; @mkdir( dirname( $f ), 0777, true ); file_put_contents( $f, 'x' ); }
function listing( $names ) { $out = array(); foreach ( $names as $n ) $out[] = array( 'path' => 'pps-artwork/2026/09/' . $n, 'name' => $n ); return json_encode( $out ); }
function run_order( $id, $items ) { $o = new FakeOrder( $items ); $GLOBALS['orders'][ $id ] = $o; pps_process_artwork_upload( $id ); return $o; }

// ── 1. everything present ───────────────────────────────────────────────────
echo "── all files on the server ──\n";
reset_drive();
foreach ( array( 'a.pdf', 'b_print-ready.pdf', 'c_manipulation_manifest.txt' ) as $n ) put( 'pps-artwork/2026/09/' . $n );
$it = new FakeItem( array( '_pps_artwork_path' => 'pps-artwork/2026/09/a.pdf', '_pps_artwork_files' => listing( array( 'a.pdf', 'b_print-ready.pdf', 'c_manipulation_manifest.txt' ) ) ) );
$o = run_order( 1, array( $it ) );
ok( 'one folder, every file uploaded, order marked processed, no note', count( $GLOBALS['drive']['folders'] ) === 1 && count( $GLOBALS['drive']['files'] ) === 3 && $o->get_meta( '_pps_artwork_processed' ) && ! $o->notes, json_encode( array( $GLOBALS['drive'], $o->notes ) ) );

// ── 2. twenty listed, one on the server ─────────────────────────────────────
echo "\n── twenty files listed, one on the server ──\n";
reset_drive();
$names = array(); for ( $i = 1; $i <= 20; $i++ ) $names[] = $i . '.jpg';
put( 'pps-artwork/2026/09/1.jpg' );
$it = new FakeItem( array( '_pps_artwork_path' => 'pps-artwork/2026/09/1.jpg', '_pps_artwork_files' => listing( $names ) ) );
$o = run_order( 2, array( $it ) );
ok( 'the file that is there goes up', $GLOBALS['drive']['files'] === array( '1.jpg' ) );
ok( 'the nineteen that are not are named on the order, not assumed uploaded', count( $o->notes ) === 1 && strpos( $o->notes[0], 'ARTWORK MISSING: 19 file(s)' ) === 0 && strpos( $o->notes[0], '2.jpg' ) !== false && strpos( $o->notes[0], '20.jpg' ) !== false, implode( ' || ', $o->notes ) );
ok( 'and kept on the item for the daily email', strpos( (string) $it->get_meta( '_pps_drive_missing' ), '2.jpg, 3.jpg' ) === 0 );
ok( 'a missing file is not retried ten times — it will not come back', $GLOBALS['drive']['retries'] === 0 && $o->get_meta( '_pps_artwork_processed' ) );

// ── 3. a reorder that reuses artwork already on Drive ───────────────────────
echo "\n── a reorder reusing artwork already on Drive ──\n";
reset_drive();
$it = new FakeItem( array( '_pps_artwork_path' => 'pps-artwork/2026/03/old.pdf', '_pps_artwork_on_drive' => 'yes' ) );
$o = run_order( 3, array( $it ) );
ok( 'no empty "Order #…" folder is created when nothing will be uploaded', count( $GLOBALS['drive']['folders'] ) === 0, json_encode( $GLOBALS['drive']['folders'] ) );
ok( 'no retries, and the note says the art is reused rather than missing', $GLOBALS['drive']['retries'] === 0 && count( $o->notes ) === 1 && strpos( $o->notes[0], 'Artwork reused from an earlier order' ) === 0, implode( ' || ', $o->notes ) );

// ── 4. a retry after a partial upload ───────────────────────────────────────
echo "\n── a partial upload, then the retry ──\n";
reset_drive( array( 'b.jpg' ) );
foreach ( array( 'a.jpg', 'b.jpg' ) as $n ) put( 'pps-artwork/2026/09/' . $n );
$it = new FakeItem( array( '_pps_artwork_path' => 'pps-artwork/2026/09/a.jpg', '_pps_artwork_files' => listing( array( 'a.jpg', 'b.jpg' ) ) ) );
$o = run_order( 4, array( $it ) );
ok( 'first run: a.jpg up, b.jpg refused, one retry scheduled', $GLOBALS['drive']['files'] === array( 'a.jpg' ) && $GLOBALS['drive']['retries'] === 1 && ! $o->get_meta( '_pps_artwork_processed' ) );
$GLOBALS['drive']['refuse'] = array();
pps_process_artwork_upload( 4 );
ok( 'the retry uploads b.jpg, knows a.jpg already went (it is off the server now), and finishes quietly',
    $GLOBALS['drive']['files'] === array( 'a.jpg', 'b.jpg' ) && $o->get_meta( '_pps_artwork_processed' ) && ! $o->notes && count( $GLOBALS['drive']['folders'] ) === 1, json_encode( array( $GLOBALS['drive'], $o->notes ) ) );

// ── 5. an item part-uploaded by the old build ───────────────────────────────
echo "\n── an item part-uploaded before the upload record existed ──\n";
reset_drive();
$it = new FakeItem( array( '_pps_artwork_path' => 'pps-artwork/2026/09/gone1.pdf', '_pps_gdrive_file_id' => 'OLD', '_pps_artwork_files' => listing( array( 'gone1.pdf', 'gone2.pdf' ) ) ) );
$o = run_order( 5, array( $it ) );
ok( 'is not reported as missing files that are on Drive', ! $o->notes && $it->get_meta( '_pps_drive_missing' ) === '' );

// ── 6. Drive refuses every time ─────────────────────────────────────────────
echo "\n── Drive refuses, ten times ──\n";
reset_drive( array( 'Coupon Book.pdf' ) );   // with no file list the uploader names the file after the product
put( 'pps-artwork/2026/09/z.pdf' );
$it = new FakeItem( array( '_pps_artwork_path' => 'pps-artwork/2026/09/z.pdf' ) );
$o = new FakeOrder( array( $it ) ); $o->meta['_pps_drive_attempts'] = 10; $GLOBALS['orders'][6] = $o;
pps_process_artwork_upload( 6 );
pps_process_artwork_upload( 6 );
ok( 'after the last attempt the order says so, once, and is flagged for the daily email',
    $o->get_meta( '_pps_drive_failed' ) && count( $o->notes ) === 1 && strpos( $o->notes[0], 'ARTWORK NOT ON GOOGLE DRIVE' ) === 0, implode( ' || ', $o->notes ) );

// tidy
exec( 'rm -rf ' . escapeshellarg( $GLOBALS['tmp'] ) );
echo "\n{$GLOBALS['t_checks']} checks, {$GLOBALS['t_failed']} failed\n";
echo $GLOBALS['t_failed'] ? ">>> GDRIVE MISSING FAILED\n" : ">>> GDRIVE MISSING OK\n";
exit( $GLOBALS['t_failed'] ? 1 : 0 );

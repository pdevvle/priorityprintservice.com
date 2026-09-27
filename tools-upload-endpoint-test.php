<?php
/**
 * The artwork upload endpoint, run for real against stubs: what it accepts, what it
 * refuses, and what it stores. A refusal here stops the customer's order (since
 * 2026-09-26 every customer file must arrive), so a refusal of a GOOD file is a
 * lost order, and must be as rare as a refusal of a bad one is certain.
 *
 * Found 2026-09-27: a PDF whose header sits a few bytes in (the PDF format and
 * pdf.js both allow up to 1024) was refused after the calculator had previewed it,
 * and a PNG saved with a .jpg name — invisible to the customer — was refused too.
 *
 * PPS_CALC_PHP points at another copy (how the pre-fix file was run).
 * Run: php tools-upload-endpoint-test.php
 */
$GLOBALS['t_checks'] = 0; $GLOBALS['t_failed'] = 0;
function ok( $label, $cond, $detail = '' ) {
    $GLOBALS['t_checks']++;
    if ( $cond ) { echo "PASS $label" . ( $detail ? "  $detail" : '' ) . "\n"; return; }
    $GLOBALS['t_failed']++;
    echo "FAIL $label" . ( $detail ? "\n       $detail" : '' ) . "\n";
}
$src = file_get_contents( __DIR__ . '/' . ( getenv( 'PPS_CALC_PHP' ) ?: 'pps-calculators.php' ) );
function lift( $src, $name ) {
    $at = strpos( $src, 'function ' . $name . '(' ); if ( $at === false ) return '';
    $open = strpos( $src, '{', $at ); $d = 0;
    for ( $i = $open; $i < strlen( $src ); $i++ ) { if ( $src[$i] === '{' ) $d++; elseif ( $src[$i] === '}' && --$d === 0 ) return substr( $src, $at, $i - $at + 1 ); }
    return '';
}
class Sent extends Exception { public $ok; public $data; function __construct( $ok, $data ) { $this->ok = $ok; $this->data = $data; } }
function check_ajax_referer() { return true; }
function wp_send_json_error( $d ) { throw new Sent( false, $d ); }
function wp_send_json_success( $d ) { throw new Sent( true, $d ); }
function size_format( $b ) { return round( $b / 1048576 ) . ' MB'; }
function wp_max_upload_size() { return 64 * 1048576; }
function get_option( $k, $d = false ) { return $d; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function wp_mkdir_p( $d ) { return @mkdir( $d, 0777, true ); }
function error_log_( $m ) {}
$GLOBALS['tmp'] = sys_get_temp_dir() . '/pps-up-' . getmypid(); @mkdir( $GLOBALS['tmp'], 0777, true );
function pps_artwork_dir() { return $GLOBALS['tmp'] . '/pps-artwork'; }
function pps_test_move( $from, $to ) { return copy( $from, $to ); }
$code = lift( $src, 'pps_artwork_sniff_type' ) . "\n" . lift( $src, 'pps_ajax_upload_artwork' );
eval( str_replace( array( 'error_log(', 'move_uploaded_file(' ), array( 'error_log_(', 'pps_test_move(' ), $code ) );

function upload( $name, $bytes ) {
    $tmp = $GLOBALS['tmp'] . '/in-' . md5( $name . $bytes ); file_put_contents( $tmp, $bytes );
    $_FILES['artwork'] = array( 'name' => $name, 'tmp_name' => $tmp, 'size' => strlen( $bytes ), 'error' => 0 );
    try { pps_ajax_upload_artwork(); } catch ( Sent $s ) { return $s; }
    return null;
}
$pdf  = "%PDF-1.7\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n";
$jpg  = "\xFF\xD8\xFF\xE0" . str_repeat( 'x', 64 );
$png  = "\x89PNG\r\n\x1a\n" . str_repeat( 'x', 64 );

$r = upload( 'art.pdf', $pdf );
ok( 'a normal PDF is accepted and stored as .pdf', $r && $r->ok && preg_match( '/\.pdf$/', $r->data['path'] ), json_encode( $r ? $r->data : null ) );
$r = upload( 'mac-export.pdf', "\xEF\xBB\xBF\r\n" . $pdf );
ok( 'a PDF whose header sits a few bytes in (allowed by the format, previewed by the calculator) is accepted', $r && $r->ok, json_encode( $r ? $r->data : null ) );
$r = upload( 'photo.jpg', $png );
ok( 'a PNG saved with a .jpg name is accepted — the customer cannot see the difference', $r && $r->ok, json_encode( $r ? $r->data : null ) );
ok( 'and stored under its true type, so everything downstream opens it as a PNG', $r && $r->ok && preg_match( '/\.png$/', $r->data['path'] ) );
$r = upload( 'photo.JPG', $jpg );
ok( 'an upper-case extension is fine', $r && $r->ok && preg_match( '/\.jpg$/', $r->data['path'] ) );
$r = upload( 'invoice.pdf', "MZ\x90\x00" . str_repeat( "\x00", 2000 ) );
ok( 'an executable renamed .pdf is refused', $r && ! $r->ok && strpos( $r->data, 'does not match' ) !== false );
$r = upload( 'shell.jpg', "<?php system(\$_GET['c']); ?>" );
ok( 'a script renamed .jpg is refused', $r && ! $r->ok );
$r = upload( 'notes.docx', "PK\x03\x04" . str_repeat( 'x', 40 ) );
ok( 'an unsupported type is refused by name', $r && ! $r->ok && strpos( $r->data, 'File type not allowed: .docx' ) === 0 );
$r = upload( 'late-header.pdf', str_repeat( ' ', 1100 ) . $pdf );
ok( 'a "PDF" with no header in its first 1024 bytes is still refused', $r && ! $r->ok );

exec( 'rm -rf ' . escapeshellarg( $GLOBALS['tmp'] ) );
echo "\n{$GLOBALS['t_checks']} checks, {$GLOBALS['t_failed']} failed\n";
echo $GLOBALS['t_failed'] ? ">>> UPLOAD ENDPOINT FAILED\n" : ">>> UPLOAD ENDPOINT OK\n";
exit( $GLOBALS['t_failed'] ? 1 : 0 );

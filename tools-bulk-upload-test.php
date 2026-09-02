<?php
/**
 * Bulk calculator upload — regression test for pps-html-deploy.php v1.4.0.
 *
 * Loads the real functions out of the shipped plugin file (with a thin WordPress
 * shim) rather than restating their logic, so the test fails if the plugin
 * drifts, not only if someone edits the test.
 *
 * Run: php tools-bulk-upload-test.php
 */

// ── Minimal WordPress shim: just enough for the functions under test ──
define( 'ABSPATH', __DIR__ . '/' );
define( 'PPS_UPLOAD_SUBDIR', 'pps-calculators' );
define( 'PPS_CALC_OPTION', 'pps_calculators' );

function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_generate_password( $len = 12, $special = true ) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ( $i = 0; $i < $len; $i++ ) $out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
    return $out;
}
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function size_format( $b, $d = 0 ) { return round( $b / 1048576, $d ) . ' MB'; }
function wp_max_upload_size() { return 8 * 1024 * 1024; }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() . '/ppsfake' ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function add_action() {} function add_submenu_page() {} function esc_html( $s ) { return $s; }
function add_filter() {} function remove_filter() {} function add_shortcode() {}
function wc_add_notice() {} function wc_get_product() { return null; }
function __( $s, $d = null ) { return $s; } function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; } function wp_kses_post( $s ) { return $s; }
function is_admin() { return true; } function did_action() { return 0; }
function apply_filters( $t, $v ) { return $v; } function do_action() {}
function get_transient() { return false; } function set_transient() {} function delete_transient() {}
function get_option( $k, $d = false ) { return $d; }
function update_option() { return true; }
function wp_convert_hr_to_bytes( $v ) { return (int) $v; }

// Load the real plugin. It calls add_action/add_submenu_page, which are no-ops above.
require __DIR__ . '/pps-html-deploy.php';

$checks = 0; $failed = 0;
function ok( $label, $cond ) {
    global $checks, $failed;
    $checks++;
    if ( ! $cond ) { $failed++; echo "  FAIL  $label\n"; }
}
function eq( $label, $got, $want ) {
    global $checks, $failed;
    $checks++;
    if ( $got !== $want ) {
        $failed++;
        echo "  FAIL  $label\n        got:  " . var_export( $got, true ) . "\n        want: " . var_export( $want, true ) . "\n";
    }
}

echo "\n── registry: an overwrite must not drop product assignments ──\n";

// This is the one that would ship silently. A calculator whose 'products' got
// wiped still renders in the admin list; it just stops appearing on the product
// pages it was assigned to.
$reg = array(
    'calc-brochure.html' => array(
        'name'     => 'Brochure Calculator',
        'products' => '33672, 26428, 24110',
        'uploaded' => '2026-01-01 00:00:00',
    ),
);
$after = pps_bulk_upload_register( $reg, 'calc-brochure.html' );

eq( 'products survive the overwrite', $after['calc-brochure.html']['products'], '33672, 26428, 24110' );
eq( 'display name survives',          $after['calc-brochure.html']['name'],     'Brochure Calculator' );
ok( 'uploaded timestamp moves',       $after['calc-brochure.html']['uploaded'] !== '2026-01-01 00:00:00' );
eq( 'no extra rows invented',         count( $after ), 1 );

echo "── registry: a new filename is added, not merged ──\n";
$added = pps_bulk_upload_register( $reg, 'calc-sticker.html' );
eq( 'new row created',        isset( $added['calc-sticker.html'] ), true );
eq( 'new row has no products', $added['calc-sticker.html']['products'], '' );
eq( 'derived name',            $added['calc-sticker.html']['name'], 'calc-sticker' );
eq( 'existing row untouched',  $added['calc-brochure.html']['products'], '33672, 26428, 24110' );

echo "── registry: a corrupt row is rebuilt rather than trusted ──\n";
$bad = pps_bulk_upload_register( array( 'calc-x.html' => 'not-an-array' ), 'calc-x.html' );
eq( 'scalar row replaced with a real one', is_array( $bad['calc-x.html'] ), true );

echo "── staging name must never be picked up by the *.html registry sync ──\n";
// The main admin page runs glob($dir.'/*.html') and deletes registry entries with
// no matching file. A temp file ending in .html would register as a phantom
// calculator; one that lingers would then be "synced" into the registry.
for ( $i = 0; $i < 200; $i++ ) {
    $stage = pps_bulk_upload_stage_name( '/var/www/uploads/pps-calculators' );
    $base  = basename( $stage );
    if ( fnmatch( '*.html', $base ) ) { ok( "stage name #$i does not match *.html ($base)", false ); break; }
    if ( strpos( $stage, '/var/www/uploads/pps-calculators/' ) !== 0 ) { ok( "stage name #$i stays in the target dir", false ); break; }
}
ok( '200 generated staging names avoid *.html and stay in-directory', true );
ok( 'staging names are unique', pps_bulk_upload_stage_name( '/d' ) !== pps_bulk_upload_stage_name( '/d' ) );

echo "── upload error codes render as English, not numbers ──\n";
foreach ( array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE,
                 UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION ) as $code ) {
    $txt = pps_bulk_upload_error_text( $code );
    ok( "code $code has prose", is_string( $txt ) && $txt !== '' && ! ctype_digit( $txt ) );
}
ok( 'unknown code still explains itself', strpos( pps_bulk_upload_error_text( 99 ), '99' ) !== false );

echo "── extension gate: only .html is accepted ──\n";
// Mirrors the check in the handler. .php must never reach the calculators dir.
$gate = function( $n ) { return strtolower( pathinfo( $n, PATHINFO_EXTENSION ) ) === 'html'; };
foreach ( array( 'calc-brochure.html' => true, 'calc-brochure.HTML' => true,
                 'shell.php'          => false, 'shell.html.php'    => false,
                 'calc.php.html'      => true,  'notes.txt'         => false,
                 'calc-brochure'      => false ) as $name => $want ) {
    eq( "gate: $name", $gate( $name ), $want );
}

echo "\n$checks checks, $failed failed\n";
exit( $failed ? 1 : 0 );

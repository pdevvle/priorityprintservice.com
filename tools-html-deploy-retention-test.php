<?php
/**
 * Retention — regression test for pps-html-deploy.php.
 *
 * Two directories grew without limit until 1.5.0: the extracted per-build
 * scripts in uploads, and the deploy archive. Pruning them is the kind of change
 * where being slightly wrong deletes something a live page is asking for, so the
 * policy is tested directly and then again against a real filesystem.
 *
 * Loads the real functions out of the shipped plugin behind a WordPress shim, so
 * this fails if the plugin drifts rather than only if someone edits the test.
 *
 * Run: php tools-html-deploy-retention-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

// Enough WordPress to let the file load. Nothing here is exercised by the tests
// below except trailingslashit.
// pps-html-deploy.php side-loads the term-html / shortcode modules, so the shim
// has to cover what those touch at load time too.
function add_action() {} function add_filter() {} function add_submenu_page() {}
function remove_filter() {} function remove_action() {} function has_filter() { return false; }
function add_shortcode() {} function remove_shortcode() {} function shortcode_exists() { return false; }
function is_admin() { return true; }
function wp_generate_password( $n = 12 ) { return str_repeat( 'a', $n ); }
function register_activation_hook() {} function register_deactivation_hook() {}
function wp_next_scheduled() { return false; } function wp_schedule_event() {}
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url() { return '/'; }
function get_transient() { return false; } function set_transient() {} function delete_transient() {}
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function current_time() { return gmdate( 'Y-m-d H:i:s' ); }
function get_option( $k, $d = false ) { return $d; }
function update_option() { return true; }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function size_format( $b ) { return $b . 'B'; }
function wp_max_upload_size() { return 8388608; }
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function wp_convert_hr_to_bytes( $s ) { return (int) $s; }
function sanitize_file_name( $s ) { return $s; }
function current_user_can() { return true; }
function wp_die( $m ) { throw new Exception( $m ); }

require __DIR__ . '/pps-html-deploy.php';

$checks = 0; $failed = 0;
function ok( $label, $cond, $detail = '' ) {
    global $checks, $failed;
    $checks++;
    if ( $cond ) { echo "PASS $label" . ( $detail ? "  $detail" : '' ) . "\n"; return; }
    $failed++;
    echo "FAIL $label" . ( $detail ? "\n       $detail" : '' ) . "\n";
}

$NOW = 1_800_000_000;
$DAY = 86400;

echo "\n── the policy: keep the newest few, and never touch anything young ──\n";

// Ten builds, one a day apart. Newest is age 0.
$files = array();
for ( $i = 0; $i < 10; $i++ ) $files[ "b$i.js" ] = $NOW - ( $i * $DAY );

$drop = pps_html_deploy_prunable( $files, 5, 14 * $DAY, $NOW );
ok( 'nothing goes while everything is younger than the floor',
    $drop === array(), implode( ',', $drop ) );

// Same ten, but each a month apart: all well past the floor.
$old = array();
for ( $i = 0; $i < 10; $i++ ) $old[ "b$i.js" ] = $NOW - ( $i * 30 * $DAY );
$drop = pps_html_deploy_prunable( $old, 5, 14 * $DAY, $NOW );
ok( 'the newest five survive on count alone', count( $drop ) === 5, implode( ',', $drop ) );
ok( 'and it is the five OLDEST that go',
    $drop === array( 'b5.js', 'b6.js', 'b7.js', 'b8.js', 'b9.js' ), implode( ',', $drop ) );
foreach ( array( 'b0.js', 'b1.js', 'b2.js', 'b3.js', 'b4.js' ) as $keep ) {
    ok( "kept $keep", ! in_array( $keep, $drop, true ) );
}

// The case that matters: a file beyond the keep count but still young.
$mixed = array(
    'new1.js' => $NOW - 1 * $DAY, 'new2.js' => $NOW - 2 * $DAY, 'new3.js' => $NOW - 3 * $DAY,
    'new4.js' => $NOW - 4 * $DAY, 'new5.js' => $NOW - 5 * $DAY, 'new6.js' => $NOW - 6 * $DAY,
    'ancient.js' => $NOW - 90 * $DAY,
);
$drop = pps_html_deploy_prunable( $mixed, 5, 14 * $DAY, $NOW );
ok( 'a young file past the keep count is still spared — a cached page may hold it',
    ! in_array( 'new6.js', $drop, true ), implode( ',', $drop ) );
ok( 'the genuinely old one goes', in_array( 'ancient.js', $drop, true ) );

ok( 'an empty set is not a crash', pps_html_deploy_prunable( array(), 5, $DAY, $NOW ) === array() );
ok( 'fewer files than the keep count means nothing goes',
    pps_html_deploy_prunable( array( 'a.js' => 0, 'b.js' => 0 ), 5, 0, $NOW ) === array() );
ok( 'keep=0 with everything old removes everything',
    count( pps_html_deploy_prunable( $old, 0, 0, $NOW ) ) === 10 );

echo "\n── against a real directory ──\n";

$root = sys_get_temp_dir() . '/pps-retention-' . getmypid();
$js   = $root . '/js';
wp_mkdir_p( $js );

// Two calculators, one a strict prefix of the other, to prove the prune cannot
// reach across. Eight builds each, all 60 days old.
// Real clock here, not $NOW: prune_scripts() calls time() itself, so fabricated
// mtimes in the future would simply never satisfy the age floor and the test
// would pass for the wrong reason.
$REAL = time();
foreach ( array( 'calc-preview-test', 'calc-preview-test-v2' ) as $base ) {
    for ( $i = 0; $i < 8; $i++ ) {
        $f = sprintf( '%s/%s-%010x.js', $js, $base, $i + 1 );
        file_put_contents( $f, 'x' );
        touch( $f, $REAL - ( 60 + $i ) * $DAY );
    }
}
// A file that is not a build artifact at all.
file_put_contents( $js . '/calc-preview-test-notahash.js', 'x' );
touch( $js . '/calc-preview-test-notahash.js', $REAL - 900 * $DAY );

// One recent build for the same calculator, well past the keep count: it must
// survive on age alone, which is the property that protects a cached page.
$young = sprintf( '%s/calc-preview-test-%010x.js', $js, 0xfee1 );
file_put_contents( $young, 'x' );
touch( $young, $REAL - 2 * $DAY );

$before = count( glob( $js . '/*.js' ) );
$gone   = pps_html_deploy_prune_scripts( $root, 'calc-preview-test' );
$after  = glob( $js . '/*.js' );

ok( 'it removed the builds beyond the keep count', $gone === 4, "removed $gone" );
ok( 'leaving five for that calculator',
    count( preg_grep( '#/calc-preview-test-[0-9a-f]{10}\.js$#', $after ) ) === 5 );
ok( 'the recent build survives even though it is past the keep count',
    in_array( $young, $after, true ), 'age is what protects a cached page' );
ok( 'the neighbouring calculator is untouched',
    count( preg_grep( '#/calc-preview-test-v2-[0-9a-f]{10}\.js$#', $after ) ) === 8,
    'a `<base>-*` glob would have eaten these' );
ok( 'a file that is not a build artifact is left alone',
    in_array( $js . '/calc-preview-test-notahash.js', $after, true ) );
ok( 'nothing else vanished', count( $after ) === $before - 4, count( $after ) . " of $before" );

ok( 'an absent js directory is not an error',
    pps_html_deploy_prune_scripts( $root . '/nope', 'calc-preview-test' ) === 0 );

echo "\n── the deploy archive ──\n";

$arch = PPS_HTML_DEPLOY_ARCHIVE_DIR;
wp_mkdir_p( $arch );
for ( $i = 1; $i <= 25; $i++ ) {
    $d = sprintf( '%s/2026-01-%02d-120000', $arch, $i );
    wp_mkdir_p( $d );
    file_put_contents( $d . '/calc-brochure.html', 'x' );
}
// Something that is not a run directory must not be swept up.
wp_mkdir_p( $arch . '/keep-me' );
file_put_contents( $arch . '/keep-me/note.txt', 'x' );

$gone = pps_html_deploy_prune_archive( 20 );
$left = glob( $arch . '/*', GLOB_ONLYDIR );

ok( 'it trimmed to the keep count', $gone === 5, "removed $gone" );
ok( 'the newest twenty runs survive',
    count( preg_grep( '#/2026-01-\d{2}-120000$#', $left ) ) === 20, count( $left ) . ' dirs' );
ok( 'and it is the oldest that went',
    ! is_dir( $arch . '/2026-01-01-120000' ) && is_dir( $arch . '/2026-01-25-120000' ) );
ok( 'a directory that is not a dated run is left alone', is_dir( $arch . '/keep-me' ) );
ok( 'under the keep count it does nothing', pps_html_deploy_prune_archive( 100 ) === 0 );

echo "\n── the filename gate ──\n";
foreach ( array( 'calc-brochure.html', 'calc-preview-test.html', 'CALC-STICKER.HTML' ) as $n ) {
    ok( "accepts $n", pps_html_deploy_name_ok( $n ) );
}
ok( 'accepts the proof surface', pps_html_deploy_name_ok( 'proof-ui-draft.html' ),
    'it is not a calculator but must reach the same directory' );
foreach ( array( 'index.php', 'evil.html', 'calc-.html', '../calc-x.html',
                 'calc-brochure.html.php', 'proof-ui-draft.html.php' ) as $n ) {
    ok( "refuses $n", ! pps_html_deploy_name_ok( $n ) );
}

// ── clean up ──
foreach ( glob( $js . '/*' ) as $f ) @unlink( $f );
@rmdir( $js );
foreach ( glob( $arch . '/*', GLOB_ONLYDIR ) as $d ) {
    foreach ( glob( $d . '/*' ) as $f ) @unlink( $f );
    @rmdir( $d );
}
@rmdir( $arch );
@rmdir( $root );

echo "\n$checks checks, $failed failed\n";
echo $failed ? ">>> RETENTION FAILED\n" : ">>> RETENTION OK\n";
exit( $failed ? 1 : 0 );

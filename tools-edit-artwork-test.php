<?php
/**
 * Editing a cart line must not shed the customer's files.
 *
 * The calculator reopens an edited line with its artwork as "existing" and posts
 * the one raw path back; the old line — which carried files 2..N, per-page files,
 * the approval package and any prepress-review flag — is then removed. Until
 * 2026-09-26 nothing moved those across, so a twenty-file order came out of a
 * quantity change with one file, and an unapproved job came out looking approved.
 *
 * Lifts pps_carry_edit_artwork() out of pps-calculators.php and checks that the
 * add-to-cart handler calls it for an edit. PPS_CALC_PHP points at another copy —
 * how the pre-fix file was run to prove it fails.
 *
 * 2026-10-13: an edit that changes the job (size, sides, folds, perforations) kept
 * the old line's approval package, so a 4×6 edited to 5×7 reached production with
 * the 4×6 print file. Now the customer's own files are kept, the package is not, and
 * an online-approved job is marked NOT APPROVED (pps_edit_shape_changed). The
 * 21bb4ae file fails those checks.
 *
 * Run: php tools-edit-artwork-test.php
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
    $at = strpos( $src, 'function ' . $name . '(' );
    if ( $at === false ) return '';
    $open = strpos( $src, '{', $at ); $depth = 0;
    for ( $i = $open; $i < strlen( $src ); $i++ ) {
        if ( $src[$i] === '{' ) $depth++;
        elseif ( $src[$i] === '}' && --$depth === 0 ) return substr( $src, $at, $i - $at + 1 );
    }
    return '';
}
$fn = lift( $src, 'pps_carry_edit_artwork' );
ok( 'the plugin has a function that carries artwork across an edit', $fn !== '' );
if ( $fn === '' ) $fn = 'function pps_carry_edit_artwork( array $n, $o ) { return $n; }';   // the old behaviour, to show what it did
$shape_fn = lift( $src, 'pps_edit_shape_changed' );
if ( $shape_fn === '' ) $shape_fn = 'function pps_edit_shape_changed( $o, $n ) { return false; }';   // absent before 2026-10-13
eval( $shape_fn );
eval( $fn );

// The handler must call it for an edit, before the new line is added.
$h_at  = strpos( $src, "function pps_ajax_add_to_cart(" );
$call  = strpos( $src, 'pps_carry_edit_artwork( $cart_item_data', $h_at === false ? 0 : $h_at );
$add   = strpos( $src, 'WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_item_data )', $h_at === false ? 0 : $h_at );
ok( 'add-to-cart calls it for an edit, before the new line is added', $h_at !== false && $call !== false && $add !== false && $call < $add );

$files = array(); for ( $i = 1; $i <= 20; $i++ ) $files[] = array( 'path' => "pps-artwork/2026/09/f$i.jpg", 'name' => "$i.jpg" );
$files[] = array( 'path' => 'pps-artwork/2026/09/p.pdf', 'name' => 'job_print-ready.pdf' );
$old = array( 'pps_artwork_path' => 'pps-artwork/2026/09/f1.jpg', 'pps_artwork_files' => $files, 'pps_proof_hash' => str_repeat( 'a', 64 ) );

$new = pps_carry_edit_artwork( array( 'pps_price' => '10', 'pps_artwork_path' => 'pps-artwork/2026/09/f1.jpg' ), $old );
ok( 'an edit that keeps the artwork keeps all twenty files and the package', count( $new['pps_artwork_files'] ?? array() ) === 21, (string) count( $new['pps_artwork_files'] ?? array() ) );
ok( 'the approval hash is not carried — the edit may have changed what prints', ! isset( $new['pps_proof_hash'] ) );

$new = pps_carry_edit_artwork( array( 'pps_artwork_path' => 'pps-artwork/2026/09/f1.jpg',
    'pps_artwork_files' => array( array( 'path' => 'pps-artwork/2026/09/f1.jpg', 'name' => '1.jpg' ), array( 'path' => 'pps-artwork/2026/09/r.pdf', 'name' => 'reference_brief.pdf' ) ) ), $old );
$names = array_map( function( $f ) { return $f['name']; }, $new['pps_artwork_files'] ?? array() );
ok( 'a reference file added during the edit is kept too, and nothing is listed twice', count( $names ) === 22 && in_array( 'reference_brief.pdf', $names, true ) && count( array_unique( $names ) ) === 22, implode( ',', $names ) );

$new = pps_carry_edit_artwork( array( 'pps_artwork_path' => 'pps-artwork/2026/09/NEW.pdf', 'pps_artwork_files' => array( array( 'path' => 'pps-artwork/2026/09/NEW.pdf', 'name' => 'new.pdf' ) ) ), $old );
ok( 'new artwork replaces the old — the old files do not ride along', count( $new['pps_artwork_files'] ) === 1 && $new['pps_artwork_files'][0]['name'] === 'new.pdf' );

$flagged = $old; $flagged['pps_prepress_review'] = 'proof could not be prepared'; unset( $flagged['pps_proof_hash'] );
$new = pps_carry_edit_artwork( array( 'pps_artwork_path' => 'pps-artwork/2026/09/f1.jpg' ), $flagged );
ok( 'a job the customer could not approve is still marked NOT APPROVED after an edit', ( $new['pps_prepress_review'] ?? '' ) === 'proof could not be prepared' );

$new = pps_carry_edit_artwork( array( 'pps_artwork_path' => 'pps-artwork/2026/09/f1.jpg' ), null );
ok( 'no old line → nothing changes', $new === array( 'pps_artwork_path' => 'pps-artwork/2026/09/f1.jpg' ) );

// ── 2026-10-13: an edit that changes the job does not keep the old job's approval ──
$pkg = array(
    array( 'path' => 'pps-artwork/2026/10/a.pdf', 'name' => 'card.pdf' ),
    array( 'path' => 'pps-artwork/2026/10/b.pdf', 'name' => 'card-back.pdf' ),
    array( 'path' => 'pps-artwork/2026/10/c.pdf', 'name' => 'card_print-ready.pdf' ),
    array( 'path' => 'pps-artwork/2026/10/d.jpg', 'name' => 'card_preview_front.jpg' ),
    array( 'path' => 'pps-artwork/2026/10/e.jpg', 'name' => 'card_preview_back.jpg' ),
    array( 'path' => 'pps-artwork/2026/10/f.txt', 'name' => 'card_manipulation_manifest.txt' ),
);
$m4x6 = array( 'calcType' => 'postcard', 'qty' => 100, 'sizeMode' => 'preset', 'sizeLabel' => '4×6', 'longEdge' => 3.5, 'shortEdge' => 2,
    'sides' => 2, 'foldType' => 'none', 'foldDir' => 'long', 'perforation' => 0, 'perfDir' => 'length', 'perfPositions' => array( 50 ), 'proof' => 0 );
$line = function( $meta, $files = null ) {
    $l = array( 'pps_artwork_path' => 'pps-artwork/2026/10/a.pdf', 'pps_metadata' => json_encode( $meta ) );
    if ( $files !== null ) $l['pps_artwork_files'] = $files;
    return $l;
};
$names = function( $l ) { return array_map( function( $f ) { return $f['name']; }, $l['pps_artwork_files'] ?? array() ); };
$old_line = $line( $m4x6, $pkg ); $old_line['pps_proof_hash'] = str_repeat( 'b', 64 );

$new = pps_carry_edit_artwork( $line( array_merge( $m4x6, array( 'qty' => 250 ) ) ), $old_line );
ok( 'an edit of the quantity only keeps the whole approval package', count( $names( $new ) ) === 6, implode( ',', $names( $new ) ) );
ok( '… and is not marked for review', empty( $new['pps_prepress_review'] ) );

$new = pps_carry_edit_artwork( $line( array_merge( $m4x6, array( 'sizeLabel' => '5×7' ) ) ), $old_line );
ok( 'a size change keeps the customer\'s own files but not the print file, previews or manifest',
    $names( $new ) === array( 'card.pdf', 'card-back.pdf' ), implode( ',', $names( $new ) ) );
ok( '… and an online-approved job is marked NOT APPROVED', strpos( (string) ( $new['pps_prepress_review'] ?? '' ), 'Edited in the cart after approval' ) === 0, (string) ( $new['pps_prepress_review'] ?? '(none)' ) );
ok( '… with no approval hash', ! isset( $new['pps_proof_hash'] ) );

$new = pps_carry_edit_artwork( $line( array_merge( $m4x6, array( 'sizeLabel' => '5×7', 'proof' => 0.01 ) ) ), $old_line );
ok( 'a staff-proof job that changed size drops the old package too, and is left to the staff proof',
    $names( $new ) === array( 'card.pdf', 'card-back.pdf' ) && empty( $new['pps_prepress_review'] ), implode( ',', $names( $new ) ) );

$cases = array(
    'two sides to one'             => array( 'sides' => 1 ),
    'a fold added'                 => array( 'foldType' => 'bifold' ),
    'the fold direction'           => array( 'foldDir' => 'short' ),
    'a perforation added'          => array( 'perforation' => 2000, 'perfPositions' => array( 50 ) ),
    'a custom size'                => array( 'sizeMode' => 'custom', 'sizeLabel' => '6×4" custom', 'longEdge' => 6, 'shortEdge' => 4 ),
);
foreach ( $cases as $what => $d ) {
    $new = pps_carry_edit_artwork( $line( array_merge( $m4x6, $d ) ), $old_line );
    ok( "the package is not carried across $what", ! in_array( 'card_print-ready.pdf', $names( $new ), true ), implode( ',', $names( $new ) ) );
}
$perf = array_merge( $m4x6, array( 'perforation' => 2000, 'perfPositions' => array( 25 ) ) );
$new = pps_carry_edit_artwork( $line( array_merge( $perf, array( 'perfPositions' => array( 60 ) ) ) ), $line( $perf, $pkg ) );
ok( 'a moved perforation is a different job', ! in_array( 'card_print-ready.pdf', $names( $new ), true ) );
$cust = array_merge( $m4x6, array( 'sizeMode' => 'custom', 'longEdge' => 6, 'shortEdge' => 4 ) );
$new = pps_carry_edit_artwork( $line( array_merge( $cust, array( 'longEdge' => 7 ) ) ), $line( $cust, $pkg ) );
ok( 'a custom size changed by an inch is a different job', ! in_array( 'card_print-ready.pdf', $names( $new ), true ) );
$new = pps_carry_edit_artwork( $line( array_merge( $m4x6, array( 'longEdge' => 6, 'shortEdge' => 4 ) ) ), $old_line );
ok( 'a preset\'s stale inches are not a change (the label is the size)', count( $names( $new ) ) === 6 );
$new = pps_carry_edit_artwork( $line( array( 'sizeLabel' => '8.5×11', 'proof' => 0 ) ), $line( array( 'sizeLabel' => '5.5×8.5', 'proof' => 0 ), $pkg ) );
ok( 'a booklet whose size changed drops the package as well', ! in_array( 'card_print-ready.pdf', $names( $new ), true ) );
$new = pps_carry_edit_artwork( $line( array( 'qty' => 5 ) ), $line( array( 'sizeLabel' => '4×6' ), $pkg ) );
ok( 'a line from an older build without the keys is not called changed', count( $names( $new ) ) === 6 );

echo "\n{$GLOBALS['t_checks']} checks, {$GLOBALS['t_failed']} failed\n";
echo $GLOBALS['t_failed'] ? ">>> EDIT ARTWORK FAILED\n" : ">>> EDIT ARTWORK OK\n";
exit( $GLOBALS['t_failed'] ? 1 : 0 );

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

echo "\n{$GLOBALS['t_checks']} checks, {$GLOBALS['t_failed']} failed\n";
echo $GLOBALS['t_failed'] ? ">>> EDIT ARTWORK FAILED\n" : ">>> EDIT ARTWORK OK\n";
exit( $GLOBALS['t_failed'] ? 1 : 0 );

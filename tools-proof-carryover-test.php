<?php
/**
 * Approval carry-over — regression test for the edit/reorder path in
 * pps-calculators.php.
 *
 * The bug: edit mode and reorder restore artwork as { type:'existing', path },
 * so the calculator cannot re-post pps_proof_hash / pps_artwork_files /
 * pps_prepress_review, and they were dropped on every edit. The imposition tool
 * only checks a line that carries a 64-hex hash — a line with none is "unbound":
 * it skips the comparison AND names its output exactly as it names a verified
 * one. The approval silently stopped existing and nothing downstream could tell.
 *
 * The fix must be safe in BOTH directions, which is what this pins:
 *   - an unchanged spec keeps its approval (the bug)
 *   - a changed spec loses it (the far worse failure — a hash that asserts a
 *     file was signed off when the spec beneath it moved)
 *
 * The two functions are lifted out of the shipped plugin rather than copied, so
 * this fails if the plugin drifts and not only if someone edits the test.
 *
 * Run: php tools-proof-carryover-test.php
 *
 * PPS_PLUGIN_FILE points it at another copy — how it was confirmed to
 * discriminate, by widening the ignore list in a scratch copy and watching the
 * "spec changed -> approval dropped" checks fail.
 */

$plugin = getenv( 'PPS_PLUGIN_FILE' ) ?: __DIR__ . '/pps-calculators.php';
$src    = file_get_contents( $plugin );
if ( $src === false ) { fwrite( STDERR, "cannot read $plugin\n" ); exit( 2 ); }

// Extract the two functions from the real file.
foreach ( array( 'pps_proof_carryover_signature', 'pps_proof_carryover_from' ) as $fn ) {
    $i = strpos( $src, "function $fn(" );
    if ( $i === false ) { fwrite( STDERR, "$fn not found in $plugin\n" ); exit( 2 ); }
    $depth = 0; $start = strpos( $src, '{', $i ); $end = null;
    for ( $k = $start; $k < strlen( $src ); $k++ ) {
        if ( $src[ $k ] === '{' ) { $depth++; }
        elseif ( $src[ $k ] === '}' ) { $depth--; if ( $depth === 0 ) { $end = $k + 1; break; } }
    }
    if ( $end === null ) { fwrite( STDERR, "$fn body unterminated\n" ); exit( 2 ); }
    eval( substr( $src, $i, $end - $i ) );
}

function wp_json_encode( $v ) { return json_encode( $v ); }

$checks = 0; $failed = 0;
function eq( $label, $got, $want ) {
    global $checks, $failed; $checks++;
    if ( $got !== $want ) {
        $failed++;
        echo "  FAIL  $label\n        got:  " . var_export( $got, true ) . "\n        want: " . var_export( $want, true ) . "\n";
    }
}

// ── A representative saddle-stitch spec, keys as buildMetadata() emits them ──
function spec( array $over = array() ) {
    return json_encode( array_merge( array(
        'sizeLabel'      => '5.5x8.5',
        'customLong'     => 8.5,
        'customShort'    => 5.5,
        'bindDir'        => 'left',
        'sets'           => array( array( 'pages' => 16, 'qty' => 100 ) ),
        'insideColor'    => 'color',
        'coverColor'     => 'color',
        'insidePaper'    => '80T',
        'coverPaper'     => '100C',
        'coverMode'      => 'separate',
        'coating'        => 'none',
        'artwork'        => 'upload',
        'bleed'          => true,
        'proof'          => 0,
        'pageTransforms' => array( '1' => array( 'fit' => 'crop', 'rot' => 0 ) ),
        'artDims'        => array( 'w' => 5.75, 'h' => 8.75 ),
        // the ignorable half
        'shipZip'        => '90210',
        'shipState'      => 'CA',
        'shipAddr'       => array( 'zip' => '90210' ),
        'needByDate'     => '2026-10-01',
        'totalQty'       => 100,
        'total'          => 812.34,
        'perUnit'        => 8.12,
        'rushCost'       => 0,
        'estimatedDeliveryDate' => '2026-10-05',
        'debug'          => array( 'x' => 1 ),
    ), $over ) );
}

const HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
const ART  = 'pps-artwork/2026/09/booklet.pdf';

function old_line( array $over = array() ) {
    return array_merge( array(
        'pps_metadata'      => spec(),
        'pps_artwork_path'  => ART,
        'pps_proof_hash'    => HASH,
        'pps_artwork_files' => array( array( 'path' => ART, 'name' => 'booklet.pdf' ) ),
    ), $over );
}

echo "\nApproval carry-over\n";

// ── 1. The bug this fixes ────────────────────────────────────────────────────
$r = pps_proof_carryover_from( old_line(), spec(), ART );
eq( 'identical re-add keeps the approval hash', $r['carry']['pps_proof_hash'] ?? null, HASH );
eq( 'and keeps the deliverables list', isset( $r['carry']['pps_artwork_files'] ), true );
eq( 'reason is ok', $r['reason'], 'ok' );

// ── 2. Things a customer may change while the approved PDF still describes
//       their order. Each of these MUST keep the approval, or the fix is inert
//       for the common edits and the bug survives in practice.
$benign = array(
    'quantity'          => array( 'sets' => array( array( 'pages' => 16, 'qty' => 250 ) ), 'totalQty' => 250 ),
    'shipping ZIP'      => array( 'shipZip' => '10001', 'shipState' => 'NY' ),
    'need-by date'      => array( 'needByDate' => '2026-11-20' ),
    'price recalculated'=> array( 'total' => 1900.00, 'perUnit' => 7.60, 'rushCost' => 120 ),
    'delivery estimate' => array( 'estimatedDeliveryDate' => '2026-11-25' ),
    'proof option'      => array( 'proof' => 3.01 ),
);
foreach ( $benign as $label => $over ) {
    $r = pps_proof_carryover_from( old_line(), spec( $over ), ART );
    eq( "$label changed -> approval survives", $r['carry']['pps_proof_hash'] ?? null, HASH );
}

// ── 3. The dangerous direction. Each of these changes what would print, so the
//       approval MUST be dropped — carrying it would assert a sign-off that
//       never happened for this spec.
$breaking = array(
    'trim size'       => array( 'sizeLabel' => '8.5x11', 'customLong' => 11, 'customShort' => 8.5 ),
    'page count'      => array( 'sets' => array( array( 'pages' => 24, 'qty' => 100 ) ) ),
    'binding edge'    => array( 'bindDir' => 'top' ),
    'per-page crop'   => array( 'pageTransforms' => array( '1' => array( 'fit' => 'fill', 'rot' => 90 ) ) ),
    'greyscale inside'=> array( 'insideColor' => 'bw' ),
    'cover colour'    => array( 'coverColor' => 'bw' ),
    'bleed answer'    => array( 'bleed' => false ),
    'artwork option'  => array( 'artwork' => 'design' ),
    'cover mode'      => array( 'coverMode' => 'self' ),
    'art dimensions'  => array( 'artDims' => array( 'w' => 8.75, 'h' => 11.25 ) ),
);
foreach ( $breaking as $label => $over ) {
    $r = pps_proof_carryover_from( old_line(), spec( $over ), ART );
    eq( "$label changed -> approval dropped", isset( $r['carry']['pps_proof_hash'] ), false );
    eq( "$label changed -> reason names it", $r['reason'], 'spec changed' );
}

// ── 4. Different artwork file ────────────────────────────────────────────────
$r = pps_proof_carryover_from( old_line(), spec(), 'pps-artwork/2026/09/OTHER.pdf' );
eq( 'new artwork -> approval dropped', isset( $r['carry']['pps_proof_hash'] ), false );
eq( 'new artwork -> reason names it', $r['reason'], 'artwork changed' );

// ── 5. Deny by default. A key the calculators gain in future must BLOCK
//       carry-over rather than be silently ignored — this is the property that
//       keeps the fix safe as the spec grows.
$r = pps_proof_carryover_from( old_line(), spec( array( 'someFutureFinishing' => 'foil' ) ), ART );
eq( 'unknown new spec key blocks carry-over', isset( $r['carry']['pps_proof_hash'] ), false );

// ── 6. The escape hatch and a hash cannot coexist ────────────────────────────
$r = pps_proof_carryover_from(
    old_line( array( 'pps_prepress_review' => 'please check the spine' ) ), spec(), ART
);
eq( 'prepress flag carries', $r['carry']['pps_prepress_review'] ?? null, 'please check the spine' );
eq( 'and suppresses the hash', isset( $r['carry']['pps_proof_hash'] ), false );

$r = pps_proof_carryover_from( old_line(), spec(), ART, array( 'pps_prepress_review' => 'fresh flag' ) );
eq( 'a flag posted this request also suppresses a carried hash', isset( $r['carry']['pps_proof_hash'] ), false );

// ── 7. This request's own values win ─────────────────────────────────────────
$fresh = str_repeat( 'f', 64 );
$r = pps_proof_carryover_from( old_line(), spec(), ART, array( 'pps_proof_hash' => $fresh ) );
eq( 'a freshly posted hash is never overwritten', isset( $r['carry']['pps_proof_hash'] ), false );

// ── 8. Degenerate inputs never carry ─────────────────────────────────────────
eq( 'no previous line',  pps_proof_carryover_from( null, spec(), ART )['reason'], 'no previous line' );
eq( 'previous line had nothing',
    pps_proof_carryover_from( array( 'pps_metadata' => spec(), 'pps_artwork_path' => ART ), spec(), ART )['reason'],
    'nothing to carry' );
eq( 'unreadable new spec',
    pps_proof_carryover_from( old_line(), '{not json', ART )['reason'], 'spec unreadable' );
eq( 'unreadable old spec',
    pps_proof_carryover_from( old_line( array( 'pps_metadata' => 'nope' ) ), spec(), ART )['reason'], 'spec unreadable' );

// ── 9. Key order in the JSON must not matter ─────────────────────────────────
$shuffled = json_decode( spec(), true );
$shuffled = array_reverse( $shuffled, true );
$r = pps_proof_carryover_from( old_line(), json_encode( $shuffled ), ART );
eq( 'reordered JSON keys still match', $r['carry']['pps_proof_hash'] ?? null, HASH );

echo "\n$checks checks, $failed failed\n";
echo $failed ? ">>> CARRYOVER FAILED\n\n" : ">>> CARRYOVER OK\n\n";
exit( $failed ? 1 : 0 );

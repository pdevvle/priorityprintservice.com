<?php
/**
 * Proof status — regression test for pps-proof-status.php.
 *
 * Loads the real functions out of the shipped plugin behind a WordPress shim and
 * drives them with the cart-item shapes the calculator actually posts, so the
 * test fails if the plugin drifts rather than only if someone edits the test.
 *
 * Run: php tools-proof-status-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
function add_action() {} function add_filter() {} function is_admin() { return true; }

require __DIR__ . '/pps-proof-status.php';

$checks = 0; $failed = 0;
function eq( $label, $got, $want ) {
    global $checks, $failed;
    $checks++;
    if ( $got !== $want ) {
        $failed++;
        echo "  FAIL  $label\n        got:  " . var_export( $got, true ) . "\n        want: " . var_export( $want, true ) . "\n";
    }
}
function ok( $label, $cond ) {
    global $checks, $failed;
    $checks++;
    if ( ! $cond ) { $failed++; echo "  FAIL  $label\n"; }
}

$HASH = str_repeat( 'a1b2c3d4', 8 );          // 64 hex
assert( strlen( $HASH ) === 64 );

/** Build a cart-item array the way the calculator posts one. */
function item( $proof, $artwork, $hash = '', $path = 'pps-artwork/x.pdf' ) {
    $v = array( 'pps_metadata' => json_encode( array( 'proof' => $proof, 'artwork' => $artwork ) ) );
    if ( $hash !== '' ) $v['pps_proof_hash'] = $hash;
    if ( $path !== '' ) $v['pps_artwork_path'] = $path;
    return $v;
}

echo "\n── the reported defect: no staff proof bought must NOT read as approved ──\n";

// Uploaded a file, never opened the proofer. This is the order that used to say
// SelfApproved and is the whole reason for this module.
list( $t ) = pps_proof_resolve( item( 0, 0.01 ) );
eq( 'uploaded but not signed off', $t, 'NotApproved' );

// Same customer, but they went through the proofer and approved.
list( $t, $s, $h ) = pps_proof_resolve( item( 0, 0.01, $HASH ) );
eq( 'uploaded and approved',           $t, 'SelfApproved' );
eq( 'hash carried into the sentence',  $h, $HASH );
ok( 'sentence quotes the hash', strpos( $s, $HASH ) !== false );

echo "── artwork arriving later is not a missing approval ──\n";
foreach ( array( 0.02 => 'email art after order', 0.03 => 'already discussed',
                 0.04 => 'Canva link',            2.01 => 'artwork needs edits',
                 4.01 => 'design from scratch' ) as $art => $why ) {
    list( $t ) = pps_proof_resolve( item( 0, $art, '', '' ) );
    eq( "art to follow: $why", $t, 'ArtToFollow' );
}

echo "── purchased proofs keep their existing tokens ──\n";
foreach ( array( 1 => 'DigitalProof', 2 => 'DigitalProof', 3 => 'Hardcopy', 4 => 'Hardcopy' ) as $p => $want ) {
    list( $t ) = pps_proof_resolve( item( $p, 0.01 ) );
    eq( "proof=$p", $t, $want );
}
// An approved file outranks the purchase: the sign-off is the stronger fact.
list( $t ) = pps_proof_resolve( item( 3, 0.01, $HASH ) );
eq( 'hardcopy bought AND approved online', $t, 'SelfApproved' );

echo "── a malformed hash must never read as approved ──\n";
foreach ( array(
    'too short'      => substr( $HASH, 0, 63 ),
    'too long'       => $HASH . 'a',
    'non-hex'        => str_repeat( 'z', 64 ),
    'empty'          => '',
    'uppercase ok'   => strtoupper( $HASH ),
) as $why => $h ) {
    list( $t ) = pps_proof_resolve( item( 0, 0.01, $h ) );
    $want = ( $why === 'uppercase ok' ) ? 'SelfApproved' : 'NotApproved';
    eq( "hash $why", $t, $want );
}

echo "── spec rewrite touches the token and nothing else ──\n";
$spec = '5.5x8.5 | 100qty | 8pg | 1set | INSIDE: 80lb Matte/Color | SELF-COVER | SelfApproved | Standard | 3days | SHIP: MI 48124';
eq( 'token replaced',
    pps_proof_rewrite_spec( $spec, 'NotApproved' ),
    '5.5x8.5 | 100qty | 8pg | 1set | INSIDE: 80lb Matte/Color | SELF-COVER | NotApproved | Standard | 3days | SHIP: MI 48124' );

// The word appearing inside a job name must not be rewritten — only whole segments.
$tricky = '5.5x8.5 | 100qty | SelfApproved | Standard | JOB: SelfApproved reprint for Acme';
$out = pps_proof_rewrite_spec( $tricky, 'NotApproved' );
ok( 'job name left intact', strpos( $out, 'JOB: SelfApproved reprint for Acme' ) !== false );
ok( 'standalone token still replaced', strpos( $out, '| NotApproved |' ) !== false );

// A spec with a purchased-proof token has nothing to rewrite.
eq( 'no legacy token -> no change', pps_proof_rewrite_spec( '5.5x8.5 | Hardcopy | Standard', 'NotApproved' ), null );
eq( 'empty spec -> no change',      pps_proof_rewrite_spec( '', 'NotApproved' ), null );

echo "── degrades safely on junk input ──\n";
list( $t ) = pps_proof_resolve( array( 'pps_metadata' => 'not json', 'pps_artwork_path' => 'pps-artwork/x.pdf' ) );
ok( 'unparseable metadata still yields a token', in_array( $t, array( 'NotApproved', 'ArtToFollow' ), true ) );
list( $t ) = pps_proof_resolve( array() );
eq( 'empty item, nothing uploaded', $t, 'ArtToFollow' );

echo "── every path returns a usable sentence ──\n";
foreach ( array( item( 0, 0.01 ), item( 0, 0.01, $HASH ), item( 0, 0.02, '', '' ), item( 3, 0.01 ) ) as $i => $v ) {
    list( $t, $s ) = pps_proof_resolve( $v );
    ok( "case $i has prose", is_string( $s ) && strlen( $s ) > 20 );
    ok( "case $i token is known", in_array( $t, array( 'SelfApproved', 'NotApproved', 'ArtToFollow', 'DigitalProof', 'Hardcopy' ), true ) );
}

echo "\n$checks checks, $failed failed\n";
exit( $failed ? 1 : 0 );

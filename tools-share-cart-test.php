<?php
/**
 * Share-cart links keep the job's specification (owner 2026-11-12).
 *
 * share-cart-url-for-woo saves the cart with update_post_meta(), which wp_unslash()es every
 * value; the backslash before every inch mark in pps_metadata went with it, the JSON stopped
 * parsing, Edit Specs reset the form and checkout wrote no Job Ticket (order 87984). This
 * lifts the heal functions out of pps-calculators.php and replays that save.
 *
 *   php tools-share-cart-test.php [path/to/pps-calculators.php]
 */
$src  = file_get_contents( $argv[1] ?? __DIR__ . '/pps-calculators.php' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "ok   $m\n"; } else { $fail++; echo "FAIL $m\n"; } }
function lift( $src, $name ) {
    return preg_match( '/\nfunction ' . $name . '\s*\(.*?\n}\n/s', $src, $m ) ? $m[0] : '';
}
$code = '';
foreach ( array( 'pps_json_object_ok', 'pps_json_restore_escapes', 'pps_json_requote', 'pps_heal_metadata', 'pps_heal_cart_line' ) as $f ) {
    $l = lift( $src, $f );
    ok( $l !== '', "$f() exists" );
    $code .= $l;
}
eval( $code );

// WordPress's wp_unslash() on an array: stripslashes on every string, recursively.
function unslash( $v ) { return is_array( $v ) ? array_map( 'unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }

// 87984's shape, plus the harder ones: a value ENDING in an inch mark, a quote before a
// comma inside text, a backslash, a newline, nested arrays.
$meta = array(
    'ticket'    => array( array( 'Size', '21×7" custom · Trifold (3 Panel) (panels across the 21" edge)' ), array( 'Quantity', '250' ) ),
    'sizeLabel' => '21×7" custom',
    'flat'      => '8.5×11"',
    'jobName'   => 'Menu 11", folded, "Spring" edition',
    'canvaInstructions' => "Path C:\\art\\menu.pdf\nsecond line",
    'shipAddr'  => array( 'name' => 'Peter Huang', 'company' => ' Yew Chung International School', 'street1' => '310 EASY ST' ),
    'paper'     => array( 'label' => '14pt C2S', 'val' => 2.24, 'inv' => false ),
    'perfPositions' => array( 50 ),
    'qty'       => 250,
);
$json = json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
$bad  = stripslashes( $json );
ok( json_decode( $bad, true ) === null, 'stripslashes breaks the JSON (the bug as it was)' );

// 1. A link saved before the fix: no base64 copy, repaired from the text alone.
$r = pps_json_restore_escapes( $bad );
ok( $r !== null, 'damaged JSON is repaired' );
$d = json_decode( (string) $r, true );
ok( ( $d['sizeLabel'] ?? '' ) === '21×7" custom', 'inch mark inside a value survives' );
ok( ( $d['flat'] ?? '' ) === '8.5×11"', 'a value ending in an inch mark survives' );
ok( ( $d['ticket'][0][1] ?? '' ) === $meta['ticket'][0][1], 'ticket row with two inch marks survives' );
ok( ( $d['jobName'] ?? '' ) === $meta['jobName'], 'quote-comma inside text survives' );
ok( ( $d['qty'] ?? 0 ) === 250 && ( $d['paper']['val'] ?? 0 ) === 2.24 && ( $d['paper']['inv'] ?? null ) === false, 'numbers and booleans intact' );
ok( strpos( (string) ( $d['canvaInstructions'] ?? '' ), 'menu.pdf' ) !== false, 'text with a backslash still decodes' );
ok( pps_json_restore_escapes( $json ) === $json, 'good JSON is returned untouched' );
ok( pps_json_restore_escapes( 'not json at all' ) === null, 'garbage is refused, not invented' );

// 2. The real round trip after the fix: the session read adds the base64 copy, the plugin
//    saves (wp_unslash), add_to_cart puts it back — byte-for-byte the original.
$line  = pps_heal_cart_line( array( 'pps_metadata' => $json, 'pps_price' => 565.73, 'pps_summary' => '250 × 21×7" custom' ) );
ok( isset( $line['pps_metadata_b64'] ) && base64_decode( $line['pps_metadata_b64'] ) === $json, 'session read adds the base64 copy' );
$saved = unslash( $line );
ok( $saved['pps_metadata'] !== $json, 'the plugin\'s save still damages the JSON text' );
$back  = pps_heal_cart_line( $saved );
ok( $back['pps_metadata'] === $json, 'restored line carries the exact original JSON' );
ok( $back['pps_summary'] === '250 × 21×7" custom' && $back['pps_price'] === 565.73, 'other fields untouched' );

// 3. The production sample from share link 87983 (as stored, backslashes already gone).
$live = '{"addons":[],"ticket":[["Product","Brochure / Flat Print"],["Quantity","250"],["Size","21×7" custom · Trifold (3 Panel) (panels across the 21" edge)"],["Paper","14pt C2S Factory Coated (Cardstock)"]],"calcType":"brochure","qty":250,"sizeMode":"custom","sizeLabel":"21×7" custom","longEdge":21,"shortEdge":7,"foldType":"trifold","perfPositions":[50],"proofAddr":{"name":"","street":""},"shipState":"CA","shipZip":"94043","shipAddr":{"name":"Peter Huang","company":" Yew Chung International School","street1":"310 EASY ST","street2":"","city":"MOUNTAIN VIEW","zip":"94043"},"needByDate":"2026-11-30","productionStartDate":"2026-11-19","mustShipByDate":"2026-11-25","total":565.73,"debug":{"P":{"paper":269.3025143792732,"nonInv":35}}}';
ok( json_decode( $live, true ) === null, 'the live stored value does not parse' );
$h = pps_heal_metadata( $live );
$hd = json_decode( $h, true );
ok( is_array( $hd ) && $hd['sizeLabel'] === '21×7" custom' && $hd['mustShipByDate'] === '2026-11-25' && $hd['qty'] === 250, 'the live stored value heals: size, quantity, must-ship back' );

// 4. Wiring in the plugin.
ok( preg_match( "/add_filter\\( 'woocommerce_add_cart_item_data', function\\( \\\$data \\) \\{\\s*try \\{ return pps_heal_cart_line/", $src ) === 1, 'add_to_cart heals the line before it is keyed' );
ok( strpos( $src, "try { \$cart_item = pps_heal_cart_line( \$cart_item ); }" ) !== false, 'the session read heals and adds the copy' );
ok( strpos( $src, "'created_via'  => 'Direct - ShareCart'" ) !== false && strpos( $src, 'function pps_repair_order_metadata' ) !== false, 'orders from share links are repaired once' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );

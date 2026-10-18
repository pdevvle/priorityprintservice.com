<?php
/**
 * pps-calculators.php: contract price lists, server half (owner 2026-10-18).
 *
 * Lifts pps_contract_parse(), pps_contract_lines_to_prices() and pps_contract_check() out of the
 * plugin and runs them: the admin's "qty = price" box in the shapes people type it, a stored value
 * cleaned of anything that is not a positive quantity and price, and the add-to-cart check —
 * a listed quantity at (or above) its listed price passes; another quantity, a mismatched job,
 * a missing contract line or an under-price is refused. Also pins where the plugin uses them:
 * injected on the product page, checked before the floors, materials floor skipped.
 *
 * Run: php tools-contract-price-test.php
 */
$src = file_get_contents( getenv( 'PPS_CALC_PHP' ) ?: __DIR__ . '/pps-calculators.php' );
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
foreach ( array( 'pps_contract_parse', 'pps_contract_lines_to_prices', 'pps_contract_check' ) as $fn ) {
    if ( ! preg_match( '/^function ' . $fn . '\(.*?^\}/ms', $src, $m ) ) { echo "FAIL: $fn not found\n"; exit( 1 ); }
    eval( $m[0] );
}
$fail = 0; $n = 0;
function ok( $c, $w, $g = null ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $w" . ( $g !== null ? ' — ' . json_encode( $g ) : '' ) . "\n"; } }

// ── the admin box ──
$typed = "100 = 150.43\n250: \$198.48\n 500 , 315.23 \n1,000 = 580.44\r\n2000=1,125.18\nnotes here\n\n750 = abc";
$p = pps_contract_lines_to_prices( $typed );
ok( $p === array( 100 => 150.43, 250 => 198.48, 500 => 315.23, 1000 => 580.44, 2000 => 1125.18 ), 'the box reads "qty = price", "qty: $price", "qty, price", thousands commas, and skips anything else', $p );

// ── a stored value is cleaned ──
$c = pps_contract_parse( array( 'name' => ' <b>Armadillo</b>  Home Warranty ', 'prices' => array( '500' => '315.234', '100' => 150.43, '0' => 9, '-5' => 3, '250' => 0, '750' => 'x' ) ) );
ok( $c['prices'] === array( 100 => 150.43, 500 => 315.23 ) && $c['name'] === 'Armadillo Home Warranty' && $c['lock'] === true, 'only positive quantities and prices survive, sorted, rounded; the name is plain text; lock defaults on', $c );
ok( pps_contract_parse( json_encode( array( 'name' => 'A', 'lock' => false, 'prices' => array( 100 => 1 ) ) ) )['lock'] === false, 'stored as JSON it reads back, lock off when saved off' );
ok( pps_contract_parse( '' ) === null && pps_contract_parse( array( 'prices' => array() ) ) === null && pps_contract_parse( array( 'prices' => array( 5 => 0 ) ) ) === null, 'no usable price means no contract (normal pricing)' );
ok( pps_contract_parse( array( 'prices' => array( 10 => 1 ) ) )['name'] === 'Contract', 'a list with no name is still a contract' );
$sh = pps_contract_parse( array( 'prices' => array( 10 => 1 ), 'show' => array( 'inside' => ' House-provided <i>text</i> weight paper ', 'cover' => '', 'evil' => 'x' ) ) );
ok( json_encode( $sh['show'] ) === '{"inside":"House-provided text weight paper"}', 'the customer-facing paper words are kept as plain text, inside and cover only', $sh['show'] );
ok( json_encode( pps_contract_parse( array( 'prices' => array( 10 => 1 ) ) )['show'] ) === '{}', 'with none set it is an empty object, not a list' );
ok( json_decode( json_encode( pps_contract_parse( json_encode( array( 'prices' => array( 10 => 1 ), 'show' => array( 'cover' => 'House-provided cardstock' ) ) ) ) ), true )['show'] === array( 'cover' => 'House-provided cardstock' ), 'and it survives being stored and read back' );
$big = array(); for ( $i = 1; $i <= 80; $i++ ) $big[ $i * 10 ] = $i;
ok( count( pps_contract_parse( array( 'prices' => $big ) )['prices'] ) === 50, 'at most 50 quantities are kept' );
ok( json_decode( json_encode( $c ), true )['prices'] == array( '100' => 150.43, '500' => 315.23 ) && strpos( json_encode( $c ), '"prices":{' ) !== false, 'it is stored and injected as a quantity → price object, not a list' );

// ── add to cart ──
$A = pps_contract_parse( array( 'name' => 'Armadillo', 'prices' => array( 100 => 150.43, 500 => 315.23 ) ) );
$meta = function ( $qty, $cqty = null ) { return json_encode( array( 'sets' => array( array( 'qty' => $qty, 'pages' => 8 ) ), 'contract' => array( 'name' => 'Armadillo', 'qty' => $cqty ?? $qty, 'price' => 315.23 ) ) ); };
ok( pps_contract_check( $A, 315.23, $meta( 500 ) ) === '', 'a listed quantity at its listed price passes' );
ok( pps_contract_check( $A, 345.22, $meta( 500 ) ) === '', 'and with a proof or rush on top' );
ok( pps_contract_check( $A, 315.226, $meta( 500 ) ) === '', 'a fraction of a cent of rounding is not a refusal' );
ok( strpos( pps_contract_check( $A, 300, $meta( 500 ) ), 'below the agreed price' ) !== false, 'under the listed price is refused' );
ok( pps_contract_check( $A, 999, $meta( 600 ) ) !== '', 'a quantity that is not on the list is refused' );
ok( pps_contract_check( $A, 999, $meta( 600, 500 ) ) !== '', 'the job\'s own quantity decides, not the contract field' );
ok( pps_contract_check( $A, 999, json_encode( array( 'sets' => array( array( 'qty' => 500 ) ) ) ) ) !== '', 'a line with no contract field (an old page) is refused' );
ok( pps_contract_check( $A, 999, 'not json' ) !== '', 'and so is garbage' );

// ── where the plugin uses them ──
ok( strpos( $src, "\$contract = pps_contract_for_product( \$product_id );\n        if ( \$contract ) \$config['contract'] = \$contract;" ) !== false, 'the product page injects the contract into PPS_CONFIG' );
$at = strpos( $src, "\$refuse = pps_contract_check( \$contract, \$price, \$metadata );" ); $floor = strpos( $src, "\$min_pct       = floatval( \$pcf['pps_min_price_pct']" );
ok( $at !== false && $floor !== false && $at < $floor, 'add to cart checks the contract before the price floors' );
ok( strpos( $src, "\$mat_floor = \$contract ? null : pps_materials_price_floor( \$product_id, \$metadata );" ) !== false, 'and a contract product skips the materials floor (its price is agreed, not computed)' );
ok( strpos( $src, "if ( ! empty( \$_POST['pps_contract_form'] ) ) {" ) !== false && strpos( $src, "delete_post_meta( \$post_id, '_pps_contract' );" ) !== false, 'the admin box saves, and an empty list removes the contract' );

ok( strpos( $src, "if ( is_string( \$defaults ) ) { \$defaults = json_decode( \$defaults, true );" ) !== false, 'the PPS Defaults tab reads defaults stored as JSON text, so a save cannot wipe them' );

// WCPA's cached product list is read through a filter that drops registry products
if ( preg_match( '/^function pps_wcpa_drop_registry_products\(.*?^\}/ms', $src, $m ) ) {
    function pps_get_calculator_for_product( $id ) { return in_array( $id, array( 35765, 22754 ), true ) ? 'calc-preview-test.html' : false; }
    eval( $m[0] );
    $l = pps_wcpa_drop_registry_products( array( 'full' => array( 100, 35765, '22754', 200 ), 'direct_purchasable' => array( 35765 ) ) );
    ok( $l === array( 'full' => array( 100, 200 ), 'direct_purchasable' => array() ), 'a registry product is never on WCPA\'s product list, whatever WCPA cached', $l );
    ok( pps_wcpa_drop_registry_products( false ) === false, 'an empty cache stays empty (WCPA rebuilds it)' );
} else { ok( false, 'pps_wcpa_drop_registry_products is in the plugin' ); }
ok( strpos( $src, "add_filter( 'transient_wcpa_products_transient_ver_3', 'pps_wcpa_drop_registry_products' );" ) !== false, 'and it is hooked on the key WCPA reads' );
ok( strpos( $src, "add_filter( 'transient_wcpa_fields_' . \$id, '__return_false' );" ) !== false, 'and WCPA\'s cached fields for a registry product are never used' );

echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> CONTRACT PRICE (server) OK ($n checks)\n";
exit( $fail ? 1 : 0 );

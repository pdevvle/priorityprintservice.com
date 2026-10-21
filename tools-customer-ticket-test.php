<?php
/**
 * The customer's copy of the Job Ticket (owner 2026-10-21: "That shouldn't be shown to the
 * customer. Only the eta; the others are just on our copy of the receipt"). Lifts
 * pps_customer_ticket() out of pps-calculators.php and checks that "Production start" and
 * "Must ship by" come out of the plain value and of WooCommerce's rendered one (wpautop:
 * "<br />\n" between lines, wrapped in <p>), Delivery stays, nothing else moves; and that the
 * meta filter applies it only where internal keys are already stripped (customer emails and
 * the customer's order pages), so the stored ticket and the staff copy keep both dates.
 *
 * Run: php tools-customer-ticket-test.php
 */
$src = file_get_contents( getenv( 'PPS_CALC_PHP' ) ?: __DIR__ . '/pps-calculators.php' );
function lift( $src, $fn ) {
    $at = strpos( $src, "function $fn(" ); if ( $at === false ) { echo "FAIL: $fn not found\n"; exit( 1 ); }
    $i = strpos( $src, '{', $at ); $d = 0;
    for ( $l = strlen( $src ); $i < $l; $i++ ) { if ( $src[ $i ] === '{' ) $d++; elseif ( $src[ $i ] === '}' && --$d === 0 ) break; }
    return substr( $src, $at, $i - $at + 1 );
}
eval( lift( $src, 'pps_customer_ticket_labels' ) ); eval( lift( $src, 'pps_customer_ticket' ) );
$fail = 0; $n = 0;
function ok( $c, $w, $g = null ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $w" . ( $g !== null ? ' — ' . var_export( $g, true ) : '' ) . "\n"; } }

$t = "Product: Brochure\nQuantity: 500\nArt status: Self-approved online\nProduction start: Tue, Oct 20, 2026\nMust ship by: Thu, Oct 22, 2026\nDelivery: Monday, Oct 26, 2026 — standard (5 business days)\nShip to: Ann Lee, 1 Main St, Mesa AZ 85201";
$want = "Product: Brochure\nQuantity: 500\nArt status: Self-approved online\nDelivery: Monday, Oct 26, 2026 — standard (5 business days)\nShip to: Ann Lee, 1 Main St, Mesa AZ 85201";
ok( pps_customer_ticket( $t ) === $want, 'plain ticket: production start and must-ship come out, delivery stays', pps_customer_ticket( $t ) );
$html = '<p>' . str_replace( "\n", "<br />\n", $t ) . '</p>';
ok( pps_customer_ticket( $html ) === '<p>' . str_replace( "\n", "<br />\n", $want ) . '</p>', 'rendered ticket (wpautop): same, with no empty line left behind', pps_customer_ticket( $html ) );
ok( pps_customer_ticket( "A: 1<br />\nMust ship by: Thu</p>" ) === 'A: 1</p>', 'a removed last line keeps the closing </p>', pps_customer_ticket( "A: 1<br />\nMust ship by: Thu</p>" ) );
ok( pps_customer_ticket( "<p>Production start: Tue<br />\nDelivery: Mon</p>" ) === "<p>\nDelivery: Mon</p>", 'a removed first line keeps the opening <p>', pps_customer_ticket( "<p>Production start: Tue<br />\nDelivery: Mon</p>" ) );
ok( pps_customer_ticket( "Notes: we must ship by Friday\nDelivery: Mon" ) === "Notes: we must ship by Friday\nDelivery: Mon", 'only lines LABELLED with those words go — text that mentions them stays' );
ok( pps_customer_ticket( '' ) === '' && pps_customer_ticket( "Delivery: Mon" ) === "Delivery: Mon", 'nothing to remove, nothing changes' );
ok( strpos( $src, "} elseif ( isset( \$meta->key ) && \$meta->key === 'Job Ticket' ) {\n            \$meta->value         = pps_customer_ticket( (string) \$meta->value );\n            \$meta->display_value = pps_customer_ticket( (string) \$meta->display_value );" ) !== false, 'the customer-facing meta filter applies it to the Job Ticket' );
$f = substr( $src, strpos( $src, "add_filter( 'woocommerce_order_item_get_formatted_meta_data'" ), 3000 );
ok( strpos( $f, "if ( ! \$customer_email && ! \$customer_page ) return \$formatted;" ) < strpos( $f, 'pps_customer_ticket(' ), 'and only after it has decided this is a customer email or the customer\'s order page — staff keep both dates' );
ok( strpos( $src, "\$put( 'Must ship by', \$fmt( \$full['mustShipByDate'] ?? '' ) );" ) !== false, 'the stored ticket still carries Must ship by (the order screen, admin email and Missive read it)' );
echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> CUSTOMER TICKET OK ($n checks)\n";
exit( $fail ? 1 : 0 );

<?php
/**
 * A self-cover book whose cover prints in another colour than its inside (owner 2026-11-10,
 * order 87921: "As-is it looks like 'bw self cover'"). Orders placed before the fix carry a
 * stored Job Ticket whose Cover line hides it; the display filter must add the note wherever the
 * ticket is shown, once, and touch nothing else. The ticket and PPS-Spec halves are in
 * tools-server-junctures-test.php; the Missive pill in tools-missive-panel-test.php.
 *   php tools-mixed-cover-test.php [path/to/pps-calculators.php]
 */
$src = file_get_contents( $argv[1] ?? __DIR__ . '/pps-calculators.php' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "ok   $m\n"; } else { $fail++; echo "FAIL $m\n"; } }
$GLOBALS['filters'] = array();
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['filters'][] = array( $h, $cb ); }

preg_match( '/\nfunction pps_mixed_self_cover\s*\(.*?\n}\n/s', $src, $m ); ok( ! empty( $m ), 'pps_mixed_self_cover() exists' ); eval( $m[0] );
$a = strpos( $src, 'Orders placed before 2026-11-10' );
$b = $a !== false ? strpos( $src, '}, 9, 2 );', $a ) : false;
ok( $a !== false && $b !== false, 'the display filter for older orders exists' );
eval( substr( $src, strpos( $src, 'add_filter', $a ), $b + 10 - strpos( $src, 'add_filter', $a ) ) );
$cb = end( $GLOBALS['filters'] )[1];

class Item { public $m; function __construct( $m ) { $this->m = $m; } function get_meta( $k ) { return $k === '_pps_metadata' ? json_encode( $this->m ) : ''; } }
$ticket = "Product: Square Booklet Printing\nInside: 80lb Gloss Text · B&W\nCover: Self-cover (same stock as inside)\nProof: Self-approved online";
$mk = function () use ( $ticket ) { return array( 1 => (object) array( 'key' => 'Job Ticket', 'value' => $ticket, 'display_value' => '<p>' . str_replace( "\n", "<br />\n", $ticket ) . '</p>' ),
                                                  2 => (object) array( 'key' => 'Add-ons', 'value' => 'None', 'display_value' => 'None' ) ); };
$mixed = array( 'sets' => array( array( 'qty' => 530, 'pages' => 8 ) ), 'coverMode' => 'same', 'insideColor' => 'bw', 'coverColor' => 'color' );

$out = $cb( $mk(), new Item( $mixed ) );
ok( strpos( $out[1]->value, 'Cover: Self-cover (same stock as inside) · MIXED: Full Color cover, B&W inside' ) !== false, 'an older mixed order\'s ticket now says MIXED (plain value)' );
ok( strpos( $out[1]->display_value, 'Cover: Self-cover (same stock as inside) · MIXED: Full Color cover, B&W inside<br />' ) !== false, '… and in the rendered value, before the line break' );
ok( substr_count( $out[1]->value, 'MIXED' ) === 1, 'the note is added once' );
$again = $cb( $out, new Item( $mixed ) );
ok( substr_count( $again[1]->value, 'MIXED' ) === 1, 'a ticket that already says MIXED (a new order) is left alone' );
ok( $out[2]->value === 'None', 'other lines are untouched' );
$same = $cb( $mk(), new Item( array( 'coverMode' => 'same', 'insideColor' => 'bw', 'coverColor' => 'bw' ) ) );
ok( $same[1]->value === $ticket, 'an all-B&W self-cover book is untouched' );
$flat = $cb( $mk(), new Item( array( 'sides' => 2, 'frontColor' => 'color', 'backColor' => 'bw' ) ) );
ok( $flat[1]->value === $ticket, 'a flat is untouched' );
echo "\n$pass passed, $fail failed\n"; exit( $fail ? 1 : 0 );

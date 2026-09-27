<?php
/**
 * The server's half of the 2026-09-27 juncture audit: the places between the cart and
 * the order where a job could be refused, or written wrong, by a setting or a stale cart.
 *
 *   - A closures list saved as text instead of a list made in_array() throw on every
 *     checkout line; the fallback list missed Thanksgiving in most years.
 *   - A blank or mistyped shop timezone ("Arizona") threw in new DateTimeZone() on every
 *     checkout line.
 *   - A cart left open over a weekend could be paid for after the shop could no longer
 *     meet its quoted delivery date — but a free-delivery quote starts production the
 *     day it is made, so the check must be "too soon", never "quoted on an earlier day",
 *     or it turns away every cart added on Friday and paid for on Saturday.
 *   - PPS-Spec read only booklet keys: every flat order reached Missive as
 *     "0qty | 0pg | 0sets | INSIDE: /Color". A '|' in a job name split the spec.
 *   - Every proof-free order's ticket said "Self-approved online" — including "email
 *     art after order", where nothing had been received.
 *
 * Lifts the functions out of pps-calculators.php. PPS_CALC_PHP points at another copy —
 * how the pre-fix file was run to prove it fails.
 *
 * Run: php tools-server-junctures-test.php
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
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_file_name( $s ) { return preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $s ); }

$want = array( 'pps_get_closures', 'pps_shop_timezone', 'pps_is_business_day', 'pps_add_business_days', 'pps_quote_is_stale',
               'pps_clean_text', 'pps_order_addons', 'pps_spec_size_label', 'pps_build_spec', 'pps_job_ticket' );
$have = array();
foreach ( $want as $n ) { $f = lift( $src, $n ); if ( $f !== '' ) { eval( $f ); $have[ $n ] = true; } }
$has = function( $n ) use ( $have ) { return ! empty( $have[ $n ] ); };

// ── 1. Closures, with no config at all: the fallback list ──
echo "── closures and timezone ──\n";
$y  = (int) gmdate( 'Y' );
$tg = gmdate( 'Y-m-d', strtotime( "fourth thursday of november $y" ) );
$list = pps_get_closures();
ok( "the fallback closures hold this year's Thanksgiving ($tg), not a fixed 11-28", in_array( $tg, $list, true ), implode( ',', $list ) );

// From here on there is a config, and the tests drive it.
$GLOBALS['cfg'] = array();
// eval, not a plain declaration: PHP hoists top-level functions, which would put a
// config in place before the fallback check above had run.
eval( 'function pps_get_config() { return $GLOBALS["cfg"]; }' );

$GLOBALS['cfg'] = array( 'closures' => "01-01, 12-25\n07-04" );
$list = null; try { $list = pps_get_closures(); } catch ( \Throwable $e ) {}
ok( 'closures saved as text still read as a list', $list === array( '01-01', '12-25', '07-04' ), json_encode( $list ) );
$GLOBALS['cfg'] = array( 'closures' => 5 );
$d = null; try { $d = pps_is_business_day( new DateTime( '2026-10-05' ) ); } catch ( \Throwable $e ) { $d = 'threw: ' . $e->getMessage(); }
ok( 'a closures value that is not a list cannot stop checkout', $d === true, var_export( $d, true ) );
$GLOBALS['cfg'] = array( 'closures' => array( '12-25', array( 'x' ), ' 07-04 ' ) );
ok( 'junk entries are dropped and the rest trimmed', pps_get_closures() === array( '12-25', '07-04' ), json_encode( pps_get_closures() ) );

$tz = function( $v ) { $GLOBALS['cfg'] = array( 'pcf' => array( 'shop_timezone' => $v ) ); try { return pps_shop_timezone(); } catch ( \Throwable $e ) { return 'threw'; } };
ok( 'a mistyped shop timezone falls back to Phoenix instead of throwing on every checkout line', $has( 'pps_shop_timezone' ) && $tz( 'Arizona' ) === 'America/Phoenix' && $tz( '' ) === 'America/Phoenix' );
ok( 'a valid one is kept', $has( 'pps_shop_timezone' ) && $tz( 'America/New_York' ) === 'America/New_York' );

// ── 2. A stale cart ──
echo "\n── a quote the shop can no longer keep ──\n";
if ( ! $has( 'pps_quote_is_stale' ) ) {
    ok( 'the plugin can tell a stale quote from an old one', false, 'pps_quote_is_stale() missing' );
} else {
    $GLOBALS['cfg'] = array( 'closures' => array( '11-26', '11-27', '12-25' ) );
    $phx = new DateTimeZone( 'America/Phoenix' );
    $sat = new DateTime( '2026-10-03 10:00', $phx );
    $q = function( $est, $prod ) { return array( 'estimatedDeliveryDate' => $est, 'productionBizDays' => $prod ); };
    ok( 'a free-delivery cart added Friday is still good on Saturday', pps_quote_is_stale( $q( '2026-10-13', 4 ), $sat ) === false );
    ok( 'the earliest date the shop can still make is good (boundary)', pps_quote_is_stale( $q( '2026-10-12', 4 ), $sat ) === false );
    ok( 'a date inside production from today is refused', pps_quote_is_stale( $q( '2026-10-09', 4 ), $sat ) === true );
    ok( 'metadata without the production days, or not JSON, is never refused', pps_quote_is_stale( array( 'estimatedDeliveryDate' => '2020-01-01' ), $sat ) === false && pps_quote_is_stale( 'nonsense', $sat ) === false );
    $wed = new DateTime( '2026-11-25 09:00', $phx );
    ok( 'Thanksgiving counts: from Wed 25 Nov, one production day cannot deliver Mon 30', pps_quote_is_stale( $q( '2026-11-30', 1 ), $wed ) === true && pps_quote_is_stale( $q( '2026-12-01', 1 ), $wed ) === false );
    ok( 'the refusal is hooked on the cart check (classic, checkout and block checkout)', strpos( $src, "add_action( 'woocommerce_check_cart_items', 'pps_refuse_stale_quotes' );" ) !== false );
    ok( 'and it can never break the cart', (bool) preg_match( '/function pps_refuse_stale_quotes\(\)\s*\{\s*try \{/', $src ) );
}

// ── 3. PPS-Spec ──
echo "\n── PPS-Spec ──\n";
if ( ! $has( 'pps_build_spec' ) ) {
    ok( 'PPS-Spec is built by a function a test can run', false, 'pps_build_spec() missing' );
} else {
    $flat = array( 'qty' => 500, 'sides' => 2, 'sizeLabel' => '8.5×11', 'paper' => array( 'label' => '100lb Gloss Text' ), 'frontColor' => 'color', 'backColor' => 'bw',
        'foldType' => 'trifold', 'jobName' => 'Spring | Gala', 'proof' => 0, 'rushCost' => 0, 'days' => 5, 'shipState' => 'AZ', 'shipZip' => '85087', 'addons' => array( 'Coating: UV Gloss' ) );
    list( $spec ) = pps_build_spec( $flat, '', '', 5 );
    ok( 'a flat order names its quantity, sides, paper and colour per side', strpos( $spec, '8.5×11 | 500qty | 2pg | 1set | INSIDE: 100lb Gloss Text/Color+BW | FLAT: trifold | Coating: UV Gloss | SelfApproved' ) === 0, $spec );
    ok( "a '|' in the job name cannot split the spec", substr( $spec, -18 ) === 'JOB: Spring / Gala' && substr_count( $spec, '|' ) === 11, $spec );
    list( $spec ) = pps_build_spec( array( 'sets' => array( array( 'qty' => 100, 'pages' => 12 ) ), 'insidePaper' => array( 'label' => 'X' ), 'coverMode' => 'same', 'proof' => 0 ), '', 'yes', 5 );
    ok( 'a booklet still reads as before, and prepress review still overrides SelfApproved', strpos( $spec, '100qty | 12pg | 1set | INSIDE: X/Color | SELF-COVER | PREPRESS-REVIEW' ) !== false, $spec );
}

// ── 4. Art status on the Job Ticket ──
echo "\n── art status ──\n";
$d = new DateTime( '2026-10-12', new DateTimeZone( 'America/Phoenix' ) );
$art = function( $a ) use ( $d ) { $t = pps_job_ticket( array( 'proof' => 0, 'artwork' => $a ), array(), $d, '' ); return preg_match( '/Art status: ([^\n]*)/', $t, $m ) ? $m[1] : ''; };
ok( '"email art after order" is NOT RECEIVED, not self-approved', stripos( $art( 0.02 ), 'NOT RECEIVED' ) === 0, $art( 0.02 ) );
ok( 'a Canva order says where the art is', stripos( $art( 0.04 ), 'Canva' ) === 0, $art( 0.04 ) );
ok( 'a design-service order says the designer has it', stripos( $art( 2.01 ), 'Artwork needs edits' ) === 0 && stripos( $art( 4.01 ), 'Design from scratch' ) === 0, $art( 2.01 ) . ' / ' . $art( 4.01 ) );
ok( 'an uploaded, self-approved order still says so', $art( 0.01 ) === 'Self-approved online', $art( 0.01 ) );

// ── 5. Add to cart ──
echo "\n── add to cart ──\n";
$h = lift( $src, 'pps_ajax_add_to_cart' );
ok( 'metadata that is not a JSON object is refused at add to cart', (bool) preg_match( '/json_last_error\(\) !== JSON_ERROR_NONE \|\| ! is_array\( \$meta_obj \)/', $h ) );
ok( 'a ZIP the carrier cannot use is refused, with the fix named', strpos( $h, '\d{5}(-\d{4})?' ) !== false && strpos( $h, 'for example 02134, not 2134' ) !== false );
ok( 'every add is its own cart line (two identical jobs are two jobs)', strpos( $h, "'pps_uid'" ) !== false );
ok( 'an edit whose new line lands on the old key does not delete itself', strpos( $h, '$edit_key && $edit_key !== $cart_item_key' ) !== false );
ok( 'calculator products are sold individually, so the cart cannot multiply a quoted job', (bool) preg_match( "/add_filter\( 'woocommerce_is_sold_individually'[\s\S]{0,200}pps_get_calculator_for_product/", $src ) );
ok( 'the calculator address is applied to an order once, not on every status change', strpos( $src, "if ( \$order->get_meta( '_pps_calc_address_applied' ) ) return false;" ) !== false );

// ── 6. The paper report: sticker stock ──
echo "\n── paper report ──\n";
$paper_src = file_get_contents( __DIR__ . '/' . ( getenv( 'PPS_PAPER_PHP' ) ?: 'pps-paper-report.php' ) );
foreach ( array( 'pps_paper_report_catalog', 'pps_paper_report_key', 'pps_paper_report_classify', 'pps_paper_report_item_papers' ) as $n ) eval( lift( $paper_src, $n ) );
// The cardstock list holds an inventoried card at val 0.02 — the same number the
// sticker list gives its factory-ordered Matte label.
$GLOBALS['cfg'] = array( 'papers_cs' => array( array( 'label' => '100lb Gloss Cover', 'val' => 0.02, 'days' => 0, 'factory' => false ) ) );
$matte = array( 'label' => 'Crack n Peel Matte Adhesive Label', 'val' => 0.02, 'days' => 5, 'factory' => true );
$p = pps_paper_report_item_papers( array( 'calcType' => 'sticker', 'paper' => $matte ) );
ok( 'a factory-ordered sticker label is not reported as the in-stock cardstock that shares its number',
    count( $p ) === 1 && $p[0]['label'] === 'Crack n Peel Matte Adhesive Label' && $p[0]['in_stock'] === false && $p[0]['days'] === 5, json_encode( $p ) );
$p = pps_paper_report_item_papers( array( 'paper' => array( 'label' => '100lb Gloss Cover', 'val' => 0.02 ) ) );
ok( 'a flat on that cardstock is still looked up in the catalog', count( $p ) === 1 && $p[0]['in_stock'] === true, json_encode( $p ) );

echo "\n{$GLOBALS['t_checks']} checks, {$GLOBALS['t_failed']} failed\n";
echo $GLOBALS['t_failed'] ? ">>> SERVER JUNCTURES FAILED\n" : ">>> SERVER JUNCTURES OK\n";
exit( $GLOBALS['t_failed'] ? 1 : 0 );

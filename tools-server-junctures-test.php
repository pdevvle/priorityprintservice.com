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

$want = array( 'pps_get_closures', 'pps_shop_timezone', 'pps_is_business_day', 'pps_add_business_days', 'pps_shop_start_day', 'pps_business_days_between', 'pps_quote_is_stale',
               'pps_requote_line', 'pps_requote_summary', 'pps_requote_describe',
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

$GLOBALS['cfg'] = array( 'closures' => array( 1, 7, 12, 12, 11, 11 ) );
$list = pps_get_closures();
ok( 'a list the old admin editor saved as bare numbers (12-25 became 12) means "the usual holidays", not "never closed"',
    in_array( '12-25', $list, true ) && in_array( $tg, $list, true ), json_encode( $list ) );
$GLOBALS['cfg'] = array( 'closures' => array( '12-25', '2026-11-26' ) );
ok( 'a good list is kept as it is', pps_get_closures() === array( '12-25', '2026-11-26' ), json_encode( pps_get_closures() ) );
ok( 'the calculators are given the cleaned list, not the raw setting', strpos( $src, "\$cfg['closures'] = pps_get_closures();" ) !== false );
$admin = file_get_contents( __DIR__ . '/pps-config-admin.php' );
ok( 'the admin editor no longer turns "12-25" into 12 on save', strpos( $admin, 'vals.push(isNaN(n) ? v : n);' ) === false && strpos( $admin, "/^-?\\d+(\\.\\d+)?$/.test(v)" ) !== false );
ok( "the admin default has this year's Thanksgiving, not a fixed 11-28", strpos( $admin, "'11-28', '11-29'" ) === false && strpos( $admin, 'fourth thursday of november' ) !== false );
$tz = function( $v ) { $GLOBALS['cfg'] = array( 'pcf' => array( 'shop_timezone' => $v ) ); try { return pps_shop_timezone(); } catch ( \Throwable $e ) { return 'threw'; } };
ok( 'a mistyped shop timezone falls back to Phoenix instead of throwing on every checkout line', $has( 'pps_shop_timezone' ) && $tz( 'Arizona' ) === 'America/Phoenix' && $tz( '' ) === 'America/Phoenix' );
ok( 'a valid one is kept', $has( 'pps_shop_timezone' ) && $tz( 'America/New_York' ) === 'America/New_York' );
ok( 'a timezone saved as a list or an object cannot throw either', $has( 'pps_shop_timezone' ) && $tz( array( 'x' ) ) === 'America/Phoenix' && $tz( new stdClass ) === 'America/Phoenix' );

// ── 2. A cart that sat before it was paid ──
echo "\n── a cart re-quoted against today ──\n";
if ( ! $has( 'pps_quote_is_stale' ) ) {
    ok( 'the plugin can tell a stale quote from an old one', false, 'pps_quote_is_stale() missing' );
} else {
    $GLOBALS['cfg'] = array( 'closures' => array( '11-26', '11-27', '12-25' ) );
    $phx = new DateTimeZone( 'America/Phoenix' );
    $sat = new DateTime( '2026-10-03 10:00', $phx );
    $q = function( $est, $prod ) { return array( 'estimatedDeliveryDate' => $est, 'productionBizDays' => $prod ); };
    ok( 'a line from an older build: the earliest date the shop can still make is good (boundary)', pps_quote_is_stale( $q( '2026-10-12', 4 ), $sat ) === false );
    ok( 'a date inside production from today is refused', pps_quote_is_stale( $q( '2026-10-09', 4 ), $sat ) === true );
    ok( 'metadata without the production days, or not JSON, is never refused', pps_quote_is_stale( array( 'estimatedDeliveryDate' => '2020-01-01' ), $sat ) === false && pps_quote_is_stale( 'nonsense', $sat ) === false );
    $wed = new DateTime( '2026-11-25 09:00', $phx );
    ok( 'Thanksgiving counts: from Wed 25 Nov, one production day cannot deliver Mon 30', pps_quote_is_stale( $q( '2026-11-30', 1 ), $wed ) === true && pps_quote_is_stale( $q( '2026-12-01', 1 ), $wed ) === false );
}
if ( ! $has( 'pps_requote_line' ) ) {
    ok( 'a cart line is re-quoted against today instead of trusted or refused', false, 'pps_requote_line() missing' );
} else {
    $phx = new DateTimeZone( 'America/Phoenix' );
    $GLOBALS['cfg'] = array( 'closures' => array( '11-26', '11-27', '12-25' ) );
    // Quoted Friday 2 Oct: 4 production + 3 transit = free delivery Tue 13 Oct, $200.
    $free_line = array( 'quotedOn' => '2026-10-02', 'estimatedDeliveryDate' => '2026-10-13', 'productionBizDays' => 4, 'freeDeliveryBizDays' => 7,
        'transitDays' => 3, 'requestedBizDays' => 7, 'rushCost' => 0, 'baseTotal' => 200, 'total' => 200, 'productionStartDate' => '2026-10-02', 'mustShipByDate' => '2026-10-08' );
    $r = pps_requote_line( $free_line, new DateTime( '2026-10-02 15:00', $phx ), 200, 0 );
    ok( 'a line paid the day it was quoted is left alone', $r['action'] === 'none', $r['action'] );
    $r = pps_requote_line( $free_line, new DateTime( '2026-10-03 10:00', $phx ), 200, 0 );
    ok( 'a free-delivery cart added Friday and paid Saturday keeps its price and moves a day (production now starts Monday)',
        $r['action'] === 'update' && $r['price'] == 200 && $r['meta']['estimatedDeliveryDate'] === '2026-10-14' && $r['meta']['productionStartDate'] === '2026-10-05'
        && $r['meta']['quotedOn'] === '2026-10-05' && $r['meta']['requoted']['fromDate'] === '2026-10-13' && $r['date_changed'] && ! $r['price_changed'], json_encode( $r['meta'] ?? $r ) );
    $again = pps_requote_line( $r['meta'], new DateTime( '2026-10-04 12:00', $phx ), 200, 0 );
    ok( 'once re-quoted, the line is stable (Sunday counts from Monday again)', $again['action'] === 'none', $again['action'] );
    $third = pps_requote_line( $r['meta'], new DateTime( '2026-10-06 09:00', $phx ), 200, 0 );
    ok( 'a second slip keeps the ORIGINAL quote as "was" — what the customer saw in the calculator',
        $third['action'] === 'update' && $third['meta']['requoted']['fromDate'] === '2026-10-13' && $third['meta']['requoted']['quotedOn'] === '2026-10-02', json_encode( $third['meta']['requoted'] ?? $third ) );
    $later = $free_line; $later['estimatedDeliveryDate'] = '2026-10-20';
    $r = pps_requote_line( $later, new DateTime( '2026-10-05 09:00', $phx ), 200, 0 );
    ok( 'a picked date still inside the free window keeps date and price; only the production dates move to today',
        $r['action'] === 'refresh' && $r['meta']['estimatedDeliveryDate'] === '2026-10-20' && $r['price'] == 200 && $r['meta']['productionStartDate'] >= '2026-10-05' && ! isset( $r['meta']['requoted'] ), json_encode( $r['meta'] ?? $r ) );

    // Quoted Monday 5 Oct for Thursday 8 Oct: 1 production day, free window 4, 3 days left
    // → rush = 300 × 4/3 − 300 = $100, total $400.
    $rush_line = array( 'quotedOn' => '2026-10-05', 'estimatedDeliveryDate' => '2026-10-08', 'productionBizDays' => 1, 'freeDeliveryBizDays' => 4,
        'transitDays' => 3, 'requestedBizDays' => 3, 'rushCost' => 100, 'baseTotal' => 300, 'total' => 400 );
    $r = pps_requote_line( $rush_line, new DateTime( '2026-10-06 08:00', $phx ), 400, 100 );
    ok( 'a rush line paid a day late keeps its date and is re-priced by the calculator rule (300 × 4/2 − 300 = $300 rush)',
        $r['action'] === 'update' && $r['rush'] == 300 && $r['price'] == 600 && $r['meta']['estimatedDeliveryDate'] === '2026-10-08' && $r['biz_days'] === 2 && $r['price_changed'] && ! $r['date_changed'], json_encode( $r ) );
    $r = pps_requote_line( $rush_line, new DateTime( '2026-10-07 08:00', $phx ), 400, 100 );
    ok( 'a rush date inside production is NOT refused: it moves to the earliest date that can be made, priced by the same rule',
        $r['action'] === 'update' && $r['meta']['estimatedDeliveryDate'] === '2026-10-09' && $r['biz_days'] === 2 && $r['price'] == 600, json_encode( $r ) );
    $r = pps_requote_line( $rush_line, new DateTime( '2026-10-06 08:00', $phx ), 450, 100 );
    ok( 'a rush line whose numbers disagree is not guessed at and not refused: it keeps its price', $r['action'] === 'refresh' && $r['price'] == 450 && $r['rush'] == 100, json_encode( $r ) );
    $r = pps_requote_line( $rush_line, new DateTime( '2026-10-07 08:00', $phx ), 450, 100 );
    ok( '…and if its date can no longer be made, moves the date and still keeps the price', $r['action'] === 'update' && $r['price'] == 450 && $r['meta']['estimatedDeliveryDate'] === '2026-10-09', json_encode( $r ) );
    $old = $rush_line; unset( $old['quotedOn'] );
    $r = pps_requote_line( $old, new DateTime( '2026-10-06 08:00', $phx ), 400, 100 );
    ok( 'a line from a build without quotedOn whose date can still be made is left alone', $r['action'] === 'none', $r['action'] );
    $r = pps_requote_line( $old, new DateTime( '2026-10-07 08:00', $phx ), 400, 100 );
    ok( 'one whose date can no longer be made is moved to the earliest date, price untouched (it used to be refused)',
        $r['action'] === 'update' && $r['price'] == 400 && $r['rush'] == 100 && $r['meta']['estimatedDeliveryDate'] === '2026-10-09', json_encode( $r ) );
    $fut = $rush_line; $fut['quotedOn'] = '2031-01-01';
    $r = pps_requote_line( $fut, new DateTime( '2026-10-07 08:00', $phx ), 400, 100 );
    ok( 'a quote day years ahead (a wrong browser clock) is not trusted: the date is still checked', $r['action'] === 'update' && $r['meta']['estimatedDeliveryDate'] === '2026-10-09', json_encode( $r ) );
    $big = $rush_line; $big['productionBizDays'] = 99999; $big['freeDeliveryBizDays'] = 1e12; $big['baseTotal'] = 'x';
    $t0 = microtime( true ); $r = pps_requote_line( $big, new DateTime( '2026-10-07 08:00', $phx ), 400, 100 ); $dt = microtime( true ) - $t0;
    ok( 'absurd day counts from a browser cannot hang checkout', $dt < 1.0, round( $dt, 3 ) . 's ' . $r['action'] );
    ok( 'nothing in the re-quote can refuse a line any more', strpos( lift( $src, 'pps_requote_line' ), "'refuse'" ) === false );
    ok( 'the summary\'s delivery line follows the re-quote', pps_requote_summary( "500 × 8.5×11\nRush: 3 business days\nShip to: AZ", 300, 2 ) === "500 × 8.5×11\nRush: 2 business days\nShip to: AZ"
        && pps_requote_summary( "x\nStandard delivery: 7 business days", 0, 8 ) === "x\nStandard delivery: 8 business days" );
    if ( $has( 'pps_requote_describe' ) ) {
        $d1 = pps_requote_describe( array( 'quotedOn' => '2026-10-02', 'fromDate' => '2026-10-13', 'toDate' => '2026-10-14', 'fromPrice' => 200, 'toPrice' => 200 ) );
        $d2 = pps_requote_describe( array( 'quotedOn' => '2026-10-05', 'fromDate' => '2026-10-08', 'toDate' => '2026-10-08', 'fromPrice' => 400, 'toPrice' => 600 ) );
        ok( 'the customer is told in words: a moved date', $d1 === 'Priced on Fri, Oct 2. Delivery is now Wed, Oct 14 (was Tue, Oct 13); the price is unchanged.', $d1 );
        ok( 'the customer is told in words: a re-priced rush', $d2 === 'Priced on Mon, Oct 5. Delivering by Thu, Oct 8 now leaves fewer working days, so the job total is $600.00 (was $400.00).', $d2 );
    } else ok( 'the re-quote can describe itself', false, 'pps_requote_describe() missing' );

    ok( 'the re-quote runs where totals are made, before the price hook — what is shown is what is charged',
        strpos( $src, "add_action( 'woocommerce_before_calculate_totals', 'pps_requote_cart', 10, 1 );" ) !== false
        && (bool) preg_match( "/add_action\( 'woocommerce_before_calculate_totals', function\( \\\$cart \) \{[\s\S]{0,300}set_price\( floatval\( \\\$item\['pps_price'\] \) \);[\s\S]{0,30}\}, 20 \);/", $src ) );
    ok( 'and no longer on the cart check, which runs after the totals', strpos( $src, "add_action( 'woocommerce_check_cart_items', 'pps_requote_cart' );" ) === false );
    ok( 'it can never break the cart', (bool) preg_match( '/function pps_requote_cart\( \$cart = null \) \{\s*try \{/', $src ) );
    ok( 'Place Order is recognised from the matched REST route, not the URL', strpos( $src, "add_filter( 'rest_request_before_callbacks'" ) !== false && strpos( $src, 'pps_checkout_is_submitting' ) === false );
    ok( 'a stop goes through the Store API\'s own cart-errors hook, once per customer per day', strpos( $src, "add_action( 'woocommerce_store_api_cart_errors'" ) !== false && strpos( lift( $src, 'pps_requote_stop_message' ), 'set_transient' ) !== false );
    ok( 'block checkout refusals are recorded (the classic hook never fires there)', strpos( $src, "add_filter( 'rest_post_dispatch'" ) !== false && strpos( $src, "'block:'" ) !== false );
    ok( 'an order paid on a later day gets a note, never a stop', strpos( $src, 'function pps_flag_late_paid_order' ) !== false );
    ok( 'writing the job onto the order cannot fail the checkout', (bool) preg_match( "/woocommerce_checkout_create_order_line_item', function\( \\\$item, \\\$cart_item_key, \\\$values, \\\$order \) \{\s*if \( ! isset\( \\\$values\['pps_metadata'\] \) \) return;[\s\S]{0,300}try \{/", $src ) );
}

// ── 2b. Robustness of the day arithmetic ──
echo "\n── day arithmetic that cannot hang ──\n";
// Every day of a leap year, 29 February included (without it the loop escapes once every four years).
$GLOBALS['cfg'] = array( 'closures' => array_map( function( $i ) { return gmdate( 'm-d', strtotime( '2024-01-01 12:00 UTC' ) + 86400 * $i ); }, range( 0, 365 ) ) );
$t0 = microtime( true ); $d = pps_add_business_days( new DateTime( '2026-10-05' ), 5 ); $dt = microtime( true ) - $t0;
ok( 'a closures list that shuts every day cannot spin a checkout request', $dt < 2.0, round( $dt, 3 ) . 's' );
$GLOBALS['cfg'] = array( 'closures' => array() );
$t0 = microtime( true ); pps_add_business_days( new DateTime( '2026-10-05' ), 999999999 ); $dt = microtime( true ) - $t0;
ok( 'nor can a day count from the browser', $dt < 2.0, round( $dt, 3 ) . 's' );

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
ok( 'a ZIP the carrier cannot use is refused, with the fix named — and ZIP+4 with a space or no dash is accepted', strpos( $h, '\d{5}(?:[-\s]?\d{4})?' ) !== false && strpos( $h, 'for example 02134, not 2134' ) !== false );
ok( 'a military (APO/FPO/DPO) address is refused at add to cart, by ZIP, state or city', strpos( $h, "09[0-8]|340|96[2-6]" ) !== false && strpos( $h, "array( 'AA', 'AE', 'AP' )" ) !== false && strpos( $h, "can't ship to APO, FPO or DPO" ) !== false );
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

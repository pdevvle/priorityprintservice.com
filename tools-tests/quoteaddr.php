<?php
// Exercise the v1/v2 address routing exactly as pps_quote_to_order resolves it.
// Getting this backwards ships to the cardholder instead of the recipient --
// the shape of the fault behind order 87032 -- so it is worth pinning.
function resolve(array $p) {
    $v2 = ('2' === (string) ($p['pps_form_v'] ?? ''));
    $primary = function($k) use ($p) { return isset($p[$k]) ? $p[$k] : ''; };
    $alt_prefix = $v2 ? 'b_' : 's_';
    $alt_same   = $v2 ? !empty($p['bill_same']) : !empty($p['ship_same']);
    $alt = function($k) use ($p, $alt_prefix, $alt_same, $primary) {
        if ($alt_same) return $primary($k);
        $src = $alt_prefix . $k;
        return isset($p[$src]) ? $p[$src] : '';
    };
    $bill = $v2 ? $alt : $primary;
    $ship = $v2 ? $primary : $alt;
    return array('bill' => $bill('city'), 'ship' => $ship('city'));
}
$pass=0; $fail=0;
function ok($l,$g,$w){ global $pass,$fail; if($g===$w){$pass++;return;} $fail++; printf("FAIL %s: got %s want %s\n",$l,var_export($g,true),var_export($w,true)); }

// v2: primary is SHIPPING, billing defaults to it.
$r = resolve(array('pps_form_v'=>'2','city'=>'Denver','bill_same'=>'1'));
ok('v2 same: ship', $r['ship'], 'Denver');
ok('v2 same: bill', $r['bill'], 'Denver');

// v2 with a separate billing address.
$r = resolve(array('pps_form_v'=>'2','city'=>'Denver','b_city'=>'Austin'));
ok('v2 split: ship stays destination', $r['ship'], 'Denver');
ok('v2 split: bill is the card address', $r['bill'], 'Austin');

// v1 (a link rendered before the swap): primary is BILLING.
$r = resolve(array('city'=>'Austin','ship_same'=>'1'));
ok('v1 same: bill', $r['bill'], 'Austin');
ok('v1 same: ship', $r['ship'], 'Austin');

$r = resolve(array('city'=>'Austin','s_city'=>'Denver'));
ok('v1 split: bill', $r['bill'], 'Austin');
ok('v1 split: ship', $r['ship'], 'Denver');

// The regression that matters: the SAME post body must not be read the same
// way under both versions, or an old open tab would invert the addresses.
$body = array('city'=>'Austin','b_city'=>'Denver','s_city'=>'Denver');
$a = resolve($body);                              // v1
$b = resolve($body + array('pps_form_v'=>'2'));   // v2
ok('version changes the reading', $a['ship'] === $b['ship'], false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

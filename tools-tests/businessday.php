<?php
/**
 * pps_is_business_day() — against the shipped pps-calculators.php logic.
 *
 * This predicate decides what delivery dates a customer may be promised, so
 * both the weekend rule and the closure-list matching are pinned. It was
 * extracted precisely because a second copy of it (in pps-job-quote.php) knew
 * about weekends and not holidays, and offered delivery on closing days.
 *
 * Run: php tools-tests/businessday.php
 */
$GLOBALS['closures'] = array();
function pps_get_closures() { return $GLOBALS['closures']; }

// The shipped implementation, lifted from the deployed file rather than
// retyped: a retyped copy passes while the real one says something else.
$src = file_get_contents(__DIR__ . '/../pps-calculators.php');
if (!preg_match('/function pps_is_business_day\s*\([^)]*\)\s*:\s*bool\s*\{.*?\n\}/s', $src, $m)) {
    fwrite(STDERR, "could not find pps_is_business_day() in pps-calculators.php\n");
    exit(1);
}
eval($m[0]);

$pass = 0; $fail = 0;
function ok($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; return; }
    $fail++; printf("FAIL %s: got %s want %s\n", $label, var_export($got, true), var_export($want, true));
}
function day($ymd) { return pps_is_business_day(new DateTime($ymd)); }

// 2026-08-27 is a Thursday; 29th Sat, 30th Sun, 31st Mon.
ok('thursday open',  day('2026-08-27'), true);
ok('friday open',    day('2026-08-28'), true);
ok('saturday shut',  day('2026-08-29'), false);
ok('sunday shut',    day('2026-08-30'), false);
ok('monday open',    day('2026-08-31'), true);

// A specific one-off closure, matched as Y-m-d.
$GLOBALS['closures'] = array('2026-09-03');
ok('one-off closure shut',        day('2026-09-03'), false);
ok('day before still open',       day('2026-09-02'), true);
// ...and must NOT recur in other years, being a dated entry.
ok('dated closure does not recur', day('2027-09-03'), true);

// An annually recurring closure, matched as m-d.
$GLOBALS['closures'] = array('12-25');
ok('recurring closure 2026', day('2026-12-25'), false);
ok('recurring closure 2027', day('2027-12-25'), false);
ok('boxing day open',        day('2026-12-24'), true);

// A closure landing on a weekend is still shut, not doubly open.
$GLOBALS['closures'] = array('2026-08-29');
ok('closure on a saturday', day('2026-08-29'), false);

// No closures configured must not make every day shut.
$GLOBALS['closures'] = array();
ok('empty closure list', day('2026-12-25'), true);

// ── The fallback in pps-pay-link.php must agree, byte for byte in behaviour ──
// It exists because pps-job-quote.php calls this behind a function_exists()
// guard: if pps-calculators.php is reverted the function vanishes and the
// closed-day check stops enforcing silently. A fallback that disagreed with
// the real one would be a different kind of silent wrong.
$fb = file_get_contents(__DIR__ . '/../pps-pay-link.php');
if (!preg_match('/function pps_is_business_day\s*\([^)]*\)\s*:\s*bool\s*\{.*?\n    \}/s', $fb, $m)) {
    fwrite(STDERR, "could not find the fallback pps_is_business_day() in pps-pay-link.php\n");
    exit(1);
}
$body = preg_replace('/^\s{4}/m', '', $m[0]);
eval(str_replace('pps_is_business_day', 'pps_fallback_business_day', $body));

function fbday($ymd) { return pps_fallback_business_day(new DateTime($ymd)); }
$GLOBALS['closures'] = array();
ok('fallback: thursday open', fbday('2026-08-27'), true);
ok('fallback: saturday shut', fbday('2026-08-29'), false);
ok('fallback: sunday shut',   fbday('2026-08-30'), false);
$GLOBALS['closures'] = array('12-25');
ok('fallback: recurring closure', fbday('2026-12-25'), false);
ok('fallback: recurring next year', fbday('2027-12-25'), false);
$GLOBALS['closures'] = array('2026-09-03');
ok('fallback: dated closure', fbday('2026-09-03'), false);
ok('fallback: dated does not recur', fbday('2027-09-03'), true);

// And the two must agree on every one of those.
$GLOBALS['closures'] = array('12-25', '2026-09-03');
foreach (array('2026-08-27','2026-08-29','2026-08-30','2026-12-25','2027-12-25','2026-09-03','2027-09-03') as $d) {
    ok('real and fallback agree on ' . $d, day($d), fbday($d));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

<?php
/**
 * pps_quote_mask_email() — against the shipped pps-job-quote.php.
 *
 * This is the mitigation that makes predictable quote tokens safe to offer:
 * the used-quote page is reachable by anyone who can guess a token, so the
 * address on it must be recognisable to the person who typed it and useless
 * to anyone enumerating.
 *
 * Run: php tools-tests/quotemask.php
 */
define('ABSPATH', '/tmp/');
function add_action(...$a) {}
function add_filter(...$a) {}
function add_shortcode(...$a) {}
function register_post_type(...$a) {}
function get_option($k, $d = '') { return $d; }

require __DIR__ . '/../pps-job-quote.php';

$pass = 0; $fail = 0;
function ok($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; return; }
    $fail++;
    printf("FAIL %s: got %s want %s\n", $label, var_export($got, true), var_export($want, true));
}

// The domain stays -- the customer recognises their own address by it.
ok('keeps first char and domain', pps_quote_mask_email('jane@example.com'), 'j•••@example.com');
ok('longer local part masked',    pps_quote_mask_email('jonathan@example.com'), 'j••••••@example.com');

// Never leak more of the local part than the mask implies: an 11-character
// name must not reveal its length beyond the cap.
ok('mask is capped', pps_quote_mask_email('averylongname@example.com'), 'a••••••@example.com');

// A one-character local part still gets a floor of mask characters, so the
// mask never reveals that the name was a single letter.
ok('short local part still masked', pps_quote_mask_email('a@example.com'), 'a•••@example.com');

// Garbage in must not produce a half-address that looks real.
ok('no at sign yields nothing', pps_quote_mask_email('notanemail'), '');
ok('leading at yields nothing',  pps_quote_mask_email('@example.com'), '');
ok('empty yields nothing',       pps_quote_mask_email(''), '');

// The whole point: two different customers on the same domain are not
// distinguishable from the masked form unless they share a first letter.
$a = pps_quote_mask_email('jane@example.com');
$b = pps_quote_mask_email('john@example.com');
ok('same initial collides (intended)', $a === $b, true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

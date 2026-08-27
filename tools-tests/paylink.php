<?php
/**
 * pps-pay-link.php — the input parsing, against the shipped file.
 *
 * Loads the real module with WordPress stubbed rather than retyping its
 * logic here: a copied-out assertion passes happily while the deployed file
 * says something else.
 *
 * Run: php tools-tests/paylink.php
 */
define('ABSPATH', '/tmp/');
$GLOBALS['opts'] = array();
function add_action(...$a) {}
function get_option($k, $d = '') { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $a = true) { $GLOBALS['opts'][$k] = $v; return true; }
function wp_generate_password($n, $s = true, $x = true) { return str_repeat('x', $n); }
class PPS_StubProduct { public function exists() { return true; } public function get_name() { return 'Custom Print Job'; } }
function wc_get_product($id) { return empty($GLOBALS['product_ok']) ? false : new PPS_StubProduct(); }
function absint($v) { return abs(intval($v)); }
class WP_Error { public $code; public $msg;
    public function __construct($c = '', $m = '') { $this->code = $c; $this->msg = $m; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->msg; } }
function is_wp_error($t) { return $t instanceof WP_Error; }

require __DIR__ . '/../pps-pay-link.php';

$pass = 0; $fail = 0;
function ok($label, $got, $want) {
    global $pass, $fail;
    $same = ($got === $want);
    if ($same) { $pass++; } else { $fail++; printf("FAIL %s: got %s want %s\n", $label, var_export($got, true), var_export($want, true)); }
}

// --- price parsing: the shapes a human types into a chat window
ok('plain int',      pps_paylink_parse_price('250'),      250.0);
ok('decimal',        pps_paylink_parse_price('250.00'),   250.0);
ok('dollar sign',    pps_paylink_parse_price('$250'),     250.0);
ok('comma thousands',pps_paylink_parse_price('1,250.00'), 1250.0);
ok('padded',         pps_paylink_parse_price('  250.50 '),250.5);
ok('native float',   pps_paylink_parse_price(250.499),    250.5);
ok('native int',     pps_paylink_parse_price(250),        250.0);
ok('empty is null',  pps_paylink_parse_price(''),         null);
ok('prose is null',  pps_paylink_parse_price('call me'),  null);
ok('zero parses',    pps_paylink_parse_price('0'),        0.0);   // parses; refused later as <= 0

// --- qbo flag: the shapes a rule engine sends a boolean in
ok('bool true',   pps_paylink_wants_qbo(true),      true);
ok('bool false',  pps_paylink_wants_qbo(false),     false);
ok('str true',    pps_paylink_wants_qbo('true'),    true);
ok('str True',    pps_paylink_wants_qbo('True'),    true);
ok('str 1',       pps_paylink_wants_qbo('1'),       true);
ok('str yes',     pps_paylink_wants_qbo('yes'),     true);
ok('int 1',       pps_paylink_wants_qbo(1),         true);
ok('int 0',       pps_paylink_wants_qbo(0),         false);
// The one that matters: "false" as a string must NOT be truthy.
ok('str false',   pps_paylink_wants_qbo('false'),   false);
ok('str no',      pps_paylink_wants_qbo('no'),      false);
ok('empty str',   pps_paylink_wants_qbo(''),        false);

// --- secret is generated once and then stable
$s1 = pps_paylink_secret();
$s2 = pps_paylink_secret();
ok('secret length', strlen($s1), 40);
ok('secret stable', $s1, $s2);

// --- product refuses when unset / invalid
ok('no product configured', pps_paylink_product_id(), 0);
$GLOBALS['opts']['pps_paylink_product'] = 999;   // wc_get_product stub returns false
ok('bad product id', pps_paylink_product_id(), 0);

// ── Routing survives the hand-off to the quote engine ────────────────────
// The bug this covers: pps_quote_create() used to coerce anything that was
// not 'quickbooks' to 'site', so a link asking for QuickBooks was silently
// downgraded and nobody could see it had happened.
$GLOBALS['captured'] = null;
function pps_quote_create($a) { $GLOBALS['captured'] = $a; return 4242; }
function pps_quote_url($t) { return 'https://example.test/quote/?q=' . $t; }
function get_post_meta($id, $k, $single = false) { return 'tok123'; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$k] = $v; return true; }
function sanitize_textarea_field($v) { return $v; }
// Approximates sanitize_title_with_dashes for the cases under test:
// lowercase, spaces to dashes, everything else non-alphanumeric dropped.
function sanitize_title($t) {
    $t = strtolower(trim((string) $t));
    $t = preg_replace('/\s+/', '-', $t);
    $t = preg_replace('/[^a-z0-9\-]/', '', $t);
    return trim(preg_replace('/-+/', '-', $t), '-');
}

// A real product is required before anything mints.
$GLOBALS['opts']['pps_paylink_product'] = 7;
function wc_get_product_real() {}
$GLOBALS['product_ok'] = true;

$res = pps_paylink_create(array('description' => '500 postcards', 'price' => '$250'));
ok('mints with a valid product', is_array($res), true);
ok('returns the quote url', $res['url'] ?? '', 'https://example.test/quote/?q=tok123');
ok('description reaches the quote', $GLOBALS['captured']['specs'] ?? '', '500 postcards');
ok('price reaches the quote', $GLOBALS['captured']['tiers'][0]['price'] ?? 0, 250.0);
ok('qty defaults to 1', $GLOBALS['captured']['tiers'][0]['qty'] ?? 0, 1);

// QuickBooks unavailable -> falls back, and SAYS it fell back.
$res = pps_paylink_create(array('description' => 'job', 'price' => '100', 'qbo' => true));
ok('falls back to site when QBO not ready', $GLOBALS['captured']['pay_source'] ?? '', 'site');
ok('fallback is reported', $res['qbo_fell_back'] ?? null, true);
ok('the ask is still recorded', $GLOBALS['meta']['_q_qbo'] ?? null, 1);

// Not asking for QuickBooks is not a fallback.
$res = pps_paylink_create(array('description' => 'job', 'price' => '100'));
ok('no ask is not a fallback', $res['qbo_fell_back'] ?? null, false);
ok('unasked flag stored as 0', $GLOBALS['meta']['_q_qbo'] ?? null, 0);

// ── Predictable tokens ───────────────────────────────────────────────────
function current_time($fmt) { return '20260827-0215'; }   // frozen clock

ok('blank reference is the timestamp', pps_paylink_token(''), '20260827-0215');
ok('reference wins over timestamp',    pps_paylink_token('Acme October'), 'acme-october');
ok('reference is slugified',           pps_paylink_token('  ACME/October!! '), 'acmeoctober');
// A reference that slugifies to nothing must not produce an empty token,
// which would make the quote unreachable.
ok('unusable reference falls back',    pps_paylink_token('!!!'), '20260827-0215');

// The token reaches the quote engine, which is what makes the link knowable.
$res = pps_paylink_create(array('description' => 'job', 'price' => '100'));
ok('timestamp token passed through', $GLOBALS['captured']['token'] ?? '', '20260827-0215');
$res = pps_paylink_create(array('description' => 'job', 'price' => '100', 'reference' => 'acme-october'));
ok('reference token passed through',  $GLOBALS['captured']['token'] ?? '', 'acme-october');

// ── Reading a command typed in a conversation ────────────────────────────
function parse($t) { return pps_paylink_parse_command($t); }
function pcode($r) { return is_wp_error($r) ? $r->get_error_code() : 'ok'; }

$r = parse('/pay $250 500 postcards, 16pt gloss');
ok('price from $ form',     $r['price'], 250.0);
ok('description survives',  $r['description'], '500 postcards, 16pt gloss');
ok('qbo off by default',    $r['qbo'], false);

// The case that makes a naive first-number parser charge $500.
$r = parse('500 postcards $250');
ok('$ wins over leading qty', $r['price'], 250.0);
ok('qty stays in description', $r['description'], '500 postcards');

// Refuse rather than guess when two bare numbers could each be the price.
ok('two bare numbers refused', pcode(parse('500 postcards 250')), 'ambiguous');
// One bare number is unambiguous, so it is allowed.
$r = parse('booklets 250');
ok('single bare number is the price', $r['price'], 250.0);

$r = parse('pay $1,250.00 qbo 2000 booklets #acme-october');
ok('commas parsed',      $r['price'], 1250.0);
ok('qbo flag read',      $r['qbo'], true);
ok('reference read',     $r['reference'], 'acme-october');
ok('flags left the text', $r['description'], '2000 booklets');

// A word merely containing "quickbooks" must not route a payment.
$r = parse('$99 quickbooks-style ledger books');
ok('substring does not set qbo', $r['qbo'], false);

ok('no price refused',       pcode(parse('some postcards')), 'price');
ok('no description refused', pcode(parse('$250')), 'description');
ok('empty refused',          pcode(parse('   ')), 'empty');

// ── Finding that text inside a rule engine's envelope ────────────────────
ok('explicit text field',  pps_paylink_extract_text(array('text' => 'a')), 'a');
ok('missive comment body', pps_paylink_extract_text(array('comment' => array('body' => 'b'))), 'b');
ok('nested message body',  pps_paylink_extract_text(array('message' => array('delivered_body' => 'c'))), 'c');
ok('nothing readable',     pps_paylink_extract_text(array('conversation' => array('id' => 'x'))), '');
ok('non-array is empty',   pps_paylink_extract_text('nope'), '');

// ── A real spec line, as an operator actually types it ───────────────────
// Five numbers in the description (3.74, 8.27, 80, 10, 50) and one price.
// Any parser that guesses at a number instead of honouring the $ bills the
// customer for a paper weight or a page size.
$real = '/pay Pads 3.74 × 8.27 Color: Full Color / Full Color Paper: 80lb Matte Text  10 pads of 50, $177.40';
$r = parse($real);
ok('real spec: price',  $r['price'], 177.40);
ok('real spec: desc',   $r['description'],
   'Pads 3.74 × 8.27 Color: Full Color / Full Color Paper: 80lb Matte Text 10 pads of 50');
ok('real spec: no qbo', $r['qbo'], false);

// The same job typed over several lines. Missive comments are usually
// multiline, and the quote page renders the spec in a <pre>, so the line
// structure has to survive rather than being flattened.
$multi = "/pay Pads 3.74 × 8.27\nColor: Full Color / Full Color\nPaper: 80lb Matte Text\n10 pads of 50, \$177.40";
$r = parse($multi);
ok('multiline: price', $r['price'], 177.40);
ok('multiline: line breaks kept',
   $r['description'],
   "Pads 3.74 × 8.27\nColor: Full Color / Full Color\nPaper: 80lb Matte Text\n10 pads of 50");

// Same spec, no $ — five candidate numbers, so it must refuse rather than
// pick one and invoice a page dimension.
ok('real spec without $ refused', pcode(parse('/pay Pads 3.74 × 8.27, 10 pads of 50, 177.40')), 'ambiguous');

// ── The bracketed form ───────────────────────────────────────────────────
$r = parse('/ppspay [Pads 3.74 × 8.27 Color: Full Color / Full Color Paper: 80lb Matte Text 10 pads of 50] $177.40');
ok('bracket: price',  $r['price'], 177.40);
ok('bracket: desc verbatim', $r['description'],
   'Pads 3.74 × 8.27 Color: Full Color / Full Color Paper: 80lb Matte Text 10 pads of 50');

// Multiline inside brackets keeps its shape.
$r = parse("/ppspay [Pads 3.74 × 8.27\nColor: Full Color\n10 pads of 50] \$177.40");
ok('bracket: line breaks kept', $r['description'], "Pads 3.74 × 8.27\nColor: Full Color\n10 pads of 50");

// Flags outside compose in any order.
$r = parse('/ppspay qbo [500 postcards, 16pt gloss] $250 #acme-october');
ok('bracket: qbo',       $r['qbo'], true);
ok('bracket: reference', $r['reference'], 'acme-october');
ok('bracket: desc clean', $r['description'], '500 postcards, 16pt gloss');

// The point of brackets: what is inside is NEVER parsed. A description that
// mentions a price, a quantity, a #tag or the word qbo must stay description.
$r = parse('/ppspay [reprint of the $50 job, 2000 up, qbo #legacy] $500');
ok('inside: price not stolen',  $r['price'], 500.0);
ok('inside: qbo not triggered', $r['qbo'], false);
ok('inside: ref not stolen',    $r['reference'], '');
ok('inside: kept verbatim',     $r['description'], 'reprint of the $50 job, 2000 up, qbo #legacy');

// Failures that must be loud rather than guessed.
ok('unclosed bracket refused', pcode(parse('/ppspay [Pads 3.74 x 8.27 $177.40')), 'unclosed');
ok('empty brackets refused',   pcode(parse('/ppspay [] $177.40')), 'description');
ok('no price outside refused', pcode(parse('/ppspay [Pads 10 of 50]')), 'price');

// The command word itself.
ok('ppspay accepted',      parse('/ppspay [job] $10')['description'], 'job');
ok('bare ppspay accepted', parse('ppspay [job] $10')['description'], 'job');
// The older spelling still answers, so anything already wired keeps working.
ok('legacy /pay accepted', parse('/pay [job] $10')['description'], 'job');
// A description that merely starts with the word must not be eaten.
ok('command word only stripped once', parse('/ppspay [payment stubs] $10')['description'], 'payment stubs');

// ── *qbo, the sigil form ─────────────────────────────────────────────────
$r = parse('/ppspay *qbo [500 postcards] $250');
ok('*qbo sets the flag', $r['qbo'], true);
ok('*qbo leaves description clean', $r['description'], '500 postcards');
// The bare word still answers, so nothing already typed stops working.
ok('bare qbo still works', parse('/ppspay qbo [500 postcards] $250')['qbo'], true);
// Inside the brackets it is description, sigil or not.
ok('*qbo inside brackets is text',
   parse('/ppspay [reprint *qbo notes] $250')['qbo'], false);
ok('*qbo inside brackets kept',
   parse('/ppspay [reprint *qbo notes] $250')['description'], 'reprint *qbo notes');
// All four sigils at once, in an awkward order.
$r = parse('/ppspay $99.50 *qbo #jan-batch [Letterhead 8.5 x 11, 70lb]');
ok('all sigils: price', $r['price'], 99.50);
ok('all sigils: qbo',   $r['qbo'], true);
ok('all sigils: ref',   $r['reference'], 'jan-batch');
ok('all sigils: desc',  $r['description'], 'Letterhead 8.5 x 11, 70lb');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

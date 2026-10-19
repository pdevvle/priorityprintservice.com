<?php
/**
 * The intake forms (pps-intake.php) must not mail strangers what a bot typed.
 *
 * October 2026: bots filled the quote form with a Russian "you have a transfer of N
 * rubles, collect it here https://…" line as the NAME and a victim's address as EMAIL.
 * The confirmation greeted the submitter by name, so the shop's mail server delivered
 * the phishing link to the victim. This drives the real file against stubs with the
 * submissions on record, spam and genuine, and checks:
 *   - every spam sample is recorded as a draft with a reason, and nobody is mailed;
 *   - every genuine sample is published, the office is mailed, and the customer gets a
 *     confirmation that contains nothing they typed;
 *   - confirmations stop at the hourly cap; staff mail does not.
 *
 * Run: php tools-intake-spam-test.php   (PPS_INTAKE_PHP points at another copy)
 * Against 5f48e1e (live until this fix) it fails: spam is published, mailed, and the
 * confirmation carries the link.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 ); define( 'DAY_IN_SECONDS', 86400 ); define( 'AUTH_SALT', 'test-salt' );
$GLOBALS['mail'] = array(); $GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['tr'] = array();
function add_action() {} function add_filter() {} function add_shortcode() {} function register_post_type() {}
function wp_mail( $to, $s, $b, $h = array() ) { $GLOBALS['mail'][] = compact( 'to', 's', 'b' ); return true; }
function wp_insert_post( $a ) { $id = 1000 + count( $GLOBALS['posts'] ); $GLOBALS['posts'][ $id ] = $a; return $id; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; }
function is_wp_error( $x ) { return false; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_SLASHES ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function get_option( $k, $d = '' ) { return $k === 'admin_email' ? 'admin@example.com' : $d; }
function get_bloginfo() { return 'Priority Print Service'; }
function wp_specialchars_decode( $s ) { return $s; }
function admin_url( $p ) { return 'https://x/wp-admin/' . $p; }
function current_time() { return 'Oct 6, 2026'; }
function error_log_( ) {}
function pps_get_config() { return array( 'pcf' => array( 'question_recipient_email' => 'office@example.com' ) ); }

require getenv( 'PPS_INTAKE_PHP' ) ?: __DIR__ . '/pps-intake.php';

$forms = pps_intake_forms();
$fail = 0; $n = 0;
function check( $ok, $what ) { global $fail, $n; $n++; if ( ! $ok ) { $fail++; echo "FAIL: $what\n"; } }
function submit( $values ) {
    global $forms;
    $GLOBALS['mail'] = array();
    $id = pps_intake_record( 'quote', $forms['quote'], $values, array() );
    pps_intake_notify( 'quote', $forms['quote'], $values, array(), $id );
    return $id;
}

// Spam actually received on production, 2026-10-05/06 (Calc Questions 87278–87362).
$spam = array(
    array( 'name' => 'Вам перевод 167382 руб. забрать тут https://sekalubanik.buzz/gRWq5FO4d#7H0PPB', 'email' => 'victim@example.org', 'message' => 'CKId0vK tdtj L3TrnEK VFoxTSv I7gd kRI5cZO' ),
    array( 'name' => 'Вам перевод 177127 руб. забрать тут https://peskartyhrt.buzz/eKqekoxT JUYEGRT17575JUYEGRT', 'email' => 'v2@example.org', 'message' => 'GdCNHwg tsKX KP7WNaE' ),
    array( 'name' => 'Вам перевод 176350 руб. забрать тут https://5a48ca64.sslip.io/TQexcp6J MTGJNF24417JUYEGRT', 'email' => 'v3@example.org', 'message' => 'dWaEzpe onm5 lCbXPZz' ),
    array( 'name' => 'Oreseeriz', 'email' => 'v4@example.org', 'message' => 'Радостная новость! Твой эксклюзивный приз в ожидании! Посмотрите детали по ссылке https://sekalubanik.buzz/Px4fS66B NFDAW17575SVWVE' ),
    array( 'name' => 'Oreseeriz', 'email' => 'v5@example.org', 'message' => 'Вам перевод 138575 руб. получить тут https://peskartyhrt.buzz/S5LjQqQ9 TUJE17575MTGJNF' ),
    array( 'name' => 'NAERTERHTE229026NERTHRRTH', 'email' => 'v6@example.org', 'message' => 'MERTHYTJTJ229026MARTHHDF' ),
);
foreach ( $spam as $i => $v ) {
    $id = submit( $v );
    check( ( $GLOBALS['posts'][ $id ]['post_status'] ?? '' ) === 'draft', "spam $i kept as a draft" );
    check( (string) get_post_meta( $id, '_pps_q_spam' ) !== '', "spam $i carries a reason" );
    check( count( $GLOBALS['mail'] ) === 0, "spam $i mails nobody (sent " . count( $GLOBALS['mail'] ) . ')' );
}

// Genuine requests on record, including a link in the message (Canva/Drive are normal).
$real = array(
    array( 'name' => 'Ronald Reid', 'email' => 'r@example.com', 'message' => "I'd like quotes for a saddle-stitch booklet: 3.5 × 5.5 in, 12 pages, 80 lb matte text, 250, 500, 1,000 copies." ),
    array( 'name' => 'Paula VanTyle-Smith', 'email' => 'p@example.com', 'message' => 'This must reach us by Oct 9th. Does your company proof, trim and fold the brochures?' ),
    array( 'name' => 'Juanita M Scheyett-Cheng', 'email' => 'j@example.com', 'phone' => '(480) 555-0199', 'message' => 'We have a P.O. Box and a street address.' ),
    array( 'name' => "Mary O'Neil", 'email' => 'm@example.com', 'message' => 'Design is here: https://www.canva.com/design/DAF123/view — 500 postcards please.' ),
    array( 'name' => 'José Núñez', 'email' => 'jn@example.com', 'message' => '1000 bookmarks 2x6, double sided.' ),
    array( 'name' => 'Megan Baloun', 'email' => 'mb@example.com', 'order_ref' => 'Order Entry form #2044', 'message' => 'Last ordered around June 16th 2026.' ),
);
foreach ( $real as $i => $v ) {
    $id = submit( $v );
    check( ( $GLOBALS['posts'][ $id ]['post_status'] ?? '' ) === 'publish', "real $i ({$v['name']}) published" );
    check( (string) get_post_meta( $id, '_pps_q_spam' ) === '', "real $i not flagged (" . get_post_meta( $id, '_pps_q_spam' ) . ')' );
    $to = array_column( $GLOBALS['mail'], 'to' );
    check( in_array( 'office@example.com', $to, true ), "real $i reaches the office" );
    $conf = array_values( array_filter( $GLOBALS['mail'], function ( $m ) use ( $v ) { return $m['to'] === $v['email']; } ) );
    check( count( $conf ) === 1, "real $i gets one confirmation" );
    if ( $conf ) {
        $body = $conf[0]['s'] . "\n" . $conf[0]['b'];
        foreach ( $v as $f => $typed ) {
            if ( $f === 'email' ) continue;
            check( strpos( $body, $typed ) === false, "real $i confirmation does not echo the $f" );
        }
    }
}

// The hourly cap: a bot that gets past every rule still cannot make the form mail more
// than 30 strangers an hour. The office still hears about every one.
$GLOBALS['tr'] = array();
$confirms = 0; $office = 0;
for ( $i = 0; $i < 40; $i++ ) {
    submit( array( 'name' => 'Pat Lee', 'email' => "p$i@example.com", 'message' => 'Quote for flyers.' ) );
    foreach ( $GLOBALS['mail'] as $m ) { if ( $m['to'] === 'office@example.com' ) $office++; else $confirms++; }
}
check( $confirms === 30, "confirmations capped at 30 an hour (sent $confirms)" );
check( $office === 40, "every request still reaches the office ($office)" );

// ── The 2026-10-18 run: subscription bombing. Text a person could have written, so the
// text rules cannot see it — what gives it away is that nothing ran the page. ──
$t0 = 1792358000;
check( pps_intake_token_reason( pps_intake_token_make( $t0 ), $t0 + 40 ) === '', 'a token the form fetched 40 s before submitting proves a person' );
check( pps_intake_token_reason( '', $t0 ) !== '' && pps_intake_token_reason( null, $t0 ) !== '', 'no token (a bot POSTing straight to admin-post.php) is not' );
check( strpos( pps_intake_token_reason( pps_intake_token_make( $t0 ), $t0 + 1 ), 'sent 1s after' ) === 0, 'nor one submitted within 2 s of the first touch' );
check( pps_intake_token_reason( $t0 . '.' . str_repeat( 'a', 32 ), $t0 + 40 ) === 'forged form token', 'nor a made-up one' );
check( pps_intake_token_reason( pps_intake_token_make( $t0 ), $t0 + 3 * 86400 ) === 'form token expired', 'nor one three days old' );
check( pps_intake_token_reason( 'x<script>', $t0 ) === 'malformed form token', 'garbage is malformed, not an error' );
$bomb = array(
    array( 'name' => 'Riley Jacobson', 'email' => 'someone@gmail.com', 'phone' => '+1 (504) 998-4657', 'callback' => '1', 'message' => 'The box this comes in is 4 meter by 5 foot and weights 18 kilogram.' ),
    array( 'name' => 'Ottilie Armstrong', 'email' => 'other@gmail.com', 'phone' => '+1 (957) 830-8186', 'callback' => '1', 'message' => "one of my hobbies is drawing. and when i'm drawing this works great." ),
    array( 'name' => 'Lola Koepp', 'email' => 'third@smaqt.com', 'phone' => '+1 (304) 872-1727', 'callback' => '1', 'message' => 'The box this comes in is 5 kilometer by 6 yard and weights 18 gram.' ),
);
foreach ( $bomb as $i => $v ) {
    check( pps_intake_spam_reason( $v ) === '', "bomb $i reads as text a person could write (why the token is needed)" );
    $id = submit( $v + array( '__spam' => pps_intake_token_reason( '' ) ) );
    check( ( $GLOBALS['posts'][ $id ]['post_status'] ?? '' ) === 'draft' && strpos( (string) get_post_meta( $id, '_pps_q_spam' ), 'no form token' ) === 0, "bomb $i without a token: kept as a spam draft, saying why" );
    check( count( $GLOBALS['mail'] ) === 0, "bomb $i mails nobody — not the office, not the stranger whose address was typed" );
}
$GLOBALS['tr'] = array();
$id = submit( $real[0] + array( '__spam' => pps_intake_token_reason( pps_intake_token_make( time() - 30 ) ) ) );
check( ( $GLOBALS['posts'][ $id ]['post_status'] ?? '' ) === 'publish' && count( $GLOBALS['mail'] ) === 2, 'a real request with a good token: published, office and confirmation mailed as before' );
check( strpos( implode( "\n", array_column( $GLOBALS['mail'], 'b' ) ), '__spam' ) === false, 'the verdict field never reaches an email' );
$src = file_get_contents( getenv( 'PPS_INTAKE_PHP' ) ?: __DIR__ . '/pps-intake.php' );
check( strpos( $src, "\$values['__spam'] = pps_intake_token_reason( wp_unslash( \$_POST['pps_tok'] ?? '' ) );" ) !== false && strpos( $src, "&& \$values['__spam'] === '' ? pps_intake_take_uploads()" ) !== false, 'the handler judges every submission by its token, and keeps no files from one without' );
check( strpos( $src, '<input type="hidden" name="pps_tok" value="">' ) !== false && strpos( $src, "echo pps_intake_token_script( \$anchor, add_query_arg( 'action', 'pps_intake_token', admin_url( 'admin-ajax.php' ) ) );" ) !== false, 'every form carries the empty token field and its script' );
check( strpos( $src, "add_action( 'wp_ajax_nopriv_pps_intake_token', 'pps_intake_token_endpoint' );" ) !== false && strpos( $src, 'nocache_headers();' ) !== false, 'the token comes from an uncached endpoint anyone can reach' );
// the script, in a browser: a person's click fetches the token; a submit with none waits for it
$html = '<!doctype html><div id="pps-intake-quote"><form method="post" action="/post"><input type="hidden" name="pps_tok" value=""><input name="name" required><button type="submit">Send</button></form>'
      . pps_intake_token_script( 'pps-intake-quote', '/tok' ) . '</div>';
$tmp = sys_get_temp_dir() . '/pps-intake-' . getmypid() . '.html'; file_put_contents( $tmp, $html );
$js = <<<'JS'
const { execSync } = require('child_process');
const pw = require(require('path').join(execSync('npm root -g').toString().trim(), 'playwright'));
(async () => {
  const html = require('fs').readFileSync(process.argv[2], 'utf8');
  const b = await pw.chromium.launch(); const out = {};
  for (const mode of ['click', 'submit-first', 'fetch-fails']) {
    const p = await b.newPage(); let toks = 0, posted = null;
    await p.route('https://site.test/**', async r => {
      const u = r.request().url();
      if (u.endsWith('/page')) return r.fulfill({ contentType: 'text/html', body: html });
      if (u.endsWith('/tok')) { toks++; return mode === 'fetch-fails' ? r.abort() : r.fulfill({ contentType: 'text/plain', body: '1792358000.' + 'ab'.repeat(16) }); }
      posted = r.request().postData(); return r.fulfill({ contentType: 'text/html', body: 'ok' });
    });
    await p.goto('https://site.test/page');
    if (mode === 'click') { await p.click('[name=name]'); await p.waitForTimeout(200); out.afterClick = await p.inputValue('[name=pps_tok]'); }
    await p.fill('[name=name]', 'Pat'); await p.click('button'); await p.waitForTimeout(500);
    out[mode] = { toks, posted };
    await p.close();
  }
  await b.close(); console.log(JSON.stringify(out));
})();
JS;
file_put_contents( "$tmp.js", $js );
$r = json_decode( (string) shell_exec( 'node ' . escapeshellarg( "$tmp.js" ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1' ), true );
@unlink( $tmp ); @unlink( "$tmp.js" );
check( is_array( $r ) && $r['afterClick'] === '1792358000.' . str_repeat( 'ab', 16 ), 'browser: a person clicking into the form fetches the token' );
check( is_array( $r ) && $r['click']['toks'] === 1 && strpos( (string) $r['click']['posted'], 'pps_tok=1792358000.' ) !== false, 'and it is posted with the form, fetched once' );
check( is_array( $r ) && strpos( (string) $r['submit-first']['posted'], 'pps_tok=1792358000.' ) !== false, 'browser: a submit before any token waits for one, then posts it' );
check( is_array( $r ) && $r['fetch-fails']['posted'] !== null && strpos( (string) $r['fetch-fails']['posted'], 'name=Pat' ) !== false, 'browser: if the token cannot be fetched the form still submits (kept as a draft, never lost)' );

echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> INTAKE SPAM OK ($n checks)\n";
exit( $fail ? 1 : 0 );

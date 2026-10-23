<?php
/**
 * The form token (pps-intake.php) on the two public forms that are not [pps_intake]: the
 * category wizard's quote request (pps-term-shortcodes.php) and the calculators' "Ask a
 * question" (pps-calculators.php), in a real browser. The wizard's own script is built from
 * the shortcode's source; the question's is pps_question_token_script() around a stand-in for
 * the calculators' compiled submit (the same FormData + fetch to admin-ajax they all run).
 * Checks: nothing is fetched before a person touches the form, the token rides on the post,
 * a Send before the token waits for it, a failed fetch still sends, and other admin-ajax
 * calls (add to cart, uploads) are left exactly as they were. The handlers' verdicts are in
 * tools-relay-test.php. Run: php tools-form-token-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_SLASHES ); }
function admin_url( $p = '' ) { return 'https://site.test/wp-admin/' . $p; }
function esc_url( $u ) { return htmlspecialchars( $u, ENT_QUOTES ); }
function esc_attr( $u ) { return htmlspecialchars( $u, ENT_QUOTES ); }
function add_action() {} function add_filter() {}
$fail = 0; $n = 0;
function check( $ok, $what ) { global $fail, $n; $n++; if ( ! $ok ) { $fail++; echo "FAIL: $what\n"; } }
function lift( $src, $name ) {
    $at = strpos( $src, "function $name(" ); if ( $at === false ) { echo "FAIL: $name not found\n"; exit( 1 ); }
    $i = strpos( $src, '{', $at ); $d = 0;
    for ( $l = strlen( $src ); $i < $l; $i++ ) { if ( $src[ $i ] === '{' ) $d++; elseif ( $src[ $i ] === '}' && --$d === 0 ) break; }
    return substr( $src, $at, $i - $at + 1 );
}
$calc  = file_get_contents( getenv( 'PPS_CALC_PHP' ) ?: __DIR__ . '/pps-calculators.php' );
$terms = file_get_contents( getenv( 'PPS_TERMS_PHP' ) ?: __DIR__ . '/pps-term-shortcodes.php' );
eval( lift( $calc, 'pps_question_token_script' ) );

// the wizard's action bar and its script, out of the shortcode source
$a = strpos( $terms, "    \$out .= '<div class=\"pps-wiz-actions\"" ); $b = strpos( $terms, "    \$out .= '</div>';\n\n    // ── Inline JS ──" );
$c = strpos( $terms, "    \$out .= '<script data-cfasync=\"false\">'" ); $e = strpos( $terms, "'</script>';", $c );
check( $a && $b && $c && $e, 'wizard: the markup and its Rocket-Loader-proof script are where they were' );
$out = ''; $id = 'wiz1'; $link = 'https://site.test/product/brochures/'; $lead_mode = false; $ajax_url = admin_url( 'admin-ajax.php' );
eval( substr( $terms, $a, $b - $a ) . "\n\$out .= '</div>';" . substr( $terms, $c, $e - $c + 12 ) );
$wiz = '<!doctype html><div id="wiz1" class="pps-wiz" data-calc="brochure" data-link="' . $link . '">' . $out;
$wiz = preg_replace( '/(<script data-cfasync="false">)/', '</div>$1', $wiz, 1 );   // the script sits after the wizard root
// the calculators' compiled submit, as all seven that have the box run it
$qhtml = '<!doctype html><script>window.PPS_CONFIG={ajaxUrl:"https://site.test/wp-admin/admin-ajax.php",cartNonce:"n"}</script>'
       . pps_question_token_script()
       . '<input id="qn"><textarea id="qm"></textarea><button id="ask">Send question</button><button id="cart">Add to Order</button>'
       . '<script>function post(a){const PPS=window.PPS_CONFIG;const fd=new FormData();fd.append("action",a);fd.append("nonce",PPS.cartNonce);fd.append("name",document.getElementById("qn").value);'
       . 'return fetch(PPS.ajaxUrl,{method:"POST",body:fd,credentials:"same-origin"}).then(r=>r.json()).then(j=>{document.body.dataset.done=a+":"+j.success})}'
       . 'document.getElementById("ask").onclick=()=>post("pps_quote_question");document.getElementById("cart").onclick=()=>post("pps_add_to_cart");</script>';
$tmp = sys_get_temp_dir() . '/pps-ft-' . getmypid(); file_put_contents( "$tmp-w.html", $wiz ); file_put_contents( "$tmp-q.html", $qhtml );
$js = <<<'JS'
const { execSync } = require('child_process');
const pw = require(require('path').join(execSync('npm root -g').toString().trim(), 'playwright'));
const fs = require('fs'); const TOK = '1792358000.' + 'ab'.repeat(16);
(async () => {
  const b = await pw.chromium.launch(); const out = {};
  async function page(file, mode) {
    const p = await b.newPage(); const log = { toks: 0, posts: [], errs: [] };
    p.on('pageerror', e => log.errs.push(e.message));
    await p.route('https://site.test/**', async r => {
      const u = r.request().url();
      if (u.endsWith('/page')) return r.fulfill({ contentType: 'text/html', body: fs.readFileSync(file, 'utf8') });
      if (u.includes('action=pps_intake_token')) { log.toks++; if (mode === 'fail') return r.abort(); if (mode === 'slow') await new Promise(z => setTimeout(z, 600)); return r.fulfill({ contentType: 'text/plain', body: TOK }); }
      log.posts.push(r.request().postDataBuffer().toString('latin1')); return r.fulfill({ contentType: 'application/json', body: '{"success":true,"data":"sent"}' });
    });
    await p.goto('https://site.test/page'); await p.waitForTimeout(150);
    log.before = log.toks; return { p, log };
  }
  // wizard
  for (const mode of ['ok', 'slow', 'fail']) {
    const { p, log } = await page(process.argv[2], mode);
    await p.evaluate(() => document.querySelector('.pps-wiz-actions').classList.add('is-visible'));
    await p.click('.pps-wiz-act-quote'); if (mode === 'ok') await p.waitForTimeout(150);
    log.afterOpen = log.toks;
    await p.evaluate(() => { const f = (k, v) => document.querySelector(`[data-field="${k}"]`).value = v; f('name', 'Pat Lee'); f('email', 'pat@example.org'); f('phone', '602-555-0100'); });
    await p.click('.pps-wiz-send'); await p.waitForTimeout(900);
    log.status = await p.evaluate(() => document.querySelector('.pps-wiz-email-status').textContent);
    out['wiz-' + mode] = log; await p.close();
  }
  // ask a question
  for (const mode of ['ok', 'slow', 'fail']) {
    const { p, log } = await page(process.argv[3], mode);
    if (mode === 'ok') { await p.click('#qn'); await p.waitForTimeout(150); }
    log.afterTouch = log.toks;
    await p.fill('#qn', 'Pat'); await p.click('#ask'); await p.waitForTimeout(900);
    log.done = await p.evaluate(() => document.body.dataset.done);
    await p.click('#cart'); await p.waitForTimeout(300);
    log.done2 = await p.evaluate(() => document.body.dataset.done);
    out['q-' + mode] = log; await p.close();
  }
  await b.close(); console.log(JSON.stringify(out));
})();
JS;
file_put_contents( "$tmp.js", $js );
$raw = (string) shell_exec( 'node ' . escapeshellarg( "$tmp.js" ) . ' ' . escapeshellarg( "$tmp-w.html" ) . ' ' . escapeshellarg( "$tmp-q.html" ) . ' 2>&1' );
@unlink( "$tmp.js" ); @unlink( "$tmp-w.html" ); @unlink( "$tmp-q.html" );
$r = json_decode( $raw, true ); if ( ! is_array( $r ) ) { echo "FAIL: browser run: $raw\n"; exit( 1 ); }
$T = 'pps_tok"' . "\r\n\r\n" . '1792358000.' . str_repeat( 'ab', 16 );
$w = $r['wiz-ok'];
check( $w['before'] === 0 && $w['afterOpen'] === 1, 'wizard: nothing is fetched until the quote form is opened, then the token once' );
check( count( $w['posts'] ) === 1 && strpos( $w['posts'][0], $T ) !== false && strpos( $w['posts'][0], 'pps_wizard_email' ) !== false, 'wizard: the quote request carries the token' );
check( $w['status'] === 'sent' && ! $w['errs'], 'wizard: and the visitor sees the answer, no script errors' );
check( count( $r['wiz-slow']['posts'] ) === 1 && strpos( $r['wiz-slow']['posts'][0], $T ) !== false, 'wizard: Send pressed before the token is in waits for it, then posts it' );
check( count( $r['wiz-fail']['posts'] ) === 1 && strpos( $r['wiz-fail']['posts'][0], 'Pat Lee' ) !== false && $r['wiz-fail']['status'] === 'sent', 'wizard: a failed token fetch still sends (kept as a draft, never lost)' );
$q = $r['q-ok'];
check( $q['before'] === 0 && $q['afterTouch'] === 1, 'question: nothing is fetched until a person touches the page, then the token once' );
check( count( $q['posts'] ) === 2 && strpos( $q['posts'][0], $T ) !== false && $q['done'] === 'pps_quote_question:true', 'question: the question carries the token, and the calculator gets its answer' );
check( strpos( $q['posts'][1], 'pps_tok' ) === false && strpos( $q['posts'][1], 'pps_add_to_cart' ) !== false && $q['done2'] === 'pps_add_to_cart:true', 'question: every other admin-ajax call is left exactly as it was' );
check( strpos( $r['q-slow']['posts'][0], $T ) !== false && $r['q-slow']['done'] === 'pps_quote_question:true', 'question: sent before the token is in, it waits for it' );
check( $r['q-fail']['done'] === 'pps_quote_question:true' && strpos( $r['q-fail']['posts'][0], 'pps_quote_question' ) !== false, 'question: a failed token fetch still sends' );
check( ! $q['errs'] && ! $r['q-slow']['errs'] && ! $r['q-fail']['errs'], 'question: no script errors' );
echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> FORM TOKEN (wizard + question) OK ($n checks)\n";
exit( $fail ? 1 : 0 );

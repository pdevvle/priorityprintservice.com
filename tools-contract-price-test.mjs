#!/usr/bin/env node
/**
 * Contract price list on the saddle calculator (owner 2026-10-18: "recreate [the Armadillo
 * WCPA form] in our pps calculator forms" — keep their price list, lock the spec, quantity only).
 *
 * Drives the COMPILED saddle build (this branch's calc-preview-test.html, or PPS_CONTRACT_PAGE)
 * in Chromium, once with no contract and once with PPS_CONFIG.contract set as the product page
 * injects it, and checks what the customer sees and what the order would carry:
 *   - no contract: the calculator is unchanged (quantity box, Pages, Printing & Paper, Finishing);
 *   - contract: the quantity is a menu of the listed quantities with their prices; the total is
 *     exactly the listed price; another quantity charges its own listed price; a hardcopy proof
 *     is added on top; the spec is shown read-only and the paper/finishing sections are gone;
 *   - a link asking for 24 pages, other paper or 600 copies cannot change the locked spec, and
 *     600 lands on the nearest listed quantity;
 *   - the order metadata carries the contract line and the Job Ticket says "Pricing: …".
 *
 * Run: node tools-contract-price-test.mjs
 */
import { createServer } from 'http';
import { readFileSync, existsSync } from 'fs';
import path from 'path';
import { execSync } from 'child_process';

const ROOT = path.dirname(new URL(import.meta.url).pathname);
const PAGE = process.env.PPS_CONTRACT_PAGE || path.join(ROOT, 'calc-preview-test.html');
const pw = await import(path.join(execSync('npm root -g').toString().trim(), 'playwright/index.mjs'));

let fail = 0, n = 0;
const ok = (c, what, got) => { n++; if (!c) { fail++; console.log('FAIL: ' + what + (got !== undefined ? ' — ' + JSON.stringify(got) : '')); } };

const html = readFileSync(PAGE, 'utf8');
const srv = createServer((req, res) => {
  if (req.url.startsWith('/calc')) { res.writeHead(200, { 'Content-Type': 'text/html' }); res.end(html); return; }
  res.writeHead(404); res.end();
}).listen(0, '127.0.0.1');
await new Promise(r => srv.on('listening', r));
const base = `http://127.0.0.1:${srv.address().port}`;

const PRICES = { 100: 150.43, 250: 198.48, 500: 315.23, 750: 445.13, 1000: 580.44, 1250: 743.75, 1500: 837.44, 1750: 984.53, 2000: 1125.18 };
const DEFAULTS = { qty: 500, pages: 8, sizeLabel: 'Custom Size', customLong: 6.125, customShort: 9.25, bindDir: 'long',
  insideColor: 'color', coverColor: 'color', insidePaperType: 'noncardstock', insidePaper: '0.002', coverMode: 'separate', coverPaperType: 'cardstock', coverPaper: '0.02' };

const browser = await pw.chromium.launch();
async function open(cfg, query = '') {
  const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
  const errs = [];
  page.on('pageerror', e => errs.push(e.message));
  await page.addInitScript(c => { window.PPS_CONFIG = c; }, cfg);
  await page.goto(base + '/calc' + query, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.body.innerText.includes('Artwork & Proofing'), null, { timeout: 60000 });
  await page.waitForTimeout(400);
  return { page, errs };
}
// The price card follows the Panel's 1px sentinel; its text is the total, split into dollars and cents.
const total = page => page.evaluate(() => {
  const s = [...document.querySelectorAll('div[aria-hidden="true"]')].find(d => d.style.height === '1px');
  const card = s && s.nextElementSibling;
  const m = card && card.innerText.replace(/\s+/g, '').match(/\$([\d,]+)\.?(\d{2})/);
  return m ? Number(m[1].replace(/,/g, '') + '.' + m[2]) : null;
});
const text = page => page.evaluate(() => document.body.innerText);
// Pick an option by its words in whichever menu offers it (React reads the native setter + change).
const choose = (page, re) => page.evaluate(src => {
  const rx = new RegExp(src, 'i');
  const s = [...document.querySelectorAll('select')].find(x => [...x.options].some(o => rx.test(o.textContent)));
  if (!s) return false;
  const o = [...s.options].find(o => rx.test(o.textContent));
  Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(s, o.value);
  s.dispatchEvent(new Event('change', { bubbles: true }));
  return true;
}, re.source);
// Until art with bleed is uploaded the saddle charges "add bleeds" on every order — but not
// on a contract: its total is the listed price plus only what the customer chose.
const qtySelect = page => page.evaluate(() => {
  const s = [...document.querySelectorAll('select')].find(x => [...x.options].some(o => /booklets · \$/.test(o.textContent)));
  return s ? { value: s.value, options: [...s.options].map(o => o.textContent) } : null;
});

// ── 1. No contract: nothing changes ──
{
  const { page, errs } = await open({ defaults: DEFAULTS });
  const t = await text(page);
  ok(t.includes('Printing & Paper') && t.includes('Finishing & Addons'), 'without a contract the paper and finishing sections are there');
  ok((await qtySelect(page)) === null, 'without a contract the quantity is the usual box, not a price menu');
  ok(!t.includes('set specification'), 'and no contract card is shown');
  const tot = await total(page);
  ok(tot > 0 && tot !== PRICES[500], 'and the price is the formula\'s, not the list\'s', tot);
  ok(errs.length === 0, 'no script errors without a contract', errs);
  await page.close();
}

// ── 2. Contract ──
const CONTRACT = { name: 'Armadillo Home Warranty', lock: true, prices: PRICES, show: { inside: 'House-provided text weight paper', cover: 'House-provided cardstock' } };
{
  const { page, errs } = await open({ defaults: DEFAULTS, contract: CONTRACT });
  const q = await qtySelect(page);
  ok(q && q.options.length === 9 && q.value === '500', 'the quantity is a menu of the nine listed quantities, 500 chosen', q);
  ok(q && q.options[0] === '100 booklets · $150.43' && q.options[8] === '2,000 booklets · $1125.18', 'each with its listed price', q && q.options);
  ok((await total(page)) === 315.23, 'the total is exactly the listed price for 500', await total(page));
  const t = await text(page);
  ok(!t.includes('Printing & Paper') && !t.includes('Finishing & Addons'), 'the paper and finishing sections are gone');
  ok(t.includes('Armadillo Home Warranty booklet · set specification'.toUpperCase()) || t.includes('Armadillo Home Warranty booklet · set specification'), 'the spec is shown as a card');
  ok(/Size\s*6\.125×9\.25″/.test(t) && /Pages\s*8 pages/.test(t), 'with the product\'s size and page count', t.slice(0, 0));
  ok(!(await page.evaluate(() => [...document.querySelectorAll('select')].some(s => [...s.options].some(o => / Pages$/.test(o.textContent))))), 'there is no Pages control');
  ok(/Inside\s*House-provided text weight paper · Full Color/.test(t) && /Cover\s*House-provided cardstock · Full Color/.test(t) && !/80lb|Matte|Gloss|Uncoated|Cardstock\b(?! ·)/.test(t.replace(/House-provided cardstock/g, '')), 'the papers read as the house wording, and no stock name appears anywhere on the page', t.slice(t.indexOf('set specification') - 40, t.indexOf('set specification') + 260));
  // the regular Shipping & Delivery step is there, and rush is charged on top of the list price
  ok(t.includes('Shipping & Delivery'), 'the Shipping & Delivery step is there');
  await page.evaluate(() => {
    const s = [...document.querySelectorAll('select')].find(x => [...x.options].some(o => /booklets · \$/.test(o.textContent)));
    const set = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set; set.call(s, '100'); s.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await page.waitForTimeout(300);
  ok((await total(page)) === 150.43, 'choosing 100 charges the listed price for 100', await total(page));
  // a hardcopy proof is the customer's choice and is added on top
  const proofed = await page.evaluate(() => {
    const s = [...document.querySelectorAll('select')].find(x => [...x.options].some(o => /hardcopy/i.test(o.textContent)));
    if (!s) return 'no proof menu';
    const o = [...s.options].find(o => /hardcopy/i.test(o.textContent));
    const set = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set; set.call(s, o.value); s.dispatchEvent(new Event('change', { bubbles: true }));
    return 'ok';
  });
  await page.waitForTimeout(300);
  const withProof = await total(page);
  ok(proofed === 'ok' && withProof > 150.43, 'a hardcopy proof is added on top of the listed price', [proofed, withProof]);
  ok(errs.length === 0, 'no script errors with a contract', errs);
  await page.close();
}

// ── 2b. Shipping & Delivery: an address, then a date inside the rush window ──
{
  const { page, errs } = await open({ defaults: DEFAULTS, contract: CONTRACT });
  // open the step and fill a ship-to the way a customer does
  await page.getByText('Shipping & Delivery', { exact: false }).first().click();
  await page.waitForTimeout(300);
  const filled = await page.evaluate(() => {
    const set = (el, v) => { const d = Object.getOwnPropertyDescriptor(el.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype, 'value'); d.set.call(el, v); el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };
    const byAuto = a => document.querySelector(`[autocomplete~="${a}"]`);
    const st = [...document.querySelectorAll('select')].find(s => [...s.options].some(o => o.value === 'MO'));
    const zip = byAuto('postal-code');
    if (!st || !zip) return 'no state/zip field';
    set(st, 'MO'); set(zip, '65738');
    return 'ok';
  });
  await page.waitForTimeout(800);
  const t = await text(page);
  ok(filled === 'ok' && /Free delivery|delivery/i.test(t) && /UPS|Ground|transit|business day/i.test(t), 'a ship-to gives the delivery estimate, as on the regular calculator', filled);
  ok((await total(page)) === 315.23, 'a ship-to adds nothing: delivery is included in the listed price', await total(page));
  // open the delivery-date picker and take the first day shaded as rush (between earliest and free)
  // the picker opens on the free-delivery date; its trigger follows the "Select a specific date" label
  await page.locator('xpath=//*[normalize-space(text())="Select a specific date"]/following::div[contains(@style,"cursor: pointer")][1]').first().click();
  await page.waitForTimeout(300);
  const pickRush = () => page.evaluate(() => {
    const cells = [...document.querySelectorAll('div')].filter(d => /^\d{1,2}$/.test(d.textContent.trim()) && getComputedStyle(d).backgroundColor === 'rgb(254, 245, 224)');
    if (!cells.length) return 'no rush day';
    cells[0].click(); return 'picked ' + cells[0].textContent.trim();
  });
  // it opens on the month of the free date; the rush days may be in the month before
  let rushed = await pickRush();
  if (rushed === 'no rush day') { await page.getByRole('button', { name: '◀' }).first().click(); await page.waitForTimeout(250); rushed = await pickRush(); }
  await page.waitForTimeout(600);
  const rt = await total(page);
  // grandTotal = total × free ÷ days-left, so the rush share is a multiple of the listed price
  ok(/^picked/.test(rushed) && rt > 315.23, 'a rush date adds rush on top of the listed price', [rushed, rt]);
  ok(errs.length === 0, 'no script errors in Shipping & Delivery', errs);
  await page.close();
}

// ── 3. A link cannot change the locked spec ──
{
  const { page, errs } = await open({ defaults: DEFAULTS, contract: CONTRACT }, '?pages=24&qty=600&size=8.5%C3%9711%20(Opens%20to%2011%C3%9717)&covermode=same&color=bw');
  const t = await text(page);
  const q = await qtySelect(page);
  ok(q && q.value === '500', '600 copies from a link lands on the nearest listed quantity, 500', q && q.value);
  ok(/Pages\s*8 pages/.test(t) && /Size\s*6\.125×9\.25″/.test(t) && /Inside\s*[^\n]*Full Color/.test(t) && !/Cover\s*Same paper/.test(t), 'pages, size, colour and cover stay as the product sets them');
  ok((await total(page)) === 315.23, 'and the price is the listed one', await total(page));
  ok(errs.length === 0, 'no script errors from the link', errs);
  await page.close();
}

// ── 4. What the order carries (buildMetadata is the function Add to Order posts) ──
{
  const { page } = await open({ defaults: DEFAULTS, contract: CONTRACT });
  const src = html;
  ok(/contract:\s*result\.contract\s*\|\|\s*undefined/.test(src), 'the order metadata carries the contract line');
  ok(/push\("Pricing",\s*`\$\{result\.contract\.name\} price list/.test(src), 'the Job Ticket names the price list');
  const flat = src.replace(/\s+/g, '');
  ok(flat.includes('push("Inside",config.insidePaper&&config.insidePaper.label?`${ppsPaperWords("inside",') && flat.includes('returnshown||label;'), 'the ticket and cart summary name the paper in the house wording only, never the stock');
  ok(flat.includes('${coverMode==="same"?ppsPaperWords("inside",cvr.label):ppsPaperWords("cover",cvr.label)}'), 'and so does the copied price table');
  await page.close();
}

await browser.close(); srv.close();
console.log(fail ? `>>> ${fail} of ${n} checks FAILED` : `>>> CONTRACT PRICE OK (${n} checks)`);
process.exit(fail ? 1 : 0);

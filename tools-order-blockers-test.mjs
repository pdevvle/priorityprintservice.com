// Nothing else may claim a product the registry owns, and a refused checkout
// must not be silent.
//
// On 2026-09-15 a customer could not pay for a 9x9 booklet: WCPA's category-scoped
// forms also claimed a registry product, and its checkout validation refused the
// order with "Addon data missing for product Square Booklet Printing". She lost
// four days, paid for rush mailing, and wrote in — which is the only reason we
// found out. Everyone else who hit that wall simply left, and nothing recorded
// that they had tried.
//
// Two guards came out of it, and this pins both:
//
//   1. The registry decides, not the database. A get_post_metadata filter forces
//      wcpa_exclude_global_forms on for anything pps_get_calculator_for_product()
//      owns, so a product added to the registry tomorrow is covered the moment it
//      is added — no per-product tick to remember, and nothing to redo after a
//      database refresh from production.
//
//   2. A refused checkout on a calculator cart is recorded. We cannot enumerate
//      every plugin that might one day claim one of our products, but we can make
//      the next one announce itself instead of quietly costing orders.
//
// The tripwire runs on every checkout of a PPS product on a live store, so the
// checks below also pin the things that keep it from becoming the failure it
// exists to observe: it must be wrapped, bounded, and scoped to our own carts.
//
// Run: node tools-order-blockers-test.mjs

import { readFileSync } from 'node:fs';
import path from 'node:path';

const HERE = path.dirname(new URL(import.meta.url).pathname);
const php = readFileSync(path.join(HERE, 'pps-calculators.php'), 'utf8');

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

console.log('── the registry decides who owns a product ──');
{
  const m = php.match(/add_filter\(\s*'get_post_metadata',\s*function[\s\S]{0,700}?\}, 10, 4 \);/);
  const block = m ? m[0] : '';
  ok('the filter exists at all', !!block);
  ok('it only touches WCPA\'s exclusion key',
     /\$meta_key !== 'wcpa_exclude_global_forms'/.test(block));
  ok('it asks the registry, not the database',
     /pps_get_calculator_for_product\(\s*\$object_id\s*\)/.test(block));
  ok('a product the registry does NOT own is left alone — WCPA still works for its own',
     /if \( ! pps_get_calculator_for_product\(\s*\$object_id\s*\) \) return \$value;/.test(block));
  ok('and a registry product is forced excluded, in both single and array form',
     /return \$single \? '1' : array\( '1' \);/.test(block));
  ok('it is defensive about load order',
     /function_exists\(\s*'pps_get_calculator_for_product'\s*\)/.test(block));
}

console.log('\n── a refused checkout on a PPS cart is recorded ──');
{
  const i = php.indexOf("add_action( 'woocommerce_after_checkout_validation'");
  const block = i >= 0 ? php.slice(i, i + 2600) : '';
  ok('the tripwire is hooked', !!block);
  ok('it runs late, after everyone else has had their say',
     /\}, 99, 2 \);/.test(block));
  ok('it does nothing when checkout is fine',
     /get_error_codes\(\)\s*\)\s*return;/.test(block));
  ok('it only fires on carts that carry a calculator line',
     /pps_metadata'\]\s*\)\s*\|\|\s*isset\(\s*\$ci\['pps_price'\]/.test(block)
     && /if \( ! \$items \) return;/.test(block));
  ok('it records which products and why',
     /'products'\s*=>/.test(block) && /'errors'\s*=>/.test(block));
  ok('the log is bounded so it cannot grow without limit',
     /array_slice\(\s*\$log,\s*0,\s*30\s*\)/.test(block));
  ok('messages are stripped and length-capped',
     /wp_strip_all_tags/.test(block) && /mb_substr\([\s\S]{0,90}?300/.test(block));
  ok('it is autoload-off, so it never rides every page load',
     /update_option\(\s*'pps_checkout_refusals',[^;]*,\s*false\s*\)/.test(block));
  ok('and it CANNOT break the checkout it is watching',
     /try \{/.test(block) && /catch \( \\Throwable \$e \)/.test(block));
}

console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> ORDER BLOCKERS FAILED' : '>>> ORDER BLOCKERS OK');
process.exit(failed ? 1 : 0);

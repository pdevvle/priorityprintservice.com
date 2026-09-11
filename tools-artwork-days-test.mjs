// What each artwork option costs in turnaround days.
//
// "Artwork already discussed" was adding a day on the saddle calculator, for no
// reason anyone had decided: the human-touch rule was written as a contiguous
// band, `art.val > 0.015 && art.val < 0.045`, and 0.03 simply happened to sit
// between email-after (0.02) and Canva (0.04). Those two cost a day because the
// art is not here yet and someone has to chase it. "Already discussed" means we
// have it and have agreed it — there is nothing to wait for. (Owner, 2026-09-11.)
//
// The rule is now an explicit list, and this pins it, because the next person to
// add an artwork option will reach for a range again.
//
// This reads the shipped source rather than a model of it, so it fails if the
// calculator changes and this does not.
//
// Run: node tools-artwork-days-test.mjs

import { readFileSync, globSync } from 'node:fs';
import path from 'node:path';

const HERE = path.dirname(new URL(import.meta.url).pathname);

let checks = 0, failed = 0;
const ok = (label, cond, detail) => {
  checks++;
  if (cond) { console.log('PASS ' + label + (detail ? '  ' + detail : '')); return; }
  failed++;
  console.log('FAIL ' + label + (detail ? '\n       ' + detail : ''));
};

const ART = {
  0.01: 'Upload Art with Order',
  0.02: 'Email Art After Order',
  0.03: 'Artwork already discussed',
  0.04: 'I have a design in Canva',
  2.01: 'Artwork needs edits',
  4.01: 'Design from scratch',
};

// ── the saddle's human-touch day, evaluated out of the file ─────────────────
console.log('── the human-touch day (calc-preview-test) ──');
{
  const src = readFileSync(path.join(HERE, 'calc-preview-test.html'), 'utf8');

  const listM = src.match(/const ART_HUMAN_DAY = (\[[^\]]*\]);/);
  ok('the rule is an explicit list, not a numeric range', !!listM,
     listM ? listM[1] : 'no ART_HUMAN_DAY found — has it gone back to a band?');

  const exprM = src.match(/const humanArt\s*=\s*(.+?)\s*;\s*$/m);
  ok('and humanArt is computed from it', !!exprM && /ART_HUMAN_DAY/.test(exprM[1]),
     exprM ? exprM[1] : '');

  if (listM && exprM) {
    const ART_HUMAN_DAY = JSON.parse(listM[1]);
    const humanArtFor = val =>
      new Function('ART_HUMAN_DAY', 'art', 'return ' + exprM[1])(ART_HUMAN_DAY, { val });

    const expected = { 0.01: 0, 0.02: 1, 0.03: 0, 0.04: 1, 2.01: 0, 4.01: 0 };
    for (const [val, want] of Object.entries(expected)) {
      const got = humanArtFor(Number(val));
      ok(`${ART[val]} (${val}) costs ${want} human-touch day${want === 1 ? '' : 's'}`,
         got === want, 'got ' + got);
    }

    // The reported bug, stated as the thing that must not come back.
    ok('"already discussed" is not caught by being between 0.02 and 0.04',
       humanArtFor(0.03) === 0 && humanArtFor(0.02) === 1 && humanArtFor(0.04) === 1);

    // Config writes these as floats; 0.0300000001 must behave as 0.03.
    ok('float drift from admin config does not flip the answer',
       humanArtFor(0.030000000000000002) === 0 && humanArtFor(0.019999999999) === 1);
  }
}

// ── no other calculator charges a day for it either ─────────────────────────
console.log('\n── the flats and the other bound calculators ──');
{
  const files = globSync(path.join(HERE, 'calc-*.html'))
    .filter(f => path.basename(f) !== 'calc-preview-test.html')
    .filter(f => readFileSync(f, 'utf8').includes('ART_OPTS'))
    .sort();

  ok('there are other calculators to check', files.length >= 7, files.length + ' found');

  for (const f of files) {
    const name = path.basename(f, '.html');
    const src = readFileSync(f, 'utf8');

    // Flats: artDays is gated on the 4.0x "new design" band, so 0.03 is free.
    const flat = src.match(/const artDays = (.+?);/);
    if (flat) {
      const artDaysFor = val => new Function('art', 'return ' + flat[1])({ val });
      ok(name + ': "already discussed" adds no design days', artDaysFor(0.03) === 0,
         'got ' + artDaysFor(0.03));
      ok(name + ': and "design from scratch" still does', artDaysFor(4.01) > 0,
         'got ' + artDaysFor(4.01));
    }

    // Nothing outside the saddle grew a human-touch day while we were not looking.
    ok(name + ': carries no human-touch day of its own', !/humanArt/.test(src));
  }
}

// ── the default config still puts it in the free band ───────────────────────
console.log('\n── the shipped default for the option itself ──');
{
  const php = readFileSync(path.join(HERE, 'pps-config-admin.php'), 'utf8');
  const row = php.match(/'label' => 'Artwork already discussed',\s*'val' => ([\d.]+),\s*'price' => ([\d.]+)/);
  ok('the default row is still 0.0x (free band) and $0', !!row && Number(row[1]) < 1 && Number(row[2]) === 0,
     row ? 'val ' + row[1] + ', price ' + row[2] : 'row not found');
  // The bands are a convention the admin screen documents; a val of 2.x here
  // would silently bill the edit rate as well as adding edit days.
  ok('so it cannot fall into the 2.0x edits band', !!row && Number(row[1]) < 2,
     row ? 'val ' + row[1] : '');
}

console.log('\n' + checks + ' checks, ' + failed + ' failed');
console.log(failed ? '>>> ARTWORK DAYS FAILED' : '>>> ARTWORK DAYS OK');
process.exit(failed ? 1 : 0);

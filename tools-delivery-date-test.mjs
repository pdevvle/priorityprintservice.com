// Delivery-date regression: the date recorded on the order must be the date the
// customer was shown, and must never be a day the shop is closed.
//
// Order 87105 (placed Sunday 2026-08-30) carried a Sunday delivery date. The
// calculator's own arithmetic cannot produce one — addBusinessDays skips weekends
// and the DatePicker disables them — but `estimatedDeliveryDate` bypassed all of
// that and shipped the raw contents of the date field to the server, which now
// trusts that field over its own recompute.
//
// This extracts isBusinessDay + quotedDeliveryYMD straight out of each calculator
// HTML and runs them, so the test fails if the shipped code drifts from the intent.

import { readFileSync } from "node:fs";

const FILES = ["calc-preview-test","calc-perfect-bound","calc-coupon-book","calc-brochure",
               "calc-postcard","calc-letterhead","calc-greeting-card","calc-sticker"];

// Take a function declaration whole, by matching braces from its opening one, so the
// test does not depend on whatever happens to follow it in the file.
function extract(src, startMarker) {
  const a = src.indexOf(startMarker);
  if (a < 0) throw new Error("missing " + startMarker);
  let depth = 0, i = a + startMarker.length - 1;
  for (; i < src.length; i++) {
    if (src[i] === "{") depth++;
    else if (src[i] === "}" && --depth === 0) return src.slice(a, i + 1);
  }
  throw new Error("unbalanced braces after " + startMarker);
}

let checks = 0, failed = 0;
const eq = (label, got, want) => {
  checks++;
  if (got !== want) { failed++; console.error(`  FAIL ${label}: got ${JSON.stringify(got)} want ${JSON.stringify(want)}`); }
};

for (const name of FILES) {
  const src = readFileSync(`${name}.html`, "utf8");

  const helper = extract(src, "function quotedDeliveryYMD(needByDate, sh) {");
  const bizday = extract(src, "function isBusinessDay(d) {");

  // The closure list is config-driven in the shipped file; the arithmetic under test
  // is the weekday rule, so an empty closure list isolates it.
  const fn = new Function(`
    const SHOP_CLOSURES = ["01-01","07-04","12-25"];
    ${bizday}
    ${helper}
    return { isBusinessDay, quotedDeliveryYMD };
  `)();

  const { isBusinessDay, quotedDeliveryYMD } = fn;
  const day = ymd => new Date(ymd + "T00:00:00").toLocaleDateString("en-US", { weekday: "long" });

  console.log(`\n${name}`);

  // Sanity on the weekday rule itself.
  eq("Sun 2026-08-30 is not a business day", isBusinessDay(new Date("2026-08-30T00:00:00")), false);
  eq("Sat 2026-08-29 is not a business day", isBusinessDay(new Date("2026-08-29T00:00:00")), false);
  eq("Mon 2026-08-31 is a business day",     isBusinessDay(new Date("2026-08-31T00:00:00")), true);
  eq("New Year's Day is a closure",          isBusinessDay(new Date("2027-01-01T00:00:00")), false);

  const base = { hasDest: true, freeDeliveryYMD: "2026-09-09", earliestYMD: "2026-09-03",
                 needByFmt: null, rushCost: 0 };

  // The reported defect: a weekend value in the field must not reach the order.
  eq("Sunday in the field is refused",
     quotedDeliveryYMD("2026-08-30", { ...base, needByFmt: "Sun, Aug 30" }), "2026-09-09");
  eq("Saturday in the field is refused",
     quotedDeliveryYMD("2026-08-29", { ...base, needByFmt: "Sat, Aug 29" }), "2026-09-09");
  eq("a closure day in the field is refused",
     quotedDeliveryYMD("2027-01-01", { ...base, needByFmt: "Fri, Jan 1" }), "2026-09-09");

  // A date the customer picked and that the engine accepted still wins.
  eq("a valid picked weekday is honoured",
     quotedDeliveryYMD("2026-09-04", { ...base, needByFmt: "Fri, Sep 4" }), "2026-09-04");

  // An invalid pick (too soon / in the past) leaves needByFmt null, so the panel shows
  // the free date. The order must record the same thing, not the rejected pick.
  eq("a rejected pick does not reach the order",
     quotedDeliveryYMD("2026-08-31", { ...base, needByFmt: null }), "2026-09-09");

  // No destination yet: the panel quotes the earliest-possible placeholder.
  eq("no destination quotes the earliest date",
     quotedDeliveryYMD("", { ...base, hasDest: false }), "2026-09-03");

  // Rush: the picked date is the commitment.
  eq("rush honours the picked date",
     quotedDeliveryYMD("2026-09-04", { ...base, rushCost: 120, needByFmt: "Fri, Sep 4" }), "2026-09-04");

  // Garbage in the field must not become an order date.
  eq("junk in the field is refused",  quotedDeliveryYMD("not-a-date", { ...base, needByFmt: "x" }), "2026-09-09");
  eq("empty field falls back to free", quotedDeliveryYMD("", base), "2026-09-09");

  // Whatever comes out, it is always a working day.
  for (const nb of ["2026-08-30","2026-08-29","2027-01-01","2026-09-04","","not-a-date"]) {
    for (const sh of [base, { ...base, needByFmt: "y" }, { ...base, hasDest: false }]) {
      const out = quotedDeliveryYMD(nb, sh);
      checks++;
      if (!out || !isBusinessDay(new Date(out + "T00:00:00"))) {
        failed++;
        console.error(`  FAIL invariant: needBy=${nb} -> ${out} (${out && day(out)})`);
      }
    }
  }
}

console.log(`\n${checks} checks, ${failed} failed`);
process.exit(failed ? 1 : 0);

# The lowest achievable price — where it belongs, and where it must not go

Two questions, and they have opposite answers.

| | Can it be the price floor? |
|---|---|
| "From $X" on shop cards, category pages, llms.txt, ad copy | **Yes** — that is exactly what a floor is for |
| Schema `AggregateOffer.lowPrice` | **Yes**, but only if the offer also carries a definite price matching the feed |
| **`g:price` in the Merchant Center feed** | **No.** This one will get items disapproved |
| The WooCommerce price | **Only** with "From" in the display, and only if the feed keeps sending the page price |

---

## 1 · Why the floor cannot be `g:price`

Google requires the advertised price to be **the price of the item as the landing
page presents it**. Not a starting price, not a price reachable by reconfiguring.

Concretely, if the feed says $24.50 (qty 1, smallest trim, cheapest stock) and the
page opens at its defaults quoting $109.04:

- The structured-data crawl reads $109.04 and the feed says $24.50 → **item
  disapproved for price mismatch**.
- A customer clicks a $24.50 ad and lands on $109.04 → that is the experience the
  price-accuracy rules exist to prevent, and repeated it becomes a
  Misrepresentation problem rather than a data one.

So the floor makes the *ad* look better and the *account* worse. `g:price` stays
pinned to `pps_product_price_facts()['effective']` — the page's own number.

### If you want the advertised price to be low, lower the page

There is a legitimate way to get a cheap Shopping price, and it is not a feed
trick: **set the product's default configuration to the cheap one.** Then the
defaults price, the Woo price, the schema price and `g:price` are all the same
number, and it is genuinely what a customer sees on arrival.

The cost is a landing page that opens at qty 1 on the smallest trim, which
converts worse than one opening at a realistic job. That is a real trade, and it
is a product decision rather than a technical one — but it is the only version
that gets a low advertised price without lying.

A middle option most print shops take: defaults set to the cheapest *realistic*
configuration — smallest trim, cheapest stock, but a normal quantity. Advertised
price drops, the page still reads like a real product, and everything agrees.

---

## 2 · Where the floor genuinely helps

None of these carry a price-match obligation:

- **"From $X" on category and shop cards.** Today those cards show the defaults
  price with no qualifier, which reads as *the* price.
- **`price_from` on presets** — the field already exists and is mostly empty.
- **llms.txt** — now emits both, labelled: `From: $X (lowest configuration)` and
  `This page as configured: $Y`. Calling the defaults price "from" was wrong in
  both directions and is fixed.
- **Schema `lowPrice`**, *if* the offer block is restructured so a definite price
  is also present. Today `lowPrice` carries the defaults price, which is not a
  low price — but changing it to the true floor without adding a matching
  definite price would re-create the exact mismatch §1 describes. Leave it until
  the offer block is dealt with properly.

---

## 3 · Yes, it can be automated

### Why PHP cannot do it

The pricing engine is JavaScript sealed inside an IIFE in each calculator, and
`pps-calculators.php` deliberately contains no pricing logic (CLAUDE.md). PHP has
no way to evaluate a quote.

### What does work — and already existed

`tools-pricing-matrix.mjs` drives the real UI with Playwright and records 1,816
quoted prices read from the rendered output. That is the same machinery, already
proven. `tools-min-price.mjs` is the minimisation version of it.

```
node tools-min-price.mjs --config live-config.json
```

**Greedy coordinate descent**, not exhaustive search: minimise one axis at a
time, repeat until a pass changes nothing. The quantity ladder gives eight price
points per render for free, so the cheapest rung comes at no extra cost.

**The safety property that makes a greedy search acceptable here:** every number
it reports is a real quote the engine produced for a real configuration. So the
result can only ever be ≥ the true minimum. A "from $X" built on it is always
achievable. The failure mode is being conservative — never overstating what a
customer can actually get.

**What it measures, precisely: the lowest *promoted* price.** The floor comes
from the cheapest rung of the rendered quantity ladder, which holds the
quantities the calculator itself puts forward. A customer typing a smaller
quantity than the ladder offers can go lower. For a "from" figure that is
arguably the right number anyway — the cheapest realistic order rather than a
degenerate quantity-of-one edge case that no print shop wants to advertise — but
it is not the absolute theoretical minimum, and the output records that.

Verified on one calculator against the embedded fallback constants: the sweep
completes, converges, and reports a real quote with the configuration that
produced it. The numbers themselves are not in this repo — see below.

### The output never gets committed

CLAUDE.md: *"treat every branch as public: don't commit pricing figures,
strategy, or credentials anywhere in the repo."* Measured floors are pricing
figures. `docs/min-prices.json`, `min-prices.json` and the sweep's scratch HTML
are all gitignored; the numbers live in `wp_options['pps_min_prices']` and
nowhere else.

### The two things that will bite

**Run it against live config or the numbers are fiction.** Without `--config`
the sweep measures the fallback constants embedded in the HTML, not what
production charges — the owner tunes pricing in Central Config, injected at
runtime as `PPS_CONFIG.calc`. Pass the output of `pps_get_public_config()`,
which is already public by construction (it is injected into every product page)
so there is no credential exposure. The tool refuses to call its output
authoritative without it.

**A stored floor goes stale silently.** Change a paper cost in Central Config and
every published "from $X" is instantly wrong, with nothing to notice. So:

- `pps_save_min_prices()` stamps the sweep with `pps_pricing_fingerprint()`
- `pps_min_prices_are_stale()` re-computes and compares
- `pps_calc_min_price()` returns **null** when they differ

Publishing nothing beats publishing a price the engine no longer produces. The
fingerprint is computed PHP-side at both import and read, never against the
tool's own hash — comparing across languages would differ on key order and
escaping alone and report every sweep as stale.

### It is per-calculator, not per-product

`PPS_CONFIG.defaults` pre-fills the form; it does not restrict it. A customer on
any product page can drive the calculator down to the same floor. So this is
eight numbers, not thirty-four — which is what makes re-running it after every
pricing change tractable.

---

## 4 · What is built, and what is left

Built and tested:

- `tools-min-price.mjs` — the sweep, with `--config` injection and a fingerprint
- `pps_min_prices()` / `pps_save_min_prices()` / `pps_min_prices_are_stale()` /
  `pps_calc_min_price()` — storage, staleness guard, reader
- llms.txt emits floor and defaults price as two labelled figures
- `/pps-product-feed.xml?debug=1` shows the floors for contrast, and says plainly
  that `g:price` is not one of them

Not done, deliberately:

- **`g:price` unchanged.** §1.
- **The offer block untouched.** It is `AggregateOffer` with `offerCount: 1` and
  a hardcoded `highPrice: 5000` — internally contradictory, since one offer
  cannot span a range. Restructuring it to `Offer` + `price` is the right fix and
  would let `lowPrice` carry a true floor elsewhere, but it changes live schema
  output and wants a deliberate decision.
- **The `'50'` fallback still ships.** `pps_product_defaults_low_price()` returns
  `'50'` for any product with no "Price at these defaults", so those pages are
  telling Google their price is $50 right now. Once a sweep is imported, that
  fallback should become the measured floor for the product's calculator — a
  strictly better wrong answer, and usually a right one. Left out of this change
  because it also touches live schema.

### Running order

1. Fetch `pps_get_public_config()` from production, save as JSON.
2. `node tools-min-price.mjs --config that.json` (~30 min for all eight).
3. Import the result to `pps_min_prices` — it stamps the fingerprint on save.
4. Re-run after **any** Central Config pricing change. Until then everything
   downstream returns null rather than guessing.

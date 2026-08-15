# Replacing the 4over scraper and the variable products

Read of the Circle Business Cards test capture, and what it implies for the
build. Nothing here is committed to yet — the last section is what would change
the design.

---

## 1 · What the test capture actually proves

### 81 rows carry 19 numbers

You already found that colorspec doesn't move the price, collapsing 81 → 27.
The data shows a second collapse on top of it:

| Run | UV | UV front only | Aqueous | distinct |
|---|---|---|---|---|
| 250 | 7.56 | 7.56 | 7.56 | 1 |
| 500 | 15.12 | 15.12 | 15.12 | 1 |
| 1,000 | 19.65 | 19.65 | 19.65 | 1 |
| 2,500 | 42.35 | 45.36 | 42.35 | 2 |
| 5,000 | 58.97 | 58.97 | 57.46 | 2 |
| 10,000 | 96.78 | 99.80 | 93.77 | 3 |
| 15,000 | 140.64 | 146.68 | 139.13 | 3 |
| 20,000 | 186.00 | 192.05 | 182.99 | 3 |
| 25,000 | 231.37 | 237.42 | 222.31 | 3 |

**Coating is free below 2,500.** Nine of the twenty-seven cells are duplicates.
The whole product is nineteen numbers.

That matters for capture cost, not storage — you still have to *probe* a cell to
learn it's a duplicate. But it means the stored matrix is tiny, and a refresh can
spot-check rather than re-walk everything.

### Coating cannot be a percentage

`UVFR / UV` ranges 1.0000 – 1.0711. `AQ / UV` ranges 0.9608 – 1.0000. Neither is
a constant, and neither trends smoothly.

**So there is no formula to reverse-engineer. The matrix is the model.** Any
attempt to store "UV price + coating multiplier" will be wrong at most run
sizes. Store the cells.

### Unit price falls monotonically in all three coatings

Good sign — no gross capture errors. `per_unit` is just `total / qty` rounded to
4dp, so it is derived and redundant. Store totals only and compute the unit for
display.

---

## 2 · Two cells I would re-capture before trusting the set

Both are non-monotonic in a way hand-maintained price tables usually aren't.

**UV-front-only premium over UV:**

```
   250      500     1,000    2,500    5,000   10,000   15,000   20,000   25,000
 +0.00    +0.00    +0.00    +3.01    +0.00   +3.02    +6.04    +6.05    +6.05
                                      ↑ zero, between two rows that both charge ~$3
```

**Aqueous discount vs UV:**

```
 +0.00    +0.00    +0.00    +0.00    -1.51    -3.01    -1.51    -3.01    -9.06
                                                        ↑ discount shrinks, then grows again
```

Either 4over's table genuinely has these quirks — entirely possible, these are
hand-maintained — or two cells were read a beat early.

**That second possibility is the thing to design against.** If the configurator
updates its price by AJAX, a capture that reads before the update lands returns
*the previous selection's price*. The signature of that bug is exactly this: a
cell that equals its neighbour when its neighbours don't equal each other.

So the capture procedure needs an explicit settle condition — change a control,
wait for the price element to actually change (or for a known idle signal),
*then* read. Not a fixed sleep. And a second pass over a random 10% that must
reproduce the first pass exactly, or the run is rejected.

---

## 3 · A missing dimension: turnaround

The capture notes 4 Business Days is the only selectable option, while 7
Business Days appears in the Specs tab with no control.

**At trade printers turnaround is normally a price dimension**, and often a
large one. Two readings, and they have very different consequences:

- The 7-day option is genuinely unavailable for this product → fine, one value,
  no axis.
- It is selectable once something else is chosen, or via a different entry point
  → **the matrix is missing an axis** and every number in it is the 4-day price.

Worth resolving on the next capture before scaling to sixty products, because
discovering it later invalidates every matrix captured until then.

---

## 4 · The shape of the replacement

Three layers, deliberately separate so the fragile one can fail without taking
the others down.

```
  CAPTURE                 STORE                    SERVE
  Claude in Chrome   →    cost matrices       →    a calculator matching
  scheduled, per          in wp_options,           the in-house eight
  product                 dated + versioned        (markup applied here)
```

**Capture** produces exactly the JSON you already produced. It never touches the
live site.

**Store** holds cost matrices keyed by product, each stamped with its capture
date and the option set it was captured against. Nothing reads a matrix without
also seeing how old it is.

**Serve** is a ninth calculator sharing the existing chrome — `Sel`, `Pil`,
`Panel`, `RichTip`, the artwork and proof flow. From the customer's side it is
indistinguishable from the in-house ones. From the code's side its `calculate()`
is a lookup plus markup instead of a press-sheet model.

### The matrix defines the form — so there is exactly one new calculator file

This is the consequence of "it acts as the matrix acts", and it is worth being
deliberate about because it decides how much work every future product costs.

The eight in-house calculators each hardcode their own option lists, because
each models a different press process. A matrix-driven calculator does not need
to: **the form is generated from the matrix's own dimensions.** A matrix with
three coatings and nine run sizes renders three coating pills and nine run
buttons, because those are the values that exist.

So:

- **One file** — `calc-4over.html` — serves every 4over product.
- A product differs from its neighbours only by which matrix it points at, which
  is already exactly how `_pps_defaults` and the registry work.
- **Adding a 4over product later costs no code at all**: capture a matrix, spawn
  a product (`docs/CREATING_A_PRODUCT.md`), point it at the matrix.

It also makes an impossible quote impossible. A lookup cannot return a
configuration 4over does not sell, because there is no cell for it — no
validation rules to write, no combination to forget.

### What comes free, and what is actually new

| Inherited unchanged from the in-house calculators | Genuinely new |
|---|---|
| `Sel` / `Pil` / `Sec` form controls and all the styling | `calculate()` → a matrix lookup |
| `Panel`, the quantity ladder, the debug panel | The markup variable |
| The proof / preview modal, `FitToggle`, approval package | Rendering controls from matrix dimensions |
| Artwork upload to Drive, the whole prepress flow | Matrix storage, ingest and staleness |
| Add-to-cart, `pps_metadata`, PPS-Spec, reorder | |

The proofer is the biggest single thing carried over, and it carries over
whole — it operates on the customer's uploaded file and the trim size, neither
of which cares where the price came from.

### Markup: one variable, never shown to the customer

The customer sees a price. Staff see the decomposition.

A single percentage is the right starting shape — global, with a per-product
override for anything where 4over's own margin is unusual. Put the cost, the
markup and the resulting price in the **debug panel**, which is already
staff-only, so a quote can always be reconciled against what 4over will invoice.

Never render the cost anywhere a customer can reach, including in `pps_metadata`
on the order — that is visible in enough places that it will eventually leak.

### Turnaround: settled, and simpler than the in-house engine

4over drop-ships. The delivery date is **stated, not computed** (owner, 2026-08-15):

```
delivery = production turnaround        (captured, per product — e.g. 4 business days)
         + 1 business day               (PPS handling)
         + 3 business days              (4over's maximum transit)
```

Circle Business Cards → 4 + 1 + 3 = **8 business days**.

Three consequences, all of them simplifications:

- **The zone map and UPS transit lookup are not used.** Delivery does not depend
  on destination, so none of that machinery applies here.
- **The rush engine does not apply either.** You cannot compress 4over's
  schedule from our side. If they sell a faster turnaround it is a *captured
  axis with its own prices*, never a multiplier over a base — same reasoning as
  coating in §1.
- **Production turnaround becomes a captured field**, per product. The Circle
  capture already found it (4 Business Days) and flagged that a 7-day option
  appears in the Specs tab with no control — see §3, still worth resolving,
  because if 7-day is selectable elsewhere it is an axis and every price
  captured so far is the 4-day price.

Reuse `pps_add_business_days()` and the existing 2pm cutoff rather than counting
days independently, so a 4over product's date behaves like every other date on
the site.

### The open money question: does the captured price include freight?

This is the one thing left that can lose money quietly.

4over is a wholesale printer; their product pages quote **product cost**, and
freight is normally calculated at checkout against the destination. If that
holds, every number in the captured matrix excludes shipping — and at low run
sizes freight will *exceed* the product cost. The 250-unit cell is $7.56.
Ground freight on a small parcel is plausibly twice that.

So a markup applied to product cost alone would price below landed cost on every
small order, and the shortfall would be largest on the cheapest, most numerous
jobs. Exactly the shape of error nobody notices for months.

Three ways out, and the choice is a business one:

| | How it works | Risk |
|---|---|---|
| **Flat freight per order** | Add a fixed figure to the cost side before markup | Wrong on outliers; simple; matches the "3 business days maximum" framing already chosen |
| **Capture 4over's freight table** | Another matrix, keyed by destination | Destination-dependent, so much bigger and far more fragile |
| **Absorb it in the markup** | Markup high enough to cover average freight | Loses money on small orders, over-charges large ones — the worst of the three |

**Verify before choosing.** Put one real order through 4over's checkout to the
point where freight is shown, and compare against the captured cell. That single
observation decides this, and it costs nothing.

### The one genuinely new problem: these are costs, not prices

Every number above is what *you pay 4over*. The in-house calculators compute
retail from a cost basis and a markup curve; this one needs the same treatment,
and the markup policy is a business decision rather than a technical one — flat
percentage, per-tier, or a curve that mirrors what the in-house calculators do
so a customer sees consistent pricing across your catalogue.

I'd argue for the last: if a customer prices booklets and business cards on the
same site, two visibly different margin shapes is something they can feel.

### Quantity: offer 4over's tiers, not arbitrary numbers

The in-house calculators let people type any quantity. Don't here. You cannot
order 1,750 circle cards from 4over, so selling 1,750 means either eating the
2,500 cost or a manual intervention on every order. Offer the nine tiers as
buttons. It is also less code and cannot mis-interpolate.

---

## 5 · The risk that actually matters

Not scraper fragility. **Staleness — quoting from a matrix after 4over raised
their prices means selling below cost, silently, on every order until someone
notices.**

The SEO work in this repo fails visibly. This fails invisibly and costs money per
transaction. So the store layer needs, from day one:

- a capture date on every matrix
- a maximum age, beyond which the calculator refuses to quote and says so rather
  than quoting stale
- an admin view showing every product's matrix age at a glance
- a per-cell spot-check on refresh: re-probe a handful, compare, and if any
  moved, treat the whole matrix as invalid and re-capture in full

That last one is what turns a fragile scraper into a system you can trust — a
price change anywhere in a product is detected by checking three cells, not
twenty-seven.

---

## 6 · What would change this design

Three things, in order of how much they'd change it.

**1. Does 4over expose an API to resellers?** Worth checking your account before
building any of this. Trade printers commonly offer one, and if pricing is
available that way, the entire capture layer disappears — you'd query costs at
quote time instead of maintaining a matrix, and staleness stops being a risk at
all. This is the single highest-leverage question here and it is a five-minute
answer from your 4over account rep.

**2. How many products?** Circle cards is one. The capture cost is ~27 probes
per product. Sixty products is 1,620 interactions per full refresh — schedulable,
but it shapes how often a refresh is realistic and whether the spot-check
approach in §5 is essential or merely nice. I'd need the list of what you
actually resell.

**3. Which WooCommerce variable products are being replaced?** Same care as the
PPS registry: a product that moves to the new calculator must leave the variable
system entirely, or the two will fight over the cart. That is the same class of
problem as the WCPA coexistence rule already in CLAUDE.md.

---

## 7 · If it proceeds, the order I'd build in

1. **Answer the API question.** It may delete most of this document.
2. **Re-capture Circle cards** with a settle condition and a 10% verification
   pass, and resolve the turnaround question. One product, done properly, is
   the template for everything after.
3. **Store + admin view** — matrices, dates, ages. Boring and load-bearing.
4. **The calculator**, cloned from the closest in-house one so it inherits the
   chrome, the shipping engine and the artwork flow for free.
5. **Markup policy**, wired as config rather than baked in.
6. **Migrate one product** end to end, place a real order through it, reconcile
   against what 4over actually charges.
7. Then the rest, in batches.

Nothing here is worth starting before step 1.

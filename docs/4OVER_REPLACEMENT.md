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

### Markup: 100.10%, plus shipping at cost

Owner, 2026-08-15:

```
retail = 4over cost x 2.0010  +  ship(cost)
```

Read as a **markup of** 100.10%, not a retail equal to 100.10% of cost — the
latter is a 0.1% margin and self-evidently not the intent ($7.56 cost → $7.57
retail). The trailing `.10` is worth keeping but is not doing much work: against
a flat 2.0000 it is 23¢ on the largest order in the Circle matrix.

Applied to Circle Business Cards (UV):

| Run | Cost | Marked up | Ship | **Retail** | Per card | Gross $ | Margin |
|---|---|---|---|---|---|---|---|
| 250 | 7.56 | 15.13 | 10.00 | **25.13** | 0.1005 | 7.57 | 30.1% |
| 500 | 15.12 | 30.26 | 15.12 | **45.38** | 0.0908 | 15.14 | 33.4% |
| 1,000 | 19.65 | 39.32 | 19.65 | **58.97** | 0.0590 | 19.67 | 33.4% |
| 2,500 | 42.35 | 84.74 | 20.00 | **104.74** | 0.0419 | 42.39 | 40.5% |
| 5,000 | 58.97 | 118.00 | 20.00 | **138.00** | 0.0276 | 59.03 | 42.8% |
| 10,000 | 96.78 | 193.66 | 20.00 | **213.66** | 0.0214 | 96.88 | 45.3% |
| 15,000 | 140.64 | 281.42 | 21.10 | **302.52** | 0.0202 | 140.78 | 46.5% |
| 20,000 | 186.00 | 372.19 | 27.90 | **400.09** | 0.0200 | 186.19 | 46.5% |
| 25,000 | 231.37 | 462.97 | 34.71 | **497.68** | 0.0199 | 231.60 | 46.5% |

Retail is a **delivered** price — shipping is inside it, so there is no separate
charge at checkout. Worth being deliberate about downstream: in the Merchant
Center feed that means `g:price` is the all-in figure and `g:shipping` is zero,
which is consistent and common, but it has to be set that way rather than
inherited.

### Two consequences of "+ shipping" being outside the markup

**1. Margin is lowest on your smallest orders — 30.1% at 250 against 46.5% at
25,000.** Shipping passes through at cost, and it is a much larger share of a
small order's retail, so it dilutes the margin exactly where the order is
smallest. That is the opposite of the usual instinct, and the opposite of what
per-order handling effort would suggest. It may well be what you want as a
loss-leader shape; it is worth having chosen rather than inherited. Marking
shipping up too, or a small-order handling fee, would flatten it.

**2. Nothing cushions a shipping under-estimate.** Every dollar the estimate is
short comes straight off profit. And the exposure has two different shapes at
the two ends:

| | Gross profit | Ship est | What a $10 miss costs |
|---|---|---|---|
| 250 | $7.57 | $10.00 | **132% of the margin — the order goes negative** |
| 1,000 | $19.67 | $19.65 | 51% |
| 10,000 | $96.88 | $20.00 | 10% |
| 25,000 | $231.60 | $34.71 | 4% |

So the **bottom of the range is fragile** — shipping is 132% of gross profit at
250, so a modest miss wipes the order out — while the **top is exposed**, where
the misses are large in absolute dollars but land against a fat margin.

That is not a reason to change the formula. It is the reason the Shippo
validation in the next section is worth doing at *both* ends rather than only
the tail, which is what I said before this markup was known.

### Never show the cost

The customer sees a price. Staff see the decomposition — cost, markup, shipping
— in the **debug panel**, which is already staff-only, so a quote can always be
reconciled against what 4over will invoice.

Never render the cost anywhere a customer can reach, including in `pps_metadata`
on the order; that is visible in enough places that it will eventually leak.

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

### Settled: a shipping floor, not a captured freight table

Owner, 2026-08-15. Stated as bands, on the **4over cost** `C` (not the retail
price — using retail would make shipping depend on markup which depends on
shipping):

| Band | Rule |
|---|---|
| Floor | never below **$10** |
| Low | **1 × C**, while that is under $20 |
| Mid | flat **$20** |
| High | **15% of C**, once 15% exceeds $20 (i.e. `C > $133.33`) |

**It reduces to one line, with no branches:**

```
ship(C) = max( 10, min( C, 20 ), 0.15C )
```

Verified identical to the piecewise reading at every cent from $0.01 to $1,000,
and **continuous at all three boundaries** — $10, $20 and $133.33 each hand over
without a jump, so there is no quantity at which a customer sees shipping lurch.
That is a good property and it was not accidental.

Applied to Circle Business Cards (UV):

| Run | 4over cost | Ship est | Landed | Band |
|---|---|---|---|---|
| 250 | 7.56 | 10.00 | 17.56 | **floor** |
| 500 | 15.12 | 15.12 | 30.24 | 1 × cost |
| 1,000 | 19.65 | 19.65 | 39.30 | 1 × cost |
| 2,500 | 42.35 | 20.00 | 62.35 | cap |
| 5,000 | 58.97 | 20.00 | 78.97 | cap |
| 10,000 | 96.78 | 20.00 | 116.78 | cap |
| 15,000 | 140.64 | 21.10 | 161.74 | 15% |
| 20,000 | 186.00 | 27.90 | 213.90 | 15% |
| 25,000 | 231.37 | 34.71 | 266.08 | 15% |

The multiplier was corrected from 2× to 1× on 2026-08-15. **Only the bottom
three runs moved** — 250 by −$5.12, 500 by −$4.88, 1,000 by −$0.35 — because
the $20 cap and the 15% band never involved the multiplier at all. Every run of
2,500 and up is unchanged.

**The $10 floor now binds at 250**, where before it never did on this product.
That matters: at ~1.2 lb the floor is close to a real ground rate rather than a
cushion over it, so it is doing genuine work rather than sitting unused.

### The one thing to validate, and it is the top of the range

Freight is a function of **weight**. This estimator is a function of **price**.
Those move together only as long as price tracks weight — and a volume discount
is precisely the thing that breaks that.

From your own numbers, 250 → 25,000:

- quantity ×100, so **weight ×100**
- cost ×30.6, because 4over discounts the unit from $0.0302 to $0.0093
- so the estimate per 1,000 units falls from **$60.48 to $1.39** — a 44× drop,
  while the parcel gets 100× heavier

The carrier discounts nothing. So the estimator necessarily over-collects at the
bottom and thins toward the top, and the 15% band — the one covering your
largest orders — is where a shortfall would land.

I have no freight data, so I am not claiming it *is* short; I am saying that is
the only place it structurally can be. **Three probes settle it:** take a 250, a
5,000 and a 25,000 through 4over's checkout far enough to see the freight
figure, and compare against the table above. If the top is short, the fix is a
steeper high band rather than anything structural.

Business cards are the worst case for this and therefore the right thing to test
with — they are dense, so they carry the most weight per dollar of any format
4over sells. An estimator that holds for cards holds for posters and brochures;
one validated on brochures will under-collect on cards.

### Weight is computable for cards — which settles the question cheaply

Owner, 2026-08-15: for business cards the weight and package count are easy to
work out. That is the missing input, and it means the bands can be checked
without touching 4over's checkout at all.

Anchoring on a standard 3.5×2″ card at 14PT C2S ≈ 2.27 g (500 ≈ 2.5 lb) and
scaling by area, a 2″ round on the same stock is **≈ 1.02 g**. One constant, and
it is corrected by weighing a single real box.

| Run | Cards | + packaging | Boxes | Ship est | Estimate per lb |
|---|---|---|---|---|---|
| 250 | 0.56 lb | 1.2 lb | 1 | 10.00 | 8.14 |
| 500 | 1.12 lb | 1.9 lb | 1 | 15.12 | 8.14 |
| 1,000 | 2.25 lb | 3.1 lb | 1 | 19.65 | 6.31 |
| 2,500 | 5.62 lb | 6.9 lb | 1 | 20.00 | 2.90 |
| 5,000 | 11.23 lb | 13.2 lb | 1 | 20.00 | 1.52 |
| 10,000 | 22.46 lb | 25.8 lb | 1 | 20.00 | **0.78** |
| 15,000 | 33.69 lb | 38.9 lb | 2 | 21.10 | **0.54** |
| 20,000 | 44.92 lb | 51.5 lb | 2 | 27.90 | **0.54** |
| 25,000 | 56.15 lb | 64.1 lb | 3 | 34.71 | **0.54** |

*(12% packaging, ~0.6 lb per carton, cartons assumed ~30 lb — all easy to
correct with one real shipment.)*

The last column is the whole story. **The estimate is collecting 54¢ per pound
at the top of the range**, on a shipment that by then is three separate parcels
— each of which carries its own base charge before a single pound is priced.

Note also that the per-pound figure flattens at $0.54 from 15,000 up. That is
the 15% band being *proportional to cost*, while cost per unit has already gone
flat ($0.0093 at 15,000, 20,000 and 25,000). Once 4over stops discounting, a
percentage-of-cost estimator stops growing too — but the parcel keeps getting
heavier.

### Settle it in Shippo, not at 4over's checkout

You already have Shippo and now you have weights. Quote a CA origin to a few
representative destinations:

| Quote | Against |
|---|---|
| ~1.2 lb, 1 parcel | $10.00 |
| ~13.2 lb, 1 parcel | $20.00 |
| ~25.8 lb, 1 parcel | $20.00 |
| ~64 lb, 3 parcels | $34.71 |

Four quotes, no orders placed, and it uses infrastructure that is already built.
This replaces the "three probes through 4over's checkout" suggestion above —
same answer, cheaper.

### If the top is short, the fix has an obvious shape

Keep the price bands where they are safe, and hand over to weight where price
stops tracking it:

```
ship(C, W) = C <= cap-threshold  ?  max(10, min(2C, 20))     // price bands, low volume
                                 :  max(20, weight_rate(W))  // weight, high volume
```

The handover lands exactly where the flat $20 cap ends, which is also where the
data above says the estimate starts thinning. Below it the bands still cover
their weight and their simplicity is worth keeping; above it, price has stopped
being a proxy for weight and only weight will do.

Note the 1× correction did not touch the thinning — the top six runs are
identical either way. What it did remove is the cushion at the bottom, so the
low end is now roughly at cost rather than comfortably over it.

**Weight belongs in the capture, not at quote time.** Compute it per cell when
the matrix is built — stock caliper and trim size are already on the page — and
store it beside the cost. Then it is available for a gate as well: any cell
where the band estimate falls below a weight-derived floor gets flagged at
ingest, alongside the monotonicity and magnitude checks in
`docs/4OVER_PIPELINE.md` §6. That keeps quoting a pure lookup with no runtime
carrier call, and still catches the one failure mode the bands have.

### Outlying states and territories → Shippo

AK, HI, PR, GU, VI, AS, MP fall out of the band formula and use the existing
Shippo integration, which is already built and already quotes real rates.

Origin is not a concern: 4over would ship these out of California, and CA vs
PPS's own origin does not move an AK/HI rate meaningfully (owner, 2026-08-15).

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

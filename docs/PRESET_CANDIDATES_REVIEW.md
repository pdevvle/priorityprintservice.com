# Review — the 10 preset candidates from search data

Assessment of the Claude-in-Chrome keyword set. The research is good and the
volume/position data is the right basis for the decision. What follows is
where I agree, where I'd change the call, and two things that have to be
fixed before any of it ships.

## The test I'm applying

A preset earns its URL when **the query's intent maps to a distinct landing
state**. The entire value proposition is "you arrive and the form already
answers your question." So the check for each row is: *what does `defaults`
actually set, and would a searcher notice?*

If the answer is "nothing — the page is identical to the product page with a
different `<title>`," it is not a preset. It is a duplicate.

---

## Two blockers before any of this ships

### 1. The defaults key bug (fixed in `ff542a1`, not yet deployed)

Every candidate below relies on camelCase defaults: `sizeLabel`,
`twoStaple`, `vividPrint`, `insidePaperType`, `customLong`. Until `ff542a1`
is on the target site, **every one of these presets built through the admin
form would silently ignore its defaults** — empty form, no error anywhere.
Deploy first, then build. Non-negotiable ordering.

### 2. Presets have no body-copy field — and ten near-identical pages is a doorway pattern

This is the one that changes the plan.

A preset page renders: `<title>`, meta description, schema, and **the
calculator**. There is no long-form content field in the row —
`pps_render_preset_calculator()` emits config, styles, mount point, app, and
nothing else. The `<noscript>` fallback carries title + description + a spec
table.

So ten saddle presets would be **ten URLs whose visible body is byte-identical**
(the same calculator), differing only in title, meta description and a few
pre-filled form values. That is the textbook definition of doorway pages, and
it is the single likeliest reason this programme underperforms: not that the
pages rank badly, but that Google declines to rank them *and* they dilute the
category page that currently does.

**Two ways out, and I'd do the first:**

- **Add a `body` field to the preset row** — 150–300 words of genuinely
  specific copy rendered above or below the calculator, per preset. This is a
  small change (one sanitised field, echoed in `pps_render_preset_calculator()`
  and in the noscript block) and it is what makes each URL a real page. It
  also gives the FAQ and schema something to be consistent with.
- Or ship **far fewer presets** — three or four where the landing state is
  genuinely distinct — and accept the rest as future category/blog content.

Either way, **do not ship ten thin presets at once.** Ship three, watch them
for 6–8 weeks, then decide.

---

## Row-by-row

### Ship these four — distinct state, distinct intent, no incumbent

| # | Slug | Vol | Why it earns the URL |
|---|---|---|---|
| 4 | `mini-catalog-printing` | 749 | No existing URL at all, so nothing to cannibalise. Cleanest net-new row in the set — agree with Chrome. |
| 6 | `color-booklet-printing` | 545 | `insideColor`/`coverColor` full + `vividPrint` is a **real** landing state a searcher can see. Spec-qualified intent no product page states. |
| 7 | `bulk-booklet-printing` | 414 | `qty: 1000` + bundling in 25s. The page answers the query on arrival — exactly what presets are for. |
| 3 | `perfect-bound-book-printing` | 344 | Different calculator, and it fixes a genuine mismatch: the existing page ranks 38.1 for "booklet" phrasing and 64.1 for "book". **Caveat below.** |

### Ship with care — worth doing, but check something first

| # | Slug | Vol | The concern |
|---|---|---|---|
| 1 | `saddle-stitch-booklet-printing` | 1,281 | Biggest prize, but this is the **head term for the whole category**. Before building it, confirm what currently ranks for it — if the booklets category page or the saddle product page has any traction, a preset splits the signal instead of adding one. Chrome says "no dedicated page"; verify that means *no page*, not *no page in the top 20*. |
| 2 | `stapled-booklet-printing` | 723 | Real query, but **thin differentiation from #1** — saddle stitch *is* stapled, so `twoStaple` is the only distinguishing default, and two-staple is a niche binding choice rather than what the searcher means. Consider folding this into #1's copy as a synonym rather than a separate URL. If built, it needs genuinely different body copy to survive. |
| 5 | `program-book-printing` | 516 | `/event-program-printing/` already ranks 20.2 for the event phrasing. Position 20 is close enough that improving the existing page may beat launching a competitor to it. Check that page first. |
| 9 | `small-book-printing` | 316 | **Page-count trap.** "Book" implies more pages than "booklet", and the **saddle calculator caps at 64 pages**. A searcher wanting a 120-page book lands, finds a 64-page ceiling, and leaves. Either route this to perfect-bound (40–300) or accept that it only serves the short end. |

### Don't build these two

| # | Slug | Vol | Why not |
|---|---|---|---|
| 8 | `affordable-booklet-printing` | 1,244 | **Fails the test outright: there is no `defaults` value that expresses "affordable."** The landing state is identical to the generic booklet page, so it is a pure duplicate with a price adjective in the title. Add to that the highest cannibalization risk in the set (Chrome flagged this too) and the weakest commercial intent — "affordable" skews to price-shoppers. If you want this traffic, it is a **content** play: a genuine "what booklet printing costs" page with real numbers, which the preset system cannot currently produce. |
| 10 | `3x5-booklet-printing` | 174 | **It is already working — leave it alone.** Position 5.3, 10.3% CTR, 18 clicks. Something already ranks. Adding a preset for a query you already win risks splitting signals and losing the one row in this table that converts. The instinct to formalise it is understandable; the risk/reward is bad. Revisit only if that position decays. |

---

---

## Deep dive — the saddle-stitch cluster (#1 and #2)

This is the biggest thing on the table and the one I'd treat most carefully.
Combined with the variants Chrome folded in, the cluster is:

| Phrase | Vol | Avg pos |
|---|---|---|
| saddle stitch booklet printing | 1,281 | **52.0** |
| stapled booklet printing | 723 | **44.4** |
| saddle stapled booklet | 406 | 39.6 |
| printing saddle stitch booklet | 380 | **18.1** |
| **Cluster total** | **2,790** | — |

That is larger than #4 + #6 + #7 combined (1,708). It deserves more than a
row in a table.

### The tell: 18.1 versus 52.0 on the same four words

"printing saddle stitch booklet" ranks **18.1**. "saddle stitch booklet
printing" ranks **52.0**. Same words, different order, **34 positions apart**.

Position 18 does not happen by accident — **something on this site already
has real saddle-stitch relevance.** So the framing "no page targets it" needs
one more question: *no page exists*, or *no page ranks in the top 20 for the
exact phrase while a page ranks 18th for a variant*? Those call for opposite
responses.

A 34-position spread across word-order variants is the classic signature of
**keyword cannibalisation**: several pages each partially matching the topic,
none owning it, Google picking a different one per variant and trusting none
of them. If that is what is happening, **adding an 11th partially-matching
page makes it worse, not better.**

### Diagnose before building (≈15 minutes, and it decides everything)

In Search Console → Performance, filter to each of the four phrases and open
the **Pages** tab. Record which URL ranks for each.

- **Same URL for all four** → you have one page with the relevance and not
  enough strength. Fix that page. 18 → 10 on an existing URL with existing
  authority roughly triples clicks and is a far better bet than launching a
  new URL at zero, which typically takes 6+ months to reach the same place.
- **Different URLs across the four** → cannibalisation is confirmed. Consolidate
  first: pick the winner, strengthen it, and de-optimise or redirect the
  others. Only then consider new URLs.
- **Nothing above ~40 for any of them, and no page whose primary topic is
  saddle stitch** → a genuine content gap, and a preset is justified — but
  see the head-term warning below.

### Do not build #1 and #2 as two presets

"Stapled booklet printing" is the layperson's phrasing for the identical
product. Google resolves that synonym well on commercial product queries, and
two thin pages competing for one intent is exactly the cannibalisation the
data may already be showing. **If a page is built, build one**, and use both
phrasings naturally in its copy.

There is also a specific problem with #2's proposed default. **`twoStaple` is
the wrong differentiator.** Two-staple is a binding option for taller books,
not what "stapled booklet" means — a searcher using that phrase wants an
ordinary saddle-stitched booklet. Setting `twoStaple` gives them an unusual
configuration, possibly at a higher price than the generic page, which hurts
conversion on arrival. The differentiation between #1 and #2 is a synonym, and
synonyms belong in copy, not in `defaults`.

### If you do build the head term, body copy is the whole ballgame

"Saddle stitch booklet printing" at 1,281/month is a **commercial head term**.
The competition is trade printers with 800-word pages, pricing tables, spec
detail and FAQ blocks.

A preset page today renders a `<title>`, a meta description, schema, and a
React calculator — **no prose at all**. That page will not outrank an
established printer's content page, however good the calculator is. For the
long-tail rows (#4, #6, #7) thin-but-exact-match can still win. For a head
term it cannot.

So the ordering is strict: **body-copy field first, then this preset.** Never
this preset on the current row shape.

### My call on the cluster

1. Run the Search Console diagnostic. It is fifteen minutes and it determines
   whether the answer is "strengthen" or "build".
2. Most likely outcome, given 18.1 exists: **strengthen the incumbent** —
   exact-match title/H1, both phrasings in the copy, the saddle FAQ block
   (saddle already has 7 defaults, so this is free), internal links from the
   booklets category and homepage.
3. Hold #1 and #2 out of the first preset batch entirely. Ship #4, #6, #7,
   learn whether presets move position at all on this domain, and come back to
   the head term with evidence and a body-copy field.

Losing patience with the head term and shipping a thin page for it is the
most likely way to spend the effort and get nothing — or to damage the page
currently sitting at 18.

## Calibrate the expected outcome

Positions 27–64 mean pages 3–7. **Zero clicks there is normal regardless of
page quality** — it is not evidence of a broken page, and a preset does not
inherit authority just by existing. The realistic mechanism is that exact-match
title/H1/URL relevance moves a page from ~40 to ~15, occasionally into the top
10 on low-competition terms. It rarely moves 40 → 3.

So: these are **6–12 month plays**, and the success metric for the first batch
is *movement in average position*, not clicks in week two. Set that expectation
before building, or the programme gets judged as a failure at the six-week mark
when it is actually on track.

## Two dependencies worth knowing

- **`perfect-bound` has no default FAQs.** `pps_default_faqs()` ships 7 FAQs
  for `saddle` and **zero** for the other seven calc types. The nine saddle
  presets inherit saddle's FAQ schema automatically; **#3 would ship with no
  FAQ block at all** unless FAQs are written for it (per-preset `faqs`, or
  fill the perfect-bound entry in the SEO admin tab).
- **`price_from` drives the schema offer price.** Leaving it empty on these
  rows means Product schema without a price, which is weaker in results.
  It pairs with the unstarted from-prices work (`tools-from-prices.mjs`).

## What I'd actually do

1. Deploy `ff542a1` (the defaults-key fix). Prerequisite.
2. Add a `body` copy field to the preset row — small change, and it is what
   separates "landing page" from "doorway page."
3. Build **three**: `mini-catalog-printing`, `color-booklet-printing`,
   `bulk-booklet-printing`. All net-new intent, all with a visible distinct
   landing state, none competing with an incumbent page.
4. Write real body copy and FAQs for each. Set `price_from`.
5. Watch average position for 6–8 weeks.
6. If they move, add `perfect-bound-book-printing` (with its own FAQs) and
   then the head terms. If they don't move, the problem is authority, not
   coverage — and building seven more thin pages would have made it worse.

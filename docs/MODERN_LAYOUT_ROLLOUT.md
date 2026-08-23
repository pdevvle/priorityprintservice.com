# Modern layout rollout

Owner direction 2026-08-04: *"start on modern layout and then we'll roll out to all
others with the new layout."* This document is the result of the "start" — an audit of
where the modern layout actually stands — and the recipe for the rollout, so each port
is a repeat of a known procedure rather than a fresh adventure.

## Verdict on calc-modern-draft.html: it is not a draft

Audited 2026-08-04. The file is a **strict superset** of calc-preview-test.html — every
top-level function in the classic saddle-stitch calculator exists in modern, plus the
layout system itself (`pps_applyTheme`, `buildCHIP`, `buildST`, `trimDims`). Several of
this cycle's fixes exist **only** in modern (per-page greyscale, the coupon field, the
wider %3414 gating, the larger Float-portal coverage).

Verified green, driving the real file in a real browser:

- compiles; zero page errors on load, desktop and mobile
- full proof regression: pdf.js 4.10.38 loads through the loader, the
  transparency-heavy customer PDF renders the block 3.11 dropped
  (probe rgb(44,66,115) where 3.11 painted white), preflight banner names the risks,
  construct-free control shows no banner
- state list: all 50 offered, Georgia survives a config that omits it
- date picker: rush-zone click populates the pre-filled field
- dark mode: fully tokened across sections, rail, and tables
- "one section at a time" accordion setting works

Every failure encountered during the audit was a **test-driver assumption**, not the
app — all traced to fork differences listed below, and the drivers now handle both
styles. That asymmetry is the strongest signal the modern file is the one to build on.

## What the "modern layout" is (the portable kit)

The pieces a port carries over, independent of product type:

1. **Design tokens + theming** — CSS custom properties for every colour/surface,
   `pps_applyTheme`, and the ⚙ Form settings popover (Dark mode, One-section-at-a-time).
2. **Header pattern** — `CONFIGURE · <PRODUCT>` eyebrow, product title, `INSTANT QUOTE`.
3. **Numbered sections with live chips** — section headers show the current answers as
   chips (`Full Color`, `100lb Gloss Text`, `Add a destination`) so a collapsed form
   still reads as a summary. Sections stay **mounted** and hide via CSS var — this is
   load-bearing: uploads, previews and effects keep working while hidden.
4. **Right rail** — dark SIZE SPEC · LIVE panel (with OPEN ↗ modal), ESTIMATED TOTAL
   card with per-unit/qty/date strip, Add to Order CTA with the destination-gate line
   under it, DISCOUNT CODE row, "Ask a question" entry, QUANTITY PRICING table with
   Copy and Show more, Save configuration, cross-sell links.
5. **Mobile condensed bar** — price + DETAILS expander + CTA pinned to the bottom,
   publishing `--pps-bottombar` so overlays (RichTip sheet) stack above it.
6. **Float portals** — every overlay (modals, sheets, chips, bars) portals to
   `document.body`; nothing position-fixed may live inside the themed container.

## What stays per-calculator

The pricing engine (`PCF`, `calculate()`) — governed by `docs/MASTER_PRICING_LOGIC.md`
and untouchable in a layout port. Section *contents* (which controls exist). The
preview component (book vs sheet vs fold). `buildMetadata`/`buildSummary` fields. Size
presets and product config consumption.

## Port order

1. **calc-perfect-bound.html** — closest sibling (booklet, cover/inside paper, sets),
   exercises the layout against the richest section content. Everything learned here
   transfers to the rest of the booklet family.
2. **calc-coupon-book.html** — second booklet; also carries the confirm-mode page-count
   field, which the modern layout must absorb without losing the staleness notice.
3. **calc-brochure.html** — defines the *flat-family* variant of the port (SheetPreview
   front/back, fold controls, 3D fold preview). Hardest single port; do it fourth-ish
   with the booklet lessons banked.
4. **calc-postcard, calc-greeting-card, calc-letterhead, calc-sticker** — mechanical
   repeats of the brochure port.
5. **calc-preview-test.html** — retired, not ported. Modern *is* the saddle-stitch
   calculator. Retirement = registry flip on the server (point the saddle products at
   the modern file) + archive the old file the way `gh-pages` was archived. Owner step.

## The recipe per port

1. Branch work on the working branch; never regex across the whole file — anchored
   exact-string edits with asserted match counts (a multi-line regex has already broken
   four calculators in one session).
2. Move the target's section contents into the modern skeleton, section by section,
   keeping the target's engine and metadata code byte-for-byte.
3. Shared components must end up **byte-identical** with modern's copy — `TxtNum`,
   `Sel`, `Pil`, `Sec`, `DatePicker`, `RichTip`, `Float`, the loader block. Check with
   md5 the way the TxtNum drift was caught. Divergence here is how nine forks became
   nine adventures.
4. Run the battery (`tools-tests/`): compile on all nine, then states + datepick +
   pagecount (where the field exists) + prooffix on the ported file. Screenshot
   desktop/mobile/dark and eyeball.
5. Bump the build stamp, cherry-pick to `pps-pricing-config`, `sync-pages-public.sh`,
   let the owner review the Pages preview before any staging deploy.

## Known fork differences the drivers already absorb

| behaviour | classic forks | modern |
|---|---|---|
| closed sections | unmounted | mounted, CSS-var hidden |
| date field | empty until picked | pre-filled with free-delivery date |
| page count | confirm-mode w/ Apply (pb, coupon) | live select |

## Standing cautions

- No literal `</script>` inside the babel block, ever — escape as `<\/script>`.
- `.nojekyll` stays on `pps-pricing-config`.
- The proofer bleed question (trim-size art centred on a bleed-size page; the paid
  "I don't have bleeds" answer unconsulted) is **owner-decision parked**, not forgotten.

# Calculator design system — review brief

Everything visual in all 8 calculators routes through **three JS objects** that appear
verbatim in each HTML file. There is no CSS framework and no build step. Restyling the
whole product = editing these objects.

| Object | What it is | Where (in `calc-modern-draft.html`) |
|---|---|---|
| `CK` | Brand hues, CMYK-derived. Rarely changed. | line ~933 |
| `CC` | Semantic palette built on `CK` | line ~947 |
| `ST` | Component styles (inputs, pills, cards, panels) | line ~5612 |
| `<style>` block | The only place pseudo-classes/media queries can live | line ~100 |

## Constraints a design change must respect

1. **Styles are inline JS objects**, so `:hover`, `:focus`, `::placeholder` and media
   queries are impossible in `ST`. Anything stateful must go in the single `<style>`
   block in `<head>` (and usually needs `!important` to beat inline styles).
2. **Self-contained HTML**, inline Babel/JSX, no bundler. Ships to GitHub Pages as-is.
3. **Never write a literal `</script>`** anywhere inside the babel block — not even in a
   JS string. Escape as `<\/script>`. The HTML parser closes the block at the first
   match regardless of JS quoting, which silently breaks the page.
4. **Mobile is mostly JS**, via a `useIsMobile()` hook that swaps style objects — not CSS
   breakpoints. Layout changes need to account for both branches.
5. The same `CC`/`ST` blocks are duplicated across all 8 calculator files. A change is
   applied by copying the two objects into each.

## Brand hues (`CK`) — unchanged by the draft

```js
const CK = {
  cyan:    "#007eff",   // primary action / active state
  magenta: "#d1246a",   // turnaround badges, accents
  yellow:  "#f0a830",   // warnings, blank-page markers
  key:     "#2b2b2b",   // (production ink; draft overrides via CC.dark)
  rush:    "#BADA55",   // rush surcharge green
};
```

`Full Color` segmented buttons use a per-option CMYK gradient passed as `onStyle`, which
overrides `ST.pillOn`. The draft deliberately keeps it — it signals the actual product.

---

## Palette: production → draft

```js
// PRODUCTION                          // DRAFT
dark:   CK.key,     // #2b2b2b         dark:   "#0f172a"   // deeper slate ink
mid:    "#555",                        mid:    "#475569"
light:  "#999",                        light:  "#94a3b8"
bg:     "#f4f4f4",                     bg:     "#f6f7f9"   // cooler field
border: "#d8d8d8",                     border: "#e3e8ef"   // hairline
red:    "#c25050",                     red:    "#dc2626"
```

Rationale: the old greys were warm and muddy against white, and `#d8d8d8` borders were
heavy enough to read as the dominant visual element on a dense form.

## Components: production → draft

**Inputs / selects** — `ST.inp`, `ST.sel`

```js
// PRODUCTION — gradient fill + 6px radius
background:"linear-gradient(180deg, #fff 0%, #f9f9fa 100%)", borderRadius:6,
border:"1px solid #d8d8d8", boxShadow:"0 1px 3px rgba(0,0,0,.04)"

// DRAFT — flat surface, softer geometry, transition for the new focus ring
background:"#fff", borderRadius:9,
border:"1px solid #e3e8ef", boxShadow:"0 1px 2px rgba(16,24,40,.04)",
transition:"border-color .15s ease, box-shadow .15s ease"
```

**Segmented control** — `ST.pills` / `ST.pill` / `ST.pillOn`. The biggest change.

```js
// PRODUCTION — butted cells split by hard rules; active = 5-stop gradient + bevels
pills:  { border:"1px solid #d8d8d8", borderRadius:6, boxShadow:"0 1px 3px rgba(0,0,0,.06)" }
pill:   { borderRight:"1px solid #d8d8d8",
          background:"linear-gradient(180deg,#fff 0%,#f7f7f8 100%)",
          boxShadow:"inset 0 1px 0 rgba(255,255,255,.8), inset 0 -1px 0 rgba(0,0,0,.04)" }
pillOn: { background:"linear-gradient(135deg,#007eff 0%,#0070e6 40%,#3d9cff 50%,#0070e6 60%,#005cc8 100%)",
          boxShadow:"inset 0 1px 0 rgba(255,255,255,.25), inset 0 -1px 0 rgba(0,0,0,.15), 0 1px 3px rgba(0,126,255,.2)" }

// DRAFT — inset track + rounded thumb; active = flat brand fill
pills:  { background:"#f1f4f8", borderRadius:10, padding:3, gap:3,
          border:"1px solid #e3e8ef", boxShadow:"inset 0 1px 2px rgba(16,24,40,.04)" }
pill:   { background:"transparent", borderRadius:7, border:"none",
          transition:"all .18s cubic-bezier(.4,0,.2,1)" }
pillOn: { background:"#007eff", color:"#fff", boxShadow:"0 1px 2px rgba(0,126,255,.35)" }
```

**Section cards / panels** — `ST.card`, `ST.pnl`

```js
// PRODUCTION — Bootstrap-panel idiom: left accent stripe, hard border, 6px
card: { borderRadius:6, border:"1px solid #d8d8d8", borderLeft:"3px solid #007eff" }
pnl:  { borderRadius:6, border:"1px solid #d8d8d8" }

// DRAFT — stripe removed; depth from layered shadow, 14px radius
card: { borderRadius:14, border:"1px solid #e3e8ef",
        boxShadow:"0 1px 2px rgba(16,24,40,.04), 0 2px 6px rgba(16,24,40,.03)" }
pnl:  { borderRadius:14, border:"1px solid #e3e8ef",
        boxShadow:"0 1px 2px rgba(16,24,40,.04), 0 6px 16px rgba(16,24,40,.05)" }
```

Spacing also opened up: `f.marginTop` 10→14, grid `gap` 12→14, `hr` margin 14→16.

## Interaction states (new — `<style>` block)

Production had **no focus styling at all**, because inline styles cannot express it. This
is an accessibility fix as much as a visual one.

```css
input:hover:not(:focus), select:hover:not(:focus){ border-color:#cbd5e1 !important }
input:focus, select:focus, textarea:focus{
  border-color:#007eff !important;
  box-shadow:0 0 0 3px rgba(0,126,255,.14) !important;
}
button:focus-visible{ outline:2px solid rgba(0,126,255,.55); outline-offset:2px }
button:active{ transform:translateY(.5px) }
h1,h2{ letter-spacing:-.02em }
```

## Open ideas, not yet drafted

- **Sticky price rail** — the quote card scrolls out of view on long configs
- **Sentence-case section headers** — the ALL-CAPS headers add noise
- **Dark mode** — cheap now that colors are centralized in `CC`
- **Mobile pass** — the compact bottom bar is where the old styling shows most

## Files

- Draft: `calc-modern-draft.html` (saddle stitch, restyled — logic untouched)
- Production reference: `calc-preview-test.html`
- Live draft: https://pdevvle.github.io/priorityprintservice.com/calc-modern-draft.html

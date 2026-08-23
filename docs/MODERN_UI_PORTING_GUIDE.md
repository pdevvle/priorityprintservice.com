# Modern UI Porting Guide

**Purpose:** apply the visual/UX redesign in `calc-modern-draft.html` (6,906 lines, "MODERN DRAFT · 2026-08-04 · PDFJS4") to the other seven calculators — perfect-bound, coupon, brochure, postcard, letterhead, greeting-card, sticker — **without touching their pricing engines**.

**Baseline for this comparison:** `calc-preview-test.html` (classic saddle stitch, "BUILD 2026-08-09 · PAPERS"). The two files share the same engine and all recent features (paper dots/PaperNote, stale-quote handling, pdf.js 4.10.38 preflight, destination gate); the diff between them is almost purely the redesign plus a handful of riders called out below. All line numbers in this guide refer to **calc-modern-draft.html at commit 9c8106b** unless stated otherwise.

Verified identical between the two files (do NOT expect changes here when porting): `useIsMobile`, PCF block, transit/date helpers, `PaperNote`/`paperInv`, size tables, `TurnBadge`, `DatePicker`, `DimSpec`, `MobilePreviewBar`, `SpecSheet`, `DebugPanel`, `BookClosed3D`/`BookOpen3D`, `InfoTip`, `TT`/`Tip`, jsPDF template helpers, `buildSummary`, `buildMetadata`, `uploadWithProgress`, `IconTextWeight`/`IconCardstock`.

---

## 1. Design tokens

### 1.1 Typography (UNIVERSAL)

Google Fonts are loaded in `<head>` (lines 90–92) and exposed as CSS variables; the old Segoe stack stays as fallback:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
```

```css
/* lines 260-264 of the head <style> */
:root{
  --pps-display:'Montserrat','Segoe UI','Helvetica Neue',Arial,sans-serif;
  --pps-sans:'Work Sans','Segoe UI','Helvetica Neue',Arial,sans-serif;
}
body{font-family:var(--pps-sans)}
```

Every hardcoded `fontFamily:"'Segoe UI','Helvetica Neue',Arial,sans-serif"` becomes `fontFamily:"var(--pps-sans)"` (App root div, ErrorBoundary at line 398) or `var(--pps-display)` for display type (hero `<h1>`, `Sec` titles, `.bp-modal-title`). Headings get `h1,h2{letter-spacing:-.02em}` (line 333).

### 1.2 Color — CC/CK and the two palettes (UNIVERSAL)

`CK` (process cyan/magenta/yellow/key + rush) is **unchanged**. `CC` changes from a flat object to a light palette spread plus new semantic keys, with a dark twin. Old → new (light):

| key | classic | modern light | modern dark |
|---|---|---|---|
| dark (ink) | `#2b2b2b` (CK.key) | `#0f172a` | `#e6edf6` |
| mid | `#555` | `#475569` | `#9fb0c6` |
| light | `#999` | `#94a3b8` | `#7183a0` |
| bg | `#f4f4f4` | `#f6f7f9` | `#0d1117` |
| white (card surface) | `#fff` | `#fff` | `#161b22` |
| border | `#d8d8d8` | `#e3e8ef` | `#2a3340` |
| red | `#c25050` | `#dc2626` | `#f87171` |
| track (NEW — pill/segment track, subtle boxes) | — | `#f1f4f8` | `#0f151d` |
| errBg / errBorder (NEW) | — | `#fef2f2` / `#fecaca` | `#2a1416` / `#5b2b2f` |
| heroBg (NEW — Panel price-card ink surface) | — | `#0f172a` | `#1e2a38` |
| rowHi (NEW — selected qty row) | — | `#f0f9ff` | `#17263a` |
| tint / tintBorder (NEW — brand-tinted info boxes, replaces `CK.cyanLight` in chrome) | — | `#eff6ff` / `#cfe6ff` | `#12243a` / `#24405e` |

Verbatim source (lines 1266–1285):

```js
// Theme palettes. CC's keys are semantic, not literal: `white` is the card surface
// and `dark` is the ink, so both invert cleanly. Swapping happens by mutating CC in
// place (pps_applyTheme) — components read CC/ST at render time, so a re-render picks
// the new values up without threading a theme prop through every component.
const PALETTES = {
  light: { dark:"#0f172a", mid:"#475569", light:"#94a3b8", bg:"#f6f7f9", white:"#fff",
           border:"#e3e8ef", red:"#dc2626", track:"#f1f4f8", errBg:"#fef2f2", errBorder:"#fecaca",
           heroBg:"#0f172a", rowHi:"#f0f9ff", tint:"#eff6ff", tintBorder:"#cfe6ff" },
  dark:  { dark:"#e6edf6", mid:"#9fb0c6", light:"#7183a0", bg:"#0d1117", white:"#161b22",
           border:"#2a3340", red:"#f87171", track:"#0f151d", errBg:"#2a1416", errBorder:"#5b2b2f",
           // Stays a dark surface in both themes, lifted here so it still reads as a
           // distinct card against the near-black page rather than blending into it.
           heroBg:"#1e2a38", rowHi:"#17263a", tint:"#12243a", tintBorder:"#24405e" },
};
const CC = {
  primary: CK.cyan,
  accent: CK.magenta,
  warm: CK.yellow,
  ...PALETTES.light,
};
```

Chrome that used `CK.cyanLight` as a box background (two-staple notice, free-delivery estimate box, switch links) now uses `CC.tint` so it follows the theme. `CK.cyanLight`/`magentaLight` remain for badges (`TurnBadge`, `Sec` badge was cyanLight → now CHIP-styled).

### 1.3 Shape, elevation, spacing (UNIVERSAL)

- **Radii:** inputs/selects 6 → **9**; pill groups 6 → **10** (thumbs **7**); cards/panels 6 → **14**; modals 14 → **16**; small buttons/chips/notice boxes 3–6 → **8–10**; progress/breakdown bars 3 → **99** (full pill); spec card 6 → 12.
- **Gradients out, flat surfaces in:** the bevelled `linear-gradient(180deg,#fff,#f9f9fa)` field backgrounds and the 5-stop glossy cyan `pillOn` gradient are gone — flat `CC.white` fields, flat `CK.cyan` active thumb.
- **Shadows:** ink-tinted layered shadows `rgba(16,24,40,…)` instead of `rgba(0,0,0,…)`: fields `0 1px 2px rgba(16,24,40,.04)`, cards `0 1px 2px rgba(16,24,40,.04), 0 2px 6px rgba(16,24,40,.03)`, panel `…, 0 6px 16px rgba(16,24,40,.05)`, menus `0 4px 6px rgba(16,24,40,.06), 0 12px 28px rgba(16,24,40,.14…18)`.
- **The card left-edge 3px cyan accent stripe is REMOVED** (`ST.card` no longer has `borderLeft`).
- **Spacing:** `ST.f` marginTop 10 → 18; field vertical padding 10px → **20px** (tall fields); `row2`/`row3` gap 12 → 18; labels 13px → 14px with `marginBottom` 5 → 7.
- **Mobile controls:** min-height 46px → **60px** for selects/inputs (incl. new `tel`/`email`), pills keep 44px (see §3.7 inline style block).

### 1.4 Motion & interaction states (UNIVERSAL)

Head `<style>` additions (lines 266–291 + 292–338):

- `@keyframes pps-pop-0/pps-pop-1` — select "pop" scale on change (two identical names so re-trigger never remounts the `<select>`).
- `@keyframes pps-mini-in` — condensed total bar slide-up.
- `input:hover`/`select:hover` border deepen; `:focus` brand ring `0 0 0 3px rgba(0,126,255,.14)`; `button:active{transform:translateY(.5px)}`; `button:focus-visible` outline.
- `[data-pps-cta]` hover lift + brightness for the primary CTA (lines 253–254).
- Styled scrollbars (10px, pill thumb, content-box clip).
- **Reduced-motion kill-switch** — `@media (prefers-reduced-motion: reduce)` zeroes all animation/transition durations (lines 288–291), with a special flat-fill carve-out for `.bp-approve` (lines 240–244).

### 1.5 Dark mode CSS (UNIVERSAL)

Most surfaces repaint via the CC/ST swap (§4.1). The head `<style>` carries the rules inline styles can't reach — page background, native `option` popups, placeholder ink, `color-scheme:dark`, scrollbars — plus **attribute-selector remaps for hardcoded status tints** (`[style*="rgb(254, 242, 242)"]` error boxes, amber warns, green success), each remapping the container AND descendants except form controls. Verbatim (lines 292–338):

```css
  /* ===== MODERN DRAFT — interaction states & page field ===== */
  body{background:#f6f7f9}
  /* ── Dark mode. Most surfaces repaint via the CC/ST swap; these are the ones
        inline styles can't reach: the page field, native select popups, and the
        handful of hardcoded #fafafa chrome bars (React emits them as rgb()). ── */
  html[data-pps-theme="dark"] body{background:#0d1117;color:#e6edf6}
  html[data-pps-theme="dark"] option{background:#161b22;color:#e6edf6}
  html[data-pps-theme="dark"] input::placeholder,html[data-pps-theme="dark"] textarea::placeholder{color:#5b6b83}
  html[data-pps-theme="dark"] [style*="rgb(250, 250, 250)"]{background:#1a212b !important}
  /* Status tints (error / warning / success banners) are pale by construction and
     stay bright on a dark page. Their ink lives on child spans with its own inline
     colour, so each family remaps the container AND its descendants. Matching on the
     rgb() React emits catches every site, including ones not enumerated by hand. */
  html[data-pps-theme="dark"] [style*="rgb(254, 242, 242)"],
  html[data-pps-theme="dark"] [style*="rgb(254, 226, 226)"]{background:#2a1416 !important;border-color:#5b2b2f !important}
  /* ...but NOT the controls inside them. A tinted box that wraps buttons (the Proof /
     Preview pair sits in the red "approval required" box) is a container, not a banner:
     its buttons carry their own surface and their own ink, and repainting them made the
     labels read as error text. Form controls opt out of the descendant repaint. */
  html[data-pps-theme="dark"] [style*="rgb(254, 242, 242)"] *:not(button):not(select):not(input):not(textarea),
  html[data-pps-theme="dark"] [style*="rgb(254, 226, 226)"] *:not(button):not(select):not(input):not(textarea){color:#fca5a5 !important}
  html[data-pps-theme="dark"] [style*="rgb(254, 243, 199)"],
  html[data-pps-theme="dark"] [style*="rgb(255, 251, 235)"]{background:#2a2210 !important;border-color:#5a4a1e !important}
  html[data-pps-theme="dark"] [style*="rgb(254, 243, 199)"] *:not(button):not(select):not(input):not(textarea),
  html[data-pps-theme="dark"] [style*="rgb(255, 251, 235)"] *:not(button):not(select):not(input):not(textarea){color:#fcd34d !important}
  html[data-pps-theme="dark"] [style*="rgb(232, 245, 227)"],
  html[data-pps-theme="dark"] [style*="rgb(240, 253, 244)"]{background:#10231a !important;border-color:#245c3a !important}
  html[data-pps-theme="dark"] [style*="rgb(232, 245, 227)"] *:not(button):not(select):not(input):not(textarea),
  html[data-pps-theme="dark"] [style*="rgb(240, 253, 244)"] *:not(button):not(select):not(input):not(textarea){color:#6ee7b7 !important}
  html[data-pps-theme="dark"] input:hover:not(:focus),html[data-pps-theme="dark"] select:hover:not(:focus){border-color:#3b4757 !important}
  html[data-pps-theme="dark"] ::-webkit-scrollbar-thumb{background:#2a3340;background-clip:content-box}
  html[data-pps-theme="dark"] ::-webkit-scrollbar-thumb:hover{background:#3b4757;background-clip:content-box}
  html[data-pps-theme="dark"] input,html[data-pps-theme="dark"] select,html[data-pps-theme="dark"] textarea{color-scheme:dark}
  input:hover:not(:focus), select:hover:not(:focus){border-color:#cbd5e1 !important}
  input:focus, select:focus, textarea:focus{
    border-color:#007eff !important;
    box-shadow:0 0 0 3px rgba(0,126,255,.14) !important;
  }
  button{transition:filter .15s ease, transform .06s ease}
  button:active{transform:translateY(.5px)}
  button:focus-visible{outline:2px solid rgba(0,126,255,.55);outline-offset:2px}
  /* headings: tighter optical tracking at large sizes */
  h1,h2{letter-spacing:-.02em}
  ::-webkit-scrollbar{width:10px;height:10px}
  ::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px;border:3px solid transparent;background-clip:content-box}
  ::-webkit-scrollbar-thumb:hover{background:#94a3b8;background-clip:content-box;border:3px solid transparent}
</style>
```

Porting note: these `rgb(…)` matchers cover the tint literals used across the calculators (`#fef2f2`, `#fee2e2`, `#fef3c7`, `#fffbeb`, `#e8f5e3`, `#f0fdf4`, `#fafafa`). If a target calculator uses additional literal tint colors, add matching rules — React emits inline styles as `rgb()` triplets, so match that form exactly.

---

## 2. Component-by-component deltas

### 2.1 `Chev` — unchanged.

### 2.2 `Sel` — RESTYLED + new props (lines 1293–1297, UNIVERSAL)

New props: `wrapStyle` (merge into outer div style — used to zero `marginTop` inside address grids), `name`, `autoComplete`, `selectId` (real ids/autofill for address fields). New behavior: a `pop` counter re-triggers the `pps-pop-*` scale animation on every change. Taller padding `20px 34px 20px 14px` (mobile `20px 32px 20px 13px`).

```jsx
function Sel({label,value,onChange,children,disabled,note,badge,wrapStyle,name,autoComplete,selectId}){
  const m=useIsMobile();
  const[pop,setPop]=useState(0);
  return <div style={{...ST.f,...wrapStyle}}>{label&&<label style={ST.lbl}>{label}</label>}<div style={{position:"relative",animation:pop?`pps-pop-${pop%2} .28s ease`:undefined}}><select id={selectId} name={name} autoComplete={autoComplete} value={value} onChange={e=>{setPop(n=>n+1);onChange(e.target.value);}} style={{...ST.sel,fontSize:m?16:15,padding:m?"20px 32px 20px 13px":"20px 34px 20px 14px",...(disabled?{opacity:.4,pointerEvents:"none"}:{})}} disabled={disabled}>{children}</select><span style={ST.selA}><Chev/></span></div>{note&&<p style={ST.note}>{note}</p>}</div>;
}
```

**⚠ Known quirk:** modern `Sel` still *accepts* `badge` but no longer renders it (classic rendered `{label}{badge}` inside the `<label>`). App still passes `badge={<TurnBadge …/>}` to several `Sel`s, so those turnaround badges are invisible in the draft (they resurface via `Sec` summary chips only indirectly). `Pil` still renders its badge. Decide before porting: restore `{badge}` after `{label}` in `Sel`'s label (one-token change) or accept the removal everywhere. Flagged to the operator rather than silently chosen.

### 2.3 `TxtNum` — RESTYLED + behavior change (lines 1299–1328, UNIVERSAL — read the caveat)

Adds a `pushed` ref that reconciles the field with parent-normalised values on every render, and an amber "Adjusted to N — allowed range is min–max" note when blur clamps a typed value. **Behavior difference vs classic:** classic commits ANY parsed number mid-typing (out-of-range reaches `calculate()`, which errors visibly); modern only commits in-range values while typing and clamps on blur with the note. Perfect-bound's `TxtNum` additionally has `confirm`/`unit`/`adjustHint` props — merge, don't overwrite (see §6 step 5).

```jsx
function TxtNum({label,value,onChange,min=1,max=99999}){
  const[raw,setRaw]=useState(String(value));
  const[note,setNote]=useState(null);
  // What we last handed the parent. The parent may normalise it — Quantity rounds, for
  // instance — and when its normalised result happens to equal the value it already
  // held, a [value]-keyed effect never fires and the box goes on showing "11.4" while
  // the quote says 11. Reconciling on every render instead means the field always ends
  // up displaying the number actually being priced. It does not fight mid-typing:
  // "10." parses to 10, the parent stores 10, and pushed===value so nothing snaps.
  const pushed=useRef(null);
  useEffect(()=>setRaw(String(value)),[value]);
  useEffect(()=>{
    if(pushed.current!==null && value!==pushed.current){ setRaw(String(value)); pushed.current=value; }
  });
  const commit=v=>{ pushed.current=v; onChange(v); };
  return <div style={ST.f}>{label&&<label style={ST.lbl}>{label}</label>}
    <input type="text" inputMode="decimal" value={raw}
      onChange={e=>{setRaw(e.target.value);setNote(null);const v=parseFloat(e.target.value);if(!isNaN(v)&&v>=min&&v<=max)commit(v)}}
      onBlur={()=>{
        const typed=parseFloat(raw);
        let v=typed; if(isNaN(v))v=min;
        v=Math.min(max,Math.max(min,v));
        // Rewriting someone's number without telling them is how a 18" height silently
        // becomes 13.25". Say what happened and why.
        setNote(!isNaN(typed)&&typed!==v ? `Adjusted to ${v} — allowed range is ${min}–${max}` : null);
        commit(v); setRaw(String(v));
      }} style={ST.inp}/>
    {note&&<div style={{fontSize:11,color:"#b45309",marginTop:4,lineHeight:1.35}}>{note}</div>}
  </div>;
}
```

### 2.4 `Pil` — RESTYLED (lines 1330–1333, UNIVERSAL)

Same API. Track+thumb segmented control (see ST.pills/pill/pillOn in §2.13). Taller: `18px 10px` mobile / `17px 10px` desktop (was 11px/9px).

```jsx
function Pil({label,options,value,onChange,disabled,badge}){
  const m=useIsMobile();
  return <div style={ST.f}>{label&&<label style={ST.lbl}>{label}{badge}</label>}<div style={ST.pills}>{options.map(o=><button type="button" aria-pressed={value===o.v} key={String(o.v)} onClick={()=>!disabled&&onChange(o.v)} style={{...ST.pill,padding:m?"18px 10px":"17px 10px",...(value===o.v?(o.onStyle||ST.pillOn):{}),...(disabled?{opacity:.4,cursor:"default"}:{})}}>{o.t}</button>)}</div></div>;
}
```

Related restyle in App: the "Full Color" pill `onStyle` changed from a `conic-gradient` to `linear-gradient(135deg, #00aeef 0%, #ec008c 55%, #fff200 100%)` (line 6608 region — Printing & Paper section); greyscale gradient unchanged.

### 2.5 `TurnBadge` — unchanged.

### 2.6 `CHIP` / `buildCHIP` — NEW (lines 1344–1345, UNIVERSAL)

Pill-chip style used by `Sec` summary chips and the job-name header field. Must be `let` (theme swap reassigns it) and defined before `Sec`:

```js
function buildCHIP(){ return {fontSize:11,fontWeight:600,color:CC.mid,background:CC.track,border:`1px solid ${CC.border}`,padding:"5px 9px",borderRadius:99,whiteSpace:"nowrap",maxWidth:170,overflow:"hidden",textOverflow:"ellipsis",display:"inline-block",lineHeight:1.3}; }
let CHIP = buildCHIP();
```

### 2.7 `Sec` — REDESIGNED + 4 new props (lines 1347–1390, UNIVERSAL)

New props: `step` (numbered circle, cyan when open), `summary` (array of strings shown as CHIP chips when closed — capped 3 desktop / 1 mobile with a "+N" chip), `headerField` (a live input inside the header while open — header becomes two flanking toggle buttons because an `<input>` inside a `<button>` is invalid), `keepMounted` (body hidden with `display:none` instead of unmounted — REQUIRED for any section that owns internal state, e.g. the artwork uploader). Title moves to `var(--pps-display)`, weight 650, no uppercase, ellipsized. Body/keepMounted markup is at the bottom. Verbatim:

```jsx
function Sec({title,open,onToggle,children,badge,subtitle,step,summary,headerField,keepMounted}){
  const m=useIsMobile();
  // Summaries arrive as a list of characteristics. Cap how many render so a section
  // with several add-ons can not push the header taller or crowd out the title; the
  // remainder collapses into a "+N" chip.
  const _items=(Array.isArray(summary)?summary:summary?[summary]:[]).filter(Boolean);
  const _cap=m?1:3;
  // Overflowing the cap costs one slot to the "+N" chip — but never the last one, or
  // a capped-at-1 mobile header would show "+3" and nothing else.
  const _shown=_items.length>_cap?_items.slice(0,Math.max(1,_cap-1)):_items;
  const _extra=_items.length-_shown.length;
  const _chip=m?{...CHIP,maxWidth:118}:CHIP;
  // A header field (e.g. Job Name) only makes sense while the section is open — when
  // it is closed its value is already showing as a summary chip, and an input crammed
  // between the title and the chips would crowd both. The header is therefore two
  // toggle buttons flanking the field rather than one button wrapping it: an <input>
  // nested inside a <button> is invalid and swallows its own clicks.
  const _hf=headerField&&open;
  const _pad=m?12:14, _padX=m?14:18;
  return <div style={ST.card}>
    <div style={{...ST.cardH,padding:0,cursor:"default",fontSize:m?14:15,alignItems:subtitle?"flex-start":"center"}}>
      {/* With a header field present the title shrinks to its own width so the field can
          take the slack and sit right beside it, reading as a continuation of the
          heading. Fixed-width fields overflowed narrow phones. */}
      <button type="button" onClick={onToggle} aria-expanded={open} style={{display:"flex",alignItems:subtitle?"flex-start":"center",gap:m?10:12,flex:_hf?"0 0 auto":1,minWidth:0,textAlign:"left",padding:`${_pad}px 0 ${_pad}px ${_padX}px`,background:"transparent",border:"none",cursor:"pointer",fontFamily:"inherit",fontSize:"inherit"}}>
        {step&&<span style={{flex:"none",width:22,height:22,borderRadius:99,background:open?CK.cyan:CC.track,border:open?"none":`1px solid ${CC.border}`,color:open?"#fff":CC.mid,fontSize:11,fontWeight:700,display:"flex",alignItems:"center",justifyContent:"center",transition:"background .18s ease, color .18s ease",marginTop:subtitle?1:0}}>{step}</span>}
        <div style={{flex:_hf?"0 0 auto":1,minWidth:0}}><div style={{fontFamily:"var(--pps-display)",fontWeight:650,color:CC.dark,fontSize:"inherit",letterSpacing:"-0.01em",whiteSpace:"nowrap",overflow:"hidden",textOverflow:"ellipsis"}}>{title}</div>{subtitle&&<div style={ST.secSub}>{subtitle}</div>}</div>
      </button>
      {_hf&&<div style={{flex:"1 1 auto",minWidth:40,padding:m?"0 4px 0 8px":"0 8px 0 10px",marginTop:subtitle?1:0}}>{headerField}</div>}
      <button type="button" onClick={onToggle} aria-expanded={open} aria-label={open?"Collapse section":"Expand section"} style={{display:"flex",alignItems:"center",gap:6,flexShrink:0,minWidth:0,marginTop:subtitle?2:0,padding:`${_pad}px ${_padX}px ${_pad}px 0`,background:"transparent",border:"none",cursor:"pointer",fontFamily:"inherit",fontSize:"inherit"}}>
        {!open&&_shown.length>0&&_shown.map((t,i)=><span key={i} style={_chip}>{t}</span>)}{!open&&_extra>0&&<span style={{..._chip,color:CC.light}}>+{_extra}</span>}{badge&&!(m&&_hf)&&<span style={_chip}>{badge}</span>}
        <span style={{color:CC.light,transition:"transform .2s",display:"flex",transform:open?"rotate(180deg)":"none"}}><Chev/></span>
      </button>
    </div>
    {/* Collapsing normally unmounts the body, which is fine for sections whose values
        all live in App state. A section that owns state internally — the artwork
        uploader holds the extracted pages, the original File and the proof approval —
        must be hidden instead, or closing it silently throws the upload away. */}
    {(open||keepMounted)&&<div hidden={!open} style={{display:open?"block":"none",padding:m?"12px 14px":"14px 18px",borderTop:`1px solid ${CC.border}`}}>{children}</div>}
  </div>;
}

const DNAMES=["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];
const MNAMES=["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
```

Note: brochure/perfect-bound `Sec` variants lack `subtitle` — modern's includes it, so the ported `Sec` is a superset and existing call sites keep working.

### 2.8 `DatePicker` — unchanged.

### 2.9 `Float` — NEW (lines 1551–1558, UNIVERSAL)

```jsx
// <body>. Rendered in place it lives inside whatever the WordPress theme wraps the
// calculator in, and a single ancestor with a transform, filter, or scroll-animation
// will-change traps it in THAT element's stacking context — after which later page
// sections paint straight over it no matter how high its z-index is. Portalling takes
// the ancestor out of the question. (Reported 2026-08-01: the condensed total bar
// rendered behind the footer's heading and logo.) z-index stays under the 9999 modal
// band so proof/preview still cover these.
const Float = ({ children }) => ReactDOM.createPortal(children, document.body);
```

Every `position:fixed` overlay now renders through it: proof modal, preview modal, question modal, mobile bottom bar, mobile question chip, condensed total bar, RichTip overlay. Reason (see comment): a WordPress-theme ancestor with `transform`/`filter` traps fixed elements in its stacking context.

### 2.10 `Panel` — REDESIGNED (lines 1560–1739, UNIVERSAL)

New props: `coupon` ({code,label,discount,type,free_shipping}|null) and `totalRef` (callback ref for the condensed-bar sentinel). Key changes:

- **Desktop price card:** brand-cyan gradient → flat ink `CC.heroBg` ("Estimated Total"); rush keeps its green. Coupon block ("CODE · label −$x / You pay $y / Applied at checkout").
- **Sentinel `<div ref={totalRef}>`** at the card top for the IntersectionObserver.
- **"Earliest / Free Ground" two-cell grid REMOVED** (desktop + mobile expanded sheet).
- Availability label: "Availability" → **"As soon as"** (or "Delivery by" when a date was requested); date logic now keys off `sh.hasDest` (see §4.7 rider) — pre-address it shows the production+1 `earliestDate` instead of a guessed ground date.
- Per Unit cell shows struck-through list price + cyan net when a coupon is live.
- **Mobile compact strip:** price + "Details ▾" disclosure on line 1, delivery date full-width on line 2, coupon line 3; $/ea + Qty moved into the expanded sheet; **cost breakdown now gated behind `debugUnlocked` on mobile** (it used to render to every customer).

```jsx
function Panel({result,compact,debugUnlocked,expanded,onToggleExpand,coupon,totalRef}){
  if(!result)return compact?null:<div style={ST.pnl}><p style={{color:CC.light,textAlign:"center",fontSize:14,margin:0,padding:"40px 20px"}}>Configure your booklet to see pricing</p></div>;
  if(result.error?.length>0)return compact?null:<div style={ST.pnl}><div style={{padding:20}}>{result.error.map((e,i)=><p key={i} style={{color:CC.red,fontSize:13,margin:"0 0 8px"}}>{e}</p>)}</div></div>;
  const{total,baseTotal,perUnit,days,tQ,bd,grouped,shipping:sh,saleActive,saleAmount,saleLabel,totalWithoutSale}=result;
  const hasRush=sh&&sh.rushCost>0;
  // Before a destination is entered the free-ground date is a guess on a guess —
  // 2× production buffer plus a placeholder transit — and it read a week later than
  // the job actually needs. Until the address is known, quote production + 1 transit
  // day (which is also what AZ ground is), then switch to the real committed date.
  const _dest=!sh||sh.hasDest;
  const availRange=sh?(_dest?(sh.earliestBizDays===sh.freeDeliveryBizDays?`${sh.earliestBizDays}`:`${sh.earliestBizDays}–${sh.freeDeliveryBizDays}`):`${sh.earliestBizDays}`):String(days);
  // Availability reads as a single date: the rush need-by date when rush is applied,
  // else the free-delivery date — or the production+1 placeholder pre-address.
  // A date the customer picked is an instruction, not an estimate, so it wins the
  // availability display. This used to be conditional and got it wrong two ways: the
  // modern draft fell back to the earliest-possible placeholder whenever no address
  // had been entered yet, and the rest honoured a picked date only when it triggered
  // a rush charge. Either way somebody who chose Aug 14 was told "as soon as Aug 7".
  // sh.needByFmt is non-null exactly when a picked date is valid and not too soon, so
  // it is the whole condition.
  // Compared against the free date, not merely checked for existence: four of these
  // calculators pre-fill the date field with the free delivery date as a convenience,
  // so "a date is set" is the default state and would make every quote read as a
  // promise. A date that DIFFERS from the free one is the customer actually asking
  // for something.
  const requestedDate = sh && sh.needByFmt && sh.needByFmt !== sh.freeDeliveryDate
    ? sh.needByFmt : null;
  const deliveryDate = sh?(requestedDate || (!_dest?sh.earliestDate:((hasRush&&sh.needByFmt)?sh.needByFmt:sh.freeDeliveryDate))) : null;
  // A discount code reduces what the customer pays but never the quoted line price —
  // WooCommerce applies it at cart level. So show it as a deduction FROM the total
  // rather than by rewriting the total, which is also how the order will read.
  const _cpn=coupon&&coupon.discount>0?coupon:null;
  const _cpnAmt=_cpn?Math.min(_cpn.discount,total):0;
  const _afterCpn=Math.max(0,Math.round((total-_cpnAmt)*100)/100);
  // Per unit moves with the code too. Quoting a discounted total beside an
  // undiscounted $/ea leaves the customer to do the division, and the two numbers
  // read as a contradiction. Derived from what they actually pay, so it can't drift.
  const _cpnUnit=(_cpn&&tQ>0)?Math.max(0,_afterCpn/tQ):null;
  const ti=result.ti||{};
  const _factors=[];
  if(ti.coverPaper>0)_factors.push("paper lead time");
  if(ti.vivid>0)_factors.push("vivid printing");
  if(ti.coating>0)_factors.push("coating");
  if(ti.bundling>0)_factors.push("bundling");
  if(ti.roundCorner>0)_factors.push("round corners");
  if(ti.artDesign>0||ti.artEdits>0)_factors.push("artwork");
  if(ti.proof>0)_factors.push("proofing");
  const _extraDays=Object.values(ti).reduce((s,v)=>s+(v||0),0);
  const _hasExtra=_extraDays>=1&&_factors.length>0;

  if(compact){
    const moneyC=Math.max(0,total).toLocaleString("en-US",{minimumFractionDigits:2,maximumFractionDigits:2});
    const [dC,cC]=moneyC.split(".");
    return(
      <div>
        {/* Collapsed, this strip shares a 390px row with the Add to Order button —
            three stat columns beside the price truncated the delivery date to
            "Mon, Aug". Price and the disclosure sit on one line; the delivery date
            gets the strip's full width on a second. $/ea and Qty move into the
            expanded sheet, where the panel is full width and lays them out the way
            desktop does. */}
        <div onClick={onToggleExpand} style={{background:hasRush?CK.rush:CK.cyan,borderRadius:expanded?"6px 6px 0 0":6,padding:"8px 14px",color:hasRush?"#333":"#fff",cursor:"pointer",WebkitTapHighlightColor:"transparent"}}>
          <div style={{display:"flex",alignItems:"center",justifyContent:"space-between",gap:8}}>
            <div style={{minWidth:0}}>
              <div style={{lineHeight:1,display:"flex",alignItems:"baseline",gap:6}}>
                {saleActive&&<span style={{fontSize:13,opacity:.6,textDecoration:"line-through",fontWeight:600}}>${totalWithoutSale.toFixed(2)}</span>}
                <span style={{fontSize:22,fontWeight:800}}>${dC}</span>
                <span style={{fontSize:13,fontWeight:600,opacity:.7,marginLeft:1}}>.{cC}</span>
              </div>
              {saleActive&&<div style={{fontSize:9,fontWeight:700,marginTop:1,letterSpacing:".5px",textTransform:"uppercase"}}>{saleLabel} · Save ${saleAmount.toFixed(2)}</div>}
            </div>
            <div style={{display:"flex",alignItems:"center",gap:5,flexShrink:0}}>
              <span style={{fontSize:9.5,fontWeight:700,letterSpacing:".5px",textTransform:"uppercase",opacity:.75}}>{expanded?"Hide":"Details"}</span>
              <span style={{fontSize:10,opacity:.6,transition:"transform .2s",display:"inline-block",transform:expanded?"rotate(180deg)":"none"}}>▼</span>
            </div>
          </div>
          <div style={{fontSize:10.5,fontWeight:600,opacity:.9,marginTop:3,letterSpacing:".2px",whiteSpace:"nowrap",overflow:"hidden",textOverflow:"ellipsis"}}>
            {requestedDate ? "Delivery by" : "As soon as"} {deliveryDate||availRange+" days"}
          </div>
          {_cpn&&<div style={{fontSize:10.5,fontWeight:700,marginTop:2,letterSpacing:".2px",whiteSpace:"nowrap"}}>
            {_cpn.code} −${_cpnAmt.toFixed(2)} · you pay ${_afterCpn.toFixed(2)}
          </div>}
        </div>
        {expanded&&<div style={{background:CC.white,borderRadius:"0 0 6px 6px",border:`1px solid ${CC.border}`,borderTop:"none",maxHeight:"40vh",overflowY:"auto",WebkitOverflowScrolling:"touch"}}>
          {/* Same stat row desktop shows under its price card, at full width. */}
          <div style={{display:"flex",borderBottom:`1px solid ${CC.border}`}}>
            {[["$/ea","$"+Math.max(0,perUnit).toFixed(2),_cpnUnit!=null?"$"+_cpnUnit.toFixed(2):null],["Qty",tQ.toLocaleString(),null],[requestedDate ? "Delivery by" : "As soon as",deliveryDate||availRange+" days",null]].map(([l,v,net],i)=>(
              <div key={i} style={{flex:1,padding:"9px 6px",textAlign:"center",borderLeft:i?`1px solid ${CC.border}`:"none",background:(i===2&&_hasExtra)?"#fffbeb":"transparent"}}>
                <div style={{fontSize:8.5,fontWeight:600,textTransform:"uppercase",letterSpacing:".5px",color:(i===2&&_hasExtra)?"#92400e":CC.light}}>{l}</div>
                {net
                  ? <><div style={{fontSize:9.5,fontWeight:600,color:CC.light,textDecoration:"line-through",lineHeight:1.1,marginTop:1}}>{v}</div>
                      <div style={{fontSize:12.5,fontWeight:700,color:CK.cyan,lineHeight:1.15}}>{net}</div></>
                  : <div style={{fontSize:12.5,fontWeight:700,color:(i===2&&_hasExtra)?"#b45309":CC.dark,marginTop:1}}>{v}</div>}
              </div>
            ))}
          </div>
          {_hasExtra&&<div style={{padding:"5px 14px",borderBottom:`1px solid ${CC.border}`,background:"#fffbeb"}}>
            <div style={{fontSize:10.5,color:"#92400e",lineHeight:1.4}}>+{Math.round(_extraDays)} day{Math.round(_extraDays)!==1?"s":""} due to {_factors.join(", ")}</div>
          </div>}
          {/* Cost breakdown is internal. Desktop keeps it behind the %3414 unlock;
              mobile rendered it to every customer, which exposed per-line costs. */}
          {debugUnlocked&&<div style={{padding:"6px 12px"}}>{bd.map((r,i)=>(<div key={i} style={{display:"flex",justifyContent:"space-between",padding:"2.5px 0"}}>
            <span style={{fontSize:11,color:CC.light}}>{r.l}</span>
            <span style={{fontSize:11,fontWeight:600,color:r.v<0?CK.cyan:CC.mid}}>{r.v<0?"−":""}${Math.abs(r.v).toFixed(2)}</span>
          </div>))}</div>}
        </div>}
      </div>
    );
  }

  const moneyD=Math.max(0,total).toLocaleString("en-US",{minimumFractionDigits:2,maximumFractionDigits:2});
  const [dD,cD]=moneyD.split(".");
  const eyeCol=hasRush?"rgba(51,51,51,.8)":"rgba(255,255,255,.85)";
  // stacked bar: positive grouped rows + rush (if any); negatives (e.g. discounts) clamped out of the visual bar
  const brkDen=Math.max(0.01,grouped.reduce((s,r)=>s+Math.max(0,r.val),0)+(hasRush?sh.rushCost:0));
  return(
    <div style={{...ST.pnl, pointerEvents: "none"}}>
      {/* Sentinel for the condensed-total bar. Watching the whole panel was wrong — on
          a tall rail the total scrolls away while the panel's lower half is still on
          screen, so the bar stayed hidden exactly when it was needed. Anchored at the
          TOP of the card so it leaves the viewport when the total does. */}
      <div ref={totalRef} style={{height:1,marginBottom:-1}} aria-hidden="true"/>
      {/* Ink price card, not brand cyan: cyan is reserved for the CTA and active
          states so the Add to Order button stays the loudest thing in the rail.
          Rush keeps its own colour — that's a state signal, not decoration. */}
      <div style={{padding:"22px 20px 18px",background:hasRush?`linear-gradient(135deg, ${CK.rush} 0%, ${CK.rush}dd 100%)`:CC.heroBg,color:hasRush?"#333":"#fff",pointerEvents:"none"}}>
        <div style={{fontSize:10,fontWeight:700,textTransform:"uppercase",letterSpacing:"1.5px",color:eyeCol,marginBottom:6}}>{hasRush?"Rush Total":"Estimated Total"}</div>
        <div style={{lineHeight:1,display:"flex",alignItems:"baseline",gap:10,flexWrap:"wrap"}}>
          {saleActive&&<span style={{fontSize:18,opacity:.55,textDecoration:"line-through",fontWeight:700,letterSpacing:"-.5px"}}>${totalWithoutSale.toFixed(2)}</span>}
          <span style={{fontSize:38,fontWeight:800,letterSpacing:"-1.5px"}}>${dD}</span>
          <span style={{fontSize:22,fontWeight:700,opacity:.7,letterSpacing:"-0.5px",marginLeft:-9}}>.{cD}</span>
          {saleActive&&<span style={{display:"inline-block",background:CK.magenta,color:"#fff",fontSize:11,fontWeight:800,letterSpacing:".5px",padding:"3px 8px",borderRadius:4,textTransform:"uppercase"}}>{saleLabel} · Save ${saleAmount.toFixed(2)}</span>}
        </div>
        {_cpn&&(
          <div style={{marginTop:10,paddingTop:9,borderTop:`1px solid ${hasRush?"rgba(0,0,0,.15)":"rgba(255,255,255,.18)"}`}}>
            <div style={{display:"flex",alignItems:"baseline",justifyContent:"space-between",gap:10,fontSize:12.5,fontWeight:700}}>
              <span style={{textTransform:"uppercase",letterSpacing:".5px",opacity:.85}}>{_cpn.code} · {_cpn.label}</span>
              <span>−${_cpnAmt.toFixed(2)}</span>
            </div>
            <div style={{display:"flex",alignItems:"baseline",justifyContent:"space-between",gap:10,marginTop:6}}>
              <span style={{fontSize:11,fontWeight:700,textTransform:"uppercase",letterSpacing:"1px",opacity:.8}}>You pay</span>
              <span style={{fontSize:24,fontWeight:800,letterSpacing:"-.7px"}}>${_afterCpn.toFixed(2)}</span>
            </div>
            <div style={{fontSize:10,opacity:.62,marginTop:3,lineHeight:1.35}}>Applied at checkout{_cpn.free_shipping?" · includes free shipping":""}</div>
          </div>
        )}
        {hasRush&&<div style={{fontSize:13.5,fontWeight:800,marginTop:6,letterSpacing:".2px"}}>Delivery by {sh.needByFmt}</div>}
        {hasRush&&debugUnlocked&&<div style={{fontSize:12,opacity:.75,marginTop:4}}>Base ${baseTotal.toFixed(2)} + Rush ${sh.rushCost.toFixed(2)} ({sh.rushMultiplier.toFixed(2)}×)</div>}
      </div>
      <div style={{display:"flex",borderBottom:`1px solid ${CC.border}`}}>
        {[["Per Unit","$"+Math.max(0,perUnit).toFixed(2),_cpnUnit!=null?"$"+_cpnUnit.toFixed(2):null],["Qty",tQ.toLocaleString(),null]].map(([l,v,net],i)=>(
          <div key={i} style={{flex:1,padding:"11px 8px",textAlign:"center",borderRight:`1px solid ${CC.border}`}}>
            <div style={{fontSize:9.5,fontWeight:600,textTransform:"uppercase",letterSpacing:".5px",color:CC.light,marginBottom:1}}>{l}</div>
            {net
              ? <><div style={{fontSize:10.5,fontWeight:600,color:CC.light,textDecoration:"line-through",lineHeight:1.1}}>{v}</div>
                  <div style={{fontSize:14.5,fontWeight:700,color:CK.cyan,lineHeight:1.15}}>{net}</div></>
              : <div style={{fontSize:14.5,fontWeight:700,color:CC.dark}}>{v}</div>}
          </div>
        ))}
        <div style={{flex:1,padding:"11px 8px",textAlign:"center",background:_hasExtra?"#fffbeb":"transparent",transition:"background .3s"}}>
          <div style={{fontSize:9.5,fontWeight:600,textTransform:"uppercase",letterSpacing:".5px",color:_hasExtra?"#92400e":CC.light,marginBottom:1}}>{requestedDate ? "Delivery by" : "As soon as"}</div>
          <div style={{fontSize:13.5,fontWeight:700,color:_hasExtra?"#b45309":CC.dark}}>{deliveryDate||availRange+" days"}</div>

        </div>
      </div>
      {_hasExtra&&<div style={{padding:"5px 18px",borderBottom:`1px solid ${CC.border}`,background:"#fffbeb"}}>
        <div style={{fontSize:10.5,color:"#92400e",lineHeight:1.4}}>* +{Math.round(_extraDays)} day{Math.round(_extraDays)!==1?"s":""} due to {_factors.join(", ")}</div>
      </div>}
      {debugUnlocked&&<details style={{borderTop:`1px solid ${CC.border}`,pointerEvents:"auto"}}><summary style={{padding:"10px 18px",fontSize:10.5,color:CC.light,cursor:"pointer",fontWeight:600,letterSpacing:".5px",textTransform:"uppercase"}}>Line Items</summary>
        <div style={{padding:"0 18px 14px"}}>{bd.map((r,i)=>(<div key={i} style={{display:"flex",justifyContent:"space-between",padding:"2px 0"}}>
          <span style={{fontSize:11.5,color:CC.light}}>{r.l}</span>
          <span style={{fontSize:11.5,fontWeight:600,color:r.v<0?CK.cyan:CC.mid}}>{r.v<0?"−":""}${Math.abs(r.v).toFixed(2)}</span>
        </div>))}</div>
      </details>}
    </div>
  );
}

// ── Flat dimensioned "spec" drawing shown in the preview card before artwork is
//    uploaded. Charts width × height with dimension lines; for booklets, marks the
```

### 2.11 `DimSpec` — unchanged. `trimDims` — NEW helper (lines 1789–1805)

Single source of truth for trim W×H, replacing the label-regex parse in `SidebarBookPreview` (fixes "Custom Size" silently reporting 5.5×8.5). SADDLE-SPECIFIC as written (bindDir), but the pattern — one dims helper shared by sidebar preview and modal — applies to any calculator with both.

```js
// Trim dimensions in inches — the single source of truth, used by both the sidebar
// preview and the proof/preview modal. Previously the sidebar parsed the numbers out
// of the *display label* and fell back to 5.5x8.5 when that failed, so "Custom Size"
// (a label with no digits in it) silently reported the default size while the modal,
// which computed dimensions properly, disagreed.
function trimDims(sizeLabel, customLong, customShort, bindDir){
  let w = 5.5, h = 8.5;
  if (sizeLabel === "Custom Size") {
    w = Math.min(customLong || 5.5, customShort || 5.5);
    h = Math.max(customLong || 8.5, customShort || 8.5);
    if (bindDir === "short") { const t = w; w = h; h = t; }
  } else {
    const m = (sizeLabel || "").match(/([\d.]+)\s*[×x]\s*([\d.]+)/);
    if (m) { w = parseFloat(m[1]); h = parseFloat(m[2]); }
  }
  return { w, h };
}
```

### 2.12 `RichTip` — 2-line change (lines 2371, 2402, UNIVERSAL)

Overlay wrapped in `<Float>…</Float>` (was fragment), and the mobile sheet is offset above the bottom bar: `bottom: "var(--pps-bottombar, 0px)"`, `maxHeight: "calc(80vh - var(--pps-bottombar, 0px))"` (see §4.5 for the variable's publisher).

### 2.13 `ST` — becomes `buildST()` + `pps_applyTheme` (lines 6851–6897, UNIVERSAL)

`ST` is now built by a function so the theme swap can rebuild it. Full old→new value table in §1.3; verbatim:

```js

// ═══════════════════════════════════════════════════════════════
// STYLES
// ═══════════════════════════════════════════════════════════════
function buildST(){ return {
  f:{marginTop:18},
  lbl:{display:"block",fontSize:14,fontWeight:600,color:CC.mid,marginBottom:7,letterSpacing:".005em"},
  sel:{width:"100%",padding:"20px 34px 20px 14px",borderRadius:9,border:`1px solid ${CC.border}`,background:CC.white,fontSize:15,color:CC.dark,fontFamily:"inherit",appearance:"none",cursor:"pointer",outline:"none",boxShadow:"0 1px 2px rgba(16,24,40,.04)",transition:"border-color .15s ease, box-shadow .15s ease"},
  selA:{position:"absolute",right:12,top:"50%",transform:"translateY(-50%)",color:CC.light,pointerEvents:"none"},
  inp:{width:"100%",padding:"20px 14px",borderRadius:9,border:`1px solid ${CC.border}`,background:CC.white,fontSize:15,color:CC.dark,fontFamily:"inherit",outline:"none",boxSizing:"border-box",boxShadow:"0 1px 2px rgba(16,24,40,.04)",transition:"border-color .15s ease, box-shadow .15s ease"},
  // Segmented control: inset track + flat brand thumb (replaces the bevelled 5-stop gradient)
  pills:{display:"flex",borderRadius:10,border:`1px solid ${CC.border}`,overflow:"hidden",background:CC.track,padding:3,gap:3,boxShadow:"inset 0 1px 2px rgba(16,24,40,.04)"},
  pill:{flex:1,padding:"17px 10px",fontSize:14,fontWeight:500,fontFamily:"inherit",border:"none",borderRadius:7,background:"transparent",color:CC.mid,cursor:"pointer",transition:"all .18s cubic-bezier(.4,0,.2,1)",display:"flex",alignItems:"center",justifyContent:"center",boxShadow:"none"},
  pillOn:{background:CK.cyan,color:"#fff",fontWeight:600,boxShadow:"0 1px 2px rgba(0,126,255,.35)"},
  row2:{display:"grid",gridTemplateColumns:"1fr 1fr",gap:18},
  row3:{display:"grid",gridTemplateColumns:"1fr 1fr 1fr",gap:18},
  hr:{height:1,background:CC.border,margin:"16px 0 4px"},
  setBox:{marginTop:12,padding:14,borderRadius:10,background:CC.track,border:`1px solid ${CC.border}`},
  addBtn:{width:"100%",marginTop:12,padding:"11px",borderRadius:10,border:`1px dashed ${CC.border}`,background:CC.white,color:CK.cyan,fontSize:14,fontWeight:600,cursor:"pointer",fontFamily:"inherit",transition:"all .15s ease"},
  err:{color:CC.red,fontSize:13,margin:"10px 0 0",padding:"10px 12px",background:CC.errBg,borderRadius:9,border:`1px solid ${CC.errBorder}`},
  note:{fontSize:12,color:CC.light,margin:"5px 0 0"},
  // Section cards: the old left-edge accent stripe is gone; depth now comes from a
  // layered shadow instead of a hard border.
  card:{background:CC.white,borderRadius:14,border:`1px solid ${CC.border}`,overflow:"hidden",boxShadow:"0 1px 2px rgba(16,24,40,.04), 0 2px 6px rgba(16,24,40,.03)"},
  cardH:{width:"100%",display:"flex",alignItems:"center",justifyContent:"space-between",padding:"15px 18px",background:CC.white,border:"none",borderBottom:`1px solid ${CC.border}`,cursor:"pointer",fontFamily:"inherit",fontSize:15},
  pnl:{background:CC.white,borderRadius:14,border:`1px solid ${CC.border}`,overflow:"hidden",boxShadow:"0 1px 2px rgba(16,24,40,.04), 0 6px 16px rgba(16,24,40,.05)"},
  secSub:{margin:"3px 0 0",fontSize:11.5,fontWeight:400,color:CC.light,lineHeight:1.4,textTransform:"none",letterSpacing:0},
  brkBar:{height:8,borderRadius:99,background:"#eef2f6",overflow:"hidden",display:"flex",marginBottom:10},
  specCard:{background:CC.dark,color:"#e2e8f0",borderRadius:12,padding:"16px 18px",marginTop:16,fontFamily:"ui-monospace,SFMono-Regular,Menlo,Consolas,monospace"},
  specEye:{fontSize:10,letterSpacing:".14em",opacity:.55,marginBottom:10},
  specGrid:{display:"grid",gridTemplateColumns:"1fr 1fr",gap:"8px 14px",fontSize:12},
}; }
const ST = buildST();

/**
 * Swap theme in place. CC/ST are module-level objects read at render time, so
 * mutating them and re-rendering repaints everything — no theme prop threading.
 */
function pps_applyTheme( name ) {
  const p = PALETTES[ name ] || PALETTES.light;
  Object.assign( CC, p );
  const next = buildST();
  for ( const k in ST ) delete ST[ k ];
  Object.assign( ST, next );
  CHIP = buildCHIP();
  if ( typeof document !== "undefined" ) document.documentElement.dataset.ppsTheme = name;
}
```

### 2.14 `SetPreview` (proof/preview modal) — REDESIGNED, SADDLE-SPECIFIC body, universal patterns

The modal component itself (lines 2641–5050) is saddle-only — other calculators have their own proof modals (brochure `SheetPreview`, coupon sheets, sticker). Port the *patterns*, not the body:

- **Modal chrome via CSS variables** (§3.6): dark-only hardcoded `#1a1d23` surfaces → `var(--bp-surface)`/`--bp-line`/`--bp-ink`/`--bp-dim`/`--bp-faint`/`--bp-btn`/`--bp-scrim`, themed on `:root[data-pps-theme="dark"]`. Every `rgba(255,255,255,…)` control in a target's modal maps to a `--bp-*` token.
- **`.bp-seg` segmented control** for mode/view toggles (Cover/Pages, single/grid, proof type) — replaces butted-together buttons.
- **Approve CTA**: `.bp-approve` (shimmer + pulse brand CTA, `.bp-approved` green done-state, `.bp-prooflabel`) replaces the red-gradient `.bp-approve-cta`; proof-type choice split out into a `.bp-seg` — "proof type is a CHOICE; approving is an ACTION".
- **View-settings gear popover** (`.bp-viewmenu`, `data-bp-viewmenu`, outside-click/Escape dismissal): guides toggle, zoom group, magnifier toggle move off the page-nav row into a gear menu with a blue "settings changed" dot.
- **Condensed `FitToggle`**: Crop | Fit-menu (Fit/Fill/Stretch) | Scale | Rotate-menu (per-page absolute 90/180/270/0 + all-pages 90/180/270/head-to-spine/foot-to-spine) | ↶ Undo — action-picker `<select value="">` pattern so re-picking fires again. Replaces the 7-button row that overflowed phones.
- **Both modals wrapped in `<Float>`**.
- Radii 3–6 → 8–10 throughout; thumbnails/labels bumped ~1.5px.

Saddle-only feature riders inside SetPreview (do NOT port): blank-page placement chooser (`blankPlacement` "covers"/"end" + preflight "Needs your attention" box + overflow/at-ceiling messaging), cover-vs-inside greyscale `gsFor(i)` (replaces `gsFilter`; needs `coverColor` prop), reader-spread flat view in the downloadable preview HTML, `staffUnlocked` gating of the approval-package downloads (gate PATTERN is universal for calculators with approval packages: `approvalPkg && staffUnlocked` instead of `artApproved || debug`), removed Reader's-Spread checkbox (machinery kept, owner-tabled 2026-07-30).

### 2.15 `SidebarBookPreview` — SADDLE-SPECIFIC

Now takes `customLong`/`customShort`/`bindDir` and uses `trimDims` instead of parsing the size label. `MobilePreviewBar` spreads props through, so it needed no edit. (Its collapsed strip still label-parses — cosmetic, saddle-only.)

### 2.16 `submitToWooCommerce` — +1 param (UNIVERSAL if porting coupons)

Signature gains trailing `couponCode`; appends `fd.append("pps_coupon", couponCode)` when set (line ~5290). Server side already exists in `pps-calculators.php`: REST `POST /wp-json/pps/v1/coupon/preview` (line ~3205, rate-limited) and `pps_coupon` intake in add-to-cart (line ~1866).

---

## 3. Layout deltas

### 3.1 Page skeleton — mostly unchanged

Same 1180px `maxWidth` container, same flex `column/row` split, same paddings (`0 10px 140px` mobile / `0 24px 80px` desktop), same 320px rail width. App root `fontFamily` → `var(--pps-sans)`.

### 3.2 Hero — font only

The bold hero (magenta eyebrow "Configure · <Product>", 44px/28px uppercase h1 with cyan second line) already exists in the classic files; the only delta is `h1` `fontFamily` → `var(--pps-display)`. Modern hero verbatim skeleton (lines 6141–6152):

```jsx
<section style={{maxWidth:1180,margin:"0 auto",padding:mob?"18px 14px 6px":"34px 28px 18px"}}>
  <div style={{display:"flex",alignItems:"flex-end",justifyContent:"space-between",flexWrap:"wrap",gap:mob?10:20}}>
    <div style={{flex:"1 1 320px",minWidth:0}}>
      <div style={{fontSize:10,fontWeight:700,letterSpacing:".14em",textTransform:"uppercase",color:CK.magenta}}>Configure · Saddlestitch Booklet</div>
      <h1 style={{fontFamily:"var(--pps-display)",fontWeight:800,lineHeight:1,letterSpacing:"-0.02em",color:CC.dark,textTransform:"uppercase",margin:mob?"8px 0 0":"10px 0 0",maxWidth:720,fontSize:mob?28:44}}>
        Saddlestitch Booklet<br/>
        <span style={{color:CK.cyan}}>Instant Quote</span>
      </h1>
    </div>
  </div>
</section>
```

### 3.3 Section column (UNIVERSAL)

- New **Form settings gear row** sits above Section 1 (§4.2).
- Every `Sec` gains `step={n}` and `summary={secSummary.sN}`; Section 1 gains `headerField` (job name); the Artwork section gains `keepMounted`.
- **Job Name moved out of the section body into the Sec header** — the old 3-col `qty | pages | job name` row becomes 2-col (`r3` → `r2`).
- Vivid Print: **hidden when unavailable** instead of shown-disabled-with-explainer (`r.vividOK && …`).
- Shipping address form: labeled inputs with real `id`/`name`/`autoComplete`; **City/State/ZIP on `addrGrid`** (`2fr 1fr 1fr` desktop, 2-col mobile with City spanning); State select gains an empty `<option value="">Select&hellip;</option>` and `shipState` defaults to `""` not `"AZ"`; proof-address inputs gain `section-proof shipping` autocomplete tokens.
- Free-delivery box: `CK.cyanLight` → `CC.tint`; **turnaround component breakdown, transit line and shop-clock/cutoff line gated behind `debugUnlocked`**; mobile keeps the earliest-date line customer-visible.
- Mobile-only discount card `{mob&&<div style={{...ST.card,padding:"12px 14px"}}>{discountField()}</div>}` after the last section.

### 3.4 Desktop rail (UNIVERSAL) — lines 6601–6626

Rail is now **sticky** and reordered: preview card → Panel (+sentinel) → **CTA directly under the panel** → missing-destination line → discount field → "Ask a question about this quote →" → windowed quantity table → "Save configuration" (renamed from "Share this configuration") → switch links → debug widgets. The duplicate bottom Add-to-Order button was deleted; the CTA carries `data-pps-cta`.

```jsx
        {/* Sticky quote rail — the price and CTA stay in view while the job is built,
            instead of scrolling away on long configurations. */}
        {!mob&&<div ref={railRef} style={{width:320,flexShrink:0,position:"sticky",top:16,alignSelf:"flex-start"}}>
          <div style={{display:"flex",flexDirection:"column",gap:10,pointerEvents:"none"}}>
            <SidebarBookPreview sizeLabel={sizeLabel} customLong={customLong} customShort={customShort} bindDir={bindDir} pages={sets[0]?.pages||0} quantity={sets.reduce((s,x)=>s+(x.qty||0),0)||sets[0]?.qty||0} coverColor={coverColor} coverPaper={coverPaper} insidePaper={insidePaper} coverMode={coverMode} coverArt={coverArt} backCoverArt={backCoverArt} bindEdge={result?.bindingEdge || result?.sz?.bindEdge} onOpenPreview={openPreview}/>
            <div><Panel result={result} debugUnlocked={debugUnlocked} coupon={coupon} totalRef={setTotalEl}/></div>
          </div>
          <button type="button" data-pps-cta onClick={handleAddToOrder} style={{width:"100%",marginTop:16,padding:"17px 18px",borderRadius:10,border:"none",background:(result?.error?.length||submitting)?CC.light:CK.cyan,color:"#fff",fontSize:16.5,fontWeight:750,cursor:(result?.error?.length||submitting)?"default":"pointer",fontFamily:"inherit",opacity:(result?.error?.length||submitting)?.5:1,letterSpacing:".01em",boxShadow:(result?.error?.length||submitting)?"none":"0 4px 14px rgba(0,126,255,.38)",transition:"transform .15s ease, box-shadow .15s ease, filter .15s ease, opacity .15s"}} disabled={!!result?.error?.length||submitting}>{submitting&&uploadProgress?<div><div style={{fontSize:12}}>{uploadProgress.phase==="upload"?`Uploading… ${uploadProgress.pct}%`:"Adding…"}</div><div style={{width:"100%",height:3,borderRadius:2,background:"rgba(255,255,255,.2)",overflow:"hidden",marginTop:4}}><div style={{height:"100%",borderRadius:2,background:"#fff",transition:"width .15s",width:uploadProgress.phase==="upload"?`${((uploadProgress.fileIdx/uploadProgress.fileCount)+(uploadProgress.pct/100/uploadProgress.fileCount))*100}%`:"100%"}}/></div></div>:submitting?"Uploading…":"Add to Order"}</button>
          {/* Shipping is required to place the order, but the CTA keeps its own label —
              a button that renames itself to describe a missing field reads as broken.
              Pressing it jumps to the shipping section. */}
          {missingDest && (
            <div style={{marginTop:7,fontSize:12,fontWeight:600,color:CC.red,lineHeight:1.35}}>
              {missingDestMsg}
            </div>
          )}
          {/* Discount code — one definition, mounted here and in the mobile flow. */}
          <div style={{marginTop:10}}>{discountField()}</div>
          {/* Secondary action, directly under the CTA: visible without scrolling past
              the pricing table, which is where it used to sit. */}
          <button type="button" onClick={()=>{setQOpen(true);setQStatus("idle");setQErrorMsg("");}}
            style={{width:"100%",marginTop:9,padding:"11px 10px",borderRadius:9,border:`1px solid ${CK.cyan}55`,
              background:CC.tint,color:CK.cyan,fontSize:13,fontWeight:650,cursor:"pointer",fontFamily:"inherit",
              transition:"all .2s"}}>
            Ask a question about this quote →
          </button>
```

### 3.5 Quantity table windowing (UNIVERSAL) — lines 6627–6706

Collapsed, the table shows a 5-row window centred on the current qty; "Show more ▾ / Show fewer ▴" toggles all tiers; selected row uses `CC.rowHi`; coupon-active tiers show struck list price + cyan net via `netOf()`; header notes "CODE applied".

```jsx
          {projections && <div style={{...ST.pnl,marginTop:10,overflow:"hidden"}}>
            <div style={{padding:"10px 18px",background:"#fafafa",borderBottom:`1px solid ${CC.border}`,display:"flex",alignItems:"center",justifyContent:"space-between",gap:8,flexWrap:"wrap"}}>
              <div style={{fontSize:10,fontWeight:700,textTransform:"uppercase",letterSpacing:"1px",color:CC.light,minWidth:0,flex:"1 1 auto",lineHeight:1.5}}>Quantity Pricing
                {(parseFloat(tableAdjPct)||0)!==0&&<span style={{marginLeft:6,color:CK.magenta,letterSpacing:".3px"}}>{(parseFloat(tableAdjPct)>0?"+":"")+parseFloat(tableAdjPct)}% applied</span>}
                {couponActive&&<span style={{marginLeft:6,color:CK.cyan,letterSpacing:".3px",whiteSpace:"nowrap"}}>{coupon.code} applied</span>}
              </div>
              <div style={{display:"flex",alignItems:"center",gap:8,flexShrink:0,marginLeft:"auto"}}>
                {debugUnlocked&&(
                  <span title="Debug only — marks this table up or down. Does NOT change the order price." style={{display:"inline-flex",alignItems:"center",gap:3}}>
                    <span style={{fontSize:9,fontWeight:700,color:CC.light,textTransform:"uppercase",letterSpacing:".4px"}}>Adjust Table</span>
                    <input type="text" inputMode="decimal" value={tableAdjPct} onChange={e=>setTableAdjPct(e.target.value)} placeholder="0"
                      style={{width:46,padding:"2px 5px",fontSize:11,textAlign:"right",border:`1px solid ${(parseFloat(tableAdjPct)||0)!==0?CK.magenta:CC.border}`,borderRadius:4,fontFamily:"inherit",color:CC.dark,background:"#fff",outline:"none"}}/>
                    <span style={{fontSize:10,color:CC.light,fontWeight:600}}>%</span>
                  </span>
                )}
                <button type="button" onClick={copyProjections} title="Copy pricing table" style={{background:"none",border:"none",cursor:"pointer",padding:"2px 6px",fontSize:11,color:projCopied?"#16a34a":CC.light,fontFamily:"inherit",fontWeight:600,transition:"color .2s",letterSpacing:".3px"}}>{projCopied?"✓ Copied":"Copy"}</button>
              </div>
            </div>
            <table style={{width:"100%",borderCollapse:"collapse",fontSize:12.5}}>
              <thead>
                <tr style={{borderBottom:`1px solid ${CC.border}`}}>
                  <th style={{padding:"7px 18px",textAlign:"left",fontSize:10,fontWeight:600,color:CC.light,textTransform:"uppercase",letterSpacing:".5px"}}>Qty</th>
                  <th style={{padding:"7px 10px",textAlign:"right",fontSize:10,fontWeight:600,color:CC.light,textTransform:"uppercase",letterSpacing:".5px"}}>Unit</th>
                  <th style={{padding:"7px 18px",textAlign:"right",fontSize:10,fontWeight:600,color:CC.light,textTransform:"uppercase",letterSpacing:".5px"}}>Total</th>
                </tr>
              </thead>
              <tbody>
                {(()=>{
                  // Collapsed, the table is a window centred on the selected quantity —
                  // two rows either side, held at five rows even when the selection sits
                  // at one end, so the neighbours a customer wants to compare are the
                  // ones on screen. Expanded shows every tier.
                  const rows=projections.filter(p=>p.total!==null);
                  const ci=Math.max(0,rows.findIndex(p=>p.current));
                  const WIN=2;
                  let lo=Math.max(0,ci-WIN), hi=Math.min(rows.length-1,ci+WIN);
                  while(hi-lo<WIN*2 && (lo>0||hi<rows.length-1)){ if(lo>0)lo--; else hi++; }
                  const shown=projOpen?rows:rows.slice(lo,hi+1);
                  // With a code applied every tier is re-priced through the same netOf()
                  // the price card uses. The list price stays visible, struck through, so
                  // the table still shows what the job costs as well as what they pay.
                  const money=n=>n.toLocaleString("en-US",{minimumFractionDigits:2,maximumFractionDigits:2});
                  return shown.map((p,i)=>{
                    const nT=couponActive?netOf(p.total):null;
                    const nU=nT!=null&&p.qty>0?nT/p.qty:null;
                    return (
                    <tr key={p.qty} style={{borderBottom:`1px solid ${CC.border}22`,background:p.current?CC.rowHi:"transparent",cursor:p.current?"default":"pointer"}}
                      onClick={() => {if(!p.current) upSet(0,{qty:p.qty})}}>
                      <td style={{padding:"6px 18px",fontWeight:p.current?700:400,color:p.current?CK.cyan:CC.dark}}>
                        {p.qty.toLocaleString()}{p.current&&<span style={{fontSize:9,color:CK.cyan,marginLeft:4}}>●</span>}
                      </td>
                      <td style={{padding:"6px 10px",textAlign:"right",color:CC.mid,fontWeight:500}}>
                        {nU!=null
                          ? <><div style={{fontSize:10,color:CC.light,textDecoration:"line-through",lineHeight:1.15}}>${p.perUnit.toFixed(2)}</div>
                              <div style={{color:CK.cyan,fontWeight:600,lineHeight:1.2}}>${nU.toFixed(2)}</div></>
                          : <>${p.perUnit.toFixed(2)}</>}
                      </td>
                      <td style={{padding:"6px 18px",textAlign:"right",fontWeight:p.current?700:500,color:p.current?CK.cyan:CC.dark}}>
                        {nT!=null
                          ? <><div style={{fontSize:10,color:CC.light,textDecoration:"line-through",fontWeight:500,lineHeight:1.15}}>${money(p.total)}</div>
                              <div style={{color:CK.cyan,fontWeight:p.current?700:600,lineHeight:1.2}}>${money(nT)}</div></>
                          : <>${money(p.total)}</>}
                      </td>
                    </tr>
                    );
                  });
                })()}
              </tbody>
            </table>
            {(()=>{
              const n=projections.filter(p=>p.total!==null).length;
              if(n<=5) return null;
              return <button type="button" onClick={()=>setProjOpen(v=>!v)}
                style={{width:"100%",padding:"9px 10px",border:"none",borderTop:`1px solid ${CC.border}`,
                  background:CC.track,color:CK.cyan,fontSize:12,fontWeight:500,letterSpacing:".01em",
                  cursor:"pointer",fontFamily:"inherit"}}>
                {projOpen?"Show fewer ▴":"Show more ▾"}
              </button>;
            })()}
          </div>}
```

### 3.6 Modal chrome CSS (UNIVERSAL) — head `<style>` lines 145–189 and 199–254

```css
  /* ── Modal chrome ──────────────────────────────────────────────────────────
     The proof and 3D preview modals used to be their own dark-only surface with
     white-on-black controls hardcoded inline. They now share the calculator's
     surface, ink and hairline tokens through these variables, so both modals sit
     in the modern skin and follow the light/dark toggle with everything else.
     Set here rather than from CC so plain CSS rules can reach them too. */
  :root{
    --bp-surface:#fff; --bp-surface-2:#f6f7f9; --bp-line:#e3e8ef;
    --bp-ink:#0f172a; --bp-dim:#475569; --bp-faint:#94a3b8;
    --bp-btn:#f1f4f8; --bp-scrim:rgba(15,23,42,.55);
  }
  :root[data-pps-theme="dark"]{
    --bp-surface:#161b22; --bp-surface-2:#0f151d; --bp-line:#2a3340;
    --bp-ink:#e6edf6; --bp-dim:#9fb0c6; --bp-faint:#7183a0;
    --bp-btn:#0f151d; --bp-scrim:rgba(2,6,12,.66);
  }
  .bp-modal-bg{position:fixed;inset:0;background:var(--bp-scrim);z-index:9999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
  .bp-modal{background:var(--bp-surface);border:1px solid var(--bp-line);border-radius:16px;width:90%;max-width:940px;max-height:90vh;display:flex;flex-direction:column;overflow:visible;box-shadow:0 4px 6px rgba(16,24,40,.06), 0 24px 64px rgba(16,24,40,.28)}
  .bp-modal-head{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid var(--bp-line);flex-shrink:0;overflow:visible;border-radius:16px 16px 0 0;background:var(--bp-surface)}
  .bp-modal-body{padding:16px 20px;overflow-y:auto;flex:1;min-height:0;border-radius:0 0 16px 16px;background:var(--bp-surface-2)}
  .bp-modal-title{font-family:var(--pps-display);font-weight:650;letter-spacing:-0.01em;color:var(--bp-ink)}
  .bp-mode-btn{background:var(--bp-btn);border:1px solid var(--bp-line);color:var(--bp-dim);padding:7px 18px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;transition:all .2s;font-family:inherit}
  .bp-mode-btn:hover{background:rgba(0,126,255,.1);border-color:#007eff;color:#007eff}
  .bp-mode-btn.on{background:#007eff;border-color:#007eff;color:#fff;box-shadow:0 2px 10px rgba(0,126,255,.3)}
  .bp-nav-btn{background:var(--bp-btn);border:1px solid var(--bp-line);color:var(--bp-dim);width:36px;height:36px;min-width:36px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:all .2s;font-family:inherit;flex-shrink:0;box-sizing:border-box}
  .bp-nav-btn:hover:not(:disabled){background:rgba(0,126,255,.12);border-color:#007eff;color:#007eff}
  .bp-nav-btn:disabled{opacity:.25;cursor:not-allowed}
  /* Segmented control, matching the form's Pil: inset track with a rounded thumb
     rather than butted-together buttons, so the modals read as the same system. */
  .bp-seg{display:flex;gap:3;padding:3px;border-radius:10px;border:1px solid var(--bp-line);
    background:var(--bp-btn);box-shadow:inset 0 1px 2px rgba(16,24,40,.04)}
  .bp-seg-btn{padding:6px 14px;border:none;border-radius:7px;background:transparent;color:var(--bp-dim);
    font-size:11.5px;font-weight:500;font-family:inherit;cursor:pointer;transition:all .18s cubic-bezier(.4,0,.2,1);
    display:flex;align-items:center;justify-content:center;gap:5px}
  .bp-seg-btn:hover:not(.on){color:#007eff}
  .bp-seg-btn.on{background:#007eff;color:#fff;font-weight:600;box-shadow:0 1px 2px rgba(0,126,255,.35)}
  /* View settings popover — guides, zoom and the loupe now live behind a gear so the
     page-nav row has room for the page label instead of six competing controls. */
  .bp-viewmenu{position:absolute;top:calc(100% + 6px);right:0;z-index:70;min-width:236px;padding:8px;
    background:var(--bp-surface);border:1px solid var(--bp-line);border-radius:12px;
    box-shadow:0 4px 6px rgba(16,24,40,.06), 0 12px 28px rgba(16,24,40,.18)}
  .bp-viewmenu-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:7px 8px;border-radius:8px}
  .bp-viewmenu-row + .bp-viewmenu-row{border-top:1px solid var(--bp-line)}
  .bp-viewmenu-label{font-size:12px;font-weight:600;color:var(--bp-ink)}
  /* Transparency checker (Photoshop-style) — used as background where art doesn't cover */
```
```css
  }
  /* Approve Now CTA — strong call-to-action when art needs approval */
  /* Approve artwork — the primary action in the proof modal.
     It used to be a red five-stop gloss with a hard red pulse, welded to the two
     proof-type buttons inside a red 2px frame. Three alarm signals stacked on the
     one control we actually want pressed, and red reads as "something is wrong"
     rather than "confirm". It is now the same flat brand CTA as Add to Order, with
     one soft brand ring to keep the nudge, and it turns green once approved. */
  @keyframes bp-approve-pulse{
    0%,100%{box-shadow:0 0 0 0 rgba(0,126,255,.42),0 2px 10px rgba(0,126,255,.32)}
    50%{box-shadow:0 0 0 7px rgba(0,126,255,0),0 2px 10px rgba(0,126,255,.32)}
  }
  /* A light sweep travels across the button on a loop. It is the one control in the
     modal we want pressed, and flat blue read as just another button beside the proof
     picker. The gradient is oversized and its position animated, so nothing paints
     outside the button and there is no overlay element to sit above the label. */
  @keyframes bp-approve-shimmer{
    from{background-position:150% 0}
    to{background-position:-50% 0}
  }
  .bp-approve{display:inline-flex;align-items:center;justify-content:center;gap:8px;
    padding:14px 23px;border:none;border-radius:10px;cursor:pointer;font-family:inherit;
    font-size:15px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;
    color:#fff;white-space:nowrap;
    background:linear-gradient(105deg,#0062cc 0%,#007eff 34%,#8ccbff 50%,#007eff 66%,#0062cc 100%);
    background-size:300% 100%;
    animation:bp-approve-shimmer 2.9s linear infinite;
    box-shadow:0 3px 14px rgba(0,126,255,.4);
    transition:transform .15s ease,box-shadow .15s ease}
  /* Nudge adds the expanding ring on top of the sweep; both run together. */
  .bp-approve.nudge{animation:bp-approve-shimmer 2.9s linear infinite,
    bp-approve-pulse 2.4s ease-in-out infinite}
  /* Hover drops the ring but keeps the sweep — killing all animation on hover made the
     button look dead exactly when someone was about to press it. */
  .bp-approve:hover:not(:disabled){transform:translateY(-1px);
    box-shadow:0 8px 22px rgba(0,126,255,.5);
    animation:bp-approve-shimmer 1.5s linear infinite}
  .bp-approve:active:not(:disabled){transform:translateY(0);box-shadow:0 2px 8px rgba(0,126,255,.4)}
  /* Generating: a frozen gradient would strand a pale band mid-button, so go flat. */
  .bp-approve:disabled{cursor:wait;animation:none;background:#0062cc;filter:saturate(.7)}
  /* The global reduced-motion rule only shortens durations, which would freeze the
     sweep at whatever position it stopped on. Give it a flat brand fill instead. */
  @media (prefers-reduced-motion: reduce){
    .bp-approve,.bp-approve.nudge,.bp-approve:hover:not(:disabled){
      background:#007eff;background-size:auto;animation:none}
  }
  /* Done state: a status, not a button — flat green, no lift, no pointer. */
  .bp-approved{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:9px;
    background:#16a34a;color:#fff;font-size:13px;font-weight:700;letter-spacing:.01em;white-space:nowrap;
    box-shadow:0 2px 10px rgba(22,163,74,.28)}
  .bp-prooflabel{font-size:10px;font-weight:700;color:var(--bp-dim);letter-spacing:.08em;
    text-transform:uppercase;flex-shrink:0}

  /* Primary CTA — lifts on hover so it reads as the one thing to press. */
  [data-pps-cta]:hover:not(:disabled){transform:translateY(-1px);filter:brightness(1.06);box-shadow:0 7px 20px rgba(0,126,255,.48) !important}
  [data-pps-cta]:active:not(:disabled){transform:translateY(0);box-shadow:0 3px 10px rgba(0,126,255,.4) !important}
```

### 3.7 App inline `<style>` block (UNIVERSAL) — lines 6126–6139

Mobile field min-height rises 46→60px and covers `tel`/`email`:

```jsx
      <style>{`
                button:focus-visible,[role="button"]:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,[tabindex]:focus-visible{outline:2px solid #1d6fe0 !important;outline-offset:2px;border-radius:3px;}
@media(max-width:700px){
          /* 60px, not 44, to match the pill toggle beside them — those escape this
             clamp because they carry no .pps-pill class, so a column of controls was
             reading as two different form systems. 44 is also only the bare iOS
             minimum for a tap target. tel/email added so the address form matches. */
          select,input[type="text"],input[type="number"],input[type="tel"],input[type="email"]{font-size:16px!important;padding:10px 12px!important;min-height:60px!important}
          .pps-sec-hdr{padding:12px 14px!important;font-size:13px!important}
          .pps-sec-body{padding:12px 14px!important}
          .pps-pill{padding:10px 6px!important;font-size:12px!important;min-height:44px!important}
          .pps-ship-box{font-size:10px!important}
        }
      `}</style>
```

### 3.8 Mobile bars (UNIVERSAL)

- Bottom bar wrapped in `<Float>`, carries `ref={bottomBarRef}` (§4.5), shows the missing-destination message above the row, and passes `debugUnlocked` + `coupon` into the compact Panel (lines 6770–6796).
- Floating "Have a question?" chip wrapped in `<Float>`, background `CC.white` (lines 6763–6768).
- Question modal wrapped in `<Float>` (line 6798+).
- `MobilePreviewBar` mount passes the trim-dims props and `onOpenPreview={openPreview}` (§4.6).

---

## 4. New components & systems (full source)

### 4.1 Theme system (UNIVERSAL)

Parts: `PALETTES` (§1.2), `buildST`/`pps_applyTheme` (§2.13), `buildCHIP` (§2.6), dark CSS (§1.5), and this App state (lines 5332–5402 also covers §4.5/§4.3 state; theme part is `theme`/`themeTick`):

```js
  // The mobile bar is fixed to the bottom of the viewport, so anything else that docks
  // there — the tooltip sheet today, anything added later — has to know how tall it is
  // or it lands on top of the price and the Add to Order button. Published as a CSS
  // variable on <html> so a portalled overlay can read it without being handed a prop.
  // Its height changes with the shipping warning and the expanded panel, hence the
  // ResizeObserver rather than a one-off measurement.
  const bottomBarRef = useRef(null);
  useEffect(() => {
    const root = document.documentElement;
    if (!mob) { root.style.setProperty("--pps-bottombar", "0px"); return; }
    const set = () => root.style.setProperty("--pps-bottombar",
      (bottomBarRef.current ? bottomBarRef.current.offsetHeight : 0) + "px");
    set();
    let ro = null;
    if (window.ResizeObserver && bottomBarRef.current) {
      ro = new ResizeObserver(set); ro.observe(bottomBarRef.current);
    }
    window.addEventListener("resize", set);
    return () => { if (ro) ro.disconnect(); window.removeEventListener("resize", set);
                   root.style.setProperty("--pps-bottombar", "0px"); };
  }, [mob]);
  // "One section at a time" — on by default: opening a section collapses the rest so
  // the customer is only looking at one topic. Preference is remembered per browser,
  // because someone who prefers seeing everything at once will want that every visit.
  // Theme. Persisted per browser like the focus-mode preference; falls back to the
  // OS setting on first visit so a dark-mode user isn't flashed a white page.
  const[theme,setTheme]=useState(()=>{
    try{
      const v=localStorage.getItem("pps_theme");
      if(v==="dark"||v==="light")return v;
      return (window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches)?"dark":"light";
    }catch(e){return "light";}
  });
  const[themeTick,setThemeTick]=useState(0);
  useEffect(()=>{
    pps_applyTheme(theme);
    try{localStorage.setItem("pps_theme",theme);}catch(e){}
    // CC/ST are mutated in place, so nothing re-renders on its own — bump a counter
    // to repaint the tree with the new palette.
    setThemeTick(t=>t+1);
  },[theme]);
  const[setOpen,setSetOpen]=useState(false);
  useEffect(()=>{
    if(!setOpen)return;
    const onDoc=e=>{ if(!e.target.closest||!e.target.closest("[data-pps-settings]")) setSetOpen(false); };
    const onKey=e=>{ if(e.key==="Escape") setSetOpen(false); };
    document.addEventListener("mousedown",onDoc);
    document.addEventListener("keydown",onKey);
    return()=>{document.removeEventListener("mousedown",onDoc);document.removeEventListener("keydown",onKey);};
  },[setOpen]);
  const[soloSec,setSoloSec]=useState(()=>{
    try{const v=localStorage.getItem("pps_solo_sections");return v===null?true:v==="1";}catch(e){return true;}
  });
  useEffect(()=>{try{localStorage.setItem("pps_solo_sections",soloSec?"1":"0");}catch(e){}},[soloSec]);
  // Turning it on mid-session collapses down to whichever section is already open
  // (the first one), rather than leaving several open and contradicting the setting.
  useEffect(()=>{
    if(!soloSec)return;
    setOp(p=>{
      const openKeys=Object.keys(p).filter(k=>p[k]);
      if(openKeys.length<=1)return p;
      const next={};for(const k in p)next[k]=false;next[openKeys[0]]=true;return next;
    });
  },[soloSec]);
  const tog=k=>setOp(p=>{
    const opening=!p[k];
    if(soloSec&&opening){const next={};for(const key in p)next[key]=false;next[k]=true;return next;}
    return {...p,[k]:!p[k]};
  });
  // Proof-debug mode — see calc-perfect-bound.html for the canonical comment
  const proofDebug = useMemo(() => {
```

Mechanism: CC/ST/CHIP are module-level objects mutated in place; `themeTick` forces the repaint; `document.documentElement.dataset.ppsTheme` drives the CSS side. First visit follows `prefers-color-scheme`; choice persists in `localStorage.pps_theme` (shared across all calculators — a plus for consistency).

### 4.2 Form settings gear menu (UNIVERSAL) — lines 6157–6208

Gear button + popover with two `menuitemcheckbox` rows: **Dark mode** and **One section at a time** (slow-slide toggle knobs). Outside-click/Escape dismissal via the `[data-pps-settings]` closest-check effect (in §4.1 block above).

```jsx
        <div style={{flex:1,minWidth:0,display:"flex",flexDirection:"column",gap:mob?6:10,width:mob?"100%":undefined}}>

          {/* Settings — preferences live behind a gear so they read as settings
              rather than as more configuration choices competing with the form. */}
          <div data-pps-settings style={{position:"relative",display:"flex",justifyContent:"flex-end",padding:mob?"2px 2px 0":"0 2px 2px"}}>
            <button type="button" onClick={()=>setSetOpen(o=>!o)} aria-haspopup="true" aria-expanded={setOpen} aria-label="Form settings"
              title="Form settings"
              style={{display:"inline-flex",alignItems:"center",gap:6,padding:"6px 10px",borderRadius:8,cursor:"pointer",fontFamily:"inherit",
                fontSize:12,fontWeight:600,color:setOpen?CC.dark:CC.light,background:setOpen?CC.track:"transparent",
                border:`1px solid ${setOpen?CC.border:"transparent"}`,transition:"all .15s ease"}}>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
              </svg>
              Form settings
            </button>
            {setOpen&&(
              <div role="menu" style={{position:"absolute",top:"100%",right:0,marginTop:6,zIndex:60,minWidth:250,
                background:CC.white,border:`1px solid ${CC.border}`,borderRadius:12,
                boxShadow:"0 4px 6px rgba(16,24,40,.06), 0 12px 28px rgba(16,24,40,.14)",padding:6}}>
                {[
                  {k:"theme", label:"Dark mode", hint:"Easier on the eyes in low light.",
                   on:theme==="dark", set:()=>setTheme(t=>t==="dark"?"light":"dark")},
                  {k:"solo", label:"One section at a time", hint:"Opening a section collapses the others.",
                   on:soloSec, set:()=>setSoloSec(v=>!v)},
                ].map(o=>(
                  <button key={o.k} type="button" role="menuitemcheckbox" aria-checked={o.on} onClick={o.set}
                    style={{width:"100%",display:"flex",alignItems:"center",gap:12,padding:"9px 10px",borderRadius:8,
                      border:"none",background:"transparent",cursor:"pointer",fontFamily:"inherit",textAlign:"left"}}>
                    <span style={{flex:1,minWidth:0}}>
                      <span style={{display:"block",fontSize:13,fontWeight:600,color:CC.dark}}>{o.label}</span>
                      <span style={{display:"block",fontSize:11,color:CC.light,marginTop:1}}>{o.hint}</span>
                    </span>
                    <span style={{flex:"none",width:34,height:20,borderRadius:99,position:"relative",
                      background:o.on?CK.cyan:CC.border,
                      transition:"background .8s cubic-bezier(.4,0,.2,1) .25s"}}>
                      {/* Straight slide: a quarter-second beat after the click, then the knob
                          travels for .8s. Symmetric easing, no overshoot and no flip, so it
                          reads as the control moving rather than performing. The track colour
                          runs on the same delay and duration so the two move as one. */}
                      <span style={{position:"absolute",top:2,left:o.on?16:2,width:16,height:16,borderRadius:99,
                        background:"#fff",boxShadow:"0 1px 2px rgba(16,24,40,.35)",
                        transition:"left .8s cubic-bezier(.4,0,.2,1) .25s"}}/>
                    </span>
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* SECTION 1: Size */}
          <Sec step={1} summary={secSummary.s1} title="Booklet" open={op.s1} onToggle={()=>tog("s1")} badge={isCustom?`${customLong}×${customShort}"`:sizeLabel.split(" (")[0]}
```

### 4.3 Solo-section (focus) mode (UNIVERSAL)

State + `tog` included in the §4.1 block: `soloSec` defaults ON, persists as `pps_solo_sections`; opening a section closes the others; switching it on mid-session collapses to the first open section. Any programmatic opener must respect it (see `openPreview`/`openShipping` pattern in §4.6).

### 4.4 Section summary chips (`secSummary`) (UNIVERSAL pattern, per-calc content) — lines 5719–5755

```js
  // Collapsed-header summaries. Built from the SAME option labels the controls use,
  // so a closed section reports exactly the wording the customer picked rather than a
  // paraphrase that could drift out of sync with the dropdowns.
  const secSummary=(()=>{
    const lbl=(arr,v)=>{const o=arr.find(x=>x.val===v);return o?o.label:null;};
    const col=v=>v==="color"?"Full Color":"Greyscale";
    const q=sets.reduce((a,x)=>a+(x.qty||0),0)||sets[0]?.qty||0;
    const jobName=(sets[0]?.name||"").trim();
    const pg=sets[0]?.pages||0;
    const fin=[
      coating>0&&lbl(COATINGS,coating),
      bundling>0&&lbl(BUNDLING,bundling),
      roundCorner>0&&lbl(CORNERS,roundCorner),
      vividPrint&&"Enhanced Vivid Print",
    ].filter(Boolean);
    const sh=result?.shipping;
    const proofLbl=proof>=3?"Hardcopy Proof":proof>0?"Manual Digital Proof":"Proof & Approve Online";
    return {
      // Job name leads the chips — it is what identifies the job at a glance, and on
      // mobile only the first chip survives the cap. The unlock code is deliberately
      // not echoed back as a chip; it is a code, not a name.
      s1:[jobName&&jobName!=="%3414"?jobName:null,q.toLocaleString()+" qty",pg+"pp"],
      s2:[col(insideColor),insidePaper.label].concat(coverMode!=="same"?[coverPaper.label+" cover"]:[]),
      s3:fin.length?fin:["No finishing added"],
      s4:[lbl(ART_OPTS,artwork),proofLbl].filter(Boolean),
      // Destination, then the date the customer actually gets — not the service or
      // the day count, which told them nothing they wanted at a glance. Rushed jobs
      // show the requested need-by date; otherwise the free-delivery date. Same
      // expression the price panel uses, so the chip can never disagree with it.
      s5:shipState
        ? [shipState+(shipZip?(" "+shipZip):"")]
            .concat(sh ? [((sh.rushCost>0 && sh.needByFmt) ? sh.needByFmt : sh.freeDeliveryDate)] : [])
            .filter(Boolean)
        : ["Add a destination"],
    };
  })();
  const r2=mob?{display:"grid",gridTemplateColumns:"1fr",gap:8}:ST.row2;
```

Rules encoded there: chips reuse the exact option labels (never paraphrase); job name leads chips (skip the `%3414` unlock code); shipping chip shows destination + the same date expression the Panel uses; empty destination → "Add a destination". Each calculator writes its own `secSummary` from its own options.

### 4.5 `--pps-bottombar` height publisher (UNIVERSAL)

In the §4.1 block: a ResizeObserver on the mobile bottom bar writes its height to `--pps-bottombar` on `<html>`, so portalled sheets (RichTip §2.12) dock above it. Cleans up to `0px` on unmount/desktop.

### 4.6 `openPreview` / `pendingPreview` (UNIVERSAL pattern wherever a modal lives inside a `keepMounted` section) — lines 5495–5515

```jsx
const[pendingPreview,setPendingPreview]=useState(false);
const openPreview=()=>{
  setOp(p=>{
    if(p.s4)return p;
    if(soloSec){const n={};for(const k in p)n[k]=false;n.s4=true;return n;}
    return {...p,s4:true};
  });
  if(openPreviewModalRef.current){openPreviewModalRef.current();return;}
  setPendingPreview(true);
};
useEffect(()=>{
  if(!pendingPreview)return;
  if(openPreviewModalRef.current){openPreviewModalRef.current();setPendingPreview(false);}
});
```

Why: a fixed modal inside a `display:none` (collapsed keepMounted) subtree is invisible — expand the owning section first, then fire. Same shape for `openShipping()` (CTA jump on missing destination, lines 6570–6574) replacing classic's DOM-crawling `focusShipping()`.

### 4.7 Engine riders in `calculate()` — DECISION REQUIRED, not design

The draft's `calculate()` differs from classic in four ways (prices identical; turnaround/display differ). They are **behavior**, listed so no one ports them by accident — or misses that the modern UI *reads* their outputs:

1. **Human-touch day** (owner rule 2026-07-29, lines ~1020–1035): +1 business day (capped at 1) when art option is email-after/already-discussed/Canva (`0.015<art.val<0.045`) or proof is a manual digital proof (`0<c.proof<3`); attributed to `ti.artDesign`/`ti.proof` for badges. NOT in any other calculator.
2. **Cardstock >24pp hard error** (lines ~925–931): returns `{error:[…]}` instead of silently degrading; plus a disabled "Not available at this page count" optgroup in the paper `Sel`. Saddle-specific (page-count rule).
3. **`hasDest` moved into `result.shipping`** (line 1245): the modern Panel/mini-bar read `sh.hasDest`. Brochure already returns it there; **perfect-bound and coupon-book only put it in `debug.shipping`** (their Panels read `sh.hasDest` → always undefined). When porting the modern Panel, add `hasDest:_hasDest,` to the RESULT `shipping:{…}` of any calc missing it — display-only, no pricing.
4. **Turnaround debug fields** `summedDays`/`minimumDays`/`minimumApplied`/`humanTouchDays` in `debug.turnaround` (lines 1198–1201), consumed by the staff-gated breakdown ("Nd shop minimum applies (components total Md)"). Guard reads with `?.` if not porting.

### 4.8 Discount-code system (UNIVERSAL, needs plugin ≥ the version with `pps/v1/coupon/preview`) — lines 5766–5865

State, REST preview (graceful offline fallback: "will still be checked at checkout"), `netOf()` single-source net-price math (percent rescales per tier; flat clamps per row), and `discountField()` — deliberately a **function returning JSX, not a component** (see comment: a component identity per render remounts the input and drops focus every keystroke). Mounted twice: desktop rail + mobile card. Re-price effect keeps percent codes honest as the quote changes. Copy-table (`copyProjections`) gains net columns.

```js
  // ── Discount code ─────────────────────────────────────────────────────────
  // The code is previewed here and applied for real by WooCommerce at add-to-cart.
  // Nothing about it touches the quoted line price: pps_price stays the true product
  // price so the materials floor and the pps_lock checksum still hold. What the panel
  // shows is an estimate of the effect; Woo re-validates and does the real maths.
  const[couponInput,setCouponInput]=useState("");
  const[coupon,setCoupon]=useState(null);      // {code,label,discount,type,free_shipping}
  const[couponMsg,setCouponMsg]=useState("");
  const[couponBusy,setCouponBusy]=useState(false);
  const applyCoupon=async()=>{
    const code=(couponInput||"").trim();
    if(!code||couponBusy)return;
    const subtotal=Number(result&&result.total)||0;
    if(subtotal<=0){setCouponMsg("Configure your order first.");return;}
    setCouponBusy(true); setCouponMsg("");
    try{
      const root=((window.PPS_CONFIG||{}).ajaxUrl||"").replace(/wp-admin\/admin-ajax\.php.*$/,"");
      const r=await fetch((root||"/")+"wp-json/pps/v1/coupon/preview",{
        method:"POST",headers:{"Content-Type":"application/json"},
        body:JSON.stringify({code,subtotal,product_id:(window.PPS_CONFIG||{}).productId||0}),
      });
      if(!r.ok&&r.status!==200){ setCoupon(null); setCouponMsg(r.status===429?"Too many attempts — please wait a minute.":"Could not check that code."); return; }
      const j=await r.json();
      if(j&&j.valid){ setCoupon(j); setCouponMsg(""); }
      else { setCoupon(null); setCouponMsg((j&&j.message)||"That code is not valid for this order."); }
    }catch(e){ setCoupon(null); setCouponMsg("Could not reach the server — the code will still be checked at checkout."); }
    finally{ setCouponBusy(false); }
  };
  const clearCoupon=()=>{ setCoupon(null); setCouponInput(""); setCouponMsg(""); };
  // One place that answers "what does this amount cost once the code is applied", so
  // the price card, the per-unit figure and the quantity table cannot disagree.
  // A percentage code scales, so it re-prices every quantity tier honestly. Anything
  // else is a flat amount off the cart: it comes off each row whole and is clamped at
  // the row's own total, which is what WooCommerce will do at checkout. A percent code
  // that somehow arrives without an `amount` falls through to the flat branch — exact
  // at the configured quantity, approximate elsewhere, rather than silently zero.
  const _cpnPct=(coupon&&coupon.discount>0&&coupon.type==="percent"&&(Number(coupon.amount)||0)>0)
    ? Math.min(1,(Number(coupon.amount)||0)/100) : 0;
  const _cpnFlat=(coupon&&coupon.discount>0&&!_cpnPct) ? Math.max(0,Number(coupon.discount)||0) : 0;
  const couponActive=_cpnPct>0||_cpnFlat>0;
  const netOf=(amt)=>{
    const a=Number(amt)||0;
    if(!couponActive||a<=0) return a;
    const off=_cpnPct>0 ? a*_cpnPct : Math.min(_cpnFlat,a);
    return Math.max(0,Math.round((a-off)*100)/100);
  };
  // Rendered in the desktop rail and again in the mobile form. Defined once so the two
  // cannot drift — the first version lived only in the rail, which meant a customer on
  // a phone had no way to enter a discount code at all.
  //
  // A FUNCTION THAT RETURNS JSX, NOT A COMPONENT, and it must stay that way. Declared
  // inside this component, every render makes a new function identity; used as
  // <DiscountField/> that is a new element *type* each time, so React unmounts the old
  // subtree and mounts a fresh one. The <input> DOM node is destroyed on every
  // keystroke, taking focus with it — the field accepted exactly one character and then
  // dropped focus to <body>. Called as {discountField()} the JSX becomes part of this
  // component's own tree and reconciles against the previous render normally.
  const discountField=()=>(
    <div>
      {coupon ? (
        <div style={{display:"flex",alignItems:"center",gap:8,padding:"9px 11px",borderRadius:9,
          background:CC.tint,border:`1px solid ${CK.cyan}55`}}>
          <span style={{fontSize:12.5,fontWeight:700,color:CK.cyan,flex:1,minWidth:0,overflow:"hidden",textOverflow:"ellipsis",whiteSpace:"nowrap"}}>
            &#10003; {coupon.code} — {coupon.label}
          </span>
          <button type="button" onClick={clearCoupon} title="Remove this code"
            style={{border:"none",background:"none",cursor:"pointer",fontFamily:"inherit",
              fontSize:11.5,fontWeight:600,color:CC.light,padding:"2px 4px"}}>Remove</button>
        </div>
      ) : (
        <div style={{display:"flex",gap:6}}>
          <input type="text" value={couponInput} aria-label="Discount code"
            onChange={e=>{setCouponInput(e.target.value.toUpperCase());setCouponMsg("");}}
            onKeyDown={e=>{if(e.key==="Enter"){e.preventDefault();applyCoupon();}}}
            placeholder="Discount code"
            style={{...ST.inp,flex:1,minWidth:0,padding:"10px 12px",fontSize:mob?16:13,letterSpacing:".04em",textTransform:"uppercase"}}/>
          <button type="button" onClick={applyCoupon} disabled={couponBusy||!couponInput.trim()}
            style={{flexShrink:0,padding:"10px 15px",borderRadius:9,border:`1px solid ${CC.border}`,
              background:CC.white,color:couponInput.trim()?CC.dark:CC.light,fontSize:12.5,fontWeight:650,
              cursor:(couponBusy||!couponInput.trim())?"default":"pointer",fontFamily:"inherit",transition:"all .15s ease"}}>
            {couponBusy?"Checking…":"Apply"}
          </button>
        </div>
      )}
      {couponMsg&&<div style={{fontSize:11.5,color:CC.red,marginTop:5,lineHeight:1.35}}>{couponMsg}</div>}
    </div>
  );
  // A percentage code has to be re-priced whenever the quote changes, or the panel
  // would keep showing a discount computed against a stale subtotal.
  useEffect(()=>{
    if(!coupon)return;
    const subtotal=Number(result&&result.total)||0;
    if(subtotal<=0)return;
    if(coupon.type==="percent"){
      const d=Math.min(Math.round(subtotal*(coupon.amount/100)*100)/100,subtotal);
      if(Math.abs(d-coupon.discount)>0.005) setCoupon(c=>c?{...c,discount:d}:c);
    } else if(coupon.discount>subtotal){
      setCoupon(c=>c?{...c,discount:Math.round(subtotal*100)/100}:c);
    }
  },[result&&result.total,coupon&&coupon.type,coupon&&coupon.amount]);
```

### 4.9 Desktop condensed total bar (UNIVERSAL) — state lines 5867–5901, JSX 6729–6761

Sentinel (in Panel, §2.10) + callback-ref + IntersectionObserver (callback ref because Panel early-returns while the quote resolves — a plain ref observed nothing) + a fixed bar tracked to the rail's own column. Shows total, qty + "as soon as/delivery by" date (or the missing-destination message), coupon net line, and an Add to Order button.

```js
  // The quantity table is the tallest thing in the rail. Left whole it pushes the rail
  // past the viewport, and a rail taller than the page's left column cannot stick — so
  // the total and the CTA scroll away. Collapsed it shows a window around the current
  // selection and the rail stays short enough to stay put.
  const[projOpen,setProjOpen]=useState(false);
  // Belt and braces for the same problem: when the price card does leave the viewport
  // (very short window, or a rail taller than everything else), a condensed bar takes
  // its place, tracked to the rail's own column so it reads as the rail folding up.
  const railRef=useRef(null);
  // Callback ref, not useRef: the sentinel lives inside Panel, which early-returns
  // before rendering it while the quote is still resolving. A plain ref plus a
  // run-once effect therefore observed nothing and the bar never appeared. A state
  // setter as the ref re-runs the effect the moment the node actually attaches.
  const[totalEl,setTotalEl]=useState(null);
  const[miniBar,setMiniBar]=useState(null); // null | {left,width}
  useEffect(()=>{
    if(mob) { setMiniBar(null); return; }
    const el=totalEl; if(!el||typeof IntersectionObserver==="undefined") return;
    const place=()=>{ const r=railRef.current&&railRef.current.getBoundingClientRect();
      setMiniBar(r?{left:Math.round(r.left),width:Math.round(r.width)}:null); };
    const io=new IntersectionObserver(([e])=>{ if(e.isIntersecting) setMiniBar(null); else place(); },{threshold:0});
    io.observe(el);
    const onResize=()=>{ setMiniBar(v=>v?(railRef.current?{left:Math.round(railRef.current.getBoundingClientRect().left),width:Math.round(railRef.current.getBoundingClientRect().width)}:v):v); };
    window.addEventListener("resize",onResize);
    return ()=>{ io.disconnect(); window.removeEventListener("resize",onResize); };
  },[mob,totalEl]);
  const copyProjections = () => {
    if (!projections) return;
    const rows = projections.filter(p => p.total !== null);
    const sz = sizeMode === "custom" ? `${customShort}×${customLong}` : sizeLabel;
    const cvr = coverMode === "same" ? insidePaper : coverPaper;
    const specs = [
      "Saddle Stitch Booklets",
      `Size: ${sz}`,
      `Pages: ${sets[0].pages}`,
```
```jsx

      {/* Desktop condensed total — only while the price card is off screen. Tracked to
          the rail's own column so it reads as the rail folding up rather than as a new
          element arriving. Same numbers, same action, one line. */}
      {!mob&&miniBar&&result&&!result.error?.length&&(()=>{
        const _t=Math.max(0,result.total).toLocaleString("en-US",{minimumFractionDigits:2,maximumFractionDigits:2});
        const _sh=result.shipping;
        const _when=_sh?(_sh.needByFmt||((_sh.hasDest)?(((_sh.rushCost>0)&&_sh.needByFmt)?_sh.needByFmt:_sh.freeDeliveryDate):_sh.earliestDate)):null;
        const _net=couponActive?netOf(result.total):null;
        return <Float><div style={{position:"fixed",bottom:16,left:miniBar.left,width:miniBar.width,zIndex:9990,
          background:CC.white,border:`1px solid ${CC.border}`,borderRadius:12,
          boxShadow:"0 6px 24px rgba(16,24,40,.16)",padding:"10px 12px",
          display:"flex",alignItems:"center",gap:10,animation:"pps-mini-in .22s ease-out"}}>
          <div style={{flex:1,minWidth:0}}>
            <div style={{fontSize:19,fontWeight:800,color:CC.dark,lineHeight:1.05}}>${_t}</div>
            <div style={{fontSize:10.5,color:missingDest?CC.red:CC.light,fontWeight:600,marginTop:1,whiteSpace:"nowrap",overflow:"hidden",textOverflow:"ellipsis"}}>
              {missingDest ? missingDestMsg
                           : `${(result.tQ||0).toLocaleString()} qty${_when?` · ${_sh&&_sh.needByFmt?"delivery by":"as soon as"} ${_when}`:""}`}
            </div>
            {/* The condensed bar is the only price on screen once the card scrolls off,
                so it cannot be the one place a live code goes unmentioned. */}
            {_net!=null&&<div style={{fontSize:10.5,fontWeight:700,color:CK.cyan,marginTop:1,whiteSpace:"nowrap",overflow:"hidden",textOverflow:"ellipsis"}}>
              {coupon.code} · you pay ${_net.toFixed(2)}
            </div>}
          </div>
          <button type="button" onClick={handleAddToOrder} disabled={submitting}
            style={{flexShrink:0,padding:"11px 16px",borderRadius:8,border:"none",background:submitting?CC.light:CK.cyan,
              color:"#fff",fontSize:14,fontWeight:750,cursor:submitting?"default":"pointer",fontFamily:"inherit",
              opacity:submitting?.5:1,whiteSpace:"nowrap"}}>
            {submitting?"Adding…":"Add to Order"}
          </button>
        </div></Float>;
      })()}
```

### 4.10 Job-name header field (UNIVERSAL) — lines 6210–6231 + `.pps-jobtitle` CSS (280–286)

```jsx
              /* Job Name lives in the header rather than the body: it names the whole
                 job, not the trim size, and it is the one field an operator scans for.
                 Styled as an editable title — heading typography, no chrome at rest —
                 so it reads as part of the heading instead of a form box wedged into
                 it. Width is fluid; a fixed width overflowed narrow phones. The 16px
                 mobile size is deliberate: anything smaller and iOS zooms on focus.
                 When the section collapses the value returns as a summary chip. */
              <input id="pps-job-name" className="pps-jobtitle" type="text" value={sets[0].name}
                onChange={e=>upSet(0,{name:e.target.value})}
                placeholder="Job name/PO" aria-label="Job name or PO number"
                style={{...CHIP,width:"100%",minWidth:0,maxWidth:"none",display:"block",
                  padding:mob?"6px 12px":"6px 13px",fontSize:mob?16:12,
                  textAlign:"center",outline:"none",textOverflow:"ellipsis"}}/>
                  /* background + border live in .pps-jobtitle, not here: an inline
                     background would outrank the :hover and :focus rules. */
            }>
            <div style={r2}>
              <Pil label={<Tip k="size">Booklet Size</Tip>} value={sizeMode} onChange={handleSizeMode} options={[{t:"Preset Sizes",v:"preset"},{t:"Custom Size",v:"custom"}]}/>
              {isCustom
                ? <div style={ST.f}><label style={ST.lbl}>Custom Size</label><div style={{position:"relative"}}><select disabled style={{...ST.sel,fontSize:mob?16:15,padding:mob?"20px 32px 20px 13px":"20px 34px 20px 14px",opacity:.6,pointerEvents:"none",fontWeight:600}}><option>{(customLong&&customShort)?(()=>{const lo=Math.max(customLong,customShort),sh=Math.min(customLong,customShort),spine=bindDir==="short"?sh:lo,nonSpine=bindDir==="short"?lo:sh;
                    /* Width x Height, in the same order as the two fields directly below and
                       the collapsed header pill. It used to print long-edge-first, so the
```

### 4.11 Misc universal fixes riding in the draft

- **Share URL is fully self-contained** (every field emitted, no default-skipping — lines 6035–6070): per-preset defaults made short URLs decode differently for the recipient.
- **Switch-target fallback regex** is filename-agnostic: `.replace(/[^/]+\.html$/, "calc-….html")` (line ~6100).
- `shipState` default `""` + `hasDest` display logic (§4.7.3).
- "Share this configuration" → **"Save configuration"**.
- Custom-size readout prints Width×Height in field order (was long-edge-first).
- `staffUnlocked`/`debugUnlocked` gating: approval-package downloads, mobile cost breakdown, turnaround component breakdown, shop-clock line (%3414 unlock, not `?debug=1`).

---

## 5. Saddle-specific vs universal — the full ledger

**UNIVERSAL (port to every calculator):**
fonts + CSS variables (§1.1); PALETTES/CC/theme system + dark CSS (§1.2, §1.5, §4.1); shape/elevation/spacing/motion tokens via `buildST` (§1.3, §1.4, §2.13); `Sel`/`TxtNum`/`Pil` restyles (§2.2–2.4); `CHIP` (§2.6); `Sec` redesign + step/summary/headerField/keepMounted (§2.7); `Float` + portalling of every fixed overlay (§2.9, §3.8); `Panel` redesign incl. coupon + sentinel + hasDest wording (§2.10); `RichTip` bottombar offset (§2.12); settings gear + solo mode (§4.2, §4.3); `secSummary` pattern (§4.4); `--pps-bottombar` (§4.5); openPreview/openShipping pattern (§4.6); discount system (§4.8); condensed total bar (§4.9); job-name header field (§4.10); sticky rail + CTA/question/table reorder + qty windowing + Save-configuration rename (§3.4, §3.5); modal chrome vars + bp-seg + viewmenu + approve CTA patterns (§2.14, §3.6); inline mobile style block (§3.7); address form grid/ids/state-placeholder (§3.3); misc fixes (§4.11); hasDest into result.shipping where missing (§4.7.3).

**SADDLE-SPECIFIC (do NOT port):**
`trimDims`'s bindDir semantics + `SidebarBookPreview`/`MobilePreviewBar` wiring (§2.11, §2.15); everything inside `SetPreview` beyond the listed patterns — blank-placement chooser, page-overflow/at-ceiling preflight copy, `gsFor` cover-vs-inside greyscale, reader-spread preview HTML, spread pagination (§2.14); cardstock >24pp stop + disabled optgroup (§4.7.2); human-touch day (§4.7.1 — owner decision per calc); the specific `secSummary.s1–s5` label content; "Two Staples" notice styling; book 3D previews.

**Per-calc features that must SURVIVE the port untouched:** each calc's pricing engine + PCF; brochure fold previewer/SheetPreview + fold-type UI; coupon-book sheets + front/back pills + its own FAQ slot; sticker step-and-repeat UI; PaperNote/paper inventory dots + legend (identical in both files — keep); perfect-bound mixed-color per-set inputs + `TxtNum` extra props; per-calc `TT` tooltip keys; stale-quote messaging; destination-gate messaging.

---

## 6. Porting recipe (ordered)

Work one calculator per session/commit. For each target file:

1. **Do not touch the engine.** `calculate()`, PCF, imp math, spread/panel math stay byte-identical — except the one sanctioned display-only addition: ensure `hasDest: _hasDest,` is present in the RESULT `shipping:{…}` object (perfect-bound and coupon-book need it; brochure already has it). Diff your work against `docs/pricing-matrix.json` expectations afterwards (`tools-pricing-matrix.mjs`) — output must be unchanged.
2. **Head:** add the two `preconnect` lines + Google Fonts stylesheet link (§1.1) directly above the `<style>` block. Then bring the head `<style>` up to parity: add/replace the modal-chrome variable block, `.bp-seg`, `.bp-viewmenu`, `.bp-approve`/`.bp-approved`/`.bp-prooflabel`, `[data-pps-cta]`, typography vars, motion keyframes, `.pps-jobtitle`, reduced-motion rule, interaction states, dark-mode block (§1.4, §1.5, §3.6). Keep any calc-specific CSS (fold-preview classes etc.) — these are additions, not a wholesale replace.
3. **Tokens:** insert `PALETTES` above `CC`, replace `CC` with the semantic spread version (§1.2). Replace the trailing `const ST = {…}` with `buildST()/ST/pps_applyTheme` (§2.13) and add `buildCHIP`/`let CHIP` before `Sec`. If the target's ST has calc-specific extra keys, fold them into its `buildST`.
4. **Components:** replace `Sel`, `TxtNum`, `Pil`, `Sec` with the modern versions (§2.2–2.7), **merging** any extra props the target already has (perfect-bound `TxtNum`: `confirm`/`unit`/`adjustHint`; some `Sel`s already take `autoComplete`/`name`). Resolve the `Sel` badge question (§2.2) the same way in every file. Add `Float` (§2.9) and wrap every fixed overlay in it. Patch `RichTip`'s two lines (§2.12).
5. **Panel:** port §2.10 wholesale, keeping any calc-specific rows the target's Panel has grown; wire `coupon` + `totalRef` at both mounts and drop the Earliest/Free-Ground grid.
6. **App state:** add the §4.1 state block (bottombar publisher, theme, settings-menu dismissal, soloSec, new `tog`), §4.8 coupon block, §4.9 miniBar state, `projOpen`. Convert programmatic section-openers to the §4.6 pattern.
7. **App JSX:** settings gear row (§4.2); `step`/`summary` on every Sec + a calc-appropriate `secSummary`; job-name `headerField` on section 1 (move Job Name out of the body; fix its row from 3-col to 2-col); `keepMounted` on the artwork section; sticky rail + reorder (§3.4); windowed qty table (§3.5); condensed bar JSX (§4.9); mobile bottom bar/chip/question-modal portalling + mobile discount card (§3.3, §3.8); address-form grid/ids/State placeholder (§3.3); free-delivery box staff-gating (§3.3); inline style block (§3.7); hero h1 font (§3.2); `debugUnlocked`/`coupon` into compact Panel.
8. **Proof/preview modals:** re-skin the target's own modals with the §2.14 patterns (bp-* variables, bp-seg, viewmenu gear, approve CTA split, Float). Do not import saddle's SetPreview.
9. **Submit:** add the `couponCode` parameter to `submitToWooCommerce` and its call site.
10. **Never write a literal closing script tag anywhere inside the Babel block** — not even in template literals that generate downloadable HTML. Escape it as `<\/script>` (the HTML parser closes the outer block on the first literal occurrence; symptom is the page rendering as raw source — see CLAUDE.md, bitten 2026-05-08).
11. **Build stamp:** set the body chip (the fixed div right after `#pps-calculator-root`) to `BUILD 2026-08-09 · MODERN`.
12. **Verify:** load standalone (file/Pages URL) — check light + dark, mobile (≤700px) bottom bar + chips, solo-section toggling, coupon offline fallback message, condensed bar appearing when the price card scrolls off, `%3414` gating, and that the quote for a few known configs matches the pricing matrix. Then push to `pps-pricing-config` (never delete `.nojekyll`) and cherry-pick to `pages-public` per CLAUDE.md.

### Cross-cutting cautions

- CHIP/CC/ST are mutated by `pps_applyTheme` — anything caching their values across renders will hold stale colors; read them at render time.
- `Sec` titles are now ellipsized `nowrap` — long titles ("Finishing & Addons" is fine) but check per calc.
- The theme localStorage keys (`pps_theme`, `pps_solo_sections`) are shared across calculators by design — do not namespace them per calc.
- Coupon preview needs the deployed plugin to carry the `pps/v1/coupon/preview` route; standalone/Pages previews will show the graceful "checked at checkout" path — that is expected, not a bug.
- The draft has no `.pps-sec-hdr`/`.pps-sec-body` class hooks on elements (the inline-style rules referencing them are inert legacy) — do not rely on them.

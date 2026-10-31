# PPS Designer: new repository brief

**Date:** 2026-10-11 · **For:** the first session in the new repository, which starts with
none of this repository's context · **Design:** `docs/DESIGNCRAFT_STORE_DESIGN.md` in
`pdevvle/priorityprintservice.com`, which explains why. This file covers what to build first and
the rules to build it by.

Copy §8 into the new repo as `CLAUDE.md` on its first commit. Leave the rest here, where it
stays next to the store it integrates with.

---

## 1. What the repository is

A Canva-style designer for Priority Print Service customers. It is built on the DesignCraft
engine (Rust compiled to WebAssembly, MIT OR Apache-2.0) and runs inside our product
calculators.

**What it does.** It turns a product spec into a document, lets a customer design on it, and
returns a print-ready PDF.

**What it does not do.** It never prices anything, never uploads anything and never touches the
cart. The calculator page that opens the designer owns all of that.

|  | Lives in |
|---|---|
| Engine, render, PDF export (upstream, untouched) | **new repo**, `crates/` |
| JS bridge to the engine (`pps-bridge`) | **new repo**, `crates/pps-bridge/` |
| The editor UI customers see (`designer.html` + JS) | **new repo**, `shell/` |
| Build: wasm, wasm-bindgen, wasm-opt, release artefacts | **new repo**, `.github/workflows/` |
| `ppsOpenDesigner()`, the "Design it online" button, the handshake host side | **this repo**, each `calc-*.html` |
| Pricing, preflight, proof, cart, Drive, imposition | **this repo**, unchanged |

## 2. Setup: one-time, before any code

- **Name:** `pdevvle/pps-designer` is the working name. The owner picks.
- **Create it as a new repository, not a GitHub fork.** GitHub won't let a fork of a public repo
  be private, and the owner plans to make the PPS repos private.
- **Seed it from upstream with history:** `https://github.com/storytold/designcraft`, starting
  at `ca343e8985dc4d92dd65cf77a8198723b5f8a9eb` (2026-10-11), with `upstream` kept as a remote.
  Upstream's history is the record of every engine decision, so it is worth keeping.
- **Remove ArtCraft's trademarks in the first commit after the seed:** `docs/brand/`, the
  wordmark and logos in the README, and the About window credits that name the brand. Their
  brand licence forbids those in modified versions. **Keep** `LICENSE-MIT`, `LICENSE-APACHE`,
  `NOTICE` and `ASSETS.md`. Add our own NOTICE line under theirs.
- **Grant the Claude session access** to the new repo. The owner works only through Claude on
  the web and has no local terminal, so a repo Claude can't push to can't be worked on at all.
- **Upstream's `plan/` directory is gitignored and is not in the seed.** Their `AGENTS.md` sends
  you to it. Ignore those pointers. This brief and the design doc replace it.

## 3. Assumptions until the owner decides

The design doc (§9) lists four open decisions. Build on these defaults, and record any change
here when the owner makes it:

1. **Customers first.** The designer is for customers, not staff.
2. **Flats first:** postcards, business cards and flyers, one or two pages. Brochure folds come
   next. Booklets come last.
3. **Press profile: placeholder.** Until the owner names the profile and confirms its licence
   allows embedding, keep upstream's built-in "DesignCraft Generic CMYK (SWOP-like)" output
   intent. Say so in every export's manifest. Don't ship to customers on it.
4. **Repo name:** `pps-designer`.

## 4. P0: measure before building

The whole project hinges on one question: **can a customer on a mid-range phone load and use
this?** Answer it with numbers before writing editor UI. P0 is a set of measurements. Nothing
in it ships.

| # | Task | Done when |
|---|---|---|
| 0.1 | CI workflow `wasm.yml`: build `apps/designcraft-web` for `wasm32-unknown-unknown` with upstream's own settings, run `wasm-bindgen` and `wasm-opt -Oz`, upload the artefacts | Workflow green on `main` |
| 0.2 | Record payload: `.wasm` raw, gzip and brotli; JS glue; fonts | Table in §9 below |
| 0.3 | Publish the build to Pages; record cold start (navigation to first frame) and frame time while dragging a frame, on desktop Chrome and a mid-range Android phone | Table in §9 below |
| 0.4 | Headless export through `designcraft-cli`: a 6×9 postcard, two sides, 0.125″ bleed, text + placed image + CMYK shape → PDF | PDF committed under `fixtures/p0/` |
| 0.5 | Run 0.4's PDF through the PPS gates: proofer preflight and the ordered-size check (`tools-proof-size-check-test.mjs` in the PPS repo) | Both accept it unmodified |
| 0.6 | **Send 0.4's PDF to the press for a real RIP pass.** The earlier TypeScript spike never did this | Owner reports the result. Record it in §9 |

**Stop rule:** if 0.2 or 0.3 comes back unusable, P1 does not start. Write the numbers down,
bring the owner the options, and let the owner decide. Options include loading the editor
only when the customer asks for it, lower preview resolution, and a staff-only first release.
Building anyway is not one of them.

Upstream's web build is just a stand-in for P0, there to measure. Our shell (P1) drops the
egui UI, so these numbers are a ceiling.

## 5. P1: the bridge and one flat product

### `crates/pps-bridge`, the only Rust we write at first

A `wasm-bindgen` crate over upstream's public API. Everything it exposes already exists:

| JS export | Wraps |
|---|---|
| `new Designer()` | `designcraft_engine::Session::new()` |
| `execute(cmd, paramsJson) → resultJson` | `Session::execute(id, &Value) -> Result<Value>`. Every user action is a command with undo |
| `pointer(evJson) → overlaysJson` | `designcraft_tools::create(id)` and `Tool::pointer` / `Tool::overlays` / `Tool::cursor` |
| `key(evJson)`, `wantsText()` | `Tool::key` and `Tool::wants_text` (text input on mobile hangs off this) |
| `renderPage(i, scale, bleed) → {width, height, rgba}` | `render_page(...) -> Rendered`, then `to_straight()` for canvas `ImageData` |
| `exportPdf(optsJson) → Uint8Array` | `file.exportPdf` (returns base64; decode in Rust, not JS) |
| `saveDesign() → json` / `openDesign(json)` | upstream's native document format |

Bridge rules:

- **Every export returns a `Result` mapped to a JS exception carrying a message. None may
  panic.** Upstream forbids `unwrap` and `expect` outside tests (§8). The bridge follows the
  same rule, and a panic in wasm takes the customer's design with it.
- **Treat every input as hostile.** JSON comes from a page, and indexes come from pointer
  events. Use `get()` rather than `[i]`, and checked arithmetic.
- **No engine logic in the bridge.** If the bridge needs something upstream doesn't offer, write
  it as a new command in a new crate of ours, and keep our code out of `crates/engine/src/cmd/`.

### `shell/`: the editor

Plain HTML and JS, with no framework required. It is one page, `designer.html`, opened in a
same-origin iframe by the calculator.

- Canvas: draws `renderPage`, then our own overlay layer (selection handles, trim, bleed and
  safety, fold lines) from `pointer` → overlays.
- Left drawer: Text, Photos (upload; `file.place`), Shapes, Colours. Templates arrive in P3.
- Top bar: product name, a **price chip (display only)**, Back, **Print ▸**.
- Always visible, never optional: trim, bleed and safety guides. **Don't add a UI feature that
  can't survive to the PDF.**
- Look: the calculators' palette and type, taken from the calculators' own CSS rather than
  copied (the PPS proofer's style suite already works this way).

### Done when

The PPS repo's existing gates pass with a designer-made PDF in place of an uploaded one: the
proofer's six suites, the size check, and `tools-order-e2e-test.mjs`. Those gates live in the
PPS repo. P1 lands there as one PR that adds `ppsOpenDesigner()` to the flats calculator, and
here as the bridge and the shell.

## 6. The handshake (canonical copy)

`postMessage` between the calculator (host) and `designer.html`. **Same origin only.** Reject
any message whose `event.origin` differs, or whose `source` isn't the expected window. The
messages carry customer artwork.

| Direction | `type` | Payload |
|---|---|---|
| host → designer | `pps-design:job` | `{spec:{trimW,trimH,bleed,safety,pages,pageMultiple,folds[],bindEdge,spine}, template?, design?}`. All lengths in **points** |
| designer → host | `pps-design:ready` / `pps-design:error` | `{message}` on error |
| host → designer | `pps-design:price` | `{total, qty, turnaround}`. **Display only. Never computed here** |
| designer → host | `pps-design:spec` | a requested change the calculator owns, e.g. `{pages: 8}` or `{sides: 1}` |
| designer → host | `pps-design:print` | `{pdf: ArrayBuffer, design: json, thumbs: [dataURL]}` |
| designer → host | `pps-design:close` | `{design: json}` so Back keeps the work |

Invariants:

- **The calculator owns the spec.** On `spec`, the designer waits. The document changes only
  when a new `job` arrives. A change the calculator refuses never reaches the document.
- **`print` hands off a file. It does not approve anything.** The PDF enters the calculator's
  normal artwork intake and goes through the same proof and approval as an upload.
- **Version the protocol from day one:** every message carries `v: 1`.

## 7. The output contract

What `exportPdf` must produce for every product, checked in this repo's CI by reloading the
bytes:

- MediaBox = trim + 2 × bleed. TrimBox = trim. BleedBox declared. All in points.
- Page count = `spec.pages`, a multiple of `spec.pageMultiple`.
- Fonts embedded and subset with spec-conforming tags (`ABCDEF+Name`). OFL fonts only, and
  every one has a row in `ASSETS.md`.
- Colour: CMYK and spot preserved. No DeviceRGB transparency groups in a CMYK file (upstream's
  `cmyk_group_spaces`). RGB placed images allowed for the digital press, and the manifest says
  so.
- Output intent: the owner's press profile once named (§3.3). Until then, the placeholder,
  flagged.
- Nothing printable outside the BleedBox. Image frames clip.

Port the TypeScript spike's assertions (`designer/test/` in the PPS repo) into a Rust
integration test: box geometry, CMYK operators in the content stream, subset fonts, image
orientation from a corner marker, rotation handedness. That test is P0's acceptance gate for
the exporter.

## 8. Agent rules: copy into the new repo's `CLAUDE.md`

> **Operator.** The owner works only through Claude on the web and has no local terminal. Never
> resolve anything with "run this locally". Do builds and pushes from the session or from CI.
> If a push is refused, say so and ask; don't hand the owner a command.
>
> **Upstream crates are read-only.** Our code lives in new crates (`crates/pps-*`) and
> `shell/`. A fix needed in an upstream crate goes upstream as a PR, or into ours as a new
> command. It is never edited in place. Merging upstream must stay a clean fast-forward of
> their files. Merge deliberately, on a branch, behind the gates in §7 and the PPS repo's
> e2e suite, and never on the day of a release.
>
> **Upstream's own rules still apply to their crates and to ours:** no panics in non-test code
> (`unwrap`, `expect`, `panic!`, indexing on input; `unsafe_code = "forbid"`), errors as
> `Result`, enforced layering (`cargo xtask layers`), and `cargo xtask ci` green before every
> commit. Never break the wasm build.
>
> **No pricing, ever.** No prices, cost constants, margins or formulas in this repo. It is
> public, and pricing lives only in the PPS calculators. The price chip shows what the host
> sends.
>
> **The designer never uploads and never touches the cart or a nonce.** Everything after
> `pps-design:print` belongs to the host page.
>
> **Deploys are pinned.** A release is a commit SHA plus its CI artefacts. The PPS site pulls a
> release by SHA. Rolling back means pulling an older SHA. Never hand-copy files onto a server.
> A change that exists only on a server is lost at the next deploy.
>
> **Brand and assets.** No ArtCraft marks (brand licence). No Adobe assets (upstream policy).
> Every font, image or template has a row in `ASSETS.md` with its licence.
>
> **Check every claim against the PDF.** A feature is done when the exported bytes, reloaded,
> prove it. Looking right on the canvas isn't enough.

## 9. Measurements (P0 fills this in)

| Measure | Value | Date / build |
|---|---|---|
| `.wasm` raw / gzip / brotli | — | — |
| JS glue + fonts | — | — |
| Cold start, desktop Chrome | — | — |
| Cold start, mid-range Android | — | — |
| Drag frame time, desktop / Android | — | — |
| Proofer preflight + size check on P0 PDF | — | — |
| RIP pass at the press | — | — |

**Why these are blank:** building upstream from the PPS repo's sandbox was refused, because the
build runs third-party build scripts. CI in the new repo is the intended place to build it.

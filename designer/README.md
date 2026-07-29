# PPS Designer — spike

A print-first layout editor. Not a Canva clone and not an Illustrator clone —
the document **is a product** (trim, bleed, safety, fold geometry and page-count
rules are structural), and the export path is the point of the whole thing.

Status: **spike**. See `../docs/DESIGNER_SPIKE.md` for what is proven, what is
faked, and what to do next. Read that before writing any code here.

## Run it

```bash
cd designer
npm install
npm run dev            # http://localhost:5173
```

## The test that matters

```bash
npm test               # headless export + preflight, writes out/spike.pdf
```

This runs the export engine in node with **no browser and no UI**, builds a
document that exercises every draw path, writes a PDF, reloads it, and asserts
the result. Re-run it after ANY change to `src/export/pdf.ts`.

Current state: 6/6 checks pass.

## Build

```bash
npm run build          # -> dist/
```

`dist/` is committed so GitHub Pages can serve it without a build step, the same
way the calculators are served. After merging to `pps-pricing-config` it lives at
`/priorityprintservice.com/designer/dist/`.

**If you change source, rebuild and commit `dist/` in the same commit** or the
preview silently serves the old app.

## Layout

| Path | What |
|---|---|
| `src/model/` | Document model, units, colour, product specs. No DOM — imported by the node test directly. |
| `src/export/pdf.ts` | **The spike.** PDF generation. Everything else is a UI over this. |
| `src/export/preflight.ts` | Reloads the output and proves it's correct. |
| `src/render/Canvas.tsx` | Canvas rendering + the interaction state machine. |
| `src/ui/App.tsx` | Toolbar, layers, inspector, export. |
| `src/state/store.ts` | Undo/redo by snapshot. |
| `test/export.test.mjs` | The headless proof. |
| `public/fonts/` | Liberation faces (SIL OFL), bundled so font embedding is testable offline. |

## Two rules

1. **`model/` and `export/` must not import anything DOM.** That's what keeps
   the node test possible and what will let a Tauri/Rust backend swap in later.
2. **Don't let the UI get ahead of the exporter.** A feature that can't survive
   to the PDF is a demo, not a feature.

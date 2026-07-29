// ===========================================================================
// THE SPIKE. Everything else in this project is a UI over this file.
//
// The question being answered: can a browser emit a file a commercial press
// will accept? Specifically —
//   1. Correct page geometry (MediaBox / BleedBox / TrimBox with real bleed)
//   2. CMYK vector art and CMYK text that survive to the PDF untouched
//   3. Fonts embedded AND SUBSET (not linked, not outlined, not substituted)
//   4. Images embedded at full resolution
//   5. Reloadable + verifiable output (preflight)
//
// What is NOT solved here is documented honestly in docs/DESIGNER_SPIKE.md.
// Do not let the UI get ahead of this file.
// ===========================================================================

import { PDFDocument, cmyk as pdfCmyk, rgb as pdfRgb, degrees, PDFName, PDFString } from 'pdf-lib';
import fontkit from '@pdf-lib/fontkit';
import type { Doc, Item, TextItem, ImageItem } from '../model/doc.ts';
import { imageFit, rotatePt, centreOf } from '../model/doc.ts';
import { mediaW, mediaH } from '../model/products.ts';
import type { Color } from '../model/color.ts';

/** Injected so the same engine runs in the browser (fetch) and node (fs). */
export type FontLoader = (fontId: string) => Promise<Uint8Array>;

export type ExportOptions = {
  loadFont: FontLoader;
  /** Draw crop/registration marks outside the bleed. Off by default — most
   *  presses impose from the TrimBox and marks just get in the way. */
  cropMarks?: boolean;
  /** Stamp an OutputIntent. See the PDF/X caveat in the docs. */
  outputIntent?: { identifier: string; info: string } | null;
  title?: string;
};

const toPdfColor = (c: Color) =>
  c.space === 'cmyk' ? pdfCmyk(c.c, c.m, c.y, c.k) : pdfRgb(c.r, c.g, c.b);

function dataUrlToBytes(src: string): { bytes: Uint8Array; mime: string } {
  const m = /^data:([^;,]+)(;base64)?,(.*)$/s.exec(src);
  if (!m) throw new Error('image src must be a data: URL');
  const mime = m[1];
  const isB64 = !!m[2];
  const raw = m[3];
  if (!isB64) return { bytes: new TextEncoder().encode(decodeURIComponent(raw)), mime };
  // atob in the browser, Buffer under node — neither is typed in both worlds.
  const g = globalThis as unknown as {
    atob?: (s: string) => string;
    Buffer?: { from: (s: string, enc: string) => { toString: (enc: string) => string } };
  };
  const bin = g.atob ? g.atob(raw) : g.Buffer!.from(raw, 'base64').toString('binary');
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return { bytes: out, mime };
}

/**
 * Word-wrap a paragraph to a width. This is intentionally naive — greedy
 * first-fit, no hyphenation, no justification, no shaping.
 *
 * This is THE known weak point of the whole project. Real typesetting needs
 * HarfBuzz shaping + Knuth-Plass breaking. Anything set here will differ from
 * a professional typesetter on: kerning pairs, ligatures, any non-Latin
 * script, and justified copy. Acceptable for v1 headline/short-copy work;
 * NOT acceptable for body-copy-heavy booklets. See docs/DESIGNER_SPIKE.md.
 */
function wrapLines(
  text: string, font: any, size: number, tracking: number, maxWidth: number,
): string[] {
  const measure = (s: string) =>
    font.widthOfTextAtSize(s, size) + (tracking ? tracking * Math.max(0, s.length - 1) : 0);
  const out: string[] = [];
  for (const para of text.split(/\r?\n/)) {
    if (para === '') { out.push(''); continue; }
    const words = para.split(/\s+/).filter(Boolean);
    let line = '';
    for (const w of words) {
      const trial = line ? `${line} ${w}` : w;
      if (measure(trial) <= maxWidth || !line) line = trial;
      else { out.push(line); line = w; }
    }
    if (line) out.push(line);
  }
  return out;
}

export type ExportResult = {
  bytes: Uint8Array;
  /** Font ids actually embedded, for the preflight report. */
  fontsUsed: string[];
  warnings: string[];
};

export async function exportPdf(doc: Doc, opts: ExportOptions): Promise<ExportResult> {
  const warnings: string[] = [];
  const p = doc.product;
  const W = mediaW(p);
  const H = mediaH(p);
  const bleed = p.bleed;

  const pdf = await PDFDocument.create();
  pdf.registerFontkit(fontkit);
  pdf.setTitle(opts.title ?? 'PPS Designer document');
  pdf.setProducer('PPS Designer (spike)');
  pdf.setCreator('PPS Designer (spike)');

  // Font cache — embed each face once, subset on.
  const fontCache = new Map<string, any>();
  const getFont = async (fontId: string) => {
    const hit = fontCache.get(fontId);
    if (hit) return hit;
    const bytes = await opts.loadFont(fontId);
    // subset:true is the load-bearing flag. Without it the whole face ships.
    const f = await pdf.embedFont(bytes, { subset: true });
    fontCache.set(fontId, f);
    return f;
  };

  // Image cache keyed by the data URL, so a logo repeated on 40 pages embeds once.
  const imgCache = new Map<string, any>();
  const getImage = async (it: ImageItem) => {
    const hit = imgCache.get(it.src);
    if (hit) return hit;
    const { bytes, mime } = dataUrlToBytes(it.src);
    let img;
    if (/png/i.test(mime)) img = await pdf.embedPng(bytes);
    else if (/jpe?g/i.test(mime)) img = await pdf.embedJpg(bytes);
    else throw new Error(`unsupported image type: ${mime} (v1 handles PNG and JPEG)`);
    imgCache.set(it.src, img);
    return img;
  };

  /** model (y-down from bleed-box top-left) -> PDF (y-up from bottom-left) */
  const toPdfY = (yModel: number) => H - yModel;

  for (const page of doc.pages) {
    const pg = pdf.addPage([W, H]);
    // Geometry. MediaBox carries the bleed; TrimBox is the finished piece.
    pg.setMediaBox(0, 0, W, H);
    pg.setCropBox(0, 0, W, H);
    pg.setBleedBox(0, 0, W, H);
    pg.setTrimBox(bleed, bleed, p.trimW, p.trimH);

    for (const it of page.items) {
      if (!it.visible) continue;
      const { cx, cy } = centreOf(it);
      const angle = degrees(-it.rot); // see note in docs: y-flip makes this negative

      if (it.kind === 'rect') {
        // Anchor = model bottom-left corner, rotated about the item centre.
        const a = rotatePt(it.x, it.y + it.h, cx, cy, it.rot);
        pg.drawRectangle({
          x: a.x, y: toPdfY(a.y), width: it.w, height: it.h, rotate: angle,
          color: it.fill ? toPdfColor(it.fill) : undefined,
          borderColor: it.strokeW > 0 && it.stroke ? toPdfColor(it.stroke) : undefined,
          borderWidth: it.strokeW > 0 ? it.strokeW : undefined,
        });
      } else if (it.kind === 'ellipse') {
        // pdf-lib anchors ellipses at the CENTRE, so no corner math needed.
        pg.drawEllipse({
          x: cx, y: toPdfY(cy), xScale: it.w / 2, yScale: it.h / 2, rotate: angle,
          color: it.fill ? toPdfColor(it.fill) : undefined,
          borderColor: it.strokeW > 0 && it.stroke ? toPdfColor(it.stroke) : undefined,
          borderWidth: it.strokeW > 0 ? it.strokeW : undefined,
        });
      } else if (it.kind === 'image') {
        const img = await getImage(it);
        const f = imageFit(it);
        if (it.fit === 'fill' && (f.dw > it.w + 0.01 || f.dh > it.h + 0.01)) {
          // No clipping path in v1 — a 'fill' image bleeds past its frame.
          warnings.push(`"${it.name}" uses Fill and overflows its frame; v1 has no clip path so the overflow will print.`);
        }
        const a = rotatePt(f.dx, f.dy + f.dh, cx, cy, it.rot);
        pg.drawImage(img, {
          x: a.x, y: toPdfY(a.y), width: f.dw, height: f.dh, rotate: angle,
        });
      } else if (it.kind === 'text') {
        const t = it as TextItem;
        const font = await getFont(t.fontId);
        const lines = wrapLines(t.text, font, t.size, t.tracking, t.w);
        const leading = t.leading || t.size * 1.2;
        // First baseline sits one ascent below the frame top.
        const ascent = font.heightAtSize(t.size, { descender: false });
        lines.forEach((line, i) => {
          const lineW = font.widthOfTextAtSize(line, t.size)
            + (t.tracking ? t.tracking * Math.max(0, line.length - 1) : 0);
          const dx = t.align === 'center' ? (t.w - lineW) / 2
            : t.align === 'right' ? t.w - lineW : 0;
          const baselineY = t.y + ascent + i * leading;
          if (baselineY > t.y + t.h + leading) return; // overset — dropped, see warning below
          const a = rotatePt(t.x + dx, baselineY, cx, cy, t.rot);
          if (!t.tracking) {
            pg.drawText(line, {
              x: a.x, y: toPdfY(a.y), size: t.size, font,
              color: toPdfColor(t.color), rotate: angle,
            });
          } else {
            // Tracking means per-glyph placement; pdf-lib has no Tc setter.
            let penX = t.x + dx;
            for (const ch of line) {
              const pa = rotatePt(penX, baselineY, cx, cy, t.rot);
              pg.drawText(ch, {
                x: pa.x, y: toPdfY(pa.y), size: t.size, font,
                color: toPdfColor(t.color), rotate: angle,
              });
              penX += font.widthOfTextAtSize(ch, t.size) + t.tracking;
            }
          }
        });
        const fits = Math.floor((t.h - 0.001) / leading) + 1;
        if (lines.length > fits) {
          warnings.push(`"${t.name}" is overset — ${lines.length - fits} line(s) do not fit and were not drawn.`);
        }
      }
    }

    if (opts.cropMarks && bleed > 0) {
      const L = Math.min(bleed * 0.8, 9);
      const off = 2;
      const mark = toPdfColor({ space: 'cmyk', c: 1, m: 1, y: 1, k: 1 });
      const seg = (x1: number, y1: number, x2: number, y2: number) =>
        pg.drawLine({ start: { x: x1, y: y1 }, end: { x: x2, y: y2 }, thickness: 0.25, color: mark });
      const xs = [bleed, bleed + p.trimW];
      const ys = [bleed, bleed + p.trimH];
      for (const x of xs) for (const y of ys) {
        const sx = x === bleed ? -1 : 1, sy = y === bleed ? -1 : 1;
        seg(x + sx * off, y, x + sx * (off + L), y);
        seg(x, y + sy * off, x, y + sy * (off + L));
      }
    }
  }

  // --- OutputIntent -------------------------------------------------------
  // PARTIAL. A genuinely PDF/X-conformant file needs the ICC profile bytes
  // embedded as DestOutputProfile, plus transparency/colour rules enforced.
  // This stamps the identification only. See docs/DESIGNER_SPIKE.md.
  if (opts.outputIntent) {
    const ctx = pdf.context;
    const oi = ctx.obj({
      Type: PDFName.of('OutputIntent'),
      S: PDFName.of('GTS_PDFX'),
      OutputConditionIdentifier: PDFString.of(opts.outputIntent.identifier),
      Info: PDFString.of(opts.outputIntent.info),
    });
    pdf.catalog.set(PDFName.of('OutputIntents'), ctx.obj([oi]));
    warnings.push('OutputIntent is identification-only — no ICC profile embedded, so this is NOT yet a conformant PDF/X file.');
  }

  const bytes = await pdf.save({ useObjectStreams: false });
  return { bytes, fontsUsed: [...fontCache.keys()], warnings };
}

// Preflight: reload the bytes we just wrote and PROVE they are what we claim.
// The imposition tool does the same thing before it will let a file be
// uploaded, and for the same reason — "it downloaded" is not "it's correct".

import { PDFDocument, PDFName, PDFDict, PDFRawStream, PDFArray, decodePDFRawStream } from 'pdf-lib';
import type { Doc } from '../model/doc.ts';
import { mediaW, mediaH } from '../model/products.ts';
import { toInch } from '../model/units.ts';

export type PreflightReport = {
  ok: boolean;
  errors: string[];
  warnings: string[];
  facts: Record<string, string>;
};

const near = (a: number, b: number, tol = 0.01) => Math.abs(a - b) < tol;
const dim = (n: number) => `${(Math.round(toInch(n) * 1000) / 1000)}"`;

export async function preflight(bytes: Uint8Array, doc: Doc): Promise<PreflightReport> {
  const errors: string[] = [];
  const warnings: string[] = [];
  const facts: Record<string, string> = {};

  let pdf: PDFDocument;
  try {
    pdf = await PDFDocument.load(bytes);
  } catch (e) {
    return { ok: false, errors: [`file will not reload: ${(e as Error).message}`], warnings, facts };
  }

  const p = doc.product;
  const expW = mediaW(p), expH = mediaH(p);
  const pages = pdf.getPages();

  facts['File size'] = `${(bytes.length / 1024).toFixed(1)} KB`;
  facts['Pages'] = String(pages.length);
  facts['Media size'] = `${dim(expW)} × ${dim(expH)}`;
  facts['Trim size'] = `${dim(p.trimW)} × ${dim(p.trimH)}`;
  facts['Bleed'] = dim(p.bleed);

  if (pages.length !== doc.pages.length) {
    errors.push(`page count ${pages.length} ≠ document ${doc.pages.length}`);
  }

  pages.forEach((pg, i) => {
    const mb = pg.getMediaBox();
    if (!near(mb.width, expW) || !near(mb.height, expH)) {
      errors.push(`page ${i + 1} MediaBox ${dim(mb.width)}×${dim(mb.height)} ≠ expected ${dim(expW)}×${dim(expH)}`);
    }
    let tb;
    try { tb = pg.getTrimBox(); } catch { tb = null; }
    if (!tb) {
      errors.push(`page ${i + 1} has no TrimBox — the press cannot tell where to cut`);
    } else {
      if (!near(tb.width, p.trimW) || !near(tb.height, p.trimH)) {
        errors.push(`page ${i + 1} TrimBox ${dim(tb.width)}×${dim(tb.height)} ≠ trim ${dim(p.trimW)}×${dim(p.trimH)}`);
      }
      if (!near(tb.x, p.bleed) || !near(tb.y, p.bleed)) {
        errors.push(`page ${i + 1} TrimBox is offset ${dim(tb.x)},${dim(tb.y)} — expected ${dim(p.bleed)} on each side`);
      }
    }
    try { if (!pg.getBleedBox()) warnings.push(`page ${i + 1} has no BleedBox`); } catch { /* optional */ }
  });

  // --- Embedded fonts -----------------------------------------------------
  // Two separate questions, and they are NOT the same:
  //   (a) is the face embedded at all      -> FontFile/FontFile2/FontFile3
  //   (b) is it subset                     -> embedded stream much smaller
  //                                           than a full face
  // Per PDF 32000 §9.6.4 a subset font is ALSO supposed to carry a six-
  // uppercase-letter tag ("ABCDEF+Name"). pdf-lib does not do this — it emits
  // "Name-1234". The subsetting itself is real; the naming is not conformant,
  // and strict PDF/X validators flag it. Tracked in docs/DESIGNER_SPIKE.md.
  const embedded: Array<{ name: string; kb: number }> = [];
  const notEmbedded: string[] = [];
  let taggedCount = 0;

  pdf.context.enumerateIndirectObjects().forEach(([, obj]) => {
    if (!(obj instanceof PDFDict)) return;
    if (obj.get(PDFName.of('Type')) !== PDFName.of('FontDescriptor')) return;
    const nameObj = obj.get(PDFName.of('FontName'));
    const name = nameObj ? String(nameObj).replace(/^\//, '') : '(unnamed)';
    const fileRef = obj.get(PDFName.of('FontFile'))
      ?? obj.get(PDFName.of('FontFile2'))
      ?? obj.get(PDFName.of('FontFile3'));
    if (!fileRef) { notEmbedded.push(name); return; }
    let kb = 0;
    try {
      const st = pdf.context.lookup(fileRef as never);
      if (st instanceof PDFRawStream) kb = st.contents.length / 1024;
    } catch { /* size unknown */ }
    embedded.push({ name, kb });
    if (/^[A-Z]{6}\+/.test(name)) taggedCount++;
  });

  facts['Fonts embedded'] = embedded.length
    ? embedded.map((f) => `${f.name} (${f.kb.toFixed(0)} KB)`).join(', ')
    : '(none)';

  // A full Latin TrueType face is ~150 KB and up; a handful of glyphs is <30 KB.
  const SUBSET_KB = 60;
  const subsetBySize = embedded.filter((f) => f.kb > 0 && f.kb < SUBSET_KB).length;
  const sized = embedded.filter((f) => f.kb > 0).length;
  facts['Fonts subset'] = sized
    ? `${subsetBySize} of ${sized} (by embedded size < ${SUBSET_KB} KB)`
    : 'not measurable';

  if (notEmbedded.length) {
    errors.push(`font(s) NOT embedded: ${notEmbedded.join(', ')} — the press will substitute`);
  }
  if (sized && subsetBySize < sized) {
    warnings.push(`${sized - subsetBySize} embedded face(s) look like full faces, not subsets — file is larger than it needs to be`);
  }
  if (embedded.length && taggedCount < embedded.length) {
    warnings.push(`${embedded.length - taggedCount} subset font(s) lack the required "ABCDEF+" subset tag (pdf-lib limitation) — strict PDF/X validators will flag this`);
  }

  // --- Images -------------------------------------------------------------
  let images = 0;
  pdf.context.enumerateIndirectObjects().forEach(([, obj]) => {
    if (obj instanceof PDFRawStream) {
      const st = obj.dict.get(PDFName.of('Subtype'));
      if (st === PDFName.of('Image')) images++;
    }
  });
  facts['Embedded images'] = String(images);

  // --- Colour space actually used in the content streams -------------------
  // 'k'/'K' are the CMYK fill/stroke operators; 'rg'/'RG' are RGB.
  let sawCmyk = false, sawRgb = false, decoded = 0;
  for (const pg of pages) {
    const contents = pg.node.get(PDFName.of('Contents'));
    const streams: PDFRawStream[] = [];
    const push = (ref: unknown) => {
      const r = pdf.context.lookup(ref as never);
      if (r instanceof PDFRawStream) streams.push(r);
    };
    if (contents instanceof PDFArray) contents.asArray().forEach(push);
    else push(contents);
    for (const s of streams) {
      try {
        const txt = new TextDecoder('latin1').decode(decodePDFRawStream(s).decode());
        decoded++;
        if (/(^|\s)-?[\d.]+ -?[\d.]+ -?[\d.]+ -?[\d.]+ (k|K)(\s|$)/m.test(txt)) sawCmyk = true;
        if (/(^|\s)-?[\d.]+ -?[\d.]+ -?[\d.]+ (rg|RG)(\s|$)/m.test(txt)) sawRgb = true;
      } catch { /* compressed in a way we can't read here; reported below */ }
    }
  }
  if (decoded === 0) {
    facts['Colour operators'] = 'not verified (streams undecodable here)';
  } else {
    facts['Colour operators'] = [sawCmyk ? 'CMYK' : null, sawRgb ? 'RGB' : null]
      .filter(Boolean).join(' + ') || 'none found';
    if (sawRgb) {
      warnings.push('RGB colour operators present — fine for a digital press, but a PDF/X-1a workflow would reject them');
    }
  }

  return { ok: errors.length === 0, errors, warnings, facts };
}

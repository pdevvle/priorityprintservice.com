// Everything in the document model is stored in PostScript points (1/72").
// Points are the PDF-native unit, so export does no unit math at all — that's
// deliberate. Inches only appear at the UI boundary and in product specs.

export const PT_PER_IN = 72;

export const inch = (n: number): number => n * PT_PER_IN;
export const toInch = (pt: number): number => pt / PT_PER_IN;

/** Round to a sane precision for display (avoids 2.9999999996"). */
export const fmtInch = (pt: number, places = 3): string =>
  (Math.round(toInch(pt) * 10 ** places) / 10 ** places).toFixed(places).replace(/\.?0+$/, '');

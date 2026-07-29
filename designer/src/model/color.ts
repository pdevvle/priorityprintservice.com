// Color is stored in the space the user picked. CMYK values survive to the PDF
// untouched — that is the whole point, and it's why we don't normalise to RGB
// internally the way a screen-design tool would.
//
// KNOWN GAP: cmykToRgb below is the naive algebraic conversion, NOT an ICC
// transform. It is for SCREEN PREVIEW ONLY. On-screen color will not match
// press output. Closing this needs lcms2 (wasm or native) + a real CMYK
// profile — see docs/DESIGNER_SPIKE.md.

export type CmykColor = { space: 'cmyk'; c: number; m: number; y: number; k: number };
export type RgbColor = { space: 'rgb'; r: number; g: number; b: number };
export type Color = CmykColor | RgbColor;

export const cmyk = (c: number, m: number, y: number, k: number): CmykColor => ({
  space: 'cmyk', c, m, y, k,
});
export const rgb = (r: number, g: number, b: number): RgbColor => ({ space: 'rgb', r, g, b });

export const BLACK: CmykColor = cmyk(0, 0, 0, 1);
/** Rich black — what you actually want for large solid areas on press. */
export const RICH_BLACK: CmykColor = cmyk(0.6, 0.4, 0.4, 1);
export const WHITE: CmykColor = cmyk(0, 0, 0, 0);
export const REGISTRATION_MAGENTA: CmykColor = cmyk(0, 1, 0, 0);

const clamp01 = (n: number): number => (n < 0 ? 0 : n > 1 ? 1 : n);

/** SCREEN PREVIEW ONLY. Not colour-managed. See note above. */
export function cmykToRgb(col: CmykColor): { r: number; g: number; b: number } {
  const c = clamp01(col.c), m = clamp01(col.m), y = clamp01(col.y), k = clamp01(col.k);
  return { r: (1 - c) * (1 - k), g: (1 - m) * (1 - k), b: (1 - y) * (1 - k) };
}

export function toCss(col: Color | undefined, fallback = 'transparent'): string {
  if (!col) return fallback;
  const { r, g, b } = col.space === 'cmyk' ? cmykToRgb(col) : { r: col.r, g: col.g, b: col.b };
  return `rgb(${Math.round(r * 255)} ${Math.round(g * 255)} ${Math.round(b * 255)})`;
}

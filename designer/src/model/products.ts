// Product specs — the thing that makes this a print tool rather than a canvas.
// The document IS a product: trim, bleed, safety, fold geometry and page-count
// rules are structural, not guides someone remembered to draw.
//
// TODO (wiring): these are standard trade sizes typed by hand. They should be
// generated from the live PPS calculator specs (PPS_CONFIG / the SIZES tables
// in calc-*.html) so the designer and the pricing engine can never disagree —
// same invariant the imposition tool holds. Until that's wired, treat any
// mismatch as the calculator being right.

import { inch } from './units.ts';

export type FoldLine = {
  /** Distance from the LEFT trim edge, in points. */
  at: number;
  dir: 'valley' | 'mountain';
};

export type ProductSpec = {
  key: string;
  label: string;
  group: string;
  /** Finished (trim) size in points. */
  trimW: number;
  trimH: number;
  /** Bleed on all four sides, points. */
  bleed: number;
  /** Safety margin inset from trim, points. */
  safety: number;
  initialPages: number;
  /** If set, page count must be a multiple of this (saddle stitch = 4). */
  pageMultiple?: number;
  minPages?: number;
  folds?: FoldLine[];
  /** Facing pages — booklets read as spreads. */
  spreads?: boolean;
  notes?: string;
};

const B = inch(0.125);
const S = inch(0.125);

export const PRODUCTS: ProductSpec[] = [
  {
    key: 'postcard-4x6', label: 'Postcard 4×6', group: 'Flat',
    trimW: inch(6), trimH: inch(4), bleed: B, safety: S, initialPages: 2,
    notes: 'Page 1 = front, page 2 = back.',
  },
  {
    key: 'postcard-5x7', label: 'Postcard 5×7', group: 'Flat',
    trimW: inch(7), trimH: inch(5), bleed: B, safety: S, initialPages: 2,
  },
  {
    key: 'postcard-6x9', label: 'Postcard 6×9', group: 'Flat',
    trimW: inch(9), trimH: inch(6), bleed: B, safety: S, initialPages: 2,
  },
  {
    key: 'letterhead-8.5x11', label: 'Letterhead 8.5×11', group: 'Flat',
    trimW: inch(8.5), trimH: inch(11), bleed: B, safety: inch(0.25), initialPages: 1,
  },
  {
    key: 'flyer-8.5x11', label: 'Flyer 8.5×11', group: 'Flat',
    trimW: inch(8.5), trimH: inch(11), bleed: B, safety: S, initialPages: 2,
  },
  {
    key: 'brochure-trifold-8.5x11', label: 'Brochure 8.5×11 tri-fold', group: 'Folded',
    trimW: inch(11), trimH: inch(8.5), bleed: B, safety: S, initialPages: 2,
    // Roll-fold: the inner panel is narrower so it tucks. 3.66 / 3.67 / 3.67.
    folds: [
      { at: inch(3.6667), dir: 'valley' },
      { at: inch(7.3334), dir: 'valley' },
    ],
    notes: 'Roll fold. Panel 1 (rightmost on the back) tucks inside — keep it 1/16" narrower.',
  },
  {
    key: 'brochure-half-8.5x11', label: 'Brochure 8.5×11 half-fold', group: 'Folded',
    trimW: inch(11), trimH: inch(8.5), bleed: B, safety: S, initialPages: 2,
    folds: [{ at: inch(5.5), dir: 'valley' }],
  },
  {
    key: 'brochure-z-8.5x11', label: 'Brochure 8.5×11 Z-fold', group: 'Folded',
    trimW: inch(11), trimH: inch(8.5), bleed: B, safety: S, initialPages: 2,
    folds: [
      { at: inch(3.6667), dir: 'valley' },
      { at: inch(7.3334), dir: 'mountain' },
    ],
  },
  {
    key: 'booklet-saddle-8.5x11', label: 'Booklet 8.5×11 saddle stitch', group: 'Bound',
    trimW: inch(8.5), trimH: inch(11), bleed: B, safety: inch(0.25),
    initialPages: 8, pageMultiple: 4, minPages: 8, spreads: true,
    notes: 'Page count must be a multiple of 4. Page 1 = cover.',
  },
  {
    key: 'booklet-saddle-5.5x8.5', label: 'Booklet 5.5×8.5 saddle stitch', group: 'Bound',
    trimW: inch(5.5), trimH: inch(8.5), bleed: B, safety: inch(0.25),
    initialPages: 8, pageMultiple: 4, minPages: 8, spreads: true,
  },
  {
    key: 'bizcard-3.5x2', label: 'Business card 3.5×2', group: 'Flat',
    trimW: inch(3.5), trimH: inch(2), bleed: B, safety: inch(0.0625), initialPages: 2,
  },
];

export const productByKey = (key: string): ProductSpec =>
  PRODUCTS.find((p) => p.key === key) ?? PRODUCTS[0];

/** Full media size including bleed — this becomes the PDF MediaBox. */
export const mediaW = (p: ProductSpec): number => p.trimW + p.bleed * 2;
export const mediaH = (p: ProductSpec): number => p.trimH + p.bleed * 2;

/** Validate page count against the product's binding rule. */
export function pageCountIssue(p: ProductSpec, n: number): string | null {
  if (p.minPages && n < p.minPages) return `${p.label} needs at least ${p.minPages} pages.`;
  if (p.pageMultiple && n % p.pageMultiple !== 0) {
    const next = Math.ceil(n / p.pageMultiple) * p.pageMultiple;
    return `${p.label} binds in ${p.pageMultiple}s — ${n} pages won't work. Next valid count is ${next}.`;
  }
  return null;
}

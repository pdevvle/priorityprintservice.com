// The document model. Deliberately small and JSON-serialisable: the whole doc
// round-trips through JSON.stringify, which is what makes undo/redo, autosave
// and .ppsd files trivial.
//
// Geometry convention: x/y is the TOP-LEFT of the item's unrotated box, in
// points, measured from the top-left of the BLEED box (not the trim box). Y
// grows downward, matching the canvas. The exporter is the only place that
// flips into PDF's y-up space.

import type { Color } from './color.ts';
import { BLACK } from './color.ts';
import type { ProductSpec } from './products.ts';

export type ItemKind = 'rect' | 'ellipse' | 'text' | 'image';

export type ItemBase = {
  id: string;
  kind: ItemKind;
  name: string;
  x: number;
  y: number;
  w: number;
  h: number;
  /** Degrees clockwise, about the item's centre. */
  rot: number;
  locked: boolean;
  visible: boolean;
};

export type RectItem = ItemBase & {
  kind: 'rect';
  fill?: Color;
  stroke?: Color;
  strokeW: number;
};

export type EllipseItem = ItemBase & {
  kind: 'ellipse';
  fill?: Color;
  stroke?: Color;
  strokeW: number;
};

export type TextItem = ItemBase & {
  kind: 'text';
  text: string;
  fontId: string;
  size: number;
  /** Baseline-to-baseline, in points. */
  leading: number;
  /** Letter-spacing in points. */
  tracking: number;
  align: 'left' | 'center' | 'right';
  color: Color;
};

export type ImageItem = ItemBase & {
  kind: 'image';
  /** data: URL. Embedded, not linked — v1 has no link management. */
  src: string;
  naturalW: number;
  naturalH: number;
  fit: 'fill' | 'fit' | 'stretch';
};

export type Item = RectItem | EllipseItem | TextItem | ImageItem;

export type Page = {
  id: string;
  items: Item[];
};

export type Doc = {
  version: 1;
  product: ProductSpec;
  pages: Page[];
};

let seq = 0;
export const uid = (p: string): string => `${p}_${(seq++).toString(36)}_${Math.floor(performance.now() % 1e6).toString(36)}`;

export function emptyPage(): Page {
  return { id: uid('pg'), items: [] };
}

export function newDoc(product: ProductSpec): Doc {
  const n = Math.max(1, product.initialPages);
  return {
    version: 1,
    product,
    pages: Array.from({ length: n }, () => emptyPage()),
  };
}

// ---------------------------------------------------------------------------
// Item factories
// ---------------------------------------------------------------------------

const base = (kind: ItemKind, name: string, x: number, y: number, w: number, h: number): ItemBase => ({
  id: uid(kind), kind, name, x, y, w, h, rot: 0, locked: false, visible: true,
});

export const makeRect = (x: number, y: number, w: number, h: number, fill: Color = BLACK): RectItem =>
  ({ ...base('rect', 'Rectangle', x, y, w, h), kind: 'rect', fill, strokeW: 0 });

export const makeEllipse = (x: number, y: number, w: number, h: number, fill: Color = BLACK): EllipseItem =>
  ({ ...base('ellipse', 'Ellipse', x, y, w, h), kind: 'ellipse', fill, strokeW: 0 });

export const makeText = (x: number, y: number, w: number, h: number, text = 'Type here'): TextItem =>
  ({
    ...base('text', 'Text', x, y, w, h), kind: 'text', text,
    fontId: 'LiberationSans-Regular', size: 14, leading: 17, tracking: 0,
    align: 'left', color: BLACK,
  });

export const makeImage = (
  x: number, y: number, w: number, h: number, src: string, naturalW: number, naturalH: number,
): ImageItem =>
  ({ ...base('image', 'Image', x, y, w, h), kind: 'image', src, naturalW, naturalH, fit: 'fill' });

// ---------------------------------------------------------------------------
// Geometry helpers shared by canvas + exporter (single source of truth, so the
// preview and the PDF can never disagree about where something sits).
// ---------------------------------------------------------------------------

export type Rect = { x: number; y: number; w: number; h: number };

export const centreOf = (it: Rect): { cx: number; cy: number } =>
  ({ cx: it.x + it.w / 2, cy: it.y + it.h / 2 });

/** Rotate a point about a centre. Angle in degrees, clockwise in screen space. */
export function rotatePt(px: number, py: number, cx: number, cy: number, deg: number): { x: number; y: number } {
  if (!deg) return { x: px, y: py };
  const r = (deg * Math.PI) / 180;
  const cos = Math.cos(r), sin = Math.sin(r);
  const dx = px - cx, dy = py - cy;
  return { x: cx + dx * cos - dy * sin, y: cy + dx * sin + dy * cos };
}

/** The four corners of an item after rotation, in model space. */
export function cornersOf(it: Rect & { rot: number }): Array<{ x: number; y: number }> {
  const { cx, cy } = centreOf(it);
  return [
    [it.x, it.y], [it.x + it.w, it.y], [it.x + it.w, it.y + it.h], [it.x, it.y + it.h],
  ].map(([px, py]) => rotatePt(px, py, cx, cy, it.rot));
}

/**
 * How an image sits inside its frame. Returns the draw rect in model space
 * plus the clip rect. Mirrors the calculators' FitToggle semantics so art
 * placed here behaves the way customers already expect.
 */
export function imageFit(it: ImageItem): { dx: number; dy: number; dw: number; dh: number } {
  const frameAR = it.w / it.h;
  const imgAR = it.naturalW / it.naturalH;
  if (it.fit === 'stretch' || !isFinite(imgAR) || imgAR <= 0) {
    return { dx: it.x, dy: it.y, dw: it.w, dh: it.h };
  }
  // 'fill' = cover (crop overflow); 'fit' = contain (letterbox).
  const cover = it.fit === 'fill';
  const scaleByWidth = cover ? imgAR < frameAR : imgAR > frameAR;
  const dw = scaleByWidth ? it.w : it.h * imgAR;
  const dh = scaleByWidth ? it.w / imgAR : it.h;
  return { dx: it.x + (it.w - dw) / 2, dy: it.y + (it.h - dh) / 2, dw, dh };
}

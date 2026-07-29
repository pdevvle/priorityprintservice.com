import { useCallback, useEffect, useRef, useState } from 'react';
import type { Store } from '../state/store.ts';
import type { Item } from '../model/doc.ts';
import { rotatePt, centreOf, imageFit, makeRect, makeEllipse, makeText } from '../model/doc.ts';
import { mediaW, mediaH } from '../model/products.ts';
import { toCss, BLACK } from '../model/color.ts';
import { toInch } from '../model/units.ts';

export type Tool = 'select' | 'rect' | 'ellipse' | 'text';

type View = { zoom: number; px: number; py: number };

type Drag =
  | { mode: 'none' }
  | { mode: 'pan'; sx: number; sy: number; ox: number; oy: number }
  | { mode: 'move'; sx: number; sy: number; start: Map<string, { x: number; y: number }> }
  | { mode: 'resize'; handle: number; sx: number; sy: number; box: { x: number; y: number; w: number; h: number; rot: number }; id: string }
  | { mode: 'rotate'; id: string; cx: number; cy: number; start: number; base: number }
  | { mode: 'create'; sx: number; sy: number; id: string };

const HANDLES = [
  [0, 0], [0.5, 0], [1, 0],
  [1, 0.5], [1, 1], [0.5, 1],
  [0, 1], [0, 0.5],
];
const HANDLE_PX = 7;
const SNAP_PX = 6;

/** Image element cache so we don't re-decode a data URL every frame. */
const imgCache = new Map<string, HTMLImageElement>();
function getImg(src: string, onLoad: () => void): HTMLImageElement | null {
  const hit = imgCache.get(src);
  if (hit) return hit.complete && hit.naturalWidth ? hit : null;
  const im = new Image();
  im.onload = onLoad;
  im.onerror = () => { /* leave uncached-complete; caller just skips drawing */ };
  im.src = src;
  imgCache.set(src, im);
  return null;
}

export function Canvas({ store, tool, setTool }: { store: Store; tool: Tool; setTool: (t: Tool) => void }) {
  const ref = useRef<HTMLCanvasElement | null>(null);
  const wrapRef = useRef<HTMLDivElement | null>(null);
  const [view, setView] = useState<View>({ zoom: 1, px: 0, py: 0 });
  const [drag, setDrag] = useState<Drag>({ mode: 'none' });
  const [guides, setGuides] = useState<{ v: number[]; h: number[] }>({ v: [], h: [] });
  const [, redraw] = useState(0);
  const fitted = useRef(false);

  const p = store.doc.product;
  const MW = mediaW(p), MH = mediaH(p);

  // --- coordinate transforms ------------------------------------------------
  const toScreen = useCallback((x: number, y: number) => ({
    x: x * view.zoom + view.px, y: y * view.zoom + view.py,
  }), [view]);
  const toModel = useCallback((x: number, y: number) => ({
    x: (x - view.px) / view.zoom, y: (y - view.py) / view.zoom,
  }), [view]);

  const fitView = useCallback(() => {
    const wrap = wrapRef.current;
    if (!wrap) return;
    const pad = 48;
    const z = Math.min((wrap.clientWidth - pad * 2) / MW, (wrap.clientHeight - pad * 2) / MH);
    setView({ zoom: z, px: (wrap.clientWidth - MW * z) / 2, py: (wrap.clientHeight - MH * z) / 2 });
  }, [MW, MH]);

  useEffect(() => { if (!fitted.current) { fitted.current = true; fitView(); } }, [fitView]);
  useEffect(() => {
    const onResize = () => redraw((n) => n + 1);
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, []);

  // --- drawing --------------------------------------------------------------
  useEffect(() => {
    const cv = ref.current, wrap = wrapRef.current;
    if (!cv || !wrap) return;
    const dpr = window.devicePixelRatio || 1;
    const W = wrap.clientWidth, H = wrap.clientHeight;
    cv.width = W * dpr; cv.height = H * dpr;
    cv.style.width = `${W}px`; cv.style.height = `${H}px`;
    const ctx = cv.getContext('2d');
    if (!ctx) return;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    // pasteboard
    ctx.fillStyle = '#6f7276';
    ctx.fillRect(0, 0, W, H);

    ctx.save();
    ctx.translate(view.px, view.py);
    ctx.scale(view.zoom, view.zoom);

    // media (bleed) sheet
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, MW, MH);

    // items
    for (const it of store.page?.items ?? []) {
      if (!it.visible) continue;
      const { cx, cy } = centreOf(it);
      ctx.save();
      ctx.translate(cx, cy);
      ctx.rotate((it.rot * Math.PI) / 180);
      ctx.translate(-cx, -cy);
      drawItem(ctx, it, () => redraw((n) => n + 1));
      ctx.restore();
    }

    // guides: bleed / trim / safety / folds
    const lw = 1 / view.zoom;
    ctx.lineWidth = lw;
    ctx.setLineDash([]);
    ctx.strokeStyle = 'rgba(220,40,40,.85)';
    ctx.strokeRect(0, 0, MW, MH); // bleed edge
    ctx.strokeStyle = 'rgba(20,20,20,.9)';
    ctx.strokeRect(p.bleed, p.bleed, p.trimW, p.trimH); // trim
    ctx.setLineDash([4 / view.zoom, 3 / view.zoom]);
    ctx.strokeStyle = 'rgba(40,110,220,.9)';
    ctx.strokeRect(p.bleed + p.safety, p.bleed + p.safety, p.trimW - p.safety * 2, p.trimH - p.safety * 2);
    if (p.folds?.length) {
      ctx.strokeStyle = 'rgba(190,60,190,.95)';
      ctx.setLineDash([8 / view.zoom, 4 / view.zoom]);
      for (const f of p.folds) {
        const x = p.bleed + f.at;
        ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, MH); ctx.stroke();
      }
    }
    ctx.setLineDash([]);

    // snap guides (live during drag)
    if (guides.v.length || guides.h.length) {
      ctx.strokeStyle = 'rgba(255,60,180,.95)';
      ctx.lineWidth = lw;
      for (const x of guides.v) { ctx.beginPath(); ctx.moveTo(x, -1e4); ctx.lineTo(x, 1e4); ctx.stroke(); }
      for (const y of guides.h) { ctx.beginPath(); ctx.moveTo(-1e4, y); ctx.lineTo(1e4, y); ctx.stroke(); }
    }

    ctx.restore();

    // selection chrome in screen space (so it stays crisp at any zoom)
    for (const it of store.selected) {
      const { cx, cy } = centreOf(it);
      const pts = [[it.x, it.y], [it.x + it.w, it.y], [it.x + it.w, it.y + it.h], [it.x, it.y + it.h]]
        .map(([x, y]) => rotatePt(x, y, cx, cy, it.rot))
        .map((q) => toScreen(q.x, q.y));
      ctx.strokeStyle = '#2b7fff';
      ctx.lineWidth = 1.5;
      ctx.beginPath();
      pts.forEach((q, i) => (i ? ctx.lineTo(q.x, q.y) : ctx.moveTo(q.x, q.y)));
      ctx.closePath();
      ctx.stroke();
      if (store.selected.length === 1) {
        ctx.fillStyle = '#fff';
        for (const [hx, hy] of HANDLES) {
          const m = rotatePt(it.x + it.w * hx, it.y + it.h * hy, cx, cy, it.rot);
          const s = toScreen(m.x, m.y);
          ctx.beginPath();
          ctx.rect(s.x - HANDLE_PX / 2, s.y - HANDLE_PX / 2, HANDLE_PX, HANDLE_PX);
          ctx.fill(); ctx.stroke();
        }
        // rotate handle above the top edge
        const rm = rotatePt(it.x + it.w / 2, it.y - 22 / view.zoom, cx, cy, it.rot);
        const rs = toScreen(rm.x, rm.y);
        const tm = rotatePt(it.x + it.w / 2, it.y, cx, cy, it.rot);
        const ts = toScreen(tm.x, tm.y);
        ctx.beginPath(); ctx.moveTo(ts.x, ts.y); ctx.lineTo(rs.x, rs.y); ctx.stroke();
        ctx.beginPath(); ctx.arc(rs.x, rs.y, HANDLE_PX / 2 + 1, 0, Math.PI * 2);
        ctx.fill(); ctx.stroke();
      }
    }
  });

  // --- hit testing ----------------------------------------------------------
  const hitHandle = (mx: number, my: number): { id: string; handle: number } | null => {
    if (store.selected.length !== 1) return null;
    const it = store.selected[0];
    const { cx, cy } = centreOf(it);
    const tol = (HANDLE_PX + 3) / view.zoom;
    for (let i = 0; i < HANDLES.length; i++) {
      const [hx, hy] = HANDLES[i];
      const m = rotatePt(it.x + it.w * hx, it.y + it.h * hy, cx, cy, it.rot);
      if (Math.abs(mx - m.x) < tol && Math.abs(my - m.y) < tol) return { id: it.id, handle: i };
    }
    const rm = rotatePt(it.x + it.w / 2, it.y - 22 / view.zoom, cx, cy, it.rot);
    if (Math.hypot(mx - rm.x, my - rm.y) < tol) return { id: it.id, handle: -1 };
    return null;
  };

  const hitItem = (mx: number, my: number): Item | null => {
    const items = store.page?.items ?? [];
    for (let i = items.length - 1; i >= 0; i--) {
      const it = items[i];
      if (!it.visible || it.locked) continue;
      const { cx, cy } = centreOf(it);
      const q = rotatePt(mx, my, cx, cy, -it.rot); // inverse-rotate into local space
      if (q.x >= it.x && q.x <= it.x + it.w && q.y >= it.y && q.y <= it.y + it.h) return it;
    }
    return null;
  };

  // --- snapping -------------------------------------------------------------
  const snapTargets = (exclude: Set<string>) => {
    const v = [0, MW, p.bleed, p.bleed + p.trimW, MW / 2,
      p.bleed + p.safety, p.bleed + p.trimW - p.safety];
    const h = [0, MH, p.bleed, p.bleed + p.trimH, MH / 2,
      p.bleed + p.safety, p.bleed + p.trimH - p.safety];
    for (const f of p.folds ?? []) v.push(p.bleed + f.at);
    for (const it of store.page?.items ?? []) {
      if (exclude.has(it.id) || it.rot) continue;
      v.push(it.x, it.x + it.w / 2, it.x + it.w);
      h.push(it.y, it.y + it.h / 2, it.y + it.h);
    }
    return { v, h };
  };

  const applySnap = (dx: number, dy: number, items: Item[]) => {
    const tol = SNAP_PX / view.zoom;
    const ex = new Set(items.map((i) => i.id));
    const t = snapTargets(ex);
    let bestX: number | null = null, bestY: number | null = null;
    let hitV: number[] = [], hitH: number[] = [];
    for (const it of items) {
      for (const edge of [it.x + dx, it.x + it.w / 2 + dx, it.x + it.w + dx]) {
        for (const g of t.v) {
          const d = g - edge;
          if (Math.abs(d) < tol && (bestX === null || Math.abs(d) < Math.abs(bestX))) { bestX = d; hitV = [g]; }
        }
      }
      for (const edge of [it.y + dy, it.y + it.h / 2 + dy, it.y + it.h + dy]) {
        for (const g of t.h) {
          const d = g - edge;
          if (Math.abs(d) < tol && (bestY === null || Math.abs(d) < Math.abs(bestY))) { bestY = d; hitH = [g]; }
        }
      }
    }
    setGuides({ v: hitV, h: hitH });
    return { dx: dx + (bestX ?? 0), dy: dy + (bestY ?? 0) };
  };

  // --- pointer --------------------------------------------------------------
  const onPointerDown = (e: React.PointerEvent) => {
    const rect = ref.current!.getBoundingClientRect();
    const sx = e.clientX - rect.left, sy = e.clientY - rect.top;
    const m = toModel(sx, sy);
    (e.target as Element).setPointerCapture(e.pointerId);

    if (e.button === 1 || e.altKey) {
      setDrag({ mode: 'pan', sx, sy, ox: view.px, oy: view.py });
      return;
    }

    if (tool !== 'select') {
      const mk = tool === 'rect' ? makeRect(m.x, m.y, 1, 1, BLACK)
        : tool === 'ellipse' ? makeEllipse(m.x, m.y, 1, 1, BLACK)
          : makeText(m.x, m.y, 1, 1);
      store.commit('add', (d) => { d.pages[store.pageIndex].items.push(mk); });
      store.setSelection([mk.id]);
      setDrag({ mode: 'create', sx: m.x, sy: m.y, id: mk.id });
      return;
    }

    const h = hitHandle(m.x, m.y);
    if (h) {
      const it = store.selected[0];
      if (h.handle === -1) {
        const { cx, cy } = centreOf(it);
        setDrag({ mode: 'rotate', id: it.id, cx, cy, start: Math.atan2(m.y - cy, m.x - cx), base: it.rot });
      } else {
        setDrag({ mode: 'resize', handle: h.handle, sx: m.x, sy: m.y, id: it.id, box: { x: it.x, y: it.y, w: it.w, h: it.h, rot: it.rot } });
      }
      return;
    }

    const hit = hitItem(m.x, m.y);
    if (!hit) { store.setSelection([]); setDrag({ mode: 'none' }); return; }
    const sel = e.shiftKey
      ? (store.selection.includes(hit.id) ? store.selection.filter((i) => i !== hit.id) : [...store.selection, hit.id])
      : (store.selection.includes(hit.id) ? store.selection : [hit.id]);
    store.setSelection(sel);
    const start = new Map<string, { x: number; y: number }>();
    for (const it of store.page.items) if (sel.includes(it.id)) start.set(it.id, { x: it.x, y: it.y });
    setDrag({ mode: 'move', sx: m.x, sy: m.y, start });
  };

  const onPointerMove = (e: React.PointerEvent) => {
    if (drag.mode === 'none') return;
    const rect = ref.current!.getBoundingClientRect();
    const sx = e.clientX - rect.left, sy = e.clientY - rect.top;
    const m = toModel(sx, sy);

    if (drag.mode === 'pan') {
      setView((v) => ({ ...v, px: drag.ox + (sx - drag.sx), py: drag.oy + (sy - drag.sy) }));
      return;
    }
    if (drag.mode === 'create') {
      const x = Math.min(drag.sx, m.x), y = Math.min(drag.sy, m.y);
      const w = Math.abs(m.x - drag.sx), h = Math.abs(m.y - drag.sy);
      store.commitLive('create', (d) => {
        const it = d.pages[store.pageIndex].items.find((i) => i.id === drag.id);
        if (it) { it.x = x; it.y = y; it.w = Math.max(1, w); it.h = Math.max(1, h); }
      });
      return;
    }
    if (drag.mode === 'move') {
      const items = store.page.items.filter((i) => drag.start.has(i.id));
      const raw = { dx: m.x - drag.sx, dy: m.y - drag.sy };
      const base = items.map((i) => ({ ...i, x: drag.start.get(i.id)!.x, y: drag.start.get(i.id)!.y }));
      const snapped = e.shiftKey ? raw : applySnap(raw.dx, raw.dy, base as Item[]);
      store.commitLive('move', (d) => {
        for (const it of d.pages[store.pageIndex].items) {
          const s = drag.start.get(it.id);
          if (s) { it.x = s.x + snapped.dx; it.y = s.y + snapped.dy; }
        }
      });
      return;
    }
    if (drag.mode === 'rotate') {
      const a = Math.atan2(m.y - drag.cy, m.x - drag.cx);
      let deg = drag.base + ((a - drag.start) * 180) / Math.PI;
      if (e.shiftKey) deg = Math.round(deg / 15) * 15;
      store.commitLive('rotate', (d) => {
        const it = d.pages[store.pageIndex].items.find((i) => i.id === drag.id);
        if (it) it.rot = Math.round(deg * 10) / 10;
      });
      return;
    }
    if (drag.mode === 'resize') {
      const b = drag.box;
      const cx = b.x + b.w / 2, cy = b.y + b.h / 2;
      // Work in the item's local (unrotated) frame.
      const loc = rotatePt(m.x, m.y, cx, cy, -b.rot);
      const st = rotatePt(drag.sx, drag.sy, cx, cy, -b.rot);
      const dx = loc.x - st.x, dy = loc.y - st.y;
      const [hx, hy] = HANDLES[drag.handle];
      let nx = b.x, ny = b.y, nw = b.w, nh = b.h;
      if (hx === 0) { nx = b.x + dx; nw = b.w - dx; }
      if (hx === 1) { nw = b.w + dx; }
      if (hy === 0) { ny = b.y + dy; nh = b.h - dy; }
      if (hy === 1) { nh = b.h + dy; }
      if (e.shiftKey && hx !== 0.5 && hy !== 0.5) {
        const ar = b.w / b.h;
        if (Math.abs(nw / ar) > Math.abs(nh)) nh = nw / ar; else nw = nh * ar;
        if (hx === 0) nx = b.x + b.w - nw;
        if (hy === 0) ny = b.y + b.h - nh;
      }
      nw = Math.max(2, nw); nh = Math.max(2, nh);
      // Keep the anchor corner fixed in world space after the local resize.
      const ncx = nx + nw / 2, ncy = ny + nh / 2;
      const anchorLocal = { x: b.x + b.w * (1 - hx), y: b.y + b.h * (1 - hy) };
      const anchorWorldBefore = rotatePt(anchorLocal.x, anchorLocal.y, cx, cy, b.rot);
      const anchorLocalAfter = { x: nx + nw * (1 - hx), y: ny + nh * (1 - hy) };
      const anchorWorldAfter = rotatePt(anchorLocalAfter.x, anchorLocalAfter.y, ncx, ncy, b.rot);
      const ox = anchorWorldBefore.x - anchorWorldAfter.x;
      const oy = anchorWorldBefore.y - anchorWorldAfter.y;
      store.commitLive('resize', (d) => {
        const it = d.pages[store.pageIndex].items.find((i) => i.id === drag.id);
        if (it) { it.x = nx + ox; it.y = ny + oy; it.w = nw; it.h = nh; }
      });
    }
  };

  const onPointerUp = () => {
    if (drag.mode === 'create') {
      const it = store.page.items.find((i) => i.id === (drag as { id: string }).id);
      if (it && (it.w < 4 || it.h < 4)) {
        // A click, not a drag — give it a sensible default size.
        store.commit('size', (d) => {
          const t = d.pages[store.pageIndex].items.find((i) => i.id === it.id);
          if (t) { t.w = t.kind === 'text' ? 216 : 144; t.h = t.kind === 'text' ? 48 : 108; }
        });
      }
      setTool('select');
    }
    store.endLive();
    setGuides({ v: [], h: [] });
    setDrag({ mode: 'none' });
  };

  const onWheel = (e: React.WheelEvent) => {
    const rect = ref.current!.getBoundingClientRect();
    const sx = e.clientX - rect.left, sy = e.clientY - rect.top;
    const m = toModel(sx, sy);
    const f = Math.exp(-e.deltaY * 0.0016);
    const z = Math.min(24, Math.max(0.05, view.zoom * f));
    setView({ zoom: z, px: sx - m.x * z, py: sy - m.y * z });
  };

  return (
    <div ref={wrapRef} className="canvasWrap">
      <canvas
        ref={ref}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onWheel={onWheel}
        style={{ cursor: tool === 'select' ? 'default' : 'crosshair', touchAction: 'none' }}
      />
      <div className="zoomChip">
        <button onClick={() => setView((v) => ({ ...v, zoom: Math.max(0.05, v.zoom / 1.25) }))}>−</button>
        <span>{Math.round(view.zoom * 100)}%</span>
        <button onClick={() => setView((v) => ({ ...v, zoom: Math.min(24, v.zoom * 1.25) }))}>+</button>
        <button onClick={fitView}>Fit</button>
      </div>
      <div className="hintChip">
        {toInch(MW).toFixed(3)}″ × {toInch(MH).toFixed(3)}″ media &nbsp;·&nbsp; alt-drag to pan &nbsp;·&nbsp; shift disables snap
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------

function drawItem(ctx: CanvasRenderingContext2D, it: Item, onImgLoad: () => void) {
  if (it.kind === 'rect') {
    if (it.fill) { ctx.fillStyle = toCss(it.fill); ctx.fillRect(it.x, it.y, it.w, it.h); }
    if (it.strokeW > 0 && it.stroke) {
      ctx.strokeStyle = toCss(it.stroke); ctx.lineWidth = it.strokeW;
      ctx.strokeRect(it.x, it.y, it.w, it.h);
    }
  } else if (it.kind === 'ellipse') {
    ctx.beginPath();
    ctx.ellipse(it.x + it.w / 2, it.y + it.h / 2, it.w / 2, it.h / 2, 0, 0, Math.PI * 2);
    if (it.fill) { ctx.fillStyle = toCss(it.fill); ctx.fill(); }
    if (it.strokeW > 0 && it.stroke) {
      ctx.strokeStyle = toCss(it.stroke); ctx.lineWidth = it.strokeW; ctx.stroke();
    }
  } else if (it.kind === 'image') {
    const im = getImg(it.src, onImgLoad);
    const f = imageFit(it);
    if (im) {
      ctx.save();
      ctx.beginPath(); ctx.rect(it.x, it.y, it.w, it.h);
      if (it.fit === 'fill') ctx.clip(); // preview clips; NOTE: the PDF export does not (v1 gap)
      ctx.drawImage(im, f.dx, f.dy, f.dw, f.dh);
      ctx.restore();
    } else {
      ctx.fillStyle = 'rgba(0,0,0,.07)'; ctx.fillRect(it.x, it.y, it.w, it.h);
    }
  } else if (it.kind === 'text') {
    ctx.fillStyle = toCss(it.color);
    ctx.textBaseline = 'alphabetic';
    ctx.font = `${it.size}px "${it.fontId}", sans-serif`;
    const leading = it.leading || it.size * 1.2;
    const lines = wrapCanvas(ctx, it.text, it.w, it.tracking);
    lines.forEach((line, i) => {
      const w = measure(ctx, line, it.tracking);
      const dx = it.align === 'center' ? (it.w - w) / 2 : it.align === 'right' ? it.w - w : 0;
      const y = it.y + it.size * 0.8 + i * leading;
      if (!it.tracking) ctx.fillText(line, it.x + dx, y);
      else {
        let pen = it.x + dx;
        for (const ch of line) { ctx.fillText(ch, pen, y); pen += ctx.measureText(ch).width + it.tracking; }
      }
    });
  }
}

const measure = (ctx: CanvasRenderingContext2D, s: string, tracking: number) =>
  ctx.measureText(s).width + (tracking ? tracking * Math.max(0, s.length - 1) : 0);

function wrapCanvas(ctx: CanvasRenderingContext2D, text: string, maxW: number, tracking: number): string[] {
  const out: string[] = [];
  for (const para of text.split(/\r?\n/)) {
    if (!para) { out.push(''); continue; }
    let line = '';
    for (const w of para.split(/\s+/).filter(Boolean)) {
      const t = line ? `${line} ${w}` : w;
      if (measure(ctx, t, tracking) <= maxW || !line) line = t;
      else { out.push(line); line = w; }
    }
    if (line) out.push(line);
  }
  return out;
}

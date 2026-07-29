import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Canvas } from '../render/Canvas.tsx';
import type { Tool } from '../render/Canvas.tsx';
import { useStore } from '../state/store.ts';
import { newDoc, makeImage, emptyPage } from '../model/doc.ts';
import type { Item, TextItem, ImageItem } from '../model/doc.ts';
import { PRODUCTS, productByKey, pageCountIssue } from '../model/products.ts';
import { inch, toInch } from '../model/units.ts';
import { cmyk, toCss } from '../model/color.ts';
import type { Color } from '../model/color.ts';
import { exportPdf } from '../export/pdf.ts';
import { preflight } from '../export/preflight.ts';
import type { PreflightReport } from '../export/preflight.ts';

const FONTS = ['LiberationSans-Regular', 'LiberationSans-Bold', 'LiberationSerif-Regular'];
const fontUrl = (id: string) => `${import.meta.env.BASE_URL}fonts/${id}.ttf`;

export default function App() {
  const [productKey, setProductKey] = useState('brochure-trifold-8.5x11');
  const [seed, setSeed] = useState(0);
  const initial = useMemo(() => newDoc(productByKey(productKey)), [productKey, seed]);
  const store = useStore(initial);
  const [tool, setTool] = useState<Tool>('select');
  const [report, setReport] = useState<PreflightReport | null>(null);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef<HTMLInputElement | null>(null);

  // Load the bundled faces so the canvas preview uses the same outlines the
  // exporter will embed. (Metrics still differ slightly — see DESIGNER_SPIKE.md.)
  useEffect(() => {
    for (const id of FONTS) {
      const ff = new FontFace(id, `url(${fontUrl(id)})`);
      ff.load().then((f) => document.fonts.add(f)).catch(() => { /* preview falls back */ });
    }
  }, []);

  const sel = store.selected[0] as Item | undefined;

  const patch = useCallback((fn: (it: Item) => void, label = 'edit') => {
    if (!store.selection.length) return;
    store.commit(label, (d) => {
      for (const it of d.pages[store.pageIndex].items) {
        if (store.selection.includes(it.id)) fn(it);
      }
    });
  }, [store]);

  // keyboard
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const t = e.target as HTMLElement;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT')) return;
      const meta = e.metaKey || e.ctrlKey;
      if (meta && e.key.toLowerCase() === 'z') {
        e.preventDefault(); e.shiftKey ? store.redo() : store.undo(); return;
      }
      if (e.key === 'Delete' || e.key === 'Backspace') {
        if (!store.selection.length) return;
        e.preventDefault();
        store.commit('delete', (d) => {
          d.pages[store.pageIndex].items = d.pages[store.pageIndex].items
            .filter((i) => !store.selection.includes(i.id));
        });
        store.setSelection([]);
        return;
      }
      if (!store.selection.length) return;
      const step = e.shiftKey ? 9 : 1;
      const nudge: Record<string, [number, number]> = {
        ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step],
      };
      if (nudge[e.key]) {
        e.preventDefault();
        const [dx, dy] = nudge[e.key];
        patch((it) => { it.x += dx; it.y += dy; }, 'nudge');
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [store, patch]);

  const placeImage = (file: File) => {
    const fr = new FileReader();
    fr.onerror = () => alert('Could not read that file.');
    fr.onload = () => {
      const src = String(fr.result);
      const im = new Image();
      im.onerror = () => alert('That image could not be decoded. v1 handles PNG and JPEG.');
      im.onload = () => {
        const p = store.doc.product;
        const maxW = p.trimW * 0.5;
        const s = Math.min(1, maxW / im.naturalWidth);
        const w = im.naturalWidth * s, h = im.naturalHeight * s;
        const it = makeImage(p.bleed + inch(0.25), p.bleed + inch(0.25), w, h, src, im.naturalWidth, im.naturalHeight);
        it.name = file.name;
        store.commit('place image', (d) => { d.pages[store.pageIndex].items.push(it); });
        store.setSelection([it.id]);
      };
      im.src = src;
    };
    fr.readAsDataURL(file);
  };

  const doExport = async () => {
    setBusy(true); setReport(null);
    try {
      const { bytes, warnings } = await exportPdf(store.doc, {
        loadFont: async (id) => {
          const r = await fetch(fontUrl(id));
          if (!r.ok) throw new Error(`font ${id} failed to load (${r.status})`);
          return new Uint8Array(await r.arrayBuffer());
        },
        cropMarks: true,
        outputIntent: { identifier: 'CGATS TR 001', info: 'U.S. Web Coated (SWOP) v2' },
        title: store.doc.product.label,
      });
      const rep = await preflight(bytes, store.doc);
      rep.warnings.push(...warnings);
      setReport(rep);
      const ab = bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) as ArrayBuffer;
      const blob = new Blob([ab], { type: 'application/pdf' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `${store.doc.product.key}.pdf`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 4000);
    } catch (e) {
      setReport({ ok: false, errors: [(e as Error).message], warnings: [], facts: {} });
    } finally {
      setBusy(false);
    }
  };

  const p = store.doc.product;
  const countIssue = pageCountIssue(p, store.doc.pages.length);

  return (
    <div className="app">
      <header>
        <strong>PPS Designer</strong>
        <span className="tag">spike</span>
        <select value={productKey} onChange={(e) => { setProductKey(e.target.value); setSeed((n) => n + 1); }}>
          {Object.entries(groupBy(PRODUCTS, (x) => x.group)).map(([g, list]) => (
            <optgroup key={g} label={g}>
              {list.map((pr) => <option key={pr.key} value={pr.key}>{pr.label}</option>)}
            </optgroup>
          ))}
        </select>
        <div className="tools">
          {(['select', 'rect', 'ellipse', 'text'] as Tool[]).map((t) => (
            <button key={t} className={tool === t ? 'on' : ''} onClick={() => setTool(t)}>{t}</button>
          ))}
          <button onClick={() => fileRef.current?.click()}>image…</button>
          <input ref={fileRef} type="file" accept="image/png,image/jpeg" hidden
            onChange={(e) => { const f = e.target.files?.[0]; if (f) placeImage(f); e.target.value = ''; }} />
        </div>
        <div className="spacer" />
        <button onClick={store.undo} disabled={!store.canUndo}>undo</button>
        <button onClick={store.redo} disabled={!store.canRedo}>redo</button>
        <button className="primary" onClick={doExport} disabled={busy}>
          {busy ? 'exporting…' : 'Export press PDF'}
        </button>
      </header>

      <main>
        <Canvas store={store} tool={tool} setTool={setTool} />

        <aside>
          <section>
            <h3>Pages</h3>
            <div className="pages">
              {store.doc.pages.map((pg, i) => (
                <button key={pg.id} className={i === store.pageIndex ? 'on' : ''}
                  onClick={() => store.setPageIndex(i)}>{i + 1}</button>
              ))}
              <button onClick={() => store.commit('add page', (d) => { d.pages.push(emptyPage()); })}>+</button>
            </div>
            {countIssue && <p className="warn">{countIssue}</p>}
            {p.notes && <p className="note">{p.notes}</p>}
          </section>

          <section>
            <h3>Layers</h3>
            <ul className="layers">
              {[...(store.page?.items ?? [])].reverse().map((it) => (
                <li key={it.id}
                  className={store.selection.includes(it.id) ? 'on' : ''}
                  onClick={() => store.setSelection([it.id])}>
                  <span className="kind">{it.kind}</span>
                  <span className="nm">{it.name}</span>
                  <button title="visibility" onClick={(e) => {
                    e.stopPropagation();
                    store.commit('visible', (d) => {
                      const t = d.pages[store.pageIndex].items.find((x) => x.id === it.id);
                      if (t) t.visible = !t.visible;
                    });
                  }}>{it.visible ? '◉' : '○'}</button>
                </li>
              ))}
              {!store.page?.items.length && <li className="empty">nothing on this page yet</li>}
            </ul>
          </section>

          {sel && (
            <section>
              <h3>{sel.name}</h3>
              <div className="grid4">
                <Num label="X" v={sel.x} onChange={(n) => patch((it) => { it.x = n; })} />
                <Num label="Y" v={sel.y} onChange={(n) => patch((it) => { it.y = n; })} />
                <Num label="W" v={sel.w} onChange={(n) => patch((it) => { it.w = Math.max(2, n); })} />
                <Num label="H" v={sel.h} onChange={(n) => patch((it) => { it.h = Math.max(2, n); })} />
              </div>
              <label className="row">
                <span>Rotation</span>
                <input type="number" step="0.5" value={sel.rot}
                  onChange={(e) => patch((it) => { it.rot = Number(e.target.value) || 0; })} />
              </label>

              {(sel.kind === 'rect' || sel.kind === 'ellipse') && (
                <ColorPick label="Fill" value={sel.fill} onChange={(c) => patch((it) => {
                  if (it.kind === 'rect' || it.kind === 'ellipse') it.fill = c;
                })} />
              )}

              {sel.kind === 'text' && (
                <>
                  <label className="row"><span>Text</span></label>
                  <textarea rows={3} value={(sel as TextItem).text}
                    onChange={(e) => patch((it) => { if (it.kind === 'text') it.text = e.target.value; })} />
                  <label className="row">
                    <span>Font</span>
                    <select value={(sel as TextItem).fontId}
                      onChange={(e) => patch((it) => { if (it.kind === 'text') it.fontId = e.target.value; })}>
                      {FONTS.map((f) => <option key={f} value={f}>{f.replace('Liberation', '')}</option>)}
                    </select>
                  </label>
                  <div className="grid4">
                    <PtNum label="Size" v={(sel as TextItem).size}
                      onChange={(n) => patch((it) => { if (it.kind === 'text') it.size = n; })} />
                    <PtNum label="Lead" v={(sel as TextItem).leading}
                      onChange={(n) => patch((it) => { if (it.kind === 'text') it.leading = n; })} />
                    <PtNum label="Track" v={(sel as TextItem).tracking} step={0.1}
                      onChange={(n) => patch((it) => { if (it.kind === 'text') it.tracking = n; })} />
                    <label className="f">
                      <span>Align</span>
                      <select value={(sel as TextItem).align}
                        onChange={(e) => patch((it) => {
                          if (it.kind === 'text') it.align = e.target.value as TextItem['align'];
                        })}>
                        <option>left</option><option>center</option><option>right</option>
                      </select>
                    </label>
                  </div>
                  <ColorPick label="Colour" value={(sel as TextItem).color} onChange={(c) => patch((it) => {
                    if (it.kind === 'text') it.color = c;
                  })} />
                </>
              )}

              {sel.kind === 'image' && (
                <label className="row">
                  <span>Fit</span>
                  <select value={(sel as ImageItem).fit}
                    onChange={(e) => patch((it) => {
                      if (it.kind === 'image') it.fit = e.target.value as ImageItem['fit'];
                    })}>
                    <option value="fill">Fill (crop)</option>
                    <option value="fit">Fit (letterbox)</option>
                    <option value="stretch">Stretch</option>
                  </select>
                </label>
              )}

              <div className="rowBtns">
                <button onClick={() => store.commit('front', (d) => {
                  const arr = d.pages[store.pageIndex].items;
                  const i = arr.findIndex((x) => x.id === sel.id);
                  if (i >= 0) arr.push(arr.splice(i, 1)[0]);
                })}>bring front</button>
                <button onClick={() => store.commit('back', (d) => {
                  const arr = d.pages[store.pageIndex].items;
                  const i = arr.findIndex((x) => x.id === sel.id);
                  if (i >= 0) arr.unshift(arr.splice(i, 1)[0]);
                })}>send back</button>
              </div>
            </section>
          )}

          {report && (
            <section className={report.ok ? 'rep ok' : 'rep bad'}>
              <h3>Preflight {report.ok ? '· passed' : '· FAILED'}</h3>
              <dl>
                {Object.entries(report.facts).map(([k, v]) => (
                  <div key={k}><dt>{k}</dt><dd>{v}</dd></div>
                ))}
              </dl>
              {report.errors.map((e, i) => <p key={i} className="err">✗ {e}</p>)}
              {report.warnings.map((w, i) => <p key={i} className="warn">· {w}</p>)}
            </section>
          )}
        </aside>
      </main>
    </div>
  );
}

// --- small inputs -----------------------------------------------------------

function Num({ label, v, onChange }: { label: string; v: number; onChange: (n: number) => void }) {
  const [txt, setTxt] = useState('');
  const [editing, setEditing] = useState(false);
  const shown = editing ? txt : String(Math.round(toInch(v) * 1000) / 1000);
  return (
    <label className="f">
      <span>{label} <em>in</em></span>
      <input value={shown} inputMode="decimal"
        onFocus={() => { setEditing(true); setTxt(String(Math.round(toInch(v) * 1000) / 1000)); }}
        onBlur={() => { setEditing(false); const n = parseFloat(txt); if (isFinite(n)) onChange(inch(n)); }}
        onChange={(e) => setTxt(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); }} />
    </label>
  );
}

function PtNum({ label, v, step = 1, onChange }: { label: string; v: number; step?: number; onChange: (n: number) => void }) {
  return (
    <label className="f">
      <span>{label} <em>pt</em></span>
      <input type="number" step={step} value={v}
        onChange={(e) => { const n = Number(e.target.value); if (isFinite(n)) onChange(n); }} />
    </label>
  );
}

function ColorPick({ label, value, onChange }: { label: string; value?: Color; onChange: (c: Color) => void }) {
  const c = value && value.space === 'cmyk' ? value : cmyk(0, 0, 0, 1);
  const set = (k: 'c' | 'm' | 'y' | 'k', n: number) => onChange({ ...c, [k]: Math.max(0, Math.min(1, n / 100)) });
  return (
    <div className="colour">
      <div className="row">
        <span>{label}</span>
        <i className="sw" style={{ background: toCss(c) }} />
      </div>
      {(['c', 'm', 'y', 'k'] as const).map((k) => (
        <label key={k} className="slider">
          <b>{k.toUpperCase()}</b>
          <input type="range" min={0} max={100} value={Math.round(c[k] * 100)}
            onChange={(e) => set(k, Number(e.target.value))} />
          <em>{Math.round(c[k] * 100)}</em>
        </label>
      ))}
    </div>
  );
}

function groupBy<T>(arr: T[], key: (t: T) => string): Record<string, T[]> {
  const out: Record<string, T[]> = {};
  for (const t of arr) (out[key(t)] ||= []).push(t);
  return out;
}

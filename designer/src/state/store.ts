// Undo/redo by whole-document snapshot. The doc is small and JSON-clonable,
// so this is fine at v1 scale and impossible to get subtly wrong. If documents
// ever get big enough that this stutters, swap to a patch log — but measure
// first, don't pre-optimise.

import { useCallback, useRef, useState } from 'react';
import type { Doc, Item, Page } from '../model/doc.ts';

const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v)) as T;

const LIMIT = 120;

export type Store = {
  doc: Doc;
  selection: string[];
  pageIndex: number;
  setPageIndex: (i: number) => void;
  setSelection: (ids: string[]) => void;
  /** Mutate a draft of the doc and push one undo entry. */
  commit: (label: string, fn: (draft: Doc) => void) => void;
  /** Same, but coalesces with the previous entry if the label matches —
   *  used for drags so a 200-event drag is one undo step. */
  commitLive: (label: string, fn: (draft: Doc) => void) => void;
  endLive: () => void;
  undo: () => void;
  redo: () => void;
  canUndo: boolean;
  canRedo: boolean;
  page: Page;
  selected: Item[];
};

export function useStore(initial: Doc): Store {
  const [doc, setDoc] = useState<Doc>(initial);
  const [selection, setSelection] = useState<string[]>([]);
  const [pageIndex, setPageIndexRaw] = useState(0);
  const past = useRef<Doc[]>([]);
  const future = useRef<Doc[]>([]);
  const liveLabel = useRef<string | null>(null);
  const [, bump] = useState(0);

  const push = useCallback((snapshot: Doc) => {
    past.current.push(snapshot);
    if (past.current.length > LIMIT) past.current.shift();
    future.current = [];
  }, []);

  const commit = useCallback((_label: string, fn: (d: Doc) => void) => {
    setDoc((cur) => {
      push(clone(cur));
      const draft = clone(cur);
      fn(draft);
      return draft;
    });
    liveLabel.current = null;
    bump((n) => n + 1);
  }, [push]);

  const commitLive = useCallback((label: string, fn: (d: Doc) => void) => {
    setDoc((cur) => {
      if (liveLabel.current !== label) {
        push(clone(cur));
        liveLabel.current = label;
      }
      const draft = clone(cur);
      fn(draft);
      return draft;
    });
  }, [push]);

  const endLive = useCallback(() => { liveLabel.current = null; }, []);

  const undo = useCallback(() => {
    setDoc((cur) => {
      const prev = past.current.pop();
      if (!prev) return cur;
      future.current.push(clone(cur));
      return prev;
    });
    liveLabel.current = null;
    bump((n) => n + 1);
  }, []);

  const redo = useCallback(() => {
    setDoc((cur) => {
      const nxt = future.current.pop();
      if (!nxt) return cur;
      past.current.push(clone(cur));
      return nxt;
    });
    liveLabel.current = null;
    bump((n) => n + 1);
  }, []);

  const setPageIndex = useCallback((i: number) => {
    setPageIndexRaw(i);
    setSelection([]);
  }, []);

  const idx = Math.min(pageIndex, doc.pages.length - 1);
  const page = doc.pages[idx];
  const selected = page ? page.items.filter((it) => selection.includes(it.id)) : [];

  return {
    doc, selection, pageIndex: idx, setPageIndex, setSelection,
    commit, commitLive, endLive, undo, redo,
    canUndo: past.current.length > 0, canRedo: future.current.length > 0,
    page, selected,
  };
}

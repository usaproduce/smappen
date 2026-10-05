import { useEffect, useRef } from 'react';
import { fmtCount } from '../../../utils/truck/format';
import { PERF_WINDOW } from './HexLayer';
import type { LayerStats, Timing } from './HexLayer';

export interface PerfHudProps {
  /** The layer's own measurements, or null while there is no layer. */
  getStats: () => LayerStats | null;
}

/** What the overlay shows and what the P key prints: one row per figure of 5.9. */
export interface PerfRow {
  key: string;
  label: string;
  value: string;
}

function ms(x: number | null, digits: number): string {
  return x === null ? '-' : x.toFixed(digits) + ' ms';
}

function pair(t: Timing, digits: number): string {
  if (t.samples === 0) return '-';
  return ms(t.last, digits) + ' / ' + ms(t.p95, digits);
}

function lastMeasure(name: string): number | null {
  try {
    const entries = performance.getEntriesByName(name, 'measure');
    return entries.length > 0 ? entries[entries.length - 1].duration : null;
  } catch {
    return null;
  }
}

/**
 * The measurement overlay behind `?tp_perf=1` (docs/truck-planner/05_FRONTEND.md 5.9): renderer kind,
 * cells and cells in view, the last and 95th-percentile tick and frame times over the last 120
 * frames, decode and mesh times. The P key prints the same rows with `console.table`.
 *
 * It measures frames itself, from one animation frame to the next, and repaints four times a second
 * by writing text into its own rows: no React render while it runs.
 */
export default function PerfHud({ getStats }: PerfHudProps) {
  const box = useRef<HTMLDivElement | null>(null);
  const stats = useRef(getStats);
  stats.current = getStats;

  useEffect(() => {
    const root = box.current;
    if (root === null) return undefined;

    const deltas = new Float64Array(PERF_WINDOW);
    let count = 0;
    let next = 0;
    let previous = -1;
    let paintedAt = -1000;
    let frame = 0;
    const cells = new Map<string, HTMLElement>();

    const frameTiming = (): Timing => {
      if (count === 0) return { last: null, p95: null, samples: 0 };
      const sorted = Array.from(deltas.subarray(0, count)).sort((a, b) => a - b);
      const at = Math.max(0, Math.min(sorted.length - 1, Math.ceil(0.95 * sorted.length) - 1));
      return { last: deltas[(next + PERF_WINDOW - 1) % PERF_WINDOW], p95: sorted[at], samples: count };
    };

    const rows = (): { rows: PerfRow[]; data: Record<string, unknown> } => {
      const s = stats.current();
      const frames = frameTiming();
      const decode = lastMeasure('tp:pack-decode');
      const list: PerfRow[] = [
        { key: 'renderer', label: 'Renderer', value: s === null ? '-' : s.renderer },
        { key: 'status', label: 'Status', value: s === null ? '-' : s.status },
        { key: 'cells', label: 'Cells', value: s === null ? '-' : fmtCount(s.cells) },
        { key: 'inView', label: 'Cells in view', value: s === null ? '-' : fmtCount(s.cellsInView) },
        { key: 'tick', label: 'Tick, last / p95', value: s === null ? '-' : pair(s.tick, 2) },
        { key: 'tickToFrame', label: 'Tick to frame, last / p95', value: s === null ? '-' : pair(s.tickToFrame, 1) },
        { key: 'frame', label: 'Frame, last / p95', value: pair(frames, 1) },
        { key: 'draw', label: 'Camera frame script, last / p95', value: s === null ? '-' : pair(s.draw, 2) },
        { key: 'pick', label: 'Hover pick, last / p95', value: s === null ? '-' : pair(s.pick, 3) },
        { key: 'decode', label: 'Pack decode', value: ms(decode, 1) },
        { key: 'mesh', label: 'Mesh build, work / start to finish', value: s === null || s.meshMs === null ? '-' : ms(s.meshWorkMs, 1) + ' / ' + ms(s.meshMs, 1) },
        { key: 'slice', label: 'Mesh build, longest slice', value: s === null ? '-' : ms(s.meshSliceMs, 1) },
        { key: 'firstFrame', label: 'First coloured frame', value: s === null ? '-' : ms(s.firstFrameMs, 0) },
        { key: 'kept', label: 'Hours scored ahead', value: s === null ? '-' : String(s.framesKept) },
      ];
      return { rows: list, data: { ...(s ?? {}), frame: frames, decodeMs: decode } };
    };

    const paint = (): void => {
      const now = rows();
      for (const row of now.rows) {
        let cell = cells.get(row.key);
        if (cell === undefined) {
          const line = document.createElement('div');
          line.style.cssText = 'display:flex;justify-content:space-between;gap:16px;white-space:nowrap;';
          const label = document.createElement('span');
          label.textContent = row.label;
          label.style.color = 'var(--body)';
          cell = document.createElement('span');
          cell.style.fontWeight = '700';
          line.appendChild(label);
          line.appendChild(cell);
          root.appendChild(line);
          cells.set(row.key, cell);
        }
        if (cell.textContent !== row.value) cell.textContent = row.value;
      }
      // The same figures as numbers, for a script that measures the layer.
      root.setAttribute('data-tp-perf-json', JSON.stringify(now.data));
    };

    const loop = (t: number): void => {
      if (previous >= 0) {
        deltas[next] = t - previous;
        next = (next + 1) % PERF_WINDOW;
        if (count < PERF_WINDOW) count++;
      }
      previous = t;
      if (t - paintedAt >= 250) {
        paintedAt = t;
        paint();
      }
      frame = requestAnimationFrame(loop);
    };
    frame = requestAnimationFrame(loop);

    const onKey = (e: KeyboardEvent): void => {
      if (e.key !== 'p' && e.key !== 'P') return;
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      const t = e.target as HTMLElement | null;
      if (t !== null && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
      const table: Record<string, string> = {};
      for (const row of rows().rows) table[row.label] = row.value;
      console.table(table);
    };
    window.addEventListener('keydown', onKey);

    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener('keydown', onKey);
      root.textContent = '';
    };
  }, []);

  return (
    <div
      ref={box}
      data-tp-perf=""
      aria-hidden="true"
      className="absolute right-3 bottom-10 z-10 bg-white rounded-xl border shadow-float px-3 py-2 text-[11px] font-semibold tabular-nums leading-5"
      style={{ borderColor: 'var(--line-soft)', color: 'var(--ink)', pointerEvents: 'none', minWidth: 264 }}
    />
  );
}

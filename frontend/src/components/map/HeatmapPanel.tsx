import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { X, Map as MapIcon, Palette as PaletteIcon, Check, ChevronUp, Settings2 } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import type { HeatmapMetric, HeatmapResponse } from '../../api/heatmap';
import { gradientCss, paletteById, valueToFraction, PALETTES } from '../../utils/heatmapColors';

const METRICS: { value: HeatmapMetric; label: string; short: string }[] = [
  { value: 'population_density', label: 'Population density', short: 'Pop. density' },
  { value: 'population',         label: 'Population',         short: 'Population' },
  { value: 'median_income',      label: 'Median household income', short: 'Income' },
  { value: 'median_home_value',  label: 'Median home value',   short: 'Home value' },
  { value: 'unemployment_rate',  label: 'Unemployment rate',   short: 'Unemployment' },
  { value: 'housing_units',      label: 'Housing units',       short: 'Housing units' },
];

const formatValue = (n: number | null | undefined, metric: HeatmapMetric): string => {
  if (n === null || n === undefined || Number.isNaN(n)) return '—';
  if (metric === 'median_income' || metric === 'median_home_value') return '$' + Math.round(n).toLocaleString();
  if (metric === 'unemployment_rate') return n.toFixed(1) + '%';
  if (Math.abs(n) >= 1000) return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
  return Math.round(n).toLocaleString();
};

interface Props { meta: HeatmapResponse['meta'] | null }

/**
 * Bottom-center heatmap legend. Compact horizontal bar that never collides
 * with the vertical panels (LeftPanel, AreaCreator, RightPanel) — bottom-
 * center is the only zone no other floating UI claims. The old top-right
 * vertical card overlapped whatever was already open on the right.
 *
 * Two visual states:
 *   • Bar    — gradient legend + active metric, always visible while
 *              the heatmap layer is on.
 *   • Tray   — opens upward from the bar when the user clicks Settings,
 *              for metric / boundary level / palette tweaks. Closes on
 *              click-outside or Esc.
 */
export default function HeatmapPanel({ meta }: Props) {
  const {
    heatmapMetric, setHeatmapMetric,
    heatmapLevel, setHeatmapLevel,
    heatmapPaletteId, setHeatmapPaletteId,
    heatmapLoading,
    toggleHeatmap,
    hoveredHeatmapValue, hoveredHeatmapName,
  } = useMapStore();

  const [trayOpen, setTrayOpen] = useState(false);
  const barRef = useRef<HTMLDivElement>(null);

  const activeMetric = METRICS.find((m) => m.value === heatmapMetric) ?? METRICS[0];
  const hasData = meta && (meta.count ?? 0) > 0;
  const breaks = meta?.breaks ?? [];
  const activePalette = paletteById(heatmapPaletteId);

  const markerT = hasData && hoveredHeatmapValue !== null && hoveredHeatmapValue !== undefined
    ? valueToFraction(hoveredHeatmapValue, meta!.min, meta!.max, breaks)
    : null;

  // Esc closes the tray (parity with the area-detail right panel).
  useEffect(() => {
    if (!trayOpen) return;
    function onKey(e: KeyboardEvent) { if (e.key === 'Escape') setTrayOpen(false); }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [trayOpen]);

  return (
    <>
      {/* Always-visible compact bar at bottom-center. left-1/2 + translate
          centers it within the WHOLE viewport (parent is the map area, which
          already excludes the AppNav). The basemap selector sits at bottom
          left-4 ~280px wide and the RightToolbar at right-4 w-12 — between
          them, the center is dead space. */}
      <div
        ref={barRef}
        className="absolute bottom-4 left-1/2 -translate-x-1/2 z-30 bg-white rounded-full shadow-float border border-slate-200 px-2 py-1.5 flex items-center gap-2 max-w-[calc(100vw-32px)]"
        style={{ minWidth: 340 }}
      >
        <span className="inline-flex items-center gap-1 px-1 text-violet-700">
          <MapIcon size={14} />
          {heatmapLoading && (
            <span
              className="inline-block w-3 h-3 border-2 border-slate-300 border-t-violet-600 rounded-full animate-spin"
              title="Loading polygons…"
            />
          )}
        </span>

        {/* Active metric — clickable to open the tray for metric switching. */}
        <button
          type="button"
          onClick={() => setTrayOpen((v) => !v)}
          className="text-xs font-bold tabular-nums text-slate-800 hover:text-violet-700 truncate max-w-[140px]"
          title={activeMetric.label}
        >
          {activeMetric.short}
        </button>

        {/* Gradient strip with hovered-tract marker. The marker still slides
            smoothly so users can read the value as they move the mouse. */}
        <div className="relative flex-1 h-3 rounded-full min-w-[120px]" style={{ background: gradientCss(activePalette) }}>
          <div
            className="absolute -top-0.5 -bottom-0.5 w-0.5 -translate-x-1/2 bg-white pointer-events-none"
            style={{
              left: `${(markerT ?? 0) * 100}%`,
              boxShadow: '0 0 0 1px #1A1A2E',
              opacity: markerT !== null ? 1 : 0,
              transition: 'left 180ms cubic-bezier(0.16, 1, 0.3, 1), opacity 120ms',
            }}
          />
        </div>

        {/* Range labels — switch to the hovered tract's name+value when one
            exists so the operator gets immediate context without expanding. */}
        <div className="text-[11px] font-semibold tabular-nums text-slate-600 whitespace-nowrap min-w-[120px] text-right">
          {markerT !== null && hoveredHeatmapName ? (
            <span title={hoveredHeatmapName}>
              <span className="text-slate-500 truncate inline-block max-w-[80px] align-middle">{hoveredHeatmapName}</span>
              <span className="ml-1 font-bold text-slate-900">{formatValue(hoveredHeatmapValue, heatmapMetric)}</span>
            </span>
          ) : hasData ? (
            <>
              <span>{formatValue(meta!.min, heatmapMetric)}</span>
              <span className="mx-1 text-slate-300">→</span>
              <span>{formatValue(meta!.max, heatmapMetric)}</span>
            </>
          ) : (
            <span className="text-slate-400">No data in view</span>
          )}
        </div>

        <button
          type="button"
          onClick={() => setTrayOpen((v) => !v)}
          className={`p-1.5 rounded-full transition-colors ${trayOpen ? 'bg-violet-100 text-violet-700' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800'}`}
          title="Heatmap settings"
        >
          <Settings2 size={14} />
        </button>
        <button
          type="button"
          onClick={toggleHeatmap}
          className="p-1.5 rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-700"
          title="Turn heatmap off"
        >
          <X size={14} />
        </button>
      </div>

      {trayOpen && <HeatmapTray
        anchor={barRef.current}
        metric={heatmapMetric}
        setMetric={setHeatmapMetric}
        level={heatmapLevel}
        setLevel={setHeatmapLevel}
        paletteId={heatmapPaletteId}
        setPaletteId={setHeatmapPaletteId}
        meta={meta}
        onClose={() => setTrayOpen(false)}
      />}
    </>
  );
}

/**
 * Settings tray — opens upward from the bottom-center bar. Rendered into
 * document.body via portal so it isn't clipped by the map container, and
 * positioned with the anchor's bounding rect so it sits exactly above the
 * gear button. Closes on outside click.
 */
function HeatmapTray({
  anchor, metric, setMetric, level, setLevel, paletteId, setPaletteId, meta, onClose,
}: {
  anchor: HTMLElement | null;
  metric: HeatmapMetric;
  setMetric: (m: HeatmapMetric) => void;
  level: any;
  setLevel: (l: any) => void;
  paletteId: string;
  setPaletteId: (id: string) => void;
  meta: HeatmapResponse['meta'] | null;
  onClose: () => void;
}) {
  const trayRef = useRef<HTMLDivElement>(null);
  const [pos, setPos] = useState<{ left: number; bottom: number; width: number }>({ left: 0, bottom: 0, width: 360 });

  useEffect(() => {
    if (!anchor) return;
    function place() {
      const r = anchor!.getBoundingClientRect();
      const W = 380;
      const centerX = r.left + r.width / 2;
      const left = Math.max(16, Math.min(window.innerWidth - W - 16, centerX - W / 2));
      const bottom = window.innerHeight - r.top + 8;
      setPos({ left, bottom, width: W });
    }
    place();
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    return () => {
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
    };
  }, [anchor]);

  // Click-outside closes — mousedown so it fires before any inner button click.
  useEffect(() => {
    function handler(e: MouseEvent) {
      if (trayRef.current?.contains(e.target as Node)) return;
      if (anchor?.contains(e.target as Node)) return;
      onClose();
    }
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [anchor, onClose]);

  return createPortal(
    <div
      ref={trayRef}
      className="fixed bg-white rounded-xl shadow-float border border-slate-200 p-3 z-40 max-h-[60vh] overflow-y-auto"
      style={{ left: pos.left, bottom: pos.bottom, width: pos.width }}
    >
      <div className="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Metric</div>
      <select
        className="w-full h-9 px-2 text-sm border border-slate-300 rounded-md bg-white mb-3 focus:outline-none focus:border-violet-600"
        value={metric}
        onChange={(e) => setMetric(e.target.value as HeatmapMetric)}
      >
        {METRICS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
      </select>

      <div className="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Boundary level</div>
      <select
        className="w-full h-9 px-2 text-sm border border-slate-300 rounded-md bg-white mb-1 focus:outline-none focus:border-violet-600"
        value={level}
        onChange={(e) => setLevel(e.target.value)}
      >
        <option value="auto">Auto (zoom-based)</option>
        <option value="state">State</option>
        <option value="county">County</option>
        <option value="tract">Census tract</option>
      </select>
      <div className="text-[10px] text-slate-400 mb-3">
        Showing <b className="text-slate-600">{meta?.level ?? '—'}</b>
        {level === 'auto' && ' · auto-switches as you zoom'}
        {meta?.count !== undefined && ` · ${meta.count.toLocaleString()} polygons`}
      </div>

      <div className="flex items-center justify-between mb-1">
        <div className="text-[11px] font-bold uppercase tracking-wider text-slate-500 inline-flex items-center gap-1">
          <PaletteIcon size={11} /> Palette
        </div>
      </div>
      <div className="grid grid-cols-1 gap-1 max-h-[180px] overflow-y-auto border border-slate-200 rounded-md p-1 bg-slate-50">
        {PALETTES.map((p) => (
          <button
            key={p.id}
            className={`flex items-center gap-2 px-2 py-1.5 rounded text-left transition ${
              p.id === paletteId ? 'bg-violet-100 ring-1 ring-violet-300' : 'hover:bg-white'
            }`}
            onClick={() => setPaletteId(p.id)}
            title={p.description}
          >
            <div className="h-2.5 w-14 shrink-0 rounded-full" style={{ background: gradientCss(p) }} />
            <span className="text-xs font-semibold flex-1 truncate" style={{ color: '#1A1A2E' }}>
              {p.name}
            </span>
            {p.id === paletteId && <Check size={11} className="text-violet-700 shrink-0" />}
          </button>
        ))}
      </div>

      {meta?.note && (
        <div className="mt-3 text-xs p-2 rounded bg-amber-50 text-amber-800 border border-amber-200">
          {meta.note}
        </div>
      )}

      <button
        type="button"
        onClick={onClose}
        className="mt-3 w-full text-center text-xs font-semibold text-slate-500 hover:text-slate-800 inline-flex items-center justify-center gap-1"
      >
        <ChevronUp size={12} className="rotate-180" /> Close
      </button>
    </div>,
    document.body
  );
}

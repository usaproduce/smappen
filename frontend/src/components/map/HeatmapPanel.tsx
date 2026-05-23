import { useState } from 'react';
import { X, Map as MapIcon, Palette as PaletteIcon, Check } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import type { HeatmapMetric, HeatmapResponse } from '../../api/heatmap';
import { gradientCss, paletteById, valueToFraction, PALETTES } from '../../utils/heatmapColors';

const METRICS: { value: HeatmapMetric; label: string }[] = [
  { value: 'population_density', label: 'Population density' },
  { value: 'population', label: 'Population' },
  { value: 'median_income', label: 'Median household income' },
  { value: 'median_home_value', label: 'Median home value' },
  { value: 'unemployment_rate', label: 'Unemployment rate' },
  { value: 'housing_units', label: 'Housing units' },
];

const formatValue = (n: number | null | undefined, metric: HeatmapMetric): string => {
  if (n === null || n === undefined || Number.isNaN(n)) return '—';
  if (metric === 'median_income' || metric === 'median_home_value') return '$' + Math.round(n).toLocaleString();
  if (metric === 'unemployment_rate') return n.toFixed(1) + '%';
  if (Math.abs(n) >= 1000) return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
  return Math.round(n).toLocaleString();
};

interface Props { meta: HeatmapResponse['meta'] | null }

export default function HeatmapPanel({ meta }: Props) {
  const {
    heatmapMetric, setHeatmapMetric,
    heatmapLevel, setHeatmapLevel,
    heatmapPaletteId, setHeatmapPaletteId,
    toggleHeatmap,
    hoveredHeatmapValue, hoveredHeatmapName,
  } = useMapStore();

  const [paletteOpen, setPaletteOpen] = useState(false);

  const labelFor = (m: HeatmapMetric) => METRICS.find((x) => x.value === m)?.label ?? m;
  const hasData = meta && (meta.count ?? 0) > 0;
  const breaks = meta?.breaks ?? [];
  const activePalette = paletteById(heatmapPaletteId);

  const markerT = hasData && hoveredHeatmapValue !== null && hoveredHeatmapValue !== undefined
    ? valueToFraction(hoveredHeatmapValue, meta!.min, meta!.max, breaks)
    : null;

  return (
    <div className="absolute bottom-4 left-4 bg-white rounded-xl shadow-float p-4 w-[360px] z-30 border border-slate-200 transition-all duration-200">
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2 font-bold text-base" style={{ color: '#1A1A2E' }}>
          <MapIcon size={18} style={{ color: '#7848BB' }} /> Heatmap
        </div>
        <button onClick={toggleHeatmap} className="text-slate-400 hover:text-slate-700" title="Close heatmap">
          <X size={16} />
        </button>
      </div>

      <div className="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Data to display</div>
      <select
        className="w-full h-10 px-3 text-sm border border-slate-300 rounded-lg bg-white mb-3 focus:outline-none focus:border-violet-600"
        value={heatmapMetric}
        onChange={(e) => setHeatmapMetric(e.target.value as HeatmapMetric)}
      >
        {METRICS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
      </select>

      <div className="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Boundary level</div>
      <div className="flex gap-2 items-stretch mb-1">
        <select
          className="flex-1 h-10 px-3 text-sm border border-slate-300 rounded-lg bg-white focus:outline-none focus:border-violet-600"
          value={heatmapLevel}
          onChange={(e) => setHeatmapLevel(e.target.value as any)}
        >
          <option value="auto">Auto (zoom-based)</option>
          <option value="state">State</option>
          <option value="county">County</option>
          <option value="tract">Census tract</option>
        </select>
        {meta?.cached && (
          <span className="self-center text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-1 rounded">cached</span>
        )}
      </div>
      <div className="text-[10px] text-slate-400 mb-3">
        Showing <b className="text-slate-600">{meta?.level ?? '—'}</b>
        {heatmapLevel === 'auto' && ' · auto-switches as you zoom'}
        {meta?.count !== undefined && ` · ${meta.count.toLocaleString()} polygons`}
      </div>

      {/* Color palette picker */}
      <div className="flex items-center justify-between mb-1">
        <div className="text-[11px] font-bold uppercase tracking-wider text-slate-500">Color palette</div>
        <button
          onClick={() => setPaletteOpen(!paletteOpen)}
          className="text-[11px] font-semibold text-violet-700 hover:bg-violet-50 px-2 py-0.5 rounded inline-flex items-center gap-1"
        >
          <PaletteIcon size={11} /> {paletteOpen ? 'Hide' : 'Browse'}
        </button>
      </div>
      <div
        className="h-9 w-full rounded-lg border border-slate-300 px-2 flex items-center gap-2 cursor-pointer mb-2 hover:border-violet-400 transition"
        onClick={() => setPaletteOpen(!paletteOpen)}
        title="Click to change palette"
      >
        <span className="text-xs font-semibold flex-1 truncate" style={{ color: '#1A1A2E' }}>
          {activePalette.name}
        </span>
        <div className="h-3 w-24 rounded-full" style={{ background: gradientCss(activePalette) }} />
      </div>
      {paletteOpen && (
        <div className="mb-3 max-h-[210px] overflow-y-auto border border-slate-200 rounded-lg p-1.5 bg-slate-50 grid grid-cols-1 gap-1">
          {PALETTES.map((p) => (
            <button
              key={p.id}
              className={`flex items-center gap-2 px-2 py-1.5 rounded-md text-left transition ${
                p.id === heatmapPaletteId
                  ? 'bg-violet-100 ring-1 ring-violet-300'
                  : 'hover:bg-white'
              }`}
              onClick={() => { setHeatmapPaletteId(p.id); setPaletteOpen(false); }}
              title={p.description}
            >
              <div className="h-3 w-16 shrink-0 rounded-full" style={{ background: gradientCss(p) }} />
              <span className="text-xs font-semibold flex-1 truncate" style={{ color: '#1A1A2E' }}>
                {p.name}
              </span>
              {p.id === heatmapPaletteId && <Check size={12} className="text-violet-700 shrink-0" />}
            </button>
          ))}
        </div>
      )}

      <div className="text-xs font-semibold mb-2" style={{ color: '#1A1A2E' }}>
        {labelFor(heatmapMetric)} {meta?.unit ? <span className="text-slate-500 font-normal">({meta.unit})</span> : null}
      </div>

      {/* Continuous gradient bar — matches the polygon coloring exactly */}
      <div className="relative h-3 w-full rounded-full" style={{ background: gradientCss(activePalette) }}>
        {markerT !== null && (
          <>
            <div
              className="absolute -top-3 w-0 h-0 -translate-x-1/2"
              style={{
                left: `${markerT * 100}%`,
                borderLeft: '5px solid transparent',
                borderRight: '5px solid transparent',
                borderTop: '7px solid #1A1A2E',
              }}
            />
            <div
              className="absolute -top-0.5 -bottom-0.5 w-0.5 -translate-x-1/2 bg-white"
              style={{ left: `${markerT * 100}%`, boxShadow: '0 0 0 1px #1A1A2E' }}
            />
          </>
        )}
      </div>

      <div className="flex justify-between text-[11px] text-slate-600 mt-1.5 font-medium">
        <span>{hasData ? formatValue(meta!.min, heatmapMetric) : '—'}</span>
        <span>{hasData ? formatValue(meta!.max, heatmapMetric) : '—'}</span>
      </div>

      {markerT !== null && (
        <div className="mt-2 text-xs flex items-center justify-between bg-slate-50 border border-slate-200 rounded px-2 py-1.5">
          <span className="text-slate-600 truncate" title={hoveredHeatmapName ?? ''}>
            {hoveredHeatmapName ?? 'hovered'}
          </span>
          <span className="font-bold" style={{ color: '#1A1A2E' }}>
            {formatValue(hoveredHeatmapValue, heatmapMetric)}
          </span>
        </div>
      )}

      {hasData && breaks.length >= 9 && markerT === null && (
        <div className="flex justify-between text-[10px] text-slate-500 mt-1.5">
          <span>p25 · {formatValue(breaks[1], heatmapMetric)}</span>
          <span>median · {formatValue(breaks[4], heatmapMetric)}</span>
          <span>p75 · {formatValue(breaks[7], heatmapMetric)}</span>
        </div>
      )}

      {!hasData && (
        <div className="mt-3 text-xs p-2 rounded bg-amber-50 text-amber-800 border border-amber-200">
          {meta?.note ?? 'No data in this view. Pan to a covered area (DC / MD / VA / WV).'}
        </div>
      )}

      {hasData && meta?.truncated && (
        <div className="mt-3 text-xs p-2 rounded bg-amber-50 text-amber-800 border border-amber-200">
          Showing {meta.count.toLocaleString()} polygons (cap reached). Some tracts hidden —
          zoom in or pick a coarser <b>boundary level</b> to see full coverage.
        </div>
      )}
    </div>
  );
}

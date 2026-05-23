import { X, Map as MapIcon } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import type { HeatmapMetric, HeatmapResponse } from '../../api/heatmap';
import { HEATMAP_STOPS } from '../../utils/heatmapColors';

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
  const { heatmapMetric, setHeatmapMetric, toggleHeatmap } = useMapStore();
  const labelFor = (m: HeatmapMetric) => METRICS.find((x) => x.value === m)?.label ?? m;
  const hasData = meta && (meta.count ?? 0) > 0;
  const breaks = meta?.breaks ?? [];

  return (
    <div className="absolute bottom-4 left-4 bg-white rounded-xl shadow-float p-4 w-[320px] z-30 border border-slate-200">
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
      <div className="w-full h-10 px-3 text-sm border border-slate-300 rounded-lg bg-slate-50 mb-3 flex items-center justify-between text-slate-700">
        <span>
          {meta?.level === 'state' ? 'State (zoomed out)'
            : meta?.level === 'county' ? 'County'
            : 'Census tract'}
          <span className="text-[10px] text-slate-400 ml-1.5">auto · zoom-based</span>
        </span>
        {meta?.cached && (
          <span className="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded">cached</span>
        )}
      </div>

      <div className="text-xs font-semibold mb-2" style={{ color: '#1A1A2E' }}>
        {labelFor(heatmapMetric)} {meta?.unit ? <span className="text-slate-500 font-normal">({meta.unit})</span> : null}
      </div>

      {/* 10 discrete decile bins so the gradient stays readable */}
      <div className="flex h-2 w-full rounded-full overflow-hidden">
        {HEATMAP_STOPS.map((c) => (
          <div key={c} className="flex-1" style={{ background: c }} />
        ))}
      </div>

      <div className="flex justify-between text-[11px] text-slate-600 mt-1.5 font-medium">
        <span>{hasData ? formatValue(meta!.min, heatmapMetric) : '—'}</span>
        <span>{hasData ? formatValue(meta!.max, heatmapMetric) : '—'}</span>
      </div>

      {hasData && breaks.length >= 9 && (
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
    </div>
  );
}

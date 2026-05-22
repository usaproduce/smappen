import { useState } from 'react';
import { X, Map as MapIcon, Building } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import type { HeatmapMetric, HeatmapResponse } from '../../api/heatmap';
import { HEATMAP_GRADIENT_CSS } from '../../utils/heatmapColors';

const METRICS: { value: HeatmapMetric; label: string; icon: any }[] = [
  { value: 'population_density', label: 'Population density', icon: Building },
  { value: 'population', label: 'Population', icon: Building },
  { value: 'median_income', label: 'Median household income', icon: Building },
  { value: 'median_home_value', label: 'Median home value', icon: Building },
  { value: 'unemployment_rate', label: 'Unemployment rate', icon: Building },
  { value: 'housing_units', label: 'Housing units', icon: Building },
];

const formatStop = (n: number, metric: HeatmapMetric): string => {
  if (metric === 'median_income' || metric === 'median_home_value') {
    return '$' + Math.round(n).toLocaleString();
  }
  if (metric === 'unemployment_rate') return n.toFixed(1) + '%';
  return Math.round(n).toLocaleString();
};

interface Props {
  meta: HeatmapResponse['meta'] | null;
}

export default function HeatmapPanel({ meta }: Props) {
  const { heatmapMetric, setHeatmapMetric, toggleHeatmap } = useMapStore();
  const [open, setOpen] = useState(true);

  if (!open) return null;
  const labelFor = (m: HeatmapMetric) => METRICS.find((x) => x.value === m)?.label ?? m;

  return (
    <div className="absolute bottom-4 left-4 bg-white rounded-xl shadow-lg p-4 w-[300px] z-30 border border-slate-200">
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2 font-bold text-base" style={{ color: '#1A1A2E' }}>
          <MapIcon size={18} style={{ color: '#7848BB' }} /> Heatmap
        </div>
        <button onClick={() => { setOpen(false); toggleHeatmap(); }} className="text-slate-400 hover:text-slate-700">
          <X size={16} />
        </button>
      </div>

      <div className="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Data to display</div>
      <select
        className="w-full h-10 px-3 text-sm border border-slate-300 rounded-lg bg-white mb-3 focus:border-violet-600 focus:outline-none"
        style={{ borderColor: '#D1D1DB' }}
        value={heatmapMetric}
        onChange={(e) => setHeatmapMetric(e.target.value as HeatmapMetric)}
      >
        {METRICS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
      </select>

      <div className="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Boundary level</div>
      <div className="w-full h-10 px-3 text-sm border border-slate-300 rounded-lg bg-slate-50 mb-3 flex items-center">
        Census tract
      </div>

      <div className="text-xs font-semibold mb-1" style={{ color: '#D32F2F' }}>
        {labelFor(heatmapMetric)} {meta?.unit ? `(${meta.unit})` : ''}
      </div>
      <div className="h-2 w-full rounded-full" style={{ background: HEATMAP_GRADIENT_CSS }} />
      <div className="flex justify-between text-[11px] text-slate-500 mt-1">
        <span>{meta ? formatStop(meta.min, heatmapMetric) : '—'}</span>
        <span>{meta ? formatStop(meta.max, heatmapMetric) : '—'}</span>
      </div>

      {meta?.note && (
        <div className="mt-3 text-xs p-2 rounded bg-amber-50 text-amber-800 border border-amber-200">
          {meta.note}
        </div>
      )}
    </div>
  );
}

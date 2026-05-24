import { useState } from 'react';
import toast from 'react-hot-toast';
import { Target } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { mclpApi } from '../../api/advanced';
import { Spinner, Field } from './shared';

export default function OptimizeTab({ projectId }: { projectId: string }) {
  const { mapInstance } = useMapStore();
  const [pickCount, setPickCount] = useState(5);
  const [radiusKm, setRadiusKm] = useState(8);
  const [gridStep, setGridStep] = useState(5);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<any>(null);

  async function run() {
    if (!mapInstance) { toast.error('Map not ready yet'); return; }
    const b = mapInstance.getBounds();
    if (!b) { toast.error('Pan or zoom the map first'); return; }
    const ne = b.getNorthEast(); const sw = b.getSouthWest();
    setBusy(true);
    try {
      const r = await mclpApi.optimize(projectId, {
        bbox: [sw.lng(), sw.lat(), ne.lng(), ne.lat()],
        grid_step_km: gridStep,
        pick_count: pickCount,
        radius_km: radiusKm,
        demand_metric: 'population',
      });
      setResult(r);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600">Find the best K locations within the map view to maximize population covered.</p>
      <Field label="Picks">
        <input type="number" min={1} max={20} value={pickCount}
          onChange={(e) => setPickCount(Math.max(0, parseInt(e.target.value, 10) || 0))} className="input h-9 text-sm" />
      </Field>
      <Field label="Reach radius (km)">
        <input type="number" min={1} max={80} value={radiusKm}
          onChange={(e) => setRadiusKm(Math.max(0, parseFloat(e.target.value) || 0))} className="input h-9 text-sm" />
      </Field>
      <Field label="Grid step (km)">
        <input type="number" min={1} max={50} value={gridStep}
          onChange={(e) => setGridStep(Math.max(0, parseFloat(e.target.value) || 0))} className="input h-9 text-sm" />
      </Field>
      <button className="btn btn-primary w-full h-10" onClick={run} disabled={busy}>
        {busy ? <Spinner /> : <Target size={14} />} {busy ? 'Optimizing…' : 'Find best locations'}
      </button>
      {result && (
        <div className="text-xs space-y-2">
          <div className="bg-emerald-50 border border-emerald-200 rounded p-2">
            <div className="font-bold text-emerald-700">{result.total_covered.toLocaleString()} people covered</div>
            <div className="text-emerald-700/80">{result.coverage_pct ?? '—'}% of universe · {result.candidate_count} candidates</div>
          </div>
          <ol className="space-y-1">
            {result.picks.map((p: any) => (
              <li key={p.rank} className="flex items-center justify-between bg-slate-50 rounded px-2 py-1.5">
                <span className="font-semibold">#{p.rank} ({p.lat.toFixed(3)}, {p.lng.toFixed(3)})</span>
                <span className="text-slate-600">+{p.unique_demand.toLocaleString()}</span>
              </li>
            ))}
          </ol>
        </div>
      )}
    </div>
  );
}

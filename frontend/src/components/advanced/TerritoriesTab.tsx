import { useState } from 'react';
import toast from 'react-hot-toast';
import { Sparkles, Wand2 } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import { territoryApi } from '../../api/advanced';
import { areasApi } from '../../api/areas';
import { Spinner, Field } from './shared';

export default function TerritoriesTab({ projectId }: { projectId: string }) {
  const { mapInstance } = useMapStore();
  const { updateArea } = useProjectStore();
  const [target, setTarget] = useState(8);
  const [metric, setMetric] = useState<'population' | 'income_weighted_pop' | 'housing_units'>('population');
  const [busy, setBusy] = useState(false);
  const [rebuilding, setRebuilding] = useState(false);
  const [last, setLast] = useState<any>(null);

  async function rebuildAll() {
    if (!last?.area_ids?.length) return;
    setRebuilding(true);
    const t = toast.loading(`Rebuilding ${last.area_ids.length} boundaries…`);
    let done = 0, failed = 0;
    for (const id of last.area_ids) {
      try {
        await areasApi.rebuildBoundary(id);
        // Refetch + push into projectStore so map polygons re-render with the
        // new clean geometry as each one finishes.
        const refreshed = await areasApi.findById(id);
        updateArea(refreshed);
        done++;
        toast.loading(`Rebuilt ${done} of ${last.area_ids.length}…`, { id: t });
      } catch { failed++; }
    }
    setRebuilding(false);
    toast.success(`Rebuilt ${done} boundaries${failed ? ` (${failed} failed)` : ''}`, { id: t });
  }

  async function run() {
    if (!mapInstance) { toast.error('Map not ready yet'); return; }
    const b = mapInstance.getBounds();
    if (!b) { toast.error('Pan or zoom the map first'); return; }
    const ne = b.getNorthEast(); const sw = b.getSouthWest();
    setBusy(true);
    try {
      const res = await territoryApi.generate(projectId, {
        target_count: target, balance_metric: metric,
        bbox: [sw.lng(), sw.lat(), ne.lng(), ne.lat()],
        name: 'Territory',
        constraints: { max_imbalance_pct: 12 },
      });
      setLast(res);
      toast.success(`Created ${res.territory_count} territories`);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Generation failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600">Auto-generate balanced territories over the current map view. Each territory becomes an area, named by compass direction (NW, SE, etc.) relative to the view center.</p>
      <Field label="Number of territories">
        <input type="number" min={2} max={30} value={target}
          onChange={(e) => setTarget(Math.max(0, parseInt(e.target.value, 10) || 0))}
          className="input h-9 text-sm" />
      </Field>
      <Field label="Balance by">
        <select className="input h-9 text-sm" value={metric} onChange={(e) => setMetric(e.target.value as any)}>
          <option value="population">Population</option>
          <option value="income_weighted_pop">Income-weighted population</option>
          <option value="housing_units">Housing units</option>
        </select>
      </Field>
      <button className="btn btn-primary w-full h-10" onClick={run} disabled={busy}>
        {busy ? <Spinner /> : <Sparkles size={14} />} {busy ? 'Generating…' : 'Generate territories'}
      </button>
      {last && (
        <div className="mt-3 border border-slate-200 rounded-lg p-3 space-y-1.5 text-xs">
          <div className="font-semibold" style={{ color: '#1A1A2E' }}>Result</div>
          <div className="text-slate-600">{last.territory_count} territories · {last.tract_count} tracts</div>
          <ul className="grid grid-cols-2 gap-1 mt-1">
            {last.territories?.map((t: any) => (
              <li key={t.index} className="bg-slate-50 rounded p-2">
                <div className="font-semibold">#{t.index + 1}</div>
                <div>{t.population.toLocaleString()} ppl</div>
                <div className="text-slate-500">{t.pop_share_pct}% share</div>
              </li>
            ))}
          </ul>
          {/* The initial shapes are convex hulls — fast but visually thin when
              the cluster's source tracts aren't contiguous. Offer a one-click
              ST_Union rebuild for all generated areas. Slow (~8s per area)
              but produces geographic, tract-following boundaries. */}
          <button
            onClick={rebuildAll}
            disabled={rebuilding}
            className="w-full mt-2 rounded-lg p-2 border-2 border-dashed border-violet-300 hover:border-violet-500 hover:bg-violet-50 transition flex items-center gap-2 text-left text-xs"
          >
            {rebuilding ? <Spinner /> : <Wand2 size={14} style={{ color: '#7848BB' }} />}
            <span>
              <b style={{ color: '#1A1A2E' }}>{rebuilding ? 'Rebuilding…' : 'Rebuild clean boundaries'}</b>
              {' '}<span className="text-slate-500">— dissolves source tracts into real geographic shapes (~8s each)</span>
            </span>
          </button>
        </div>
      )}
    </div>
  );
}

import { useState } from 'react';
import toast from 'react-hot-toast';
import { Sparkles } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { territoryApi } from '../../api/advanced';
import { Spinner, Field } from './shared';

export default function TerritoriesTab({ projectId }: { projectId: string }) {
  const { mapInstance } = useMapStore();
  const [target, setTarget] = useState(8);
  const [metric, setMetric] = useState<'population' | 'income_weighted_pop' | 'housing_units'>('population');
  const [busy, setBusy] = useState(false);
  const [last, setLast] = useState<any>(null);

  async function run() {
    if (!mapInstance) return;
    const b = mapInstance.getBounds();
    if (!b) return;
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
          onChange={(e) => setTarget(parseInt(e.target.value || '0'))}
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
        </div>
      )}
    </div>
  );
}

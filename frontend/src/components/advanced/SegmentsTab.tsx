import { useState } from 'react';
import toast from 'react-hot-toast';
import { segmentationApi, type SegmentBreakdownRow } from '../../api/advanced';
import { Spinner } from './shared';

export default function SegmentsTab({ projectId }: { projectId: string }) {
  const [totals, setTotals] = useState<SegmentBreakdownRow[]>([]);
  const [busy, setBusy] = useState(false);

  async function run() {
    setBusy(true);
    try {
      const r = await segmentationApi.forProject(projectId);
      setTotals(r.totals);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed (run /api/segmentation/recompute first?)');
    } finally {
      setBusy(false);
    }
  }

  const total = totals.reduce((s, x) => s + x.population, 0) || 1;
  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600">Mix of customer segments across this project's areas.</p>
      <button className="btn btn-primary w-full h-10" onClick={run} disabled={busy}>
        {busy ? <Spinner /> : null} {busy ? 'Computing…' : 'Compute segment mix'}
      </button>
      {totals.length > 0 && (
        <ul className="text-xs space-y-1">
          {totals.map((s) => {
            const pct = (100 * s.population) / total;
            return (
              <li key={s.segment_id} className="bg-white border border-slate-200 rounded p-2">
                <div className="flex items-center justify-between mb-1">
                  <span className="font-semibold" style={{ color: '#1A1A2E' }}>{s.segment_name}</span>
                  <span className="text-slate-600">{s.population.toLocaleString()} · {pct.toFixed(1)}%</span>
                </div>
                <div className="h-1.5 rounded bg-slate-100 overflow-hidden">
                  <div className="h-full" style={{ width: `${pct}%`, background: s.color }} />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

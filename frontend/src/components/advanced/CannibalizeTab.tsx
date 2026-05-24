import { useState } from 'react';
import toast from 'react-hot-toast';
import { cannibalizationApi, type CannibalizationResponse } from '../../api/advanced';
import { Spinner } from './shared';

/** Color/label for risk-tier badge (item #14). */
function severityBadge(sev?: string) {
  switch (sev) {
    case 'low':      return { bg: 'bg-emerald-100', fg: 'text-emerald-800', label: 'Low' };
    case 'moderate': return { bg: 'bg-amber-100',   fg: 'text-amber-800',   label: 'Moderate' };
    case 'high':     return { bg: 'bg-orange-100',  fg: 'text-orange-800',  label: 'High' };
    case 'critical': return { bg: 'bg-rose-100',    fg: 'text-rose-800',    label: 'Critical' };
    default:         return { bg: 'bg-slate-100',   fg: 'text-slate-700',   label: '—' };
  }
}

export default function CannibalizeTab({ projectId }: { projectId: string }) {
  const [data, setData] = useState<CannibalizationResponse | null>(null);
  const [busy, setBusy] = useState(false);

  async function run() {
    setBusy(true);
    try {
      setData(await cannibalizationApi.forProject(projectId));
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Analysis failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600">How much population each pair of your areas shares. Severity is the worse of the two coverage percentages.</p>
      <button className="btn btn-primary w-full h-10" onClick={run} disabled={busy}>
        {busy ? <Spinner /> : null} {busy ? 'Analyzing…' : 'Analyze overlaps'}
      </button>
      {data?.note && <div className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1.5">{data.note}</div>}
      {data && data.areas.length > 0 && (
        <>
          <div className="text-[10px] font-bold uppercase tracking-wide text-slate-500 mt-3">Per area</div>
          <ul className="text-xs space-y-1">
            {data.areas.map((a) => (
              <li key={a.id} className="flex items-center justify-between bg-slate-50 rounded px-2 py-1.5">
                <span className="flex items-center gap-1.5 truncate">
                  <span className="w-2.5 h-2.5 rounded-sm" style={{ background: a.color }} />
                  <span className="font-semibold truncate" style={{ color: '#1A1A2E' }}>{a.name}</span>
                </span>
                <span className="text-slate-600 shrink-0 ml-2">
                  {a.population.toLocaleString()} · {a.unique_pct}% unique
                </span>
              </li>
            ))}
          </ul>
          {data.overlaps.length > 0 && (
            <>
              <div className="text-[10px] font-bold uppercase tracking-wide text-slate-500 mt-3">Top overlaps</div>
              <ul className="text-xs space-y-1.5">
                {data.overlaps.slice(0, 10).map((o, i) => {
                  const sev = severityBadge((o as any).severity);
                  return (
                    <li key={i} className="bg-white border border-slate-200 rounded p-2">
                      <div className="flex items-center justify-between gap-2 mb-1">
                        <span className="font-semibold truncate" style={{ color: '#1A1A2E' }}>
                          {o.area_a_name} ↔ {o.area_b_name}
                        </span>
                        <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${sev.bg} ${sev.fg} shrink-0`}>{sev.label}</span>
                      </div>
                      <div className="text-slate-600">
                        {o.shared_population.toLocaleString()} shared · {o.pct_of_a}% of A · {o.pct_of_b}% of B
                      </div>
                      {(o as any).recommendation && (
                        <div className="text-slate-500 mt-0.5 italic">{(o as any).recommendation}</div>
                      )}
                    </li>
                  );
                })}
              </ul>
            </>
          )}
        </>
      )}
    </div>
  );
}

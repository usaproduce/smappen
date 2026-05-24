import { useRef, useState } from 'react';
import toast from 'react-hot-toast';
import { Sparkles, FileSpreadsheet, Scale, TrendingUp } from 'lucide-react';
import { featuresApi } from '../../api/features';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { Spinner, Field, Empty } from './shared';

/**
 * Combined panel for NF1 (drive-time matrix), NF2 (rebalancer), NF3
 * (forecast). Three sub-modes selected by a small chip row at the top so
 * the Advanced tab strip doesn't grow another 3 tabs.
 */
type Mode = 'matrix' | 'rebalance' | 'forecast';

export default function AnalyticsTab({ projectId }: { projectId: string }) {
  const [mode, setMode] = useState<Mode>('matrix');
  return (
    <div className="space-y-3">
      <div className="flex items-center gap-1 -mx-1 px-1 overflow-x-auto scroll-x">
        {([
          { k: 'matrix',    label: 'Drive-time matrix', icon: FileSpreadsheet },
          { k: 'rebalance', label: 'Rebalance',         icon: Scale },
          { k: 'forecast',  label: 'Forecast',          icon: TrendingUp },
        ] as { k: Mode; label: string; icon: any }[]).map((m) => {
          const Active = mode === m.k;
          const Icon = m.icon;
          return (
            <button
              key={m.k}
              onClick={() => setMode(m.k)}
              className={`flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold whitespace-nowrap shrink-0 ${
                Active ? 'bg-violet-100 text-violet-700 ring-1 ring-violet-300' : 'text-slate-500 hover:bg-slate-100'
              }`}
            >
              <Icon size={11} /> {m.label}
            </button>
          );
        })}
      </div>

      <div className="flex items-center gap-1.5 text-[10px] uppercase tracking-wider font-bold text-violet-700">
        <Sparkles size={10} /> {mode === 'matrix' ? 'NF1' : mode === 'rebalance' ? 'NF2' : 'NF3'} · new
      </div>

      {mode === 'matrix' && <MatrixSection />}
      {mode === 'rebalance' && <RebalanceSection projectId={projectId} />}
      {mode === 'forecast' && <ForecastSection />}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// NF1 — Drive-Time Matrix
// ─────────────────────────────────────────────────────────────────────────

function MatrixSection() {
  const [originsText, setOriginsText] = useState('');
  const [destText, setDestText] = useState('');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<any | null>(null);

  function parsePoints(text: string): { lat: number; lng: number; label?: string }[] {
    return text.split('\n').map((line) => {
      const m = line.trim().match(/^([\w \-.,]+?)?\s*[,\t]\s*(-?\d+(?:\.\d+)?)\s*[,\t]\s*(-?\d+(?:\.\d+)?)\s*$/);
      if (!m) return null;
      return { label: m[1]?.trim() || undefined, lat: parseFloat(m[2]), lng: parseFloat(m[3]) };
    }).filter(Boolean) as any[];
  }

  async function run() {
    const origins = parsePoints(originsText);
    const destinations = parsePoints(destText);
    if (origins.length === 0 || destinations.length === 0) {
      toast.error('Paste at least one origin + destination');
      return;
    }
    setBusy(true);
    try {
      const r = await featuresApi.driveTimeMatrix({ origins, destinations });
      setResult(r);
      toast.success(`${origins.length} × ${destinations.length} matrix computed`);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Matrix failed');
    } finally { setBusy(false); }
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600 leading-snug">
        Paste origin + destination lists below (one per line, format: <code>label, lat, lng</code>).
        Up to 200 of each. Returns an N × M drive-time matrix in minutes.
      </p>
      <Field label="Origins (1 per line)">
        <textarea
          className="textarea text-xs"
          rows={4}
          value={originsText}
          onChange={(e) => setOriginsText(e.target.value)}
          placeholder="Store A, 38.9072, -77.0369"
        />
      </Field>
      <Field label="Destinations (1 per line)">
        <textarea
          className="textarea text-xs"
          rows={4}
          value={destText}
          onChange={(e) => setDestText(e.target.value)}
          placeholder="Customer 1, 38.85, -77.30"
        />
      </Field>
      <button className="btn btn-primary w-full justify-center" onClick={run} disabled={busy}>
        {busy ? <><Spinner /> Computing…</> : 'Compute matrix'}
      </button>
      {result && <MatrixView result={result} />}
    </div>
  );
}

function MatrixView({ result }: { result: any }) {
  return (
    <div className="space-y-2">
      <div className="text-[10px] uppercase tracking-wider font-bold text-slate-500">
        {result.origins.length} origins × {result.destinations.length} destinations
      </div>
      <div className="overflow-auto max-h-72 border border-slate-200 rounded-md">
        <table className="w-full text-[11px] tabular-nums">
          <thead className="bg-slate-50 sticky top-0">
            <tr>
              <th className="px-2 py-1 text-left font-bold">Origin</th>
              {result.destinations.map((d: any, j: number) => (
                <th key={j} className="px-2 py-1 text-right font-semibold text-slate-600 whitespace-nowrap">
                  {d.label || `D${j + 1}`}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {result.origins.map((o: any, i: number) => (
              <tr key={i} className="border-t border-slate-100">
                <td className="px-2 py-1 font-semibold whitespace-nowrap">{o.label || `O${i + 1}`}</td>
                {result.durations[i].map((sec: number | null, j: number) => (
                  <td key={j} className="px-2 py-1 text-right">
                    {sec == null ? '—' : (sec / 60).toFixed(1)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="text-[10px] text-slate-400 italic">Cells show drive time in minutes. — = unreachable.</div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// NF2 — Rebalancer
// ─────────────────────────────────────────────────────────────────────────

function RebalanceSection({ projectId }: { projectId: string }) {
  const [csvText, setCsvText] = useState('');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<any | null>(null);

  function parseCustomers() {
    return csvText.split('\n').map((line) => {
      // Expects: name, lat, lng, revenue
      const parts = line.split(/[,\t]/).map((s) => s.trim());
      if (parts.length < 4) return null;
      const lat = parseFloat(parts[1]); const lng = parseFloat(parts[2]); const revenue = parseFloat(parts[3]);
      if (!Number.isFinite(lat) || !Number.isFinite(lng) || !Number.isFinite(revenue)) return null;
      return { name: parts[0] || undefined, lat, lng, revenue };
    }).filter(Boolean) as any[];
  }

  async function run() {
    const customers = parseCustomers();
    if (customers.length === 0) { toast.error('Paste customer rows: name, lat, lng, revenue'); return; }
    setBusy(true);
    try {
      const r = await featuresApi.rebalance(projectId, { customers });
      setResult(r);
      toast.success(`${customers.length} customers analyzed`);
    } catch (e: any) { toast.error(e?.response?.data?.error ?? 'Rebalance failed'); }
    finally { setBusy(false); }
  }

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600 leading-snug">
        Paste customer rows (<code>name, lat, lng, revenue</code>). The analyzer assigns
        each to its current territory, sums revenue, and suggests which customers
        to reassign to balance the books.
      </p>
      <Field label="Customers (name, lat, lng, revenue)">
        <textarea className="textarea text-xs" rows={5} value={csvText} onChange={(e) => setCsvText(e.target.value)}
          placeholder="Acme Co, 38.85, -77.30, 125000" />
      </Field>
      <button className="btn btn-primary w-full justify-center" disabled={busy} onClick={run}>
        {busy ? <><Spinner /> Analyzing…</> : 'Analyze revenue balance'}
      </button>
      {result && (
        <div className="space-y-2">
          <div className="text-xs text-slate-700">
            Imbalance: <b style={{ color: result.imbalance_pct > 30 ? '#D85A30' : '#1D9E75' }}>
              {result.imbalance_pct}%
            </b> · Target/territory: ${result.target_per_territory.toLocaleString()}
          </div>
          <ul className="space-y-1">
            {result.territories.map((t: any) => (
              <li key={t.id} className="flex items-center gap-2 text-xs px-2 py-1.5 rounded bg-slate-50">
                <span className="inline-block w-3 h-3 rounded-full" style={{ background: t.color || '#7848BB' }} />
                <span className="flex-1 font-semibold truncate">{t.name}</span>
                <span className="tabular-nums">${t.revenue.toLocaleString()}</span>
                <span className={`tabular-nums text-[10px] font-bold ${t.delta_pct > 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                  {t.delta_pct > 0 ? '+' : ''}{t.delta_pct}%
                </span>
              </li>
            ))}
          </ul>
          {result.suggestions?.length > 0 && (
            <div>
              <div className="text-[10px] uppercase tracking-wider font-bold text-violet-700 mt-2 mb-1">
                Suggested reassignments
              </div>
              <ul className="space-y-1 text-xs">
                {result.suggestions.slice(0, 8).map((s: any, i: number) => (
                  <li key={i} className="px-2 py-1.5 rounded bg-violet-50 border border-violet-100">
                    Move <b>{s.customer.name}</b> (${s.revenue.toLocaleString()}) · {s.distance_to_target_km}km from target
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// NF3 — Forecast
// ─────────────────────────────────────────────────────────────────────────

function ForecastSection() {
  const { areas } = useProjectStore() as any;
  const { selectedAreaId } = useMapStore();
  const [revenueByArea, setRevenueByArea] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<any | null>(null);
  const candidate = areas.find((a: any) => a.id === selectedAreaId);

  async function run() {
    if (!candidate) { toast.error('Select an area to forecast'); return; }
    const training = Object.entries(revenueByArea)
      .map(([area_id, rev]) => ({ area_id, revenue: parseFloat(rev) }))
      .filter((t) => Number.isFinite(t.revenue) && t.revenue > 0 && t.area_id !== candidate.id);
    if (training.length < 3) { toast.error('Enter revenue for at least 3 other areas'); return; }
    setBusy(true);
    try {
      const r = await featuresApi.forecast(candidate.id, { training_data: training });
      setResult(r);
    } catch (e: any) { toast.error(e?.response?.data?.error ?? 'Forecast failed'); }
    finally { setBusy(false); }
  }

  if (!candidate) return <Empty msg="Select an area to use as the forecast target." />;

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-600 leading-snug">
        Enter revenue for your existing locations; the model predicts revenue
        for the selected <b>{candidate.name}</b> by similarity-weighted k-NN
        regression across analog demographic fingerprints.
      </p>
      <div className="max-h-56 overflow-y-auto border border-slate-200 rounded-md">
        {areas.filter((a: any) => a.id !== candidate.id).slice(0, 30).map((a: any) => (
          <label key={a.id} className="flex items-center gap-2 px-2 py-1.5 border-b border-slate-100 last:border-0 text-xs">
            <span className="inline-block w-3 h-3 rounded-full shrink-0" style={{ background: a.fill_color || '#7848BB' }} />
            <span className="flex-1 truncate">{a.name}</span>
            <input
              type="number"
              placeholder="$"
              className="input h-7 text-xs w-24 text-right"
              value={revenueByArea[a.id] ?? ''}
              onChange={(e) => setRevenueByArea({ ...revenueByArea, [a.id]: e.target.value })}
            />
          </label>
        ))}
      </div>
      <button className="btn btn-primary w-full justify-center" onClick={run} disabled={busy}>
        {busy ? <><Spinner /> Forecasting…</> : 'Forecast revenue'}
      </button>
      {result && (
        <div className="rounded-lg bg-violet-50 border border-violet-200 p-3">
          <div className="text-[10px] uppercase tracking-wider font-bold text-violet-700">Predicted revenue</div>
          <div className="text-2xl font-extrabold mt-1" style={{ color: '#1A1A2E' }}>
            ${Math.round(result.predicted_revenue).toLocaleString()}
          </div>
          <div className="text-[11px] text-slate-600 mt-1">
            95% CI: ${Math.round(result.confidence_low).toLocaleString()} – ${Math.round(result.confidence_high).toLocaleString()}
          </div>
          <div className="text-[10px] uppercase tracking-wider font-bold text-slate-500 mt-3 mb-1">Top contributors</div>
          <ul className="text-xs space-y-0.5">
            {result.k_neighbors.map((n: any) => (
              <li key={n.area_id} className="flex justify-between">
                <span className="truncate">{n.name}</span>
                <span className="tabular-nums text-slate-500">{Math.round(n.similarity * 100)}% · ${n.revenue.toLocaleString()}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

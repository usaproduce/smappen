import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Activity, AlertTriangle, Database, TrendingUp, Search } from 'lucide-react';
import { api } from '../../api/client';

/**
 * COGS benchmark health & audit page — /admin/carafe/cogs.
 *
 * Four blocks:
 *   1. Per-source freshness (USDA AMS, NASS, GreenDock) with status pills
 *   2. Recent anomalies (today's median > 3σ from prior 30d normal)
 *   3. Top unmatched commodities (AMS rows not matched to any
 *      ingredient_key — operator extends config from this)
 *   4. Lookup-trace tool (key + region → full provenance drill-in)
 */
export default function CogsHealthPage() {
  const { data: health, isLoading } = useQuery({
    queryKey: ['cogs', 'health'],
    queryFn: async () => (await api.get('/api/admin/cogs/health')).data.data as Health,
    refetchInterval: 60_000,
  });

  const [traceKey, setTraceKey] = useState('');
  const [traceRegion, setTraceRegion] = useState('US-NE');
  const [trace, setTrace] = useState<TraceResult | null>(null);
  const [traceLoading, setTraceLoading] = useState(false);

  async function runTrace() {
    if (!traceKey.trim()) return;
    setTraceLoading(true);
    try {
      const r = await api.get('/api/admin/cogs/trace', {
        params: { key: traceKey.trim(), region: traceRegion || undefined },
      });
      setTrace(r.data.data as TraceResult);
    } finally {
      setTraceLoading(false);
    }
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between flex-wrap gap-2">
        <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: '#1A1A2E' }}>
          <Database size={22} style={{ color: '#7848BB' }} /> COGS benchmark health
        </h1>
        {health && (
          <OverallPill status={health.overall_status} />
        )}
      </div>

      {isLoading || !health ? (
        <div className="space-y-3">
          <div className="skeleton h-32" />
          <div className="skeleton h-40" />
        </div>
      ) : (
        <>
          <section>
            <SectionHeader icon={Activity} title="Per-source freshness (last 7 days)" />
            {health.sources.length === 0 ? (
              <Empty>No ingest runs recorded in the last 7 days.</Empty>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {health.sources.map((s) => (
                  <SourceCard key={s.source} s={s} />
                ))}
              </div>
            )}
            <Totals t={health.totals} />
          </section>

          <section>
            <SectionHeader icon={AlertTriangle} title={`Recent anomalies (${health.recent_anomalies.length})`} />
            {health.recent_anomalies.length === 0 ? (
              <Empty>No 3σ deviations in the last 14 days. Prices behaving normally.</Empty>
            ) : (
              <div className="overflow-x-auto bg-white border border-slate-200 rounded-xl">
                <table className="w-full text-sm">
                  <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-600">
                    <tr>
                      <th className="text-left px-3 py-2">Ingredient</th>
                      <th className="text-left px-3 py-2">Region</th>
                      <th className="text-left px-3 py-2">Source</th>
                      <th className="text-right px-3 py-2">Price</th>
                      <th className="text-right px-3 py-2">Stddev</th>
                      <th className="text-left px-3 py-2">As of</th>
                    </tr>
                  </thead>
                  <tbody>
                    {health.recent_anomalies.map((a, i) => (
                      <tr key={i} className="border-t border-slate-100">
                        <td className="px-3 py-2 font-semibold" style={{ color: '#1A1A2E' }}>{a.ingredient_key}</td>
                        <td className="px-3 py-2 text-slate-600">{a.region ?? 'US'}</td>
                        <td className="px-3 py-2 text-slate-600">{a.source}</td>
                        <td className="px-3 py-2 text-right tabular-nums">${(a.market_price_cents / 100).toFixed(2)}/{a.unit}</td>
                        <td className="px-3 py-2 text-right tabular-nums text-slate-500">±{a.price_stddev_cents != null ? '$' + (a.price_stddev_cents / 100).toFixed(2) : '—'}</td>
                        <td className="px-3 py-2 text-slate-600">{a.as_of}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <section>
            <SectionHeader icon={TrendingUp} title="Top unmatched upstream commodities" />
            <p className="text-xs text-slate-500 italic mb-2">
              AMS rows whose commodity+variety didn't map to any ingredient_key.
              Add high-volume entries to <code>config/cogs_usda_ams_reports.php</code>.
            </p>
            {health.top_unmatched.length === 0 ? (
              <Empty>No unmatched commodities recorded yet.</Empty>
            ) : (
              <div className="overflow-x-auto bg-white border border-slate-200 rounded-xl">
                <table className="w-full text-sm">
                  <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-600">
                    <tr>
                      <th className="text-left px-3 py-2">Adapter</th>
                      <th className="text-left px-3 py-2">Commodity</th>
                      <th className="text-left px-3 py-2">Variety</th>
                      <th className="text-right px-3 py-2">Observations</th>
                      <th className="text-left px-3 py-2">Last seen</th>
                    </tr>
                  </thead>
                  <tbody>
                    {health.top_unmatched.map((u, i) => (
                      <tr key={i} className="border-t border-slate-100">
                        <td className="px-3 py-2 text-slate-600">{u.adapter}</td>
                        <td className="px-3 py-2 font-semibold" style={{ color: '#1A1A2E' }}>{u.commodity}</td>
                        <td className="px-3 py-2 text-slate-600">{u.variety || '—'}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{u.observation_count.toLocaleString()}</td>
                        <td className="px-3 py-2 text-slate-500 text-xs">{u.last_seen_at}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          {health.missing_recipe_keys.length > 0 && (
            <section>
              <SectionHeader icon={AlertTriangle} title="Recipe ingredients with no benchmark price" />
              <p className="text-xs text-slate-500 italic mb-2">
                These ingredient_keys are used in at least one recipe but never have a
                row in cogs_benchmark, so plate-cost coverage will report them as missing.
              </p>
              <div className="bg-white border border-slate-200 rounded-xl divide-y divide-slate-100">
                {health.missing_recipe_keys.map((m, i) => (
                  <div key={i} className="px-3 py-2 flex items-center justify-between text-sm">
                    <span className="font-semibold" style={{ color: '#1A1A2E' }}>{m.ingredient_key}</span>
                    <span className="text-slate-500 text-xs">{m.recipes_using} recipe{m.recipes_using === 1 ? '' : 's'}</span>
                  </div>
                ))}
              </div>
            </section>
          )}

          <section>
            <SectionHeader icon={Search} title="Lookup trace — one ingredient × region" />
            <div className="bg-white border border-slate-200 rounded-xl p-3 space-y-3">
              <div className="flex items-center gap-2 flex-wrap">
                <input
                  className="input h-9 text-sm flex-1 min-w-[200px]"
                  placeholder="ingredient_key (e.g. tomato_roma)"
                  value={traceKey}
                  onChange={(e) => setTraceKey(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && runTrace()}
                />
                <select className="input h-9 text-sm" value={traceRegion} onChange={(e) => setTraceRegion(e.target.value)}>
                  <option value="">national</option>
                  <option value="US">US</option>
                  <option value="US-NE">US-NE</option>
                  <option value="US-MID-ATLANTIC">US-MID-ATLANTIC</option>
                  <option value="US-MW">US-MW</option>
                  <option value="US-SE">US-SE</option>
                  <option value="US-S">US-S</option>
                  <option value="US-W">US-W</option>
                </select>
                <button className="btn h-9 px-4 text-sm" onClick={runTrace} disabled={traceLoading || !traceKey.trim()}>
                  {traceLoading ? 'Loading…' : 'Trace'}
                </button>
              </div>
              {trace && <TracePanel trace={trace} />}
            </div>
          </section>
        </>
      )}
    </div>
  );
}

function OverallPill({ status }: { status: 'green' | 'yellow' | 'red' }) {
  const bg = status === 'green' ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
           : status === 'yellow' ? 'bg-amber-100 text-amber-800 border-amber-200'
           : 'bg-red-100 text-red-800 border-red-200';
  return <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-bold uppercase tracking-wider border ${bg}`}>{status}</span>;
}

function SectionHeader({ icon: Icon, title }: { icon: any; title: string }) {
  return (
    <h2 className="font-extrabold text-base mb-3 flex items-center gap-2" style={{ color: '#1A1A2E' }}>
      <Icon size={16} style={{ color: '#7848BB' }} /> {title}
    </h2>
  );
}

function Empty({ children }: { children: React.ReactNode }) {
  return <div className="bg-slate-50 rounded-xl p-6 text-center text-sm text-slate-500">{children}</div>;
}

function SourceCard({ s }: { s: SourceHealth }) {
  const tone = s.status === 'green' ? 'border-emerald-200 bg-emerald-50/40'
             : s.status === 'yellow' ? 'border-amber-200 bg-amber-50/40'
             : 'border-red-200 bg-red-50/40';
  const okPct = s.total_batches > 0 ? Math.round((s.ok_batches / s.total_batches) * 100) : 0;
  return (
    <div className={`border rounded-xl p-3 ${tone}`}>
      <div className="flex items-center justify-between">
        <div className="font-extrabold uppercase tracking-wider text-xs" style={{ color: '#1A1A2E' }}>{s.source}</div>
        <OverallPill status={s.status} />
      </div>
      <div className="mt-2 grid grid-cols-3 gap-2 text-center">
        <Stat label="batches/7d" value={s.total_batches.toLocaleString()} />
        <Stat label="ok rate"    value={`${okPct}%`} />
        <Stat label="rows ingested" value={s.rows_inserted.toLocaleString()} />
      </div>
      <div className="mt-2 text-xs text-slate-500">
        last fetch {s.hours_since < 1 ? '<1h' : `${s.hours_since}h`} ago
        {s.last_fetched && <span className="ml-1">({s.last_fetched})</span>}
      </div>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{label}</div>
      <div className="text-base font-extrabold tabular-nums" style={{ color: '#1A1A2E' }}>{value}</div>
    </div>
  );
}

function Totals({ t }: { t: { rows_total: number; stub_rows: number; live_rows: number; anomaly_rows: number } }) {
  return (
    <div className="mt-3 text-xs text-slate-500 flex items-center gap-4">
      <span><b className="text-slate-700">{t.rows_total.toLocaleString()}</b> total rows</span>
      <span><b className="text-slate-700">{t.live_rows.toLocaleString()}</b> live</span>
      <span><b className="text-slate-700">{t.stub_rows.toLocaleString()}</b> stub</span>
      {t.anomaly_rows > 0 && <span><b className="text-amber-700">{t.anomaly_rows}</b> anomalies on file</span>}
    </div>
  );
}

function TracePanel({ trace }: { trace: TraceResult }) {
  if (trace.error) return <div className="text-sm text-red-700">{trace.error}</div>;
  if (!trace.current) return <div className="text-sm text-slate-500">No benchmark row found for that key/region.</div>;
  const c = trace.current;
  const b = trace.batch;
  const r = trace.rolling;
  const pctVsMean = r && r.mean_30d_cents
    ? ((c.market_price_cents - r.mean_30d_cents) / r.mean_30d_cents) * 100
    : null;
  return (
    <div className="space-y-3 mt-2">
      <div className="bg-slate-50/60 border border-slate-200 rounded-md p-3 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
        <KV label="Price"           value={`$${(c.market_price_cents / 100).toFixed(2)}/${c.unit}`} />
        <KV label="Source"          value={`${c.source}${c.region ? ' · ' + c.region : ''}`} />
        <KV label="As of"           value={c.as_of} />
        <KV label="Observations"    value={`${c.observation_count}${c.price_stddev_cents != null ? ' (σ=$'+(c.price_stddev_cents/100).toFixed(2)+')' : ''}`} />
        {pctVsMean != null && (
          <KV label="vs 30d mean" value={`${pctVsMean >= 0 ? '+' : ''}${pctVsMean.toFixed(1)}%`}
              tone={Math.abs(pctVsMean) > 15 ? 'warn' : 'neutral'} />
        )}
        {c.is_anomaly === 1 && <KV label="Anomaly" value="⚠ 3σ spike" tone="warn" />}
      </div>
      {b && (
        <details className="bg-white border border-slate-200 rounded-md p-2 text-xs">
          <summary className="font-semibold text-slate-700 cursor-pointer">Upstream batch provenance</summary>
          <div className="mt-2 space-y-1 text-slate-600 font-mono break-all">
            <div><span className="text-slate-400">adapter:</span> {b.adapter}</div>
            <div><span className="text-slate-400">source_ref:</span> {b.source_ref ?? '—'}</div>
            <div><span className="text-slate-400">endpoint:</span> {b.endpoint ?? '—'}</div>
            <div><span className="text-slate-400">http:</span> {b.http_status ?? '—'} · {b.latency_ms ?? '—'}ms · ok={b.ok}</div>
            <div><span className="text-slate-400">fetched_at:</span> {b.fetched_at}</div>
            <div><span className="text-slate-400">rows_inserted:</span> {b.rows_inserted}</div>
            {b.notes_json && <div><span className="text-slate-400">notes:</span> {b.notes_json}</div>}
          </div>
        </details>
      )}
      {r && (
        <details className="bg-white border border-slate-200 rounded-md p-2 text-xs">
          <summary className="font-semibold text-slate-700 cursor-pointer">Rolling stats (30d window)</summary>
          <div className="mt-2 grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
            <KV label="mean 7d"  value={r.mean_7d_cents  != null ? `$${(r.mean_7d_cents/100).toFixed(2)}` : '—'} />
            <KV label="mean 30d" value={r.mean_30d_cents != null ? `$${(r.mean_30d_cents/100).toFixed(2)}` : '—'} />
            <KV label="σ 30d"    value={r.stddev_30d_cents != null ? `±$${(r.stddev_30d_cents/100).toFixed(2)}` : '—'} />
            <KV label="range 30d" value={r.min_30d_cents != null ? `$${(r.min_30d_cents/100).toFixed(2)}–$${(r.max_30d_cents!/100).toFixed(2)}` : '—'} />
            <KV label="observations" value={String(r.obs_count_30d)} />
            <KV label="updated_at" value={r.updated_at} />
          </div>
        </details>
      )}
      <details className="bg-white border border-slate-200 rounded-md p-2 text-xs">
        <summary className="font-semibold text-slate-700 cursor-pointer">30-day history ({trace.history_30d.length} rows)</summary>
        <table className="mt-2 w-full">
          <thead className="text-[10px] text-slate-500 uppercase tracking-wider">
            <tr>
              <th className="text-left py-1">as_of</th>
              <th className="text-left py-1">source</th>
              <th className="text-left py-1">region</th>
              <th className="text-right py-1">price</th>
              <th className="text-right py-1">obs</th>
            </tr>
          </thead>
          <tbody>
            {trace.history_30d.map((h, i) => (
              <tr key={i} className="border-t border-slate-100">
                <td className="py-1">{h.as_of}</td>
                <td className="py-1">{h.source}</td>
                <td className="py-1">{h.region ?? 'US'}</td>
                <td className="py-1 text-right tabular-nums">${(h.market_price_cents/100).toFixed(2)}/{h.unit}</td>
                <td className="py-1 text-right tabular-nums">{h.observation_count}{h.is_anomaly === 1 && ' ⚠'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </details>
    </div>
  );
}

function KV({ label, value, tone = 'neutral' }: { label: string; value: string; tone?: 'neutral' | 'warn' }) {
  const color = tone === 'warn' ? '#d97706' : '#1A1A2E';
  return (
    <div>
      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{label}</div>
      <div className="font-extrabold tabular-nums" style={{ color }}>{value}</div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────
// API response shapes
// ─────────────────────────────────────────────────────────────────

interface SourceHealth {
  source: string;
  last_fetched: string | null;
  hours_since: number;
  ok_batches: number;
  total_batches: number;
  rows_inserted: number;
  status: 'green' | 'yellow' | 'red';
}

interface Anomaly {
  ingredient_key: string;
  region: string | null;
  source: string;
  market_price_cents: number;
  unit: string;
  as_of: string;
  price_stddev_cents: number | null;
}

interface Unmatched {
  adapter: string;
  commodity: string;
  variety: string;
  observation_count: number;
  last_seen_at: string;
}

interface Health {
  generated_at: string;
  overall_status: 'green' | 'yellow' | 'red';
  sources: SourceHealth[];
  recent_anomalies: Anomaly[];
  top_unmatched: Unmatched[];
  missing_recipe_keys: Array<{ ingredient_key: string; recipes_using: number }>;
  totals: { rows_total: number; stub_rows: number; live_rows: number; anomaly_rows: number };
}

interface TraceResult {
  ingredient_key: string;
  region: string | null;
  current: {
    id: string;
    ingredient_key: string;
    region: string | null;
    source: string;
    market_price_cents: number;
    unit: string;
    as_of: string;
    observation_count: number;
    price_stddev_cents: number | null;
    is_anomaly: number;
    batch_id: string | null;
    created_at: string;
  } | null;
  batch: {
    id: string;
    adapter: string;
    source: string;
    region: string | null;
    endpoint: string | null;
    source_ref: string | null;
    as_of: string;
    fetched_at: string;
    http_status: number | null;
    latency_ms: number | null;
    ok: number;
    rows_inserted: number;
    error_message: string | null;
    notes_json: string | null;
  } | null;
  rolling: {
    mean_7d_cents: number | null;
    mean_30d_cents: number | null;
    stddev_30d_cents: number | null;
    min_30d_cents: number | null;
    max_30d_cents: number | null;
    obs_count_30d: number;
    as_of_max: string;
    updated_at: string;
  } | null;
  history_30d: Array<{
    as_of: string;
    source: string;
    region: string | null;
    market_price_cents: number;
    unit: string;
    observation_count: number;
    price_stddev_cents: number | null;
    is_anomaly: number;
  }>;
  error?: string;
}

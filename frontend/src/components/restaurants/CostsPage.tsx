import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { DollarSign } from 'lucide-react';
import { foodCostApi, type FoodCostTheoretical } from '../../api/restaurants';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';

/**
 * Theoretical food cost — spec §5.8 (half of it; actual-purchase variance
 * needs invoice ingestion which is out of Phase 1/2 scope). For the
 * window:
 *   - Theoretical $ + % of revenue
 *   - Top 10 cost contributors
 *
 * Operators read this to see (a) where their margin floor sits and
 * (b) which items dominate the cost basis — the targets for margin work.
 */
export default function CostsPage() {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  const [start, setStart] = useState<string>(firstOfMonth());
  const [end, setEnd] = useState<string>(today());
  const [data, setData] = useState<FoodCostTheoretical | null>(null);
  const [loading, setLoading] = useState(true);

  async function load() {
    setLoading(true);
    try {
      setData(await foodCostApi.theoretical(restaurantId, { start, end }));
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    if (!restaurantId) return;
    let cancelled = false;
    (async () => {
      try {
        const r = await foodCostApi.theoretical(restaurantId, { start, end });
        if (!cancelled) setData(r);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [restaurantId, start, end]);

  const pct = data ? Math.round(data.theoretical_pct * 100) : null;

  return (
    <RestaurantWorkspaceLayout>
      <div className="space-y-4">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: '#1A1A2E' }}>
            <DollarSign size={22} style={{ color: '#7848BB' }} /> Food cost
          </h1>
          <div className="flex items-center gap-2">
            <input type="date" className="input h-9 text-sm" value={start} onChange={(e) => setStart(e.target.value)} />
            <span className="text-slate-400">→</span>
            <input type="date" className="input h-9 text-sm" value={end} onChange={(e) => setEnd(e.target.value)} />
            <button className="btn h-9 px-3 text-sm" onClick={load} disabled={loading}>Refresh</button>
          </div>
        </div>

        {loading ? (
          <div className="space-y-2"><div className="skeleton h-24" /><div className="skeleton h-64" /></div>
        ) : !data ? (
          <div className="bg-slate-50 rounded-xl p-10 text-center text-sm text-slate-500">No data yet for this window.</div>
        ) : (
          <>
            <section className="grid grid-cols-2 md:grid-cols-3 gap-3">
              <Tile label="Theoretical cost" value={`$${(data.theoretical_cost_cents / 100).toFixed(2)}`} />
              <Tile label="Revenue"          value={`$${(data.revenue_cents / 100).toFixed(2)}`} />
              <Tile label="Cost %"           value={pct === null ? '—' : `${pct}%`}
                    tone={pct === null ? 'neutral' : pct < 30 ? 'good' : pct < 40 ? 'warn' : 'bad'} />
            </section>

            <p className="text-xs text-slate-500 italic">{data.note}</p>

            <section>
              <h2 className="font-extrabold text-base mb-3" style={{ color: '#1A1A2E' }}>Top cost contributors</h2>
              {data.top_contributors.length === 0 ? (
                <div className="bg-slate-50 rounded-xl p-10 text-center text-sm text-slate-500">No sales with linked plate costs in this window.</div>
              ) : (
                <div className="overflow-x-auto bg-white border border-slate-200 rounded-xl">
                  <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-600">
                      <tr>
                        <th className="text-left px-3 py-2">Item</th>
                        <th className="text-right px-3 py-2">Qty sold</th>
                        <th className="text-right px-3 py-2">Cost</th>
                        <th className="text-right px-3 py-2">Revenue</th>
                        <th className="text-right px-3 py-2">Cost / rev</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.top_contributors.map((c) => {
                        const ratio = c.revenue_cents > 0 ? Math.round((c.cost_cents / c.revenue_cents) * 100) : null;
                        return (
                          <tr key={c.menu_item_id} className="border-t border-slate-100">
                            <td className="px-3 py-2 font-semibold" style={{ color: '#1A1A2E' }}>{c.name}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{c.qty_sold.toLocaleString()}</td>
                            <td className="px-3 py-2 text-right tabular-nums">${(c.cost_cents / 100).toFixed(2)}</td>
                            <td className="px-3 py-2 text-right tabular-nums">${(c.revenue_cents / 100).toFixed(2)}</td>
                            <td className="px-3 py-2 text-right tabular-nums" style={{ color: ratio !== null && ratio >= 40 ? '#dc2626' : '#1A1A2E' }}>
                              {ratio === null ? '—' : `${ratio}%`}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
          </>
        )}
      </div>
    </RestaurantWorkspaceLayout>
  );
}

function Tile({ label, value, tone = 'neutral' }: { label: string; value: string; tone?: 'good' | 'warn' | 'bad' | 'neutral' }) {
  const color = { good: '#059669', warn: '#d97706', bad: '#dc2626', neutral: '#1A1A2E' }[tone];
  return (
    <div className="bg-white border border-slate-200 rounded-xl p-3">
      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{label}</div>
      <div className="text-xl font-extrabold tabular-nums mt-1" style={{ color }}>{value}</div>
    </div>
  );
}

function firstOfMonth(): string { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`; }
function today(): string { return new Date().toISOString().slice(0, 10); }

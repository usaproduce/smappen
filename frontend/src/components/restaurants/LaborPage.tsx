import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Users2, AlertTriangle, AlertCircle, Lightbulb, Plus } from 'lucide-react';
import { laborApi, type LaborAnalysis } from '../../api/restaurants';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';

/**
 * Labor + Daypart — spec §5.6 + §5.7.
 *
 *   Top: window picker + median revenue-per-cover headline.
 *   Middle: over/understaffed hour flags with dollar context.
 *   Bottom: slow-window suggestions (daypart-anchored promos).
 *   Side action: add a manual shift if POS labor isn't connected.
 */
export default function LaborPage() {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  const [start, setStart] = useState<string>(daysAgo(14));
  const [end, setEnd] = useState<string>(today());
  const [data, setData] = useState<LaborAnalysis | null>(null);
  const [loading, setLoading] = useState(true);
  const [showShiftForm, setShowShiftForm] = useState(false);

  async function load() {
    setLoading(true);
    try {
      setData(await laborApi.analysis(restaurantId, { start, end }));
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
        const r = await laborApi.analysis(restaurantId, { start, end });
        if (!cancelled) setData(r);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [restaurantId, start, end]);

  return (
    <RestaurantWorkspaceLayout>
      <div className="space-y-4">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: '#1A1A2E' }}>
            <Users2 size={22} style={{ color: '#7848BB' }} /> Labor & Daypart
          </h1>
          <div className="flex items-center gap-2">
            <input type="date" className="input h-9 text-sm" value={start} onChange={(e) => setStart(e.target.value)} />
            <span className="text-slate-400">→</span>
            <input type="date" className="input h-9 text-sm" value={end} onChange={(e) => setEnd(e.target.value)} />
            <button className="btn h-9 px-3 text-sm" onClick={load} disabled={loading}>Refresh</button>
            <button className="btn btn-primary h-9 px-3 text-sm" onClick={() => setShowShiftForm((v) => !v)}>
              <Plus size={14} /> Shift
            </button>
          </div>
        </div>

        {showShiftForm && (
          <ShiftForm
            onCancel={() => setShowShiftForm(false)}
            onCreate={async (input) => {
              try {
                await laborApi.createShift(restaurantId, input);
                setShowShiftForm(false);
                await load();
                toast.success('Shift recorded');
              } catch (e: any) { toast.error(e?.response?.data?.error ?? 'Failed'); }
            }}
          />
        )}

        {loading ? (
          <div className="space-y-2"><div className="skeleton h-20" /><div className="skeleton h-40" /></div>
        ) : !data ? null : (
          <>
            <section className="bg-white border border-slate-200 rounded-xl p-4">
              <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Median revenue per cover this window</div>
              <div className="text-2xl font-extrabold tabular-nums mt-1" style={{ color: '#1A1A2E' }}>
                ${(data.median_rpc / 100).toFixed(2)} <span className="text-sm font-semibold text-slate-500">/ shift hour</span>
              </div>
              <p className="text-xs text-slate-600 mt-1">
                Hours where revenue-per-cover deviates 2× from this median are flagged below. If most hours show no covers, add some shifts using the button above.
              </p>
            </section>

            <FlagList
              title="Under-staffed hours (revenue/cover too high)"
              icon={<AlertCircle size={14} className="text-amber-600" />}
              rows={data.understaffed}
            />
            <FlagList
              title="Over-staffed hours (revenue/cover too low)"
              icon={<AlertTriangle size={14} className="text-rose-600" />}
              rows={data.overstaffed}
            />

            <section>
              <h2 className="font-extrabold text-base mb-3 flex items-center gap-2" style={{ color: '#1A1A2E' }}>
                <Lightbulb size={16} style={{ color: '#7848BB' }} />
                Slow windows — daypart suggestions
              </h2>
              {data.slow_windows.length === 0 ? (
                <div className="bg-slate-50 rounded-xl p-6 text-center text-sm text-slate-500">No slow windows in this period.</div>
              ) : (
                <ul className="space-y-2">
                  {data.slow_windows.slice(0, 12).map((s, i) => (
                    <li key={i} className="bg-white border border-slate-200 rounded-xl p-3 flex items-start gap-3">
                      <span className="inline-flex items-center justify-center w-12 h-12 bg-violet-50 rounded-lg font-bold" style={{ color: '#7848BB' }}>
                        {String(s.hour).padStart(2, '0')}
                      </span>
                      <div className="flex-1">
                        <div className="font-semibold text-sm" style={{ color: '#1A1A2E' }}>
                          {s.date} · {s.hour}:00 — ${(s.revenue_cents / 100).toFixed(0)} revenue
                        </div>
                        <p className="text-sm text-slate-700 mt-1">{s.suggestion}</p>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </>
        )}
      </div>
    </RestaurantWorkspaceLayout>
  );
}

function FlagList({ title, icon, rows }: { title: string; icon: React.ReactNode; rows: any[] }) {
  if (rows.length === 0) return null;
  return (
    <section>
      <h2 className="font-extrabold text-sm mb-2 flex items-center gap-2" style={{ color: '#1A1A2E' }}>{icon} {title}</h2>
      <ul className="space-y-1.5">
        {rows.slice(0, 8).map((row, i) => (
          <li key={i} className="bg-white border border-slate-200 rounded p-2 text-xs flex items-center gap-3">
            <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{row.date} · {row.hour}:00</span>
            <span className="text-slate-500">{row.covers} cover{row.covers === 1 ? '' : 's'}</span>
            <span className="text-slate-500">${(row.revenue_cents / 100).toFixed(0)} rev</span>
            <span className="text-slate-500">$/cover: ${(row.revenue_per_cover / 100).toFixed(2)}</span>
            <span className="flex-1" />
            <span className="text-slate-700 truncate max-w-[40%]">{row.note}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}

function ShiftForm({ onCancel, onCreate }: { onCancel: () => void; onCreate: (input: { starts_at: string; ends_at?: string; employee_label?: string; role?: string; hourly_wage_cents?: number }) => void }) {
  const [start, setStart] = useState<string>(new Date().toISOString().slice(0, 16));
  const [end, setEnd] = useState<string>('');
  const [label, setLabel] = useState('');
  const [role, setRole] = useState('foh');
  const [wage, setWage] = useState('20');

  function submit() {
    if (!start) { toast.error('starts_at required'); return; }
    onCreate({
      starts_at: start.replace('T', ' ') + ':00',
      ends_at: end ? end.replace('T', ' ') + ':00' : undefined,
      employee_label: label || undefined,
      role,
      hourly_wage_cents: wage ? Math.round(Number(wage) * 100) : undefined,
    });
  }

  return (
    <div className="bg-slate-50 rounded-xl p-4 space-y-3">
      <div className="grid grid-cols-12 gap-3">
        <label className="col-span-6 md:col-span-3 block">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Starts</div>
          <input type="datetime-local" className="input h-9 text-sm w-full" value={start} onChange={(e) => setStart(e.target.value)} />
        </label>
        <label className="col-span-6 md:col-span-3 block">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Ends</div>
          <input type="datetime-local" className="input h-9 text-sm w-full" value={end} onChange={(e) => setEnd(e.target.value)} />
        </label>
        <label className="col-span-6 md:col-span-2 block">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Role</div>
          <select className="input h-9 text-sm w-full" value={role} onChange={(e) => setRole(e.target.value)}>
            <option value="foh">FOH</option><option value="boh">BOH</option>
            <option value="prep">Prep</option><option value="manager">Manager</option>
          </select>
        </label>
        <label className="col-span-6 md:col-span-2 block">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Wage / hr ($)</div>
          <input type="number" min={0} step={0.01} className="input h-9 text-sm w-full" value={wage} onChange={(e) => setWage(e.target.value)} />
        </label>
        <label className="col-span-12 md:col-span-2 block">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Name</div>
          <input className="input h-9 text-sm w-full" value={label} onChange={(e) => setLabel(e.target.value)} placeholder="anon ok" />
        </label>
      </div>
      <div className="flex gap-2">
        <button className="btn btn-primary h-9 px-3 text-sm" onClick={submit}>Save shift</button>
        <button className="btn h-9 px-3 text-sm" onClick={onCancel}>Cancel</button>
      </div>
    </div>
  );
}

function today(): string { return new Date().toISOString().slice(0, 10); }
function daysAgo(n: number): string { const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().slice(0, 10); }

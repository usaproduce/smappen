import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Users2, Lightbulb, Plus } from 'lucide-react';
import { laborApi, type LaborAnalysis } from '../../api/restaurants';
import type { Recommendation } from '../../stores/restaurantStore';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';
import {
  SkeletonBlock, SkeletonCard, SkeletonList, SkeletonChart,
  LaborDemandChart, RecommendationCard, FreshnessChip,
} from '../carafe';

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
          <div className="space-y-4" aria-busy="true" aria-live="polite">
            <SkeletonCard minH={96} />
            <SkeletonChart height={260} />
            <div className="space-y-2">
              <SkeletonBlock className="h-4 w-48" />
              <SkeletonList rows={3} rowHeight={88} />
            </div>
          </div>
        ) : !data ? (
          <LaborEmptyState />
        ) : (
          <>
            <section
              className="rounded-xl border p-4"
              style={{ background: 'white', borderColor: 'var(--line-soft)' }}
            >
              <div className="flex items-baseline justify-between gap-2 flex-wrap">
                <div className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
                  Median revenue per cover this window
                </div>
                <FreshnessChip
                  timestamp={`${data.window.end}T23:59:59`}
                  label="through"
                  freshUntilMinutes={36 * 60}
                  staleAfterMinutes={7 * 24 * 60}
                />
              </div>
              <div className="text-2xl font-extrabold tabular-nums mt-1" style={{ color: 'var(--ink)' }}>
                ${(data.median_rpc / 100).toFixed(2)}{' '}
                <span className="text-sm font-semibold" style={{ color: 'var(--slate)' }}>/ shift hour</span>
              </div>
              <p className="text-xs mt-1" style={{ color: 'var(--body)' }}>
                The chart below overlays staffing against demand by hour-of-day. Tinted bands flag windows
                running over- or under-staffed; the slow-window suggestions sit below as rec cards.
              </p>
            </section>

            <LaborDemandChart analysis={data} />

            <section>
              <h2 className="font-extrabold text-base mb-3 flex items-center gap-2" style={{ color: 'var(--ink)' }}>
                <Lightbulb size={16} style={{ color: 'var(--brand)' }} />
                Slow-window suggestions
              </h2>
              {data.slow_windows.length === 0 ? (
                <div
                  className="rounded-xl p-6 text-center text-sm flex flex-col items-center gap-2"
                  style={{ background: 'var(--money-positive-bg)', color: 'var(--money-positive)' }}
                >
                  <Lightbulb size={20} />
                  <div className="font-semibold">No slow windows in this period</div>
                  <div style={{ color: 'var(--slate)' }}>
                    Every hour with sales pulled its weight against the median.
                  </div>
                  <button
                    type="button"
                    onClick={() => setShowShiftForm(true)}
                    className="inline-flex items-center gap-1.5 mt-1 px-3 min-h-[40px] rounded-lg font-bold text-xs"
                    style={{ background: 'var(--brand)', color: 'white' }}
                  >
                    <Plus size={14} /> Add another shift
                  </button>
                </div>
              ) : (
                <ul className="space-y-2">
                  {data.slow_windows.slice(0, 8).map((s, i) => (
                    <li
                      key={`${s.date}-${s.hour}`}
                      className="stagger-in"
                      style={{ ['--stagger-i' as any]: i }}
                    >
                      <RecommendationCard
                        rec={slowWindowToRec(s, data.median_rpc)}
                        itemName={null}
                        density="comfortable"
                        readonly
                      />
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

function LaborEmptyState() {
  return (
    <section
      className="rounded-xl border-2 border-dashed p-6 sm:p-10 text-center flex flex-col items-center gap-4"
      style={{ background: 'white', borderColor: 'var(--brand-light)' }}
    >
      <span
        aria-hidden
        className="inline-flex items-center justify-center w-14 h-14 rounded-2xl"
        style={{ background: 'var(--brand-light)', color: 'var(--brand)' }}
      >
        <Users2 size={26} strokeWidth={2.2} />
      </span>
      <div className="space-y-1.5 max-w-md">
        <h2 className="font-extrabold text-lg" style={{ color: 'var(--ink)' }}>
          No labor data for this window
        </h2>
        <p className="text-sm" style={{ color: 'var(--body)' }}>
          Labor analysis needs at least one shift (or a POS feed with timecards) to compare
          against revenue. Add a shift to start finding slow-window opportunities.
        </p>
      </div>
      <p className="text-[11px]" style={{ color: 'var(--slate)' }}>
        Use the <strong>+ Shift</strong> button in the header to record your first one.
      </p>
    </section>
  );
}

/* Synthesizes a Recommendation-shaped object from a daypart slow-window
 * so it can render through the unified <RecommendationCard>. The dollar
 * anchor is the gap between this hour's revenue and the median revenue
 * pace — i.e. the addressable upside if this hour ran like a normal one.
 * The server-side slow-window endpoint isn't in the recommendations table
 * yet, so the card is shown in readonly mode (no Accept/Dismiss). */
function slowWindowToRec(
  s: { date: string; hour: number; revenue_cents: number; suggestion: string },
  medianRpc: number,
): Recommendation {
  // For the dollar estimate we use the slow-window's own revenue as the
  // addressable spend — honest framing of "this hour is on your books
  // generating $X; here's how to lift it." medianRpc enriches the payload
  // for the why-this expander.
  return {
    id: `slow-${s.date}-${s.hour}`,
    menu_item_id: null,
    kind: 'reposition',
    payload: { date: s.date, hour: s.hour, median_rpc_cents: medianRpc },
    narrative: `${s.date} · ${s.hour}:00 — ${s.suggestion}`,
    dollar_estimate_cents: s.revenue_cents,
    status: 'suggested',
    measured_impact_cents: null,
    created_at: new Date().toISOString(),
    decided_at: null,
    measured_at: null,
  };
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

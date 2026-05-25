import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Plus, Target, TrendingUp, TrendingDown, Trash2, RefreshCw } from 'lucide-react';
import { goalsApi, type Goal, type GoalMetric, type GoalCadence } from '../../api/restaurants';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';

const METRIC_LABEL: Record<GoalMetric, string> = {
  food_cost_pct:        'Food cost %',
  avg_check_cents:      'Avg check',
  margin_pct:           'Margin %',
  weekly_revenue_cents: 'Weekly revenue',
};
const CADENCES: GoalCadence[] = ['weekly', 'monthly', 'quarterly'];

/**
 * Goals — the operator scorecard. Each goal is a metric + target + cadence;
 * snapshots accumulate so the operator sees the trend, not just the
 * current period. "Lower is better" goals (food cost %) are colored
 * green when actual < target; "higher is better" (revenue, margin %)
 * are green when actual > target.
 */
export default function GoalsPage() {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  const [goals, setGoals] = useState<Goal[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);

  async function load() {
    try {
      setGoals(await goalsApi.list(restaurantId));
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed');
    }
  }

  useEffect(() => {
    if (!restaurantId) return;
    let cancelled = false;
    (async () => {
      try {
        const g = await goalsApi.list(restaurantId);
        if (!cancelled) setGoals(g);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed to load goals');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [restaurantId]);

  async function snapshot(g: Goal) {
    try {
      await goalsApi.snapshot(g.id);
      await load();
      toast.success('Snapshot recorded');
    } catch (e: any) { toast.error(e?.response?.data?.error ?? 'Failed'); }
  }

  async function destroy(g: Goal) {
    if (!confirm(`Archive goal "${formatMetricLabel(g)}"?`)) return;
    try {
      await goalsApi.destroy(g.id);
      await load();
    } catch (e: any) { toast.error(e?.response?.data?.error ?? 'Failed'); }
  }

  return (
    <RestaurantWorkspaceLayout>
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: '#1A1A2E' }}>
            <Target size={22} style={{ color: '#7848BB' }} /> Goals
          </h1>
          <button onClick={() => setShowCreate(true)} className="btn btn-primary h-9 px-3 text-sm">
            <Plus size={14} /> New goal
          </button>
        </div>

        <p className="text-sm text-slate-600">
          Set targets you measure the business by. Each cadence rolls into a snapshot — the trend tells you whether you're closing the gap.
        </p>

        {showCreate && (
          <CreateGoalForm
            onCancel={() => setShowCreate(false)}
            onCreate={async (input) => {
              try {
                await goalsApi.create(restaurantId, input);
                setShowCreate(false);
                await load();
                toast.success('Goal created');
              } catch (e: any) {
                toast.error(e?.response?.data?.error ?? 'Failed');
              }
            }}
          />
        )}

        {loading ? (
          <div className="space-y-2">
            <div className="skeleton h-24" /><div className="skeleton h-24" />
          </div>
        ) : goals.length === 0 && !showCreate ? (
          <div className="bg-slate-50 rounded-xl p-10 text-center text-sm text-slate-500">
            No goals yet. Add one to start tracking.
          </div>
        ) : (
          <ul className="space-y-3">
            {goals.map((g) => (
              <GoalCard key={g.id} goal={g} onSnapshot={() => snapshot(g)} onDelete={() => destroy(g)} />
            ))}
          </ul>
        )}
      </div>
    </RestaurantWorkspaceLayout>
  );
}

function GoalCard({ goal, onSnapshot, onDelete }: { goal: Goal; onSnapshot: () => void; onDelete: () => void }) {
  const recent = goal.recent_snapshots[0];
  const actual = recent ? Number(recent.actual_value) : null;
  const target = Number(goal.target_value);
  const lowerIsBetter = goal.metric === 'food_cost_pct';
  const onTrack = actual === null ? null : lowerIsBetter ? actual <= target : actual >= target;
  const trendIcon = onTrack === true ? <TrendingUp size={14} className="text-emerald-600" />
                  : onTrack === false ? <TrendingDown size={14} className="text-rose-600" />
                  : null;
  return (
    <li className="bg-white border border-slate-200 rounded-xl p-4">
      <div className="flex items-start gap-4">
        <div className="flex-1 min-w-0">
          <div className="flex items-baseline gap-2">
            <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>{formatMetricLabel(goal)}</div>
            <div className="text-[10px] uppercase tracking-wider font-bold text-slate-500">{goal.cadence}</div>
          </div>
          <div className="text-xs text-slate-500 mt-0.5">Target {formatValue(goal.metric, target)}</div>
          {actual !== null ? (
            <div className="flex items-center gap-2 mt-2">
              <span className="text-2xl font-extrabold tabular-nums" style={{ color: '#1A1A2E' }}>{formatValue(goal.metric, actual)}</span>
              {trendIcon}
              <span className={`text-xs font-semibold ${onTrack ? 'text-emerald-700' : 'text-rose-700'}`}>
                {onTrack ? 'on track' : 'off target'}
              </span>
            </div>
          ) : (
            <div className="text-sm text-slate-400 mt-2 italic">No data yet — click Snapshot to compute the current period.</div>
          )}
        </div>
        <div className="flex flex-col gap-1 flex-shrink-0">
          <button onClick={onSnapshot} className="p-1.5 text-slate-500 hover:text-violet-700 hover:bg-violet-50 rounded" title="Recompute current snapshot">
            <RefreshCw size={14} />
          </button>
          <button onClick={onDelete} className="p-1.5 text-slate-400 hover:text-rose-700 hover:bg-rose-50 rounded" title="Archive goal">
            <Trash2 size={14} />
          </button>
        </div>
      </div>
      {goal.recent_snapshots.length > 1 && (
        <div className="mt-3 pt-3 border-t border-slate-100">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Trend</div>
          <div className="flex items-end gap-1 h-8">
            {goal.recent_snapshots.slice().reverse().map((s) => {
              const v = Number(s.actual_value);
              const max = Math.max(target, ...goal.recent_snapshots.map((x) => Number(x.actual_value)));
              const h = Math.max(2, (v / Math.max(max, 1)) * 100);
              const ok = lowerIsBetter ? v <= target : v >= target;
              return (
                <div
                  key={s.period_start + s.period_end}
                  className="flex-1 rounded-t"
                  title={`${s.period_start} → ${s.period_end}: ${formatValue(goal.metric, v)}`}
                  style={{ height: `${h}%`, background: ok ? '#10b981' : '#f59e0b', opacity: 0.7 }}
                />
              );
            })}
          </div>
        </div>
      )}
    </li>
  );
}

function CreateGoalForm({ onCancel, onCreate }: { onCancel: () => void; onCreate: (g: { metric: GoalMetric; target_value: number; cadence: GoalCadence; label?: string }) => void }) {
  const [metric, setMetric] = useState<GoalMetric>('food_cost_pct');
  const [targetInput, setTargetInput] = useState('30');
  const [cadence, setCadence] = useState<GoalCadence>('monthly');
  const [label, setLabel] = useState('');

  function submit() {
    const n = Number(targetInput);
    if (!n || n <= 0) { toast.error('target_value > 0 required'); return; }
    const target = metric === 'food_cost_pct' || metric === 'margin_pct'
      ? n / 100   // store pct as 0.30 not 30
      : n * 100;  // store cents
    onCreate({ metric, target_value: target, cadence, label: label || undefined });
  }

  return (
    <div className="bg-slate-50 rounded-xl p-4 space-y-3">
      <div className="grid grid-cols-12 gap-3">
        <label className="col-span-6 md:col-span-4 block">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Metric</div>
          <select className="input h-9 text-sm w-full" value={metric} onChange={(e) => setMetric(e.target.value as GoalMetric)}>
            {Object.entries(METRIC_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
        <label className="col-span-6 md:col-span-4 block">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">
            Target {metric === 'food_cost_pct' || metric === 'margin_pct' ? '(%)' : '($)'}
          </div>
          <input className="input h-9 text-sm w-full" type="number" min={0} step={0.01} value={targetInput} onChange={(e) => setTargetInput(e.target.value)} />
        </label>
        <label className="col-span-6 md:col-span-4 block">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Cadence</div>
          <select className="input h-9 text-sm w-full" value={cadence} onChange={(e) => setCadence(e.target.value as GoalCadence)}>
            {CADENCES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </label>
        <label className="col-span-12 block">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Label (optional)</div>
          <input className="input h-9 text-sm w-full" value={label} onChange={(e) => setLabel(e.target.value)} placeholder="e.g. Q2 food cost target" />
        </label>
      </div>
      <div className="flex gap-2">
        <button className="btn btn-primary h-9 px-3 text-sm" onClick={submit}>Create</button>
        <button className="btn h-9 px-3 text-sm" onClick={onCancel}>Cancel</button>
      </div>
    </div>
  );
}

function formatMetricLabel(g: Goal): string {
  return g.label ?? METRIC_LABEL[g.metric];
}

function formatValue(metric: GoalMetric, v: number): string {
  if (metric === 'food_cost_pct' || metric === 'margin_pct') return `${Math.round(v * 100)}%`;
  return '$' + Math.round(v / 100).toLocaleString();
}

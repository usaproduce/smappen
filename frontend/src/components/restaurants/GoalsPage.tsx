import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  Plus, Target, TrendingUp, TrendingDown, Trash2, RefreshCw,
  CheckCircle2, AlertTriangle, AlertOctagon,
} from 'lucide-react';
import { goalsApi, type Goal, type GoalMetric, type GoalCadence } from '../../api/restaurants';
import RestaurantWorkspaceLayout from './RestaurantWorkspaceLayout';
import { SkeletonBlock, SkeletonList, MoneyStat, FreshnessChip } from '../carafe';

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
          <div className="space-y-3" aria-busy="true" aria-live="polite">
            <SkeletonBlock className="h-3 w-72" />
            <SkeletonList rows={3} rowHeight={104} />
          </div>
        ) : goals.length === 0 && !showCreate ? (
          <GoalsEmptyState onCreate={() => setShowCreate(true)} />
        ) : (
          <ul className="grid grid-cols-1 lg:grid-cols-2 gap-3 sm:gap-4">
            {goals.map((g, i) => (
              <GoalCard
                key={g.id}
                goal={g}
                onSnapshot={() => snapshot(g)}
                onDelete={() => destroy(g)}
                staggerIndex={i}
              />
            ))}
          </ul>
        )}
      </div>
    </RestaurantWorkspaceLayout>
  );
}

function GoalsEmptyState({ onCreate }: { onCreate: () => void }) {
  const SUGGESTED = [
    { metric: 'food_cost_pct',        label: 'Food cost ≤ 30%',         desc: 'The single biggest lever on margin.' },
    { metric: 'margin_pct',           label: 'Contribution margin ≥ 65%', desc: 'The complement — track the goal you sell from.' },
    { metric: 'weekly_revenue_cents', label: 'Weekly revenue target',     desc: 'Anchor every week against a number.' },
  ] as const;
  return (
    <section
      className="rounded-xl border-2 border-dashed p-6 sm:p-10 flex flex-col items-center gap-5 text-center"
      style={{ background: 'white', borderColor: 'var(--brand-light)' }}
    >
      <span
        aria-hidden
        className="inline-flex items-center justify-center w-14 h-14 rounded-2xl"
        style={{ background: 'var(--brand-light)', color: 'var(--brand)' }}
      >
        <Target size={26} strokeWidth={2.2} />
      </span>
      <div className="space-y-1.5 max-w-md">
        <h2 className="font-extrabold text-lg" style={{ color: 'var(--ink)' }}>
          Set the numbers you run the business by
        </h2>
        <p className="text-sm" style={{ color: 'var(--body)' }}>
          Each goal snapshots on its cadence so you see the trend, not just today's number.
          Start with one — most operators add three or four over the first month.
        </p>
      </div>
      <ul className="grid grid-cols-1 sm:grid-cols-3 gap-3 w-full max-w-2xl">
        {SUGGESTED.map((s) => (
          <li
            key={s.metric}
            className="rounded-lg border p-3 text-left"
            style={{ background: 'var(--bg-panel)', borderColor: 'var(--line-soft)' }}
          >
            <div className="font-bold text-sm" style={{ color: 'var(--ink)' }}>{s.label}</div>
            <div className="text-[11px] mt-1" style={{ color: 'var(--slate)' }}>{s.desc}</div>
          </li>
        ))}
      </ul>
      <button
        type="button"
        onClick={onCreate}
        className="inline-flex items-center gap-1.5 px-4 min-h-[44px] rounded-lg font-bold text-sm text-white"
        style={{ background: 'var(--brand)' }}
      >
        <Plus size={16} /> New goal
      </button>
    </section>
  );
}

/* ── Scorecard card ───────────────────────────────────────────────────
   Hierarchy designed for a <2 second scan:
     row 1 — metric name + cadence pill + status badge (icon+label+color)
     row 2 — large current value + delta vs target
     row 3 — ring (% to target) on the left, sparkline on the right
   Restrained celebration: when the goal is hit, the status badge swaps
   to a checkmark with "Hit" — no fireworks. Border tints green so the
   card reads as "won" at a glance without overpowering the rest. */
function GoalCard({ goal, onSnapshot, onDelete, staggerIndex = 0 }: {
  goal: Goal; onSnapshot: () => void; onDelete: () => void; staggerIndex?: number;
}) {
  const target = Number(goal.target_value);
  const recent = goal.recent_snapshots[0];          // recent_snapshots is newest-first
  const actual = recent ? Number(recent.actual_value) : null;
  const status = computeStatus(actual, target, goal.metric);
  const meta = STATUS_META[status];
  const StatusIcon = meta.Icon;
  const progress = actual == null ? null : computeProgressPct(actual, target, goal.metric);

  // For sparkline: chronological (oldest → newest).
  const series = [...goal.recent_snapshots].reverse();
  const hasHistory = series.length >= 2;

  return (
    <li
      className="stagger-in rounded-xl border p-4 sm:p-5 flex flex-col gap-3"
      style={{
        background: 'white',
        borderColor: status === 'hit' ? 'var(--money-positive)' : 'var(--line-soft)',
        borderWidth: status === 'hit' ? 2 : 1,
        ['--stagger-i' as any]: staggerIndex,
      }}
      aria-label={`${formatMetricLabel(goal)} — ${meta.label}`}
    >
      {/* ── Header row ─────────────────────────────────────────────── */}
      <div className="flex items-start justify-between gap-2 flex-wrap">
        <div className="min-w-0">
          <div className="font-extrabold text-base truncate" style={{ color: 'var(--ink)' }}>
            {formatMetricLabel(goal)}
          </div>
          <div className="flex items-center gap-2 mt-0.5 flex-wrap">
            <span
              className="text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
              style={{ background: 'var(--bg-panel)', color: 'var(--slate)' }}
            >
              {goal.cadence}
            </span>
            <span className="text-[11px]" style={{ color: 'var(--slate)' }}>
              Target {formatValue(goal.metric, target)}
            </span>
            {recent && (
              <FreshnessChip
                timestamp={`${recent.period_end}T23:59:59`}
                label="snapshot"
                size="xs"
                freshUntilMinutes={cadenceFreshMinutes(goal.cadence)}
                staleAfterMinutes={cadenceStaleMinutes(goal.cadence)}
              />
            )}
          </div>
        </div>
        <div className="flex items-center gap-1 flex-shrink-0">
          <StatusBadge status={status} />
          <button
            onClick={onSnapshot}
            aria-label="Recompute current snapshot"
            title="Recompute current snapshot"
            className="inline-flex items-center justify-center w-11 h-11 rounded-lg hover:bg-slate-50"
            style={{ color: 'var(--slate)' }}
          >
            <RefreshCw size={16} />
          </button>
          <button
            onClick={onDelete}
            aria-label="Archive goal"
            title="Archive goal"
            className="inline-flex items-center justify-center w-11 h-11 rounded-lg hover:bg-rose-50"
            style={{ color: 'var(--slate)' }}
          >
            <Trash2 size={16} />
          </button>
        </div>
      </div>

      {/* ── Hero value + delta ────────────────────────────────────── */}
      <div className="flex items-baseline justify-between gap-3 flex-wrap">
        {actual === null ? (
          <div className="text-sm italic" style={{ color: 'var(--slate)' }}>
            No data yet — click the refresh icon to compute this period.
          </div>
        ) : isPercentMetric(goal.metric) ? (
          <div
            className="font-extrabold leading-none tabular-nums text-4xl"
            style={{ color: meta.figureColor }}
          >
            {(actual * 100).toFixed(1)}<span className="text-xl ml-1" style={{ color: 'var(--slate)' }}>%</span>
          </div>
        ) : (
          <MoneyStat
            label=""
            value={Math.round(actual / 100)}
            size="lg"
            tone={status === 'hit' || status === 'on_track' ? 'positive' : status === 'at_risk' ? 'neutral' : 'negative'}
            durationMs={240}
            className="-my-1"
          />
        )}
        {actual !== null && (
          <GoalDelta metric={goal.metric} actual={actual} target={target} />
        )}
      </div>

      {/* ── Ring + sparkline ──────────────────────────────────────── */}
      <div className="flex items-center gap-4 flex-wrap">
        <ProgressRing pct={progress} color={meta.figureColor} />
        <div className="flex-1 min-w-[160px]">
          <div
            className="text-[9px] font-bold uppercase tracking-wider mb-1"
            style={{ color: 'var(--slate)' }}
          >
            {hasHistory ? `Last ${series.length} ${pluralCadence(goal.cadence, series.length)}` : 'Trend'}
          </div>
          {hasHistory ? (
            <GoalSparkline
              series={series.map((s) => Number(s.actual_value))}
              target={target}
              metric={goal.metric}
              color={meta.figureColor}
            />
          ) : (
            <div
              className="text-[11px] rounded-md px-2 py-1.5"
              style={{ color: 'var(--slate)', background: 'var(--bg-panel)' }}
            >
              Not enough history yet — at least two snapshots build the trend line.
            </div>
          )}
        </div>
      </div>
    </li>
  );
}

/* ── Status logic ─────────────────────────────────────────────────────── */
type GoalStatus = 'hit' | 'on_track' | 'at_risk' | 'off_track' | 'unknown';

const STATUS_META: Record<GoalStatus, {
  label: string;
  figureColor: string;
  badgeBg: string;
  badgeFg: string;
  Icon: typeof CheckCircle2;
}> = {
  hit:       { label: 'Hit',       figureColor: 'var(--money-positive)', badgeBg: 'var(--money-positive-bg)', badgeFg: 'var(--money-positive)', Icon: CheckCircle2 },
  on_track:  { label: 'On track',  figureColor: 'var(--money-positive)', badgeBg: 'var(--money-positive-bg)', badgeFg: 'var(--money-positive)', Icon: TrendingUp },
  at_risk:   { label: 'At risk',   figureColor: '#92670E',                badgeBg: 'var(--fresh-aging-bg)',    badgeFg: '#92670E',                Icon: AlertTriangle },
  off_track: { label: 'Off track', figureColor: 'var(--money-negative)',  badgeBg: 'var(--money-negative-bg)', badgeFg: 'var(--money-negative)',  Icon: AlertOctagon },
  unknown:   { label: 'No data',   figureColor: 'var(--ink)',             badgeBg: 'var(--bg-panel)',          badgeFg: 'var(--slate)',           Icon: TrendingDown },
};

function isLowerBetter(metric: GoalMetric): boolean {
  return metric === 'food_cost_pct';
}

function isPercentMetric(metric: GoalMetric): boolean {
  return metric === 'food_cost_pct' || metric === 'margin_pct';
}

function computeStatus(actual: number | null, target: number, metric: GoalMetric): GoalStatus {
  if (actual === null || !Number.isFinite(target) || target <= 0) return 'unknown';
  const ratio = isLowerBetter(metric) ? target / actual : actual / target;
  if (ratio >= 1)    return 'hit';
  if (ratio >= 0.95) return 'on_track';
  if (ratio >= 0.80) return 'at_risk';
  return 'off_track';
}

/** Progress toward target, 0..1, capped at 1.2 for "over-shot" cases. */
function computeProgressPct(actual: number, target: number, metric: GoalMetric): number {
  if (target <= 0) return 0;
  if (isLowerBetter(metric)) {
    // For "lower is better": full ring when actual ≤ target. As actual grows past target, ring shrinks.
    if (actual <= 0) return 1;
    return Math.max(0, Math.min(1.2, target / actual));
  }
  return Math.max(0, Math.min(1.2, actual / target));
}

function StatusBadge({ status }: { status: GoalStatus }) {
  const meta = STATUS_META[status];
  const Icon = meta.Icon;
  return (
    <span
      role="status"
      aria-label={meta.label}
      className="inline-flex items-center gap-1.5 px-2 h-7 rounded-full text-[11px] font-bold whitespace-nowrap"
      style={{ background: meta.badgeBg, color: meta.badgeFg }}
    >
      <Icon size={12} strokeWidth={2.6} aria-hidden />
      {meta.label}
    </span>
  );
}

/* ── Delta vs target — colored DollarDelta-style chip ─────────────────── */
function GoalDelta({ metric, actual, target }: { metric: GoalMetric; actual: number; target: number }) {
  const lowerBetter = isLowerBetter(metric);
  const diff = actual - target;
  if (Math.abs(diff) < 0.0001) {
    return (
      <span className="text-xs font-semibold" style={{ color: 'var(--money-positive)' }}>
        On target
      </span>
    );
  }
  const good = lowerBetter ? diff < 0 : diff > 0;
  const Icon = diff > 0 ? TrendingUp : TrendingDown;
  const color = good ? 'var(--money-positive)' : 'var(--money-negative)';
  const sign = diff > 0 ? '+' : '−';
  const magnitude = Math.abs(diff);
  return (
    <span
      className="inline-flex items-center gap-1 text-xs font-semibold"
      style={{ color }}
      aria-label={`${good ? 'good' : 'bad'} delta ${sign}${formatMagnitude(metric, magnitude)} versus target`}
    >
      <Icon size={12} strokeWidth={2.6} aria-hidden />
      {sign}{formatMagnitude(metric, magnitude)}
      <span className="font-medium" style={{ color: 'var(--slate)' }}>vs target</span>
    </span>
  );
}

function formatMagnitude(metric: GoalMetric, v: number): string {
  if (isPercentMetric(metric)) return `${(v * 100).toFixed(1)}pp`;
  return '$' + Math.round(v / 100).toLocaleString();
}

/* ── Progress ring — pure SVG, no extra dependency ─────────────────────
   Stroke width thick enough to read at 84px; background ring uses a calm
   neutral tint so the filled arc stays the dominant visual. */
function ProgressRing({ pct, color }: { pct: number | null; color: string }) {
  const size = 84;
  const stroke = 9;
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const safe = pct == null ? 0 : Math.max(0, Math.min(1, pct));
  const dash = c * safe;
  const display = pct == null
    ? '—'
    : pct >= 1
      ? '100%'
      : `${Math.round(pct * 100)}%`;
  return (
    <div
      className="relative flex-shrink-0"
      style={{ width: size, height: size }}
      aria-label={pct == null ? 'No progress data' : `${Math.round(safe * 100)} percent to target`}
      role="img"
    >
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
        <circle
          cx={size / 2} cy={size / 2} r={r}
          fill="none"
          stroke="var(--bg-panel)"
          strokeWidth={stroke}
        />
        <circle
          cx={size / 2} cy={size / 2} r={r}
          fill="none"
          stroke={color}
          strokeWidth={stroke}
          strokeDasharray={`${dash} ${c - dash}`}
          strokeDashoffset={c / 4} /* start at 12 o'clock */
          strokeLinecap="round"
          transform={`rotate(-90 ${size / 2} ${size / 2})`}
          style={{ transition: 'stroke-dasharray 0.6s cubic-bezier(0.16, 1, 0.3, 1)' }}
        />
      </svg>
      <div
        className="absolute inset-0 flex flex-col items-center justify-center text-center"
        style={{ color: 'var(--ink)' }}
      >
        <span className="text-base font-extrabold tabular-nums leading-none">{display}</span>
        <span className="text-[9px] font-bold uppercase tracking-wider mt-0.5" style={{ color: 'var(--slate)' }}>
          to target
        </span>
      </div>
    </div>
  );
}

/* ── Goal sparkline — chronological, with target line + per-stop dot ─── */
function GoalSparkline({
  series, target, metric, color,
}: { series: number[]; target: number; metric: GoalMetric; color: string }) {
  const w = 220, h = 48, pad = 4;
  // Anchor max to include the target so the marker line lives in the
  // visible range even when actuals are clustered far from it.
  const max = Math.max(target * 1.1, ...series.map((v) => v), 0.0001);
  const min = Math.min(0, target * 0.9, ...series);
  const range = Math.max(max - min, 0.0001);
  const step = series.length > 1 ? (w - pad * 2) / (series.length - 1) : 0;
  const points = series.map((v, i) => {
    const x = pad + i * step;
    const y = pad + (1 - (v - min) / range) * (h - pad * 2);
    return [x, y] as const;
  });
  const path = points.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ');
  const area = `${path} L ${points[points.length - 1][0]} ${h - pad} L ${pad} ${h - pad} Z`;
  const targetY = pad + (1 - (target - min) / range) * (h - pad * 2);
  const last = points[points.length - 1];
  const lowerBetter = isLowerBetter(metric);

  return (
    <svg width="100%" height={h} viewBox={`0 0 ${w} ${h}`} role="img" aria-label="Recent trend">
      <path d={area} fill={color} opacity={0.10} />
      {/* Target reference line. */}
      <line
        x1={pad} y1={targetY} x2={w - pad} y2={targetY}
        stroke="var(--line)"
        strokeWidth={1}
        strokeDasharray="3 3"
      />
      <text
        x={w - pad} y={targetY - 3}
        fontSize="9" fontWeight={700}
        fill="var(--slate)"
        textAnchor="end"
      >
        target
      </text>
      <path d={path} fill="none" stroke={color} strokeWidth={1.75} strokeLinejoin="round" strokeLinecap="round" />
      {points.map(([x, y], i) => {
        const v = series[i];
        const ok = lowerBetter ? v <= target : v >= target;
        return (
          <circle
            key={i}
            cx={x} cy={y} r={2}
            fill={ok ? color : 'var(--money-negative)'}
            stroke="white" strokeWidth={1}
          />
        );
      })}
      {last && (
        <circle cx={last[0]} cy={last[1]} r={3.5} fill={color} stroke="white" strokeWidth={1.5} />
      )}
    </svg>
  );
}

/* Cadence-aware freshness thresholds for the snapshot chip. A weekly
 * goal is "fresh" for a couple days, monthly for a few weeks, quarterly
 * for ~a month. Past stale, the chip turns red to nudge a re-snapshot. */
function cadenceFreshMinutes(c: GoalCadence): number {
  if (c === 'weekly')    return 48 * 60;          // 2 days
  if (c === 'monthly')   return 14 * 24 * 60;     // 2 weeks
  return 45 * 24 * 60;                            // 45 days for quarterly
}
function cadenceStaleMinutes(c: GoalCadence): number {
  if (c === 'weekly')    return 7 * 24 * 60;      // 1 week
  if (c === 'monthly')   return 35 * 24 * 60;     // 5 weeks
  return 100 * 24 * 60;                           // ~3 months for quarterly
}

function pluralCadence(c: GoalCadence, n: number): string {
  const single = c === 'weekly' ? 'week' : c === 'monthly' ? 'month' : 'quarter';
  return n === 1 ? single : single + 's';
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

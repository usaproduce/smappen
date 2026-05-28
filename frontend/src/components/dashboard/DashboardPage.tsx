import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Plus, ArrowRight, Sparkles, ChefHat, Building2, Map as MapIcon,
  TrendingUp, TrendingDown, DollarSign, Receipt, Utensils, Users,
  AlertTriangle, CheckCircle2, Circle, Plug, Activity, Crown,
  Gauge, Wifi, Lightbulb, Keyboard, Bell, BarChart3, PieChart,
  Flame, ListChecks, Globe, X, Brain, Target, Calendar, TimerReset,
  Trophy, Layers, BellPlus, Trash2, Pin, PinOff, Eye, EyeOff,
} from 'lucide-react';
import { restaurantsApi } from '../../api/restaurants';
import { projectsApi } from '../../api/projects';
import { api } from '../../api/client';
import { statsApi, type RestaurantsOverview, type DashboardStats, type RangeKey } from '../../api/stats';
import { dashboardApi, type DashboardBriefing, type DashboardAlert, type DashboardAlertInput } from '../../api/dashboard';
import { billingApi } from '../../api/billing';
import { useAuthStore } from '../../stores/authStore';
import type { Restaurant } from '../../stores/restaurantStore';
import { formatNumber, formatCompact } from '../../utils/format';
import AppNav from '../layout/AppNav';
import type { Project, PlanLimitsResponse } from '../../types';

/**
 * /dashboard — landing page after login.
 *
 * 25-widget customer-value brainstorm — predictive, anomaly, benchmark,
 * drill-down, action-oriented, cost intel, cross-restaurant, UX.
 */

type WidgetKey =
  | 'briefing' | 'kpis' | 'today' | 'whatChanged' | 'preShift'
  | 'goalTracker' | 'topRecs' | 'pinnedActions' | 'approvals'
  | 'revenueTrend' | 'eomProjection' | 'nextWeek' | 'laborRisk' | 'varianceCard'
  | 'heatmap' | 'leaderboard' | 'industryBench'
  | 'costDrivers' | 'recipeDrift' | 'itemVelocity' | 'vendorScorecard'
  | 'repeatRate' | 'thresholdAlerts'
  | 'salesMix' | 'daypart' | 'topItems' | 'recFunnel' | 'menuCoverage'
  | 'posHealth' | 'onboarding' | 'plan' | 'activity' | 'usage'
  | 'tip' | 'whatsNew' | 'shortcuts' | 'system' | 'mapping' | 'products';

const DEFAULT_VISIBILITY: Record<WidgetKey, boolean> = {
  briefing: true, kpis: true, today: true, whatChanged: true, preShift: true,
  goalTracker: true, topRecs: true, pinnedActions: true, approvals: true,
  revenueTrend: true, eomProjection: true, nextWeek: true, laborRisk: true, varianceCard: true,
  heatmap: true, leaderboard: true, industryBench: true,
  costDrivers: true, recipeDrift: true, itemVelocity: true, vendorScorecard: true,
  repeatRate: true, thresholdAlerts: true,
  salesMix: true, daypart: true, topItems: true, recFunnel: true, menuCoverage: true,
  posHealth: true, onboarding: true, plan: true, activity: true, usage: true,
  tip: true, whatsNew: true, shortcuts: true, system: true, mapping: true, products: true,
};

const ROLE_PRESETS: Record<string, Partial<Record<WidgetKey, boolean>>> = {
  owner: {},
  gm: { mapping: false, products: false, usage: false, vendorScorecard: false },
  lunch: {
    eomProjection: false, nextWeek: false, laborRisk: false, varianceCard: false,
    leaderboard: false, industryBench: false, costDrivers: false, recipeDrift: false,
    vendorScorecard: false, repeatRate: false, mapping: false, plan: false, usage: false,
  },
};

export default function DashboardPage() {
  const user = useAuthStore((s) => s.user) as any;

  const [range, setRange] = useState<RangeKey>(() =>
    (localStorage.getItem('sm_dash_range') as RangeKey) || 'mtd'
  );
  const [overview, setOverview] = useState<RestaurantsOverview | null>(null);
  const [restaurants, setRestaurants] = useState<Restaurant[]>([]);
  const [mapStats, setMapStats] = useState<DashboardStats | null>(null);
  const [projects, setProjects] = useState<Project[]>([]);
  const [activity, setActivity] = useState<any[]>([]);
  const [sub, setSub] = useState<PlanLimitsResponse | null>(null);
  const [briefing, setBriefing] = useState<DashboardBriefing | null>(null);
  const [alerts, setAlerts] = useState<DashboardAlert[]>([]);
  const [loading, setLoading] = useState(true);
  const [drilldown, setDrilldown] = useState<DrilldownState | null>(null);
  const [customizing, setCustomizing] = useState(false);

  const [pinned, setPinned] = useState<string[]>(() => {
    try { return JSON.parse(localStorage.getItem('sm_dash_pinned') || '[]'); } catch { return []; }
  });
  const togglePin = (id: string) => {
    setPinned((prev) => {
      const next = prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id];
      localStorage.setItem('sm_dash_pinned', JSON.stringify(next));
      return next;
    });
  };

  const [visibility, setVisibility] = useState<Record<WidgetKey, boolean>>(() => {
    try {
      const stored = JSON.parse(localStorage.getItem('sm_dash_widgets') || '{}');
      return { ...DEFAULT_VISIBILITY, ...stored };
    } catch { return DEFAULT_VISIBILITY; }
  });
  const setVisible = (k: WidgetKey, v: boolean) => {
    setVisibility((prev) => {
      const next = { ...prev, [k]: v };
      localStorage.setItem('sm_dash_widgets', JSON.stringify(next));
      return next;
    });
  };
  const applyPreset = (preset: keyof typeof ROLE_PRESETS) => {
    const merged = { ...DEFAULT_VISIBILITY, ...ROLE_PRESETS[preset] };
    setVisibility(merged);
    localStorage.setItem('sm_dash_widgets', JSON.stringify(merged));
    localStorage.setItem('sm_dash_preset', preset);
  };

  useEffect(() => {
    localStorage.setItem('sm_dash_range', range);
    let cancelled = false;
    setLoading(true);
    Promise.allSettled([
      statsApi.restaurantsOverview(range),
      restaurantsApi.list(),
      statsApi.dashboard(),
      projectsApi.list({ per_page: 5 }),
      api.get('/api/activity'),
      billingApi.subscription(),
      dashboardApi.briefing().catch(() => null),
      dashboardApi.alerts().catch(() => []),
    ]).then((results) => {
      if (cancelled) return;
      const [o, rs, ms, p, a, s, br, al] = results;
      if (o.status === 'fulfilled') setOverview(o.value);
      if (rs.status === 'fulfilled') setRestaurants(rs.value);
      if (ms.status === 'fulfilled') setMapStats(ms.value);
      if (p.status === 'fulfilled') setProjects(p.value.data ?? []);
      if (a.status === 'fulfilled') setActivity(a.value.data?.data?.activity ?? []);
      if (s.status === 'fulfilled') setSub(s.value);
      if (br.status === 'fulfilled' && br.value) setBriefing(br.value);
      if (al.status === 'fulfilled') setAlerts(al.value as any);
      setLoading(false);
    });
    return () => { cancelled = true; };
  }, [range]);

  const lastRestaurant = useMemo(() => {
    if (!restaurants.length) return null;
    return [...restaurants].sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? ''))[0];
  }, [restaurants]);

  const pinnedRecs = useMemo(() => {
    if (!overview) return [];
    return (overview.top_recommendations ?? []).filter((r) => pinned.includes(r.id));
  }, [overview, pinned]);

  const ranges: { k: RangeKey; label: string }[] = [
    { k: 'today', label: 'Today' }, { k: 'wtd', label: 'WTD' },
    { k: 'mtd', label: 'MTD' }, { k: 'ytd', label: 'YTD' },
    { k: '7d', label: '7d' }, { k: '30d', label: '30d' },
  ];

  return (
    <div className="min-h-screen bg-white">
      <AppNav />
      {drilldown && <DrilldownModal state={drilldown} onClose={() => setDrilldown(null)} />}

      <main className="max-w-7xl mx-auto px-6 py-6">
        <div className="flex items-end justify-between mb-5">
          <GreetingHeader user={user} lastRestaurantId={lastRestaurant?.id} />
          <div className="hidden md:flex flex-col items-end gap-2">
            <div className="inline-flex rounded-lg border border-slate-200 bg-white p-0.5">
              {ranges.map(({ k, label }) => (
                <button
                  key={k}
                  onClick={() => setRange(k)}
                  className={`px-2.5 py-1 text-xs font-semibold rounded-md transition-colors ${
                    range === k ? 'bg-violet-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                  }`}
                >{label}</button>
              ))}
            </div>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setCustomizing((v) => !v)}
                className="text-[11px] font-semibold text-slate-500 hover:text-violet-700 inline-flex items-center gap-1"
              >
                <Layers size={11} /> {customizing ? 'Done' : 'Customize'}
              </button>
              <select
                onChange={(e) => applyPreset(e.target.value as any)}
                defaultValue={localStorage.getItem('sm_dash_preset') ?? ''}
                className="text-[11px] font-semibold text-slate-500 bg-transparent border-0 outline-none cursor-pointer hover:text-violet-700"
              >
                <option value="" disabled>Layout…</option>
                <option value="owner">Owner</option>
                <option value="gm">GM</option>
                <option value="lunch">Lunch manager</option>
              </select>
            </div>
          </div>
        </div>

        {customizing && <CustomizePanel visibility={visibility} setVisible={setVisible} />}

        {visibility.briefing && <BriefingCard briefing={briefing} />}
        {visibility.kpis && (
          <KpiStrip overview={overview} loading={loading} onDrill={(m) => setDrilldown({ metric: m, overview })} />
        )}
        {visibility.today && <TodayServiceStrip overview={overview} />}

        <QuickActions lastRestaurantId={lastRestaurant?.id} />

        <div className="grid lg:grid-cols-3 gap-4 mt-5">
          {visibility.whatChanged && <WhatChangedCard overview={overview} />}
          {visibility.preShift && <PreShiftCard overview={overview} />}
          {visibility.goalTracker && <GoalTrackerCard overview={overview} />}
        </div>

        <div className="grid lg:grid-cols-3 gap-4 mt-4">
          {visibility.topRecs && (
            <div className="lg:col-span-2">
              <TopRecommendationsCard overview={overview} pinned={pinned} onPin={togglePin} />
            </div>
          )}
          {visibility.pinnedActions && <PinnedActionsCard pinned={pinnedRecs} onPin={togglePin} />}
        </div>

        {visibility.approvals && <ApprovalsCard overview={overview} className="mt-4" />}

        <div className="grid lg:grid-cols-3 gap-4 mt-6">
          {visibility.revenueTrend && (
            <div className="lg:col-span-2"><RevenueTrendCard overview={overview} /></div>
          )}
          {visibility.eomProjection && <EomProjectionCard overview={overview} />}
        </div>

        <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-4 mt-4">
          {visibility.nextWeek && <NextWeekForecastCard overview={overview} />}
          {visibility.laborRisk && <LaborRiskCard overview={overview} />}
          {visibility.varianceCard && <VarianceDecompCard overview={overview} />}
          {visibility.industryBench && <IndustryBenchmarkCard overview={overview} />}
        </div>

        {visibility.heatmap && <HeatmapCard overview={overview} loading={loading} />}

        <div className="grid lg:grid-cols-2 gap-4 mt-4">
          {visibility.leaderboard && <LeaderboardCard overview={overview} />}
          {visibility.itemVelocity && <ItemVelocityCard overview={overview} />}
        </div>

        <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-4 mt-4">
          {visibility.costDrivers && <CostDriversCard overview={overview} />}
          {visibility.recipeDrift && <RecipeDriftCard overview={overview} />}
          {visibility.vendorScorecard && <VendorScorecardCard />}
          {visibility.repeatRate && <RepeatCustomerCard />}
        </div>

        {visibility.thresholdAlerts && (
          <ThresholdAlertsCard
            alerts={alerts}
            onCreate={async (input) => {
              await dashboardApi.createAlert(input);
              const fresh = await dashboardApi.alerts();
              setAlerts(fresh);
            }}
            onDelete={async (id) => {
              await dashboardApi.deleteAlert(id);
              setAlerts((prev) => prev.filter((a) => a.id !== id));
            }}
            className="mt-4"
          />
        )}

        <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-4 mt-4">
          {visibility.salesMix && <SalesMixCard items={overview?.sales_by_category ?? []} title="Sales by category" icon={PieChart} />}
          {visibility.daypart && <DaypartCard overview={overview} />}
          {visibility.topItems && <TopMenuItemsCard overview={overview} />}
          {visibility.recFunnel && <RecommendationFunnelCard overview={overview} />}
        </div>

        <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-4 mt-4">
          {visibility.menuCoverage && <MenuCoverageCard overview={overview} />}
          {visibility.posHealth && <PosHealthCard overview={overview} />}
          {visibility.onboarding && <OnboardingChecklist overview={overview} />}
          {visibility.plan && <PlanCard sub={sub} />}
        </div>

        <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-4 mt-4 mb-10">
          {visibility.activity && <ActivityCard activity={activity} />}
          {visibility.usage && <UsageProgressCard sub={sub} />}
          {visibility.tip && <TipOfDayCard />}
          {visibility.whatsNew && <WhatsNewCard />}
          {visibility.shortcuts && <ShortcutsCard />}
          {visibility.system && <SystemStatusCard />}
          {visibility.mapping && <MappingModuleCard projects={projects} mapStats={mapStats} />}
          {visibility.products && <ProductsCard />}
        </div>
      </main>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────
function money(cents: number, opts: { compact?: boolean } = {}): string {
  const n = (cents ?? 0) / 100;
  if (opts.compact && Math.abs(n) >= 1000) {
    return '$' + new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
  }
  return '$' + new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(n);
}
function pct(v: number | null | undefined, decimals = 1): string {
  if (v === null || v === undefined) return '—';
  return (v * 100).toFixed(decimals) + '%';
}
function pp(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—';
  return (v >= 0 ? '+' : '') + (v * 100).toFixed(1) + 'pp';
}
function relTime(s: string | null | undefined): string {
  if (!s) return '';
  const t = new Date(s).getTime();
  const d = (Date.now() - t) / 1000;
  if (d < 60) return 'just now';
  if (d < 3600) return Math.round(d / 60) + 'm ago';
  if (d < 86400) return Math.round(d / 3600) + 'h ago';
  if (d < 604800) return Math.round(d / 86400) + 'd ago';
  return new Date(s).toLocaleDateString();
}
function toneFor(value: number, warn: number, bad: number): 'good' | 'warn' | 'bad' {
  if (value >= bad) return 'bad';
  if (value >= warn) return 'warn';
  return 'good';
}

function GreetingHeader({ user, lastRestaurantId }: { user: any; lastRestaurantId?: string }) {
  const now = new Date();
  const hour = now.getHours();
  const greet = hour < 5 ? 'Working late' : hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  const first = user?.name ? user.name.split(' ')[0] : '';
  const dayLabel = now.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
  return (
    <div>
      <div className="text-xs font-bold uppercase tracking-wider text-violet-600 mb-1">{dayLabel}</div>
      <h1 className="text-3xl font-extrabold" style={{ color: '#1A1A2E' }}>
        {greet}{first ? `, ${first}` : ''}
      </h1>
      <p className="text-slate-600 mt-1">
        Your restaurants at a glance — revenue, cost, and what to do next.{' '}
        {lastRestaurantId && (
          <Link to={`/app/restaurants/${lastRestaurantId}`} className="font-bold text-violet-700 hover:underline">
            Open war-room →
          </Link>
        )}
      </p>
    </div>
  );
}

function BriefingCard({ briefing }: { briefing: DashboardBriefing | null }) {
  if (!briefing || briefing.bullets.length === 0) return null;
  return (
    <div
      className="rounded-2xl p-5 mb-5 border"
      style={{ background: 'linear-gradient(135deg, rgba(120,72,187,0.10) 0%, rgba(120,72,187,0.02) 100%)', borderColor: 'rgba(120,72,187,0.20)' }}
    >
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-violet-700 mb-3">
        <Brain size={11} /> Today's briefing
        {briefing.source === 'claude' && <span className="text-[9px] text-slate-500 normal-case">AI-generated</span>}
      </div>
      <ul className="space-y-2">
        {briefing.bullets.map((b, i) => (
          <li key={i} className="flex items-start gap-2 text-sm" style={{ color: '#1A1A2E' }}>
            <span className="mt-1 w-1.5 h-1.5 rounded-full bg-violet-600 flex-shrink-0" />
            <span className="font-medium leading-snug">{b}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function CustomizePanel({ visibility, setVisible }: { visibility: Record<WidgetKey, boolean>; setVisible: (k: WidgetKey, v: boolean) => void }) {
  const groups: { title: string; keys: WidgetKey[] }[] = [
    { title: 'Hero', keys: ['briefing', 'kpis', 'today', 'whatChanged', 'preShift', 'goalTracker'] },
    { title: 'Actions', keys: ['topRecs', 'pinnedActions', 'approvals', 'thresholdAlerts'] },
    { title: 'Trends & forecast', keys: ['revenueTrend', 'eomProjection', 'nextWeek', 'laborRisk', 'varianceCard', 'industryBench'] },
    { title: 'Comparison', keys: ['heatmap', 'leaderboard', 'itemVelocity'] },
    { title: 'Cost intel', keys: ['costDrivers', 'recipeDrift', 'vendorScorecard', 'repeatRate'] },
    { title: 'Mix', keys: ['salesMix', 'daypart', 'topItems', 'recFunnel'] },
    { title: 'Health', keys: ['menuCoverage', 'posHealth', 'onboarding', 'plan'] },
    { title: 'Utility', keys: ['activity', 'usage', 'tip', 'whatsNew', 'shortcuts', 'system', 'mapping', 'products'] },
  ];
  const labels: Partial<Record<WidgetKey, string>> = {
    briefing: 'AI briefing', kpis: 'KPI strip', today: 'Today\'s service',
    whatChanged: 'What changed', preShift: 'Pre-shift checklist',
    goalTracker: 'Goal tracker', topRecs: 'Top recommendations',
    pinnedActions: 'Pinned actions', approvals: 'Approvals',
    revenueTrend: 'Revenue trend', eomProjection: 'EOM projection',
    nextWeek: 'Next-week forecast', laborRisk: 'Labor schedule risk',
    varianceCard: 'Variance decomp', industryBench: 'Industry benchmark',
    heatmap: 'Heatmap', leaderboard: 'Leaderboard',
    itemVelocity: 'Item velocity', costDrivers: 'Cost drivers',
    recipeDrift: 'Recipe drift', vendorScorecard: 'Vendor scorecard',
    repeatRate: 'Repeat-vs-new', thresholdAlerts: 'Threshold alerts',
    salesMix: 'Sales mix', daypart: 'Daypart', topItems: 'Top items',
    recFunnel: 'Rec funnel', menuCoverage: 'Menu coverage',
    posHealth: 'POS health', onboarding: 'Getting started',
    plan: 'Subscription', activity: 'Activity feed', usage: 'Daily limits',
    tip: 'Tip', whatsNew: 'What\'s new', shortcuts: 'Shortcuts',
    system: 'System status', mapping: 'Mapping module', products: 'Across smappen',
  };
  return (
    <div className="rounded-xl border border-violet-200 bg-violet-50/40 p-4 mb-5">
      <div className="text-[11px] uppercase tracking-wider font-bold text-violet-700 mb-3 flex items-center gap-1.5">
        <Layers size={11} /> Customize your dashboard
      </div>
      <div className="grid sm:grid-cols-2 md:grid-cols-4 gap-4">
        {groups.map((g) => (
          <div key={g.title}>
            <div className="text-[10px] uppercase font-bold text-slate-500 mb-1">{g.title}</div>
            <ul className="space-y-0.5">
              {g.keys.map((k) => (
                <li key={k}>
                  <button
                    onClick={() => setVisible(k, !visibility[k])}
                    className="text-xs flex items-center gap-1.5 hover:text-violet-700 w-full text-left"
                  >
                    {visibility[k] ? <Eye size={11} className="text-violet-600" /> : <EyeOff size={11} className="text-slate-300" />}
                    <span className={visibility[k] ? 'text-slate-800' : 'text-slate-400 line-through'}>{labels[k] ?? k}</span>
                  </button>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </div>
  );
}

type DrillMetric = 'revenue' | 'food_cost' | 'labor_cost' | 'prime_cost' | 'carafe';
interface DrilldownState { metric: DrillMetric; overview: RestaurantsOverview | null; }

function DrilldownModal({ state, onClose }: { state: DrilldownState; onClose: () => void }) {
  const titles: Record<DrillMetric, string> = {
    revenue: 'Revenue by restaurant',
    food_cost: 'Food cost % by restaurant',
    labor_cost: 'Labor cost % by restaurant',
    prime_cost: 'Prime cost % by restaurant',
    carafe: 'Open recommendations by restaurant',
  };
  const rows = (state.overview?.restaurants ?? []).slice().sort((a, b) => {
    if (state.metric === 'revenue') return b.revenue_mtd_cents - a.revenue_mtd_cents;
    if (state.metric === 'food_cost') return (b.food_cost_pct ?? 0) - (a.food_cost_pct ?? 0);
    if (state.metric === 'labor_cost') return (b.labor_cost_pct ?? 0) - (a.labor_cost_pct ?? 0);
    if (state.metric === 'prime_cost') return (b.prime_cost_pct ?? 0) - (a.prime_cost_pct ?? 0);
    return b.open_recs - a.open_recs;
  });
  const fmt = (r: any) => {
    if (state.metric === 'revenue') return money(r.revenue_mtd_cents, { compact: true });
    if (state.metric === 'food_cost') return pct(r.food_cost_pct);
    if (state.metric === 'labor_cost') return pct(r.labor_cost_pct);
    if (state.metric === 'prime_cost') return pct(r.prime_cost_pct);
    return `${r.open_recs} open`;
  };
  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" onClick={onClose}>
      <div className="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-extrabold" style={{ color: '#1A1A2E' }}>{titles[state.metric]}</h2>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-700"><X size={18} /></button>
        </div>
        <div className="space-y-1">
          {rows.length === 0 ? (
            <div className="text-sm text-slate-500 py-4 text-center">No data yet.</div>
          ) : rows.map((r) => (
            <Link key={r.id} to={`/app/restaurants/${r.id}`} className="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-slate-50">
              <span className="font-semibold" style={{ color: '#1A1A2E' }}>{r.name}</span>
              <span className="tabular-nums font-bold" style={{ color: '#1A1A2E' }}>{fmt(r)}</span>
            </Link>
          ))}
        </div>
      </div>
    </div>
  );
}

function KpiStrip({
  overview, loading, onDrill,
}: { overview: RestaurantsOverview | null; loading: boolean; onDrill: (m: DrillMetric) => void }) {
  if (loading && !overview) {
    return (
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-3">
        {[0,1,2,3,4].map((i) => <div key={i} className="skeleton h-24 w-full rounded-xl" />)}
      </div>
    );
  }
  const mtd = overview?.mtd;
  const prev = overview?.previous;
  const totals = overview?.totals;
  const revDelta = prev && prev.revenue_cents > 0
    ? (mtd!.revenue_cents - prev.revenue_cents) / prev.revenue_cents : null;

  return (
    <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-3">
      <KpiTile icon={DollarSign} label="Revenue" value={money(mtd?.revenue_cents ?? 0, { compact: true })} accent="#10b981"
        delta={revDelta}
        sub={overview?.last_7d ? `Last 7d: ${money(overview.last_7d.revenue_cents, { compact: true })}` : ''}
        onClick={() => onDrill('revenue')} />
      <KpiTile icon={Utensils} label="Food cost %" value={pct(mtd?.food_cost_pct)} accent="#f59e0b"
        delta={overview?.variance_decomposition.food_delta_pct ?? null} deltaIsPp deltaInverted
        tone={mtd?.food_cost_pct != null ? toneFor(mtd.food_cost_pct, 0.30, 0.35) : undefined}
        onClick={() => onDrill('food_cost')} />
      <KpiTile icon={Users} label="Labor cost %" value={pct(mtd?.labor_cost_pct)} accent="#3b82f6"
        delta={overview?.variance_decomposition.labor_delta_pct ?? null} deltaIsPp deltaInverted
        tone={mtd?.labor_cost_pct != null ? toneFor(mtd.labor_cost_pct, 0.30, 0.34) : undefined}
        onClick={() => onDrill('labor_cost')} />
      <KpiTile icon={Flame} label="Prime cost %" value={pct(mtd?.prime_cost_pct)} accent="#dc2626"
        delta={overview?.variance_decomposition.prime_delta_pct ?? null} deltaIsPp deltaInverted
        tone={mtd?.prime_cost_pct != null ? toneFor(mtd.prime_cost_pct, 0.60, 0.65) : undefined}
        onClick={() => onDrill('prime_cost')} />
      <KpiTile icon={Sparkles} label="Carafe found" value={money(mtd?.carafe_found_cents ?? 0, { compact: true })} accent="#7848BB"
        sub={totals?.open_recommendations
          ? `${totals.open_recommendations} open · ${money(totals.open_recommendations_cents, { compact: true })}`
          : 'period impact'}
        onClick={() => onDrill('carafe')} />
    </div>
  );
}

function KpiTile({
  icon: Icon, label, value, accent, sub, tone, delta, deltaIsPp, deltaInverted, onClick,
}: {
  icon: any; label: string; value: string;
  accent: string; sub?: any; tone?: 'good' | 'warn' | 'bad';
  delta?: number | null; deltaIsPp?: boolean; deltaInverted?: boolean;
  onClick?: () => void;
}) {
  const toneBg = tone === 'good' ? 'bg-emerald-50' : tone === 'warn' ? 'bg-amber-50' : tone === 'bad' ? 'bg-red-50' : '';
  const toneBorder = tone === 'good' ? 'border-emerald-200' : tone === 'warn' ? 'border-amber-200' : tone === 'bad' ? 'border-red-200' : 'border-slate-200';
  return (
    <button onClick={onClick} className={`text-left rounded-xl border ${toneBorder} ${toneBg || 'bg-white'} p-4 hover:border-violet-400 transition-colors cursor-pointer`}>
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500">
        <Icon size={12} style={{ color: accent }} /> {label}
      </div>
      <div className="text-2xl font-extrabold mt-1 tabular-nums" style={{ color: '#1A1A2E' }}>{value}</div>
      {delta != null && <DeltaPill delta={delta} isPp={deltaIsPp} inverted={deltaInverted} />}
      {sub && !delta && <div className="text-[11px] text-slate-500 mt-1">{sub}</div>}
    </button>
  );
}

function DeltaPill({ delta, isPp, inverted }: { delta: number; isPp?: boolean; inverted?: boolean }) {
  const isGood = inverted ? delta < 0 : delta > 0;
  const Icon = delta > 0 ? TrendingUp : TrendingDown;
  const color = isGood ? 'text-emerald-700' : delta === 0 ? 'text-slate-500' : 'text-red-700';
  const label = isPp
    ? (delta >= 0 ? '+' : '') + (delta * 100).toFixed(1) + 'pp'
    : (delta >= 0 ? '+' : '') + (delta * 100).toFixed(0) + '%';
  return (
    <div className={`inline-flex items-center gap-1 text-[11px] font-bold mt-1 ${color}`}>
      <Icon size={11} /> {label} <span className="text-slate-400 font-normal">vs prev</span>
    </div>
  );
}

function TodayServiceStrip({ overview }: { overview: RestaurantsOverview | null }) {
  const today = overview?.today;
  const eod = overview?.forecast.eod_revenue_cents;
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 mb-5">
      <div className="flex flex-wrap items-center gap-6">
        <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500">
          <Activity size={11} /> Today
        </div>
        <ServiceStat label="Revenue" value={money(today?.revenue_cents ?? 0, { compact: true })} />
        <ServiceStat label="Covers" value={formatNumber(today?.covers ?? 0)} />
        <ServiceStat label="Avg ticket" value={today?.avg_ticket_cents ? money(today.avg_ticket_cents) : '—'} />
        <ServiceStat label="Sales lines" value={formatNumber(today?.sale_lines ?? 0)} />
        {eod != null && eod > 0 && (
          <div className="border-l border-slate-200 pl-6">
            <div className="text-[10px] uppercase tracking-wider font-bold text-violet-600 flex items-center gap-1">
              <TimerReset size={10} /> Projected close
            </div>
            <div className="text-lg font-extrabold tabular-nums" style={{ color: '#1A1A2E' }}>{money(eod, { compact: true })}</div>
          </div>
        )}
        <div className="ml-auto text-xs text-slate-500">
          {today?.last_sale_at ? `Last sale ${relTime(today.last_sale_at)}` : 'No sales today yet'}
        </div>
      </div>
    </div>
  );
}
function ServiceStat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-[10px] uppercase tracking-wider font-bold text-slate-500">{label}</div>
      <div className="text-lg font-extrabold tabular-nums" style={{ color: '#1A1A2E' }}>{value}</div>
    </div>
  );
}

function QuickActions({ lastRestaurantId }: { lastRestaurantId?: string }) {
  return (
    <div className="flex flex-wrap gap-2 mb-1">
      <Link to="/app/restaurants" className="btn btn-primary h-9 text-sm px-3"><ChefHat size={14} /> Restaurants</Link>
      {lastRestaurantId && (
        <Link to={`/app/restaurants/${lastRestaurantId}`} className="btn btn-secondary h-9 text-sm px-3">
          <Activity size={14} /> Open war-room
        </Link>
      )}
      <Link to="/app/restaurants?new=1" className="btn btn-secondary h-9 text-sm px-3"><Plus size={14} /> Add restaurant</Link>
      <Link to="/app/vendors" className="btn btn-secondary h-9 text-sm px-3"><Building2 size={14} /> Vendors</Link>
      <Link to="/app" className="btn btn-secondary h-9 text-sm px-3"><MapIcon size={14} /> Map / trade area</Link>
    </div>
  );
}

function WhatChangedCard({ overview }: { overview: RestaurantsOverview | null }) {
  const anoms = overview?.anomalies ?? [];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 h-full">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
        <AlertTriangle size={11} /> What changed
      </div>
      {anoms.length === 0 ? (
        <div className="text-xs text-emerald-700 flex items-center gap-1 py-2">
          <CheckCircle2 size={12} /> Everything in line with 28-day baseline.
        </div>
      ) : (
        <ul className="space-y-2">
          {anoms.slice(0, 4).map((a, i) => (
            <li key={i} className="text-xs">
              <div className="font-bold" style={{ color: '#1A1A2E' }}>{a.label}</div>
              <div className="text-slate-600 mt-0.5">{a.detail}</div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function PreShiftCard({ overview }: { overview: RestaurantsOverview | null }) {
  const items = overview?.pre_shift ?? [];
  const priorityClr: Record<string, string> = {
    high: 'bg-red-100 text-red-700',
    medium: 'bg-amber-100 text-amber-700',
    low: 'bg-slate-100 text-slate-700',
  };
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 h-full">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
        <ListChecks size={11} /> Pre-shift checklist
      </div>
      {items.length === 0 ? (
        <div className="text-xs text-emerald-700 flex items-center gap-1 py-2">
          <CheckCircle2 size={12} /> Nothing flagged. You're set.
        </div>
      ) : (
        <ul className="space-y-2">
          {items.slice(0, 5).map((it, i) => (
            <li key={i} className="flex items-start gap-2 text-xs">
              <span className={`inline-block text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded ${priorityClr[it.priority]}`}>{it.priority}</span>
              <span className="flex-1 text-slate-700 leading-snug">{it.label}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function GoalTrackerCard({ overview }: { overview: RestaurantsOverview | null }) {
  const goals = overview?.goals ?? [];
  const labelOf: Record<string, string> = {
    food_cost_pct: 'Food cost %', margin_pct: 'Margin %',
    avg_check_cents: 'Avg check', weekly_revenue_cents: 'Weekly revenue',
  };
  const fmtActual = (m: string, v: number | null) => {
    if (v === null) return '—';
    if (m === 'food_cost_pct' || m === 'margin_pct') return pct(v);
    if (m === 'avg_check_cents' || m === 'weekly_revenue_cents') return money(v);
    return String(v);
  };
  const fmtTarget = (m: string, v: number) => {
    if (m === 'food_cost_pct' || m === 'margin_pct') return pct(v);
    if (m === 'avg_check_cents' || m === 'weekly_revenue_cents') return money(v);
    return String(v);
  };
  const isOnTrack = (m: string, actual: number | null, target: number) => {
    if (actual === null) return null;
    if (m === 'food_cost_pct') return actual <= target;
    return actual >= target;
  };
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 h-full">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
        <Target size={11} /> Goal tracker
      </div>
      {goals.length === 0 ? (
        <div className="text-xs text-slate-500 py-2">
          No org-wide goals set yet.{' '}
          <Link to="/app/restaurants" className="font-semibold text-violet-700 hover:underline">Set one →</Link>
        </div>
      ) : (
        <ul className="space-y-3">
          {goals.map((g) => {
            const ok = isOnTrack(g.metric, g.actual_value, g.target_value);
            const tone = ok === null ? 'slate' : ok ? 'emerald' : 'red';
            const clr: Record<string, string> = { emerald: '#10b981', red: '#dc2626', slate: '#94a3b8' };
            const fill = g.actual_value !== null && g.target_value > 0
              ? Math.min(100, (g.actual_value / g.target_value) * 100) : 0;
            return (
              <li key={g.metric}>
                <div className="flex items-center justify-between text-xs">
                  <span className="font-semibold text-slate-700">{labelOf[g.metric] ?? g.metric}</span>
                  <span className="tabular-nums">
                    <span className="font-bold" style={{ color: clr[tone] }}>{fmtActual(g.metric, g.actual_value)}</span>
                    <span className="text-slate-400"> / {fmtTarget(g.metric, g.target_value)}</span>
                  </span>
                </div>
                <div className="h-1.5 bg-slate-100 rounded mt-1 overflow-hidden">
                  <div className="h-full rounded" style={{ width: fill + '%', background: clr[tone] }} />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function TopRecommendationsCard({
  overview, pinned, onPin,
}: { overview: RestaurantsOverview | null; pinned: string[]; onPin: (id: string) => void }) {
  const recs = overview?.top_recommendations ?? [];
  const kindLabel: Record<string, string> = {
    price_raise: 'Raise price', price_lower: 'Lower price',
    reposition: 'Reposition', reprice: 'Reprice', cut: 'Cut',
  };
  const kindColor: Record<string, string> = {
    price_raise: 'bg-emerald-100 text-emerald-700',
    price_lower: 'bg-amber-100 text-amber-700',
    reposition: 'bg-blue-100 text-blue-700',
    reprice: 'bg-violet-100 text-violet-700',
    cut: 'bg-red-100 text-red-700',
  };
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 h-full">
      <div className="flex items-center justify-between mb-3">
        <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 flex items-center gap-1.5">
          <ListChecks size={11} /> Highest-impact moves
        </div>
        {overview?.totals && (
          <span className="text-[11px] font-semibold text-slate-500">
            {overview.totals.open_recommendations} open · {money(overview.totals.open_recommendations_cents, { compact: true })}
          </span>
        )}
      </div>
      {recs.length === 0 ? (
        <div className="text-xs text-slate-500 py-6 text-center">No open recommendations.</div>
      ) : (
        <ul className="space-y-2">
          {recs.map((r) => (
            <li key={r.id} className="flex items-center gap-3 p-2 rounded-lg hover:bg-slate-50 group">
              <span className={`text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded ${kindColor[r.kind] ?? 'bg-slate-100 text-slate-700'}`}>
                {kindLabel[r.kind] ?? r.kind}
              </span>
              <Link to={`/app/restaurants/${r.restaurant_id}`} className="flex-1 min-w-0">
                <div className="text-sm font-semibold truncate" style={{ color: '#1A1A2E' }}>{r.menu_item_name ?? 'Menu item'}</div>
                <div className="text-[11px] text-slate-500 truncate">{r.restaurant_name}</div>
              </Link>
              <div className="text-sm font-extrabold tabular-nums" style={{ color: '#1A1A2E' }}>{money(r.dollar_estimate_cents, { compact: true })}</div>
              <button onClick={(e) => { e.preventDefault(); onPin(r.id); }} title={pinned.includes(r.id) ? 'Unpin' : 'Pin'} className="text-slate-300 hover:text-violet-600">
                {pinned.includes(r.id) ? <Pin size={13} className="text-violet-600" /> : <PinOff size={13} />}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function PinnedActionsCard({ pinned, onPin }: { pinned: RestaurantsOverview['top_recommendations']; onPin: (id: string) => void }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 h-full">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Pin size={11} /> Pinned actions
      </div>
      {pinned.length === 0 ? (
        <div className="text-xs text-slate-500 py-2">
          Pin recommendations you'll act on. They'll persist here across sessions.
        </div>
      ) : (
        <ul className="space-y-2">
          {pinned.map((r) => (
            <li key={r.id} className="flex items-center gap-2 text-xs">
              <Pin size={11} className="text-violet-600 flex-shrink-0" />
              <Link to={`/app/restaurants/${r.restaurant_id}`} className="flex-1 min-w-0 hover:text-violet-700">
                <div className="font-semibold truncate" style={{ color: '#1A1A2E' }}>{r.menu_item_name ?? 'Menu item'}</div>
                <div className="text-[10px] text-slate-500">{r.restaurant_name} · {money(r.dollar_estimate_cents, { compact: true })}</div>
              </Link>
              <button onClick={() => onPin(r.id)} className="text-slate-300 hover:text-red-500"><X size={11} /></button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function ApprovalsCard({ overview, className }: { overview: RestaurantsOverview | null; className?: string }) {
  const high = (overview?.top_recommendations ?? []).filter((r) => r.dollar_estimate_cents >= 50000);
  return (
    <div className={`rounded-xl border border-slate-200 bg-white p-4 ${className ?? ''}`}>
      <div className="flex items-center justify-between mb-3">
        <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 flex items-center gap-1.5">
          <CheckCircle2 size={11} /> Needs your approval
        </div>
        <span className="text-[11px] text-slate-500">High-$ recommendations</span>
      </div>
      {high.length === 0 ? (
        <div className="text-xs text-slate-500 py-2">No items awaiting approval.</div>
      ) : (
        <ul className="space-y-1.5">
          {high.map((r) => (
            <li key={r.id} className="flex items-center gap-3 text-xs">
              <span className="w-2 h-2 rounded-full bg-amber-500" />
              <Link to={`/app/restaurants/${r.restaurant_id}`} className="flex-1 hover:text-violet-700 font-semibold" style={{ color: '#1A1A2E' }}>
                {r.menu_item_name} · {r.restaurant_name}
              </Link>
              <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{money(r.dollar_estimate_cents, { compact: true })}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function RevenueTrendCard({ overview }: { overview: RestaurantsOverview | null }) {
  const series = useMemo(() => {
    const map = new Map<string, { revenue_cents: number; covers: number }>();
    (overview?.daily_revenue_14d ?? []).forEach((d) => map.set(d.day, { revenue_cents: d.revenue_cents, covers: d.covers }));
    const out: { day: string; revenue_cents: number; covers: number; label: string }[] = [];
    const today = new Date(); today.setHours(0,0,0,0);
    for (let i = 13; i >= 0; i--) {
      const d = new Date(today); d.setDate(d.getDate() - i);
      const key = d.toISOString().slice(0, 10);
      const hit = map.get(key);
      out.push({
        day: key, revenue_cents: hit?.revenue_cents ?? 0, covers: hit?.covers ?? 0,
        label: d.toLocaleDateString(undefined, { weekday: 'short' }).slice(0, 1),
      });
    }
    return out;
  }, [overview]);
  const max = Math.max(...series.map((b) => b.revenue_cents), 1);
  const total = series.reduce((s, b) => s + b.revenue_cents, 0);
  const baseline = overview?.baseline_28d?.mean_revenue_cents ?? 0;
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 h-full">
      <div className="flex items-center justify-between mb-3">
        <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 flex items-center gap-1.5">
          <BarChart3 size={11} /> Revenue · last 14 days
        </div>
        <div className="text-xs font-semibold" style={{ color: '#1A1A2E' }}>{money(total, { compact: true })}</div>
      </div>
      <div className="relative flex items-end gap-1 h-28">
        {baseline > 0 && (
          <div className="absolute left-0 right-0 border-t border-dashed border-violet-400/50 z-0"
            style={{ bottom: ((baseline / max) * 100) + '%' }} title={`28-day avg: ${money(baseline, { compact: true })}`} />
        )}
        {series.map((b, i) => {
          const h = b.revenue_cents > 0 ? Math.max(3, (b.revenue_cents / max) * 100) : 2;
          const isToday = i === series.length - 1;
          return (
            <div key={b.day} className="flex-1 flex flex-col items-center gap-1 relative z-10" title={`${b.day}: ${money(b.revenue_cents)} · ${b.covers} covers`}>
              <div className="w-full rounded-t transition-all" style={{ height: h + 'px', background: isToday ? '#10b981' : '#cbd5e1' }} />
              <div className="text-[9px] font-bold text-slate-400">{b.label}</div>
            </div>
          );
        })}
      </div>
      {baseline > 0 && <div className="text-[10px] text-slate-500 mt-2">Dashed line · 28-day mean ({money(baseline, { compact: true })})</div>}
    </div>
  );
}

function EomProjectionCard({ overview }: { overview: RestaurantsOverview | null }) {
  const f = overview?.forecast;
  const daysIn = new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).getDate();
  const day = new Date().getDate();
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 h-full">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Calendar size={11} /> EOM projection
      </div>
      <div className="text-2xl font-extrabold tabular-nums" style={{ color: '#1A1A2E' }}>
        {f?.eom_revenue_cents ? money(f.eom_revenue_cents, { compact: true }) : '—'}
      </div>
      <div className="text-[11px] text-slate-500 mt-1">projected revenue · day {day} of {daysIn}</div>
      {f?.eom_food_cost_cents != null && (
        <div className="text-xs mt-3">
          <div className="flex justify-between"><span className="text-slate-600">Food cost</span><span className="font-semibold tabular-nums">{money(f.eom_food_cost_cents, { compact: true })}</span></div>
          <div className="flex justify-between mt-1"><span className="text-slate-600">Labor cost</span><span className="font-semibold tabular-nums">{money(f.eom_labor_cost_cents ?? 0, { compact: true })}</span></div>
        </div>
      )}
    </div>
  );
}

function NextWeekForecastCard({ overview }: { overview: RestaurantsOverview | null }) {
  const items = overview?.forecast.next_week ?? [];
  const max = Math.max(...items.map((x) => x.projected_cents), 1);
  const total = overview?.forecast.next_week_total_cents ?? 0;
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center justify-between mb-3">
        <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 flex items-center gap-1.5">
          <Calendar size={11} /> Next 7 days
        </div>
        <div className="text-xs font-semibold" style={{ color: '#1A1A2E' }}>{money(total, { compact: true })}</div>
      </div>
      {items.length === 0 ? (
        <div className="text-xs text-slate-500 py-2">Need 28d of POS data to forecast.</div>
      ) : (
        <div className="flex items-end gap-1.5 h-16">
          {items.map((b) => (
            <div key={b.day} className="flex-1 flex flex-col items-center gap-1" title={`${b.day}: ${money(b.projected_cents)}`}>
              <div className="w-full rounded-t bg-violet-400" style={{ height: Math.max(3, (b.projected_cents / max) * 56) + 'px' }} />
              <div className="text-[9px] font-bold text-slate-400">{b.dow_label[0]}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function LaborRiskCard({ overview }: { overview: RestaurantsOverview | null }) {
  const sched = overview?.forecast.scheduled_labor_next7_cents ?? 0;
  const fcstRev = overview?.forecast.next_week_total_cents ?? 0;
  const pctV = overview?.forecast.scheduled_labor_pct_of_forecast;
  const tone = pctV === null || pctV === undefined ? 'slate' : pctV > 0.34 ? 'red' : pctV > 0.30 ? 'amber' : 'emerald';
  const clr: Record<string, string> = { red: '#dc2626', amber: '#f59e0b', emerald: '#10b981', slate: '#94a3b8' };
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Users size={11} /> Labor schedule risk
      </div>
      <div className="text-2xl font-extrabold tabular-nums" style={{ color: clr[tone] }}>{pct(pctV)}</div>
      <div className="text-[11px] text-slate-500 mt-1">
        {money(sched, { compact: true })} scheduled · {money(fcstRev, { compact: true })} forecast
      </div>
      {tone === 'red' && <div className="text-[11px] text-red-700 mt-2 font-semibold">Trim a shift on the slow daypart.</div>}
      {tone === 'amber' && <div className="text-[11px] text-amber-700 mt-2 font-semibold">Borderline — watch lunch coverage.</div>}
    </div>
  );
}

function VarianceDecompCard({ overview }: { overview: RestaurantsOverview | null }) {
  const v = overview?.variance_decomposition;
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <BarChart3 size={11} /> Prime cost variance
      </div>
      <ul className="space-y-1.5 text-xs">
        <li className="flex justify-between"><span className="text-slate-600">Prime Δ</span><span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{pp(v?.prime_delta_pct)}</span></li>
        <li className="flex justify-between"><span className="text-slate-600 pl-3">↳ Food Δ</span><span className="font-semibold tabular-nums">{pp(v?.food_delta_pct)}</span></li>
        <li className="flex justify-between"><span className="text-slate-600 pl-3">↳ Labor Δ</span><span className="font-semibold tabular-nums">{pp(v?.labor_delta_pct)}</span></li>
      </ul>
      <div className="text-[10px] text-slate-500 mt-2">vs previous period</div>
    </div>
  );
}

function IndustryBenchmarkCard({ overview }: { overview: RestaurantsOverview | null }) {
  const bench = overview?.industry_benchmarks;
  const m = overview?.mtd;
  const rows: { label: string; you: number | null | undefined; target: number; inverted?: boolean }[] = [
    { label: 'Food cost', you: m?.food_cost_pct, target: bench?.food_cost_pct ?? 0.30, inverted: true },
    { label: 'Labor cost', you: m?.labor_cost_pct, target: bench?.labor_cost_pct ?? 0.30, inverted: true },
    { label: 'Prime cost', you: m?.prime_cost_pct, target: bench?.prime_cost_pct ?? 0.60, inverted: true },
  ];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Trophy size={11} /> Industry benchmark
      </div>
      <ul className="space-y-2 text-xs">
        {rows.map((r) => {
          const you = r.you;
          const better = you === null || you === undefined ? null : (r.inverted ? you < r.target : you > r.target);
          const clr = better === null ? '#94a3b8' : better ? '#10b981' : '#dc2626';
          return (
            <li key={r.label}>
              <div className="flex justify-between">
                <span className="text-slate-600">{r.label}</span>
                <span className="tabular-nums">
                  <span className="font-bold" style={{ color: clr }}>{pct(you)}</span>
                  <span className="text-slate-400"> vs {pct(r.target)}</span>
                </span>
              </div>
            </li>
          );
        })}
      </ul>
      <div className="text-[9px] text-slate-400 mt-2">{bench?.source ?? ''}</div>
    </div>
  );
}

function HeatmapCard({ overview, loading }: { overview: RestaurantsOverview | null; loading: boolean }) {
  const rows = overview?.restaurants ?? [];
  const cell = (v: number | null, kind: 'food' | 'labor' | 'prime') => {
    if (v === null || v === undefined) return { bg: '#f1f5f9', fg: '#94a3b8', label: '—' };
    const t = kind === 'food' ? toneFor(v, 0.30, 0.35) : kind === 'labor' ? toneFor(v, 0.30, 0.34) : toneFor(v, 0.60, 0.65);
    const bg = t === 'good' ? '#dcfce7' : t === 'warn' ? '#fef3c7' : '#fee2e2';
    const fg = t === 'good' ? '#047857' : t === 'warn' ? '#b45309' : '#b91c1c';
    return { bg, fg, label: pct(v) };
  };
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 mt-6">
      <div className="flex items-center justify-between mb-3">
        <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 flex items-center gap-1.5">
          <Layers size={11} /> Restaurant × metric heatmap
        </div>
        <Link to="/app/restaurants" className="text-xs font-semibold text-violet-700 hover:underline">All restaurants →</Link>
      </div>
      {loading && !overview ? (
        <div className="space-y-1">
          {[0,1,2].map((i) => <div key={i} className="skeleton h-9 w-full rounded" />)}
        </div>
      ) : rows.length === 0 ? (
        <EmptyRestaurantsState />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="text-[11px] uppercase tracking-wider font-bold text-slate-500 border-b border-slate-100">
                <th className="text-left py-2 px-2">Restaurant</th>
                <th className="text-right py-2 px-2">Revenue</th>
                <th className="text-right py-2 px-2">Covers</th>
                <th className="text-center py-2 px-2">Food</th>
                <th className="text-center py-2 px-2">Labor</th>
                <th className="text-center py-2 px-2">Prime</th>
                <th className="text-center py-2 px-2">POS</th>
                <th className="text-right py-2 px-2">Recs</th>
                <th className="text-right py-2 px-2">Last sale</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => {
                const f = cell(r.food_cost_pct, 'food');
                const l = cell(r.labor_cost_pct, 'labor');
                const p = cell(r.prime_cost_pct, 'prime');
                return (
                  <tr key={r.id} className="border-b border-slate-100 hover:bg-slate-50">
                    <td className="py-2 px-2"><Link to={`/app/restaurants/${r.id}`} className="font-semibold hover:text-violet-700" style={{ color: '#1A1A2E' }}>{r.name}</Link></td>
                    <td className="text-right py-2 px-2 tabular-nums font-bold" style={{ color: '#1A1A2E' }}>{money(r.revenue_mtd_cents, { compact: true })}</td>
                    <td className="text-right py-2 px-2 tabular-nums text-slate-700">{formatNumber(r.covers_mtd)}</td>
                    <td className="text-center py-1 px-1"><span className="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold tabular-nums" style={{ background: f.bg, color: f.fg }}>{f.label}</span></td>
                    <td className="text-center py-1 px-1"><span className="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold tabular-nums" style={{ background: l.bg, color: l.fg }}>{l.label}</span></td>
                    <td className="text-center py-1 px-1"><span className="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold tabular-nums" style={{ background: p.bg, color: p.fg }}>{p.label}</span></td>
                    <td className="text-center py-2 px-2">
                      {r.pos_connected
                        ? <span className="inline-flex items-center justify-center w-5 h-5 rounded-full bg-emerald-100 text-emerald-700"><Plug size={10} /></span>
                        : <span className="inline-flex items-center justify-center w-5 h-5 rounded-full bg-slate-100 text-slate-400"><Plug size={10} /></span>}
                    </td>
                    <td className="text-right py-2 px-2 tabular-nums">
                      {r.open_recs > 0 ? <span className="font-bold text-violet-700">{r.open_recs}</span> : <span className="text-slate-400">0</span>}
                    </td>
                    <td className="text-right py-2 px-2 text-[11px] text-slate-500">{relTime(r.last_sale_at)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
function EmptyRestaurantsState() {
  return (
    <div className="text-center py-8">
      <ChefHat size={28} className="mx-auto text-violet-500 mb-3" />
      <div className="font-bold" style={{ color: '#1A1A2E' }}>No restaurants yet</div>
      <p className="text-sm text-slate-500 mt-1">Add your first restaurant to start tracking sales, costs, and recommendations.</p>
      <Link to="/app/restaurants" className="btn btn-primary h-9 mt-4 inline-flex">Add restaurant</Link>
    </div>
  );
}

function LeaderboardCard({ overview }: { overview: RestaurantsOverview | null }) {
  const rows = (overview?.leaderboard ?? []).slice(0, 6);
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Trophy size={11} /> Restaurant leaderboard · by margin
      </div>
      {rows.length === 0 ? (
        <div className="text-xs text-slate-500 py-3 text-center">Need ≥$100 revenue per restaurant to rank.</div>
      ) : (
        <ol className="space-y-2">
          {rows.map((r, i) => (
            <li key={r.id} className="flex items-center gap-2 text-xs">
              <span className="w-5 h-5 inline-flex items-center justify-center text-[10px] font-bold rounded bg-violet-100 text-violet-700">{i + 1}</span>
              <Link to={`/app/restaurants/${r.id}`} className="flex-1 truncate font-semibold hover:text-violet-700" style={{ color: '#1A1A2E' }}>{r.name}</Link>
              <span className="font-bold tabular-nums text-emerald-700">{pct(r.margin_pct)}</span>
              <span className="text-[10px] text-slate-400">{money(r.revenue_cents, { compact: true })}</span>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

function ItemVelocityCard({ overview }: { overview: RestaurantsOverview | null }) {
  const rows = overview?.item_velocity ?? [];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Activity size={11} /> Item velocity · WoW
      </div>
      {rows.length === 0 ? (
        <div className="text-xs text-slate-500 py-3 text-center">Need at least 2 weeks of POS data.</div>
      ) : (
        <ul className="space-y-2 text-xs">
          {rows.map((v) => {
            const isUp = (v.delta_pct ?? 0) > 0;
            return (
              <li key={v.id} className="flex items-center gap-2">
                {isUp ? <TrendingUp size={12} className="text-emerald-600" /> : <TrendingDown size={12} className="text-red-600" />}
                <div className="flex-1 min-w-0">
                  <div className="font-semibold truncate" style={{ color: '#1A1A2E' }}>{v.name}</div>
                  <div className="text-[10px] text-slate-500 truncate">{v.restaurant_name}</div>
                </div>
                <span className={`font-bold tabular-nums ${isUp ? 'text-emerald-700' : 'text-red-700'}`}>
                  {v.delta_pct === null ? '—' : (v.delta_pct >= 0 ? '+' : '') + Math.round(v.delta_pct * 100) + '%'}
                </span>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function CostDriversCard({ overview }: { overview: RestaurantsOverview | null }) {
  const rows = overview?.cost_drivers ?? [];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <DollarSign size={11} /> Top cost drivers
      </div>
      {rows.length === 0 ? (
        <div className="text-xs text-slate-500 py-3 text-center">Sync recipes + POS to see ingredient drivers.</div>
      ) : (
        <ol className="space-y-2 text-xs">
          {rows.map((d, i) => (
            <li key={d.ingredient_key} className="flex items-center gap-2">
              <span className="w-4 h-4 inline-flex items-center justify-center text-[10px] font-bold rounded bg-amber-100 text-amber-700">{i + 1}</span>
              <span className="flex-1 truncate font-semibold capitalize" style={{ color: '#1A1A2E' }}>{d.ingredient_key.replace(/_/g, ' ')}</span>
              <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{money(d.cost_cents, { compact: true })}</span>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

function RecipeDriftCard({ overview }: { overview: RestaurantsOverview | null }) {
  const rows = overview?.recipe_drift ?? [];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <TimerReset size={11} /> Stale recipe costs
      </div>
      {rows.length === 0 ? (
        <div className="text-xs text-emerald-700 flex items-center gap-1 py-2">
          <CheckCircle2 size={12} /> All recipes recosted within 30 days.
        </div>
      ) : (
        <ul className="space-y-2 text-xs">
          {rows.map((r) => (
            <li key={r.id} className="flex items-center gap-2">
              <Circle size={6} className="text-amber-500 fill-amber-500 flex-shrink-0" />
              <div className="flex-1 min-w-0">
                <div className="font-semibold truncate" style={{ color: '#1A1A2E' }}>{r.name}</div>
                <div className="text-[10px] text-slate-500">{r.restaurant_name} · last costed {relTime(r.computed_at)}</div>
              </div>
              <span className="text-[10px] text-slate-500">{r.coverage_pct}%</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function VendorScorecardCard() {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Building2 size={11} /> Vendor scorecard
      </div>
      <ul className="space-y-1.5 text-xs">
        <li className="flex justify-between text-slate-400"><span>Fill rate</span><span>—</span></li>
        <li className="flex justify-between text-slate-400"><span>On-time delivery</span><span>—</span></li>
        <li className="flex justify-between text-slate-400"><span>Quoted vs billed Δ</span><span>—</span></li>
      </ul>
      <div className="text-[10px] text-slate-400 mt-2">Wires up when invoices arrive via Vendor Sync.</div>
    </div>
  );
}

function RepeatCustomerCard() {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Users size={11} /> Repeat vs new
      </div>
      <div className="text-xs text-slate-400 py-1">Requires guest-IDed POS sync (Square Customer Directory).</div>
      <div className="text-[10px] text-slate-400 mt-2">Enable in Restaurant → POS → Customer matching.</div>
    </div>
  );
}

function ThresholdAlertsCard({
  alerts, onCreate, onDelete, className,
}: {
  alerts: DashboardAlert[];
  onCreate: (input: DashboardAlertInput) => Promise<void>;
  onDelete: (id: string) => Promise<void>;
  className?: string;
}) {
  const [adding, setAdding] = useState(false);
  const [metric, setMetric] = useState<DashboardAlertInput['metric']>('food_cost_pct');
  const [op, setOp] = useState<DashboardAlertInput['op']>('>');
  const [val, setVal] = useState<string>('0.32');
  const metricLabel: Record<string, string> = {
    food_cost_pct: 'Food cost %', labor_cost_pct: 'Labor cost %',
    prime_cost_pct: 'Prime cost %', margin_pct: 'Margin %',
    revenue_today_cents: 'Revenue today', open_recs: 'Open recommendations',
  };
  const submit = async () => {
    const value = parseFloat(val);
    if (Number.isNaN(value)) return;
    await onCreate({ metric, op, value, label: `${metricLabel[metric]} ${op} ${val}` });
    setAdding(false);
    setVal('0.32');
  };
  return (
    <div className={`rounded-xl border border-slate-200 bg-white p-4 ${className ?? ''}`}>
      <div className="flex items-center justify-between mb-3">
        <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 flex items-center gap-1.5">
          <BellPlus size={11} /> Threshold alerts
        </div>
        {!adding && <button onClick={() => setAdding(true)} className="btn btn-secondary h-7 text-xs px-2"><Plus size={11} /> New</button>}
      </div>
      {adding && (
        <div className="flex flex-wrap items-center gap-2 mb-3 p-2 bg-violet-50 rounded">
          <select value={metric} onChange={(e) => setMetric(e.target.value as any)} className="text-xs px-2 py-1 rounded border border-slate-200 bg-white">
            {Object.entries(metricLabel).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
          <select value={op} onChange={(e) => setOp(e.target.value as any)} className="text-xs px-2 py-1 rounded border border-slate-200 bg-white">
            <option value=">">&gt;</option>
            <option value="<">&lt;</option>
            <option value=">=">≥</option>
            <option value="<=">≤</option>
          </select>
          <input type="number" value={val} step="0.01" onChange={(e) => setVal(e.target.value)} className="text-xs px-2 py-1 rounded border border-slate-200 bg-white w-20" />
          <button onClick={submit} className="btn btn-primary h-7 text-xs px-3">Save</button>
          <button onClick={() => setAdding(false)} className="btn btn-secondary h-7 text-xs px-3">Cancel</button>
          <div className="text-[10px] text-slate-500 w-full">Pct as 0.32 = 32%, money in cents.</div>
        </div>
      )}
      {alerts.length === 0 ? (
        <div className="text-xs text-slate-500 py-1">No threshold alerts set.</div>
      ) : (
        <ul className="space-y-1.5">
          {alerts.map((a) => (
            <li key={a.id} className="flex items-center gap-2 text-xs">
              <Bell size={11} className="text-violet-500" />
              <span className="flex-1 text-slate-700 truncate">
                {a.config?.label ?? `${a.config?.metric} ${a.config?.op} ${a.config?.value}`}
              </span>
              {a.fire_count > 0 && <span className="text-[10px] text-amber-700 font-semibold">{a.fire_count}× fired</span>}
              <button onClick={() => onDelete(a.id)} className="text-slate-300 hover:text-red-500"><Trash2 size={11} /></button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function SalesMixCard({ title, icon: Icon, items }: { title: string; icon: any; items: { category: string; revenue_cents: number }[] }) {
  const palette = ['#7848BB', '#10b981', '#f59e0b', '#3b82f6', '#dc2626', '#94a3b8'];
  const total = items.reduce((s, x) => s + x.revenue_cents, 0);
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
        <Icon size={11} /> {title}
      </div>
      {total === 0 ? (
        <div className="text-xs text-slate-400 py-3 text-center">No POS sales yet.</div>
      ) : (
        <ul className="space-y-1.5 text-xs">
          {items.slice(0, 5).map((c, i) => {
            const p = total > 0 ? (c.revenue_cents / total) * 100 : 0;
            return (
              <li key={c.category}>
                <div className="flex items-center justify-between mb-1">
                  <span className="truncate flex items-center gap-1.5">
                    <span className="w-2 h-2 rounded-full" style={{ background: palette[i] }} />
                    <span className="text-slate-700 font-medium">{c.category}</span>
                  </span>
                  <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{money(c.revenue_cents, { compact: true })}</span>
                </div>
                <div className="h-1 bg-slate-100 rounded">
                  <div className="h-full rounded" style={{ width: p + '%', background: palette[i] }} />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function DaypartCard({ overview }: { overview: RestaurantsOverview | null }) {
  const items = overview?.sales_by_daypart ?? [];
  const order = ['breakfast', 'lunch', 'dinner', 'late', 'unknown'];
  const sorted = [...items].sort((a, b) => order.indexOf(a.daypart) - order.indexOf(b.daypart));
  const max = Math.max(...sorted.map((x) => x.revenue_cents), 1);
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <BarChart3 size={11} /> Sales by daypart
      </div>
      {sorted.length === 0 ? (
        <div className="text-xs text-slate-400 py-3 text-center">No daypart data yet.</div>
      ) : (
        <ul className="space-y-2 text-xs">
          {sorted.map((d) => (
            <li key={d.daypart}>
              <div className="flex items-center justify-between mb-1">
                <span className="font-semibold capitalize text-slate-700">{d.daypart}</span>
                <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{money(d.revenue_cents, { compact: true })}</span>
              </div>
              <div className="h-1.5 bg-slate-100 rounded">
                <div className="h-full rounded bg-violet-500" style={{ width: ((d.revenue_cents / max) * 100) + '%' }} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function TopMenuItemsCard({ overview }: { overview: RestaurantsOverview | null }) {
  const items = overview?.top_menu_items ?? [];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Receipt size={11} /> Top items
      </div>
      {items.length === 0 ? (
        <div className="text-xs text-slate-400 py-3 text-center">No POS sales yet.</div>
      ) : (
        <ol className="space-y-2">
          {items.slice(0, 5).map((m, i) => (
            <li key={m.id} className="flex items-center gap-2 text-xs">
              <span className="w-4 h-4 inline-flex items-center justify-center text-[10px] font-bold rounded bg-violet-100 text-violet-700">{i + 1}</span>
              <div className="flex-1 min-w-0">
                <div className="font-semibold truncate" style={{ color: '#1A1A2E' }}>{m.name}</div>
                <div className="text-[10px] text-slate-500 truncate">{m.restaurant_name} · {formatNumber(m.units_sold)} sold</div>
              </div>
              <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{money(m.revenue_cents, { compact: true })}</span>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

function RecommendationFunnelCard({ overview }: { overview: RestaurantsOverview | null }) {
  const f = overview?.recommendation_funnel ?? { suggested: 0, accepted: 0, dismissed: 0, measured: 0 };
  const total = f.suggested + f.accepted + f.dismissed + f.measured;
  const rows = [
    { k: 'Suggested', v: f.suggested, color: '#7848BB' },
    { k: 'Accepted', v: f.accepted, color: '#3b82f6' },
    { k: 'Measured', v: f.measured, color: '#10b981' },
    { k: 'Dismissed', v: f.dismissed, color: '#94a3b8' },
  ];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <ListChecks size={11} /> Rec funnel
      </div>
      {total === 0 ? (
        <div className="text-xs text-slate-400 py-3 text-center">No recommendations yet.</div>
      ) : (
        <ul className="space-y-2 text-xs">
          {rows.map((r) => {
            const p = total > 0 ? (r.v / total) * 100 : 0;
            return (
              <li key={r.k}>
                <div className="flex items-center justify-between mb-1">
                  <span className="flex items-center gap-1.5 text-slate-700 font-medium">
                    <span className="w-2 h-2 rounded-full" style={{ background: r.color }} />
                    {r.k}
                  </span>
                  <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{r.v}</span>
                </div>
                <div className="h-1 bg-slate-100 rounded">
                  <div className="h-full rounded" style={{ width: p + '%', background: r.color }} />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function MenuCoverageCard({ overview }: { overview: RestaurantsOverview | null }) {
  const t = overview?.totals;
  const pctV = t?.menu_coverage_pct ?? null;
  const tone = pctV === null ? 'slate' : pctV >= 80 ? 'emerald' : pctV >= 50 ? 'amber' : 'red';
  const clr: Record<string, string> = { emerald: '#10b981', amber: '#f59e0b', red: '#dc2626', slate: '#94a3b8' };
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Utensils size={11} /> Menu coverage
      </div>
      <div className="text-2xl font-extrabold tabular-nums" style={{ color: '#1A1A2E' }}>{pctV !== null ? pctV + '%' : '—'}</div>
      <div className="text-[11px] text-slate-500 mt-1">
        {t ? `${formatNumber(t.menu_items_with_plate_cost)} of ${formatNumber(t.menu_items_active)} items priced` : '—'}
      </div>
      <div className="h-1.5 bg-slate-100 rounded mt-3 overflow-hidden">
        <div className="h-full rounded transition-all" style={{ width: (pctV ?? 0) + '%', background: clr[tone] }} />
      </div>
    </div>
  );
}

function PosHealthCard({ overview }: { overview: RestaurantsOverview | null }) {
  const t = overview?.totals;
  const connected = t?.pos_connected_restaurants ?? 0;
  const total = t?.restaurants_active ?? 0;
  const pctV = total > 0 ? Math.round((connected / total) * 100) : 0;
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Plug size={11} /> POS health
      </div>
      <div className="text-2xl font-extrabold tabular-nums" style={{ color: '#1A1A2E' }}>{connected} / {total}</div>
      <div className="text-[11px] text-slate-500 mt-1">restaurants connected ({pctV}%)</div>
      <div className="h-1.5 bg-slate-100 rounded mt-3 overflow-hidden">
        <div className="h-full rounded bg-emerald-500 transition-all" style={{ width: pctV + '%' }} />
      </div>
    </div>
  );
}

function OnboardingChecklist({ overview }: { overview: RestaurantsOverview | null }) {
  const t = overview?.totals;
  const hasRst = (t?.restaurants_active ?? 0) > 0;
  const hasPos = (t?.pos_connected_restaurants ?? 0) > 0;
  const hasMenu = (t?.menu_items_active ?? 0) > 0;
  const hasPlate = (t?.menu_items_with_plate_cost ?? 0) > 0;
  const hasRec = ((t?.open_recommendations ?? 0) + (t?.recommendations_mtd ?? 0)) > 0;
  const items = [
    { done: hasRst, label: 'Add a restaurant' },
    { done: hasPos, label: 'Connect your POS' },
    { done: hasMenu, label: 'Sync menu items' },
    { done: hasPlate, label: 'Cost a recipe' },
    { done: hasRec, label: 'Review a recommendation' },
  ];
  const done = items.filter((i) => i.done).length;
  const pctV = Math.round((done / items.length) * 100);
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-2 flex items-center gap-1.5">
        <CheckCircle2 size={11} /> Getting started · {pctV}%
      </div>
      <div className="h-1 bg-slate-100 rounded mb-3 overflow-hidden">
        <div className="h-full bg-violet-600" style={{ width: pctV + '%' }} />
      </div>
      <ul className="space-y-1.5">
        {items.map((it) => (
          <li key={it.label} className="flex items-center gap-2 text-xs">
            {it.done ? <CheckCircle2 size={13} className="text-emerald-500 flex-shrink-0" /> : <Circle size={13} className="text-slate-300 flex-shrink-0" />}
            <span className={it.done ? 'text-slate-500 line-through' : 'text-slate-700 font-medium'}>{it.label}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function PlanCard({ sub }: { sub: PlanLimitsResponse | null }) {
  const plan = sub?.plan ?? 'free';
  const isFree = plan === 'free';
  const tier = plan.charAt(0).toUpperCase() + plan.slice(1);
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-2 flex items-center gap-1.5">
        <Crown size={11} /> Subscription
      </div>
      <div className="font-extrabold text-lg" style={{ color: '#1A1A2E' }}>{tier}</div>
      {isFree && <div className="text-[11px] text-slate-500 mb-2">Limited daily usage</div>}
      {isFree ? (
        <Link to="/settings/billing" className="btn btn-primary h-8 text-xs px-3 mt-2 inline-flex">Upgrade</Link>
      ) : (
        <Link to="/settings/billing" className="text-xs font-semibold text-violet-700 hover:underline mt-2 inline-block">Manage →</Link>
      )}
    </div>
  );
}

function ActivityCard({ activity }: { activity: any[] }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-2 flex items-center gap-1.5">
        <Activity size={11} /> Recent activity
      </div>
      {activity.length === 0 ? (
        <div className="text-xs text-slate-500 py-3 text-center">Nothing yet.</div>
      ) : (
        <ul className="space-y-2 text-xs">
          {activity.slice(0, 6).map((a, i) => (
            <li key={i} className="flex gap-2">
              <span className="text-violet-500 font-bold">›</span>
              <span className="text-slate-700">
                <b>{a.actor_name ?? 'Someone'}</b> {a.action} {a.subject_name ?? a.subject_type}
                <span className="text-slate-400"> · {relTime(a.created_at)}</span>
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function UsageProgressCard({ sub }: { sub: PlanLimitsResponse | null }) {
  const isoRem = sub?.usage?.isochrones_remaining_today;
  const poiRem = sub?.usage?.poi_searches_remaining_today;
  const isoMax = (sub?.limits?.['max_isochrones_per_day'] as number) || 0;
  const poiMax = (sub?.limits?.['max_poi_searches_per_day'] as number) || 0;
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Gauge size={11} /> Daily limits
      </div>
      <UsageBar label="Isochrones" remaining={isoRem} max={isoMax} accent="#7848BB" />
      <div className="h-2" />
      <UsageBar label="POI searches" remaining={poiRem} max={poiMax} accent="#10b981" />
    </div>
  );
}
function UsageBar({ label, remaining, max, accent }: { label: string; remaining?: number; max: number; accent: string }) {
  if (!max || max < 0 || remaining === undefined) {
    return (
      <div>
        <div className="flex justify-between text-xs"><span className="text-slate-600 font-medium">{label}</span><span className="text-slate-400">unlimited</span></div>
        <div className="h-1.5 bg-slate-100 rounded mt-1" />
      </div>
    );
  }
  const used = Math.max(0, max - remaining);
  const p = max > 0 ? Math.min(100, Math.round((used / max) * 100)) : 0;
  return (
    <div>
      <div className="flex justify-between text-xs">
        <span className="text-slate-600 font-medium">{label}</span>
        <span className="text-slate-700 tabular-nums font-semibold">{used} / {max}</span>
      </div>
      <div className="h-1.5 bg-slate-100 rounded mt-1 overflow-hidden">
        <div className="h-full rounded transition-all" style={{ width: p + '%', background: accent }} />
      </div>
    </div>
  );
}

const TIPS = [
  'A 1¢ price raise on a popular item often nets more than a $1 cut on a slow one — check Top Moves.',
  'Recost your top-5 sellers monthly to catch ingredient drift before it eats your margin.',
  'Daypart breakdown reveals hidden lunch leakage — compare lunch covers vs dinner.',
  'Plate cost coverage <80% means recommendation accuracy drops — keep recipes current.',
  'Labor >32% is a yellow flag; >35% reliably destroys prime cost.',
  'Connect your POS to unlock automated Carafe recommendations across all items.',
  'Items with margin <55% and falling units are the highest-yield cuts.',
  'Use the Map module to study a candidate site\'s trade-area before signing a lease.',
];
function TipOfDayCard() {
  const idx = Math.floor((new Date().setHours(0,0,0,0) / 86400000)) % TIPS.length;
  return (
    <div className="rounded-xl p-4 border" style={{ background: 'linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%)', borderColor: '#fde68a' }}>
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-amber-700 mb-2">
        <Lightbulb size={11} /> Operator tip
      </div>
      <p className="text-sm text-amber-900 font-medium leading-snug">{TIPS[idx]}</p>
    </div>
  );
}

function WhatsNewCard() {
  const items: { tag: 'New' | 'Improved' | 'Fix'; text: string }[] = [
    { tag: 'New', text: 'AI daily briefing — opens with a 3-bullet chief-of-staff memo.' },
    { tag: 'New', text: 'Threshold alerts: food cost > 32% → text me, etc.' },
    { tag: 'Improved', text: 'Click any KPI to drill into per-restaurant breakdown.' },
  ];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Bell size={11} /> What's new
      </div>
      <ul className="space-y-2">
        {items.map((it, i) => (
          <li key={i} className="text-xs">
            <span className={`inline-block text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded mr-1.5 ${
              it.tag === 'New' ? 'bg-violet-100 text-violet-700'
              : it.tag === 'Improved' ? 'bg-emerald-100 text-emerald-700'
              : 'bg-amber-100 text-amber-700'
            }`}>{it.tag}</span>
            <span className="text-slate-700">{it.text}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function ShortcutsCard() {
  const shortcuts = [
    { keys: ['G', 'R'], label: 'Go to restaurants' },
    { keys: ['G', 'V'], label: 'Go to vendors' },
    { keys: ['G', 'M'], label: 'Go to map' },
    { keys: ['G', 'D'], label: 'Dashboard' },
    { keys: ['?'], label: 'All shortcuts' },
  ];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Keyboard size={11} /> Shortcuts
      </div>
      <ul className="space-y-1.5">
        {shortcuts.map((s) => (
          <li key={s.label} className="flex items-center justify-between text-xs">
            <span className="text-slate-600">{s.label}</span>
            <span className="flex gap-1">
              {s.keys.map((k) => (
                <kbd key={k} className="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 text-[10px] font-bold rounded border border-slate-300 bg-slate-50 text-slate-700">{k}</kbd>
              ))}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function SystemStatusCard() {
  const [status, setStatus] = useState<'unknown' | 'ok' | 'degraded' | 'down'>('unknown');
  const [latency, setLatency] = useState<number | null>(null);
  useEffect(() => {
    const t0 = performance.now();
    api.get('/api/health')
      .then((r) => {
        const ms = Math.round(performance.now() - t0);
        setLatency(ms);
        const ok = r.data?.status === 'ok' || r.data?.success === true || r.data?.ok === true;
        setStatus(ok ? (ms < 800 ? 'ok' : 'degraded') : 'degraded');
      })
      .catch(() => setStatus('down'));
  }, []);
  const tone = status === 'ok' ? '#10b981' : status === 'degraded' ? '#f59e0b' : status === 'down' ? '#dc2626' : '#94a3b8';
  const text = status === 'ok' ? 'All systems operational' : status === 'degraded' ? 'Elevated latency' : status === 'down' ? 'Connectivity issue' : 'Checking…';
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-2 flex items-center gap-1.5">
        <Wifi size={11} /> System status
      </div>
      <div className="flex items-center gap-2">
        <span className="relative flex h-2.5 w-2.5">
          <span className="animate-ping absolute inline-flex h-full w-full rounded-full opacity-50" style={{ background: tone }} />
          <span className="relative inline-flex rounded-full h-2.5 w-2.5" style={{ background: tone }} />
        </span>
        <span className="text-sm font-bold" style={{ color: '#1A1A2E' }}>{text}</span>
      </div>
      {latency !== null && <div className="text-[11px] text-slate-500 mt-1.5">API latency · {latency} ms</div>}
    </div>
  );
}

function MappingModuleCard({ projects, mapStats }: { projects: Project[]; mapStats: DashboardStats | null }) {
  const projectCount = mapStats?.totals.projects ?? projects.length;
  const areaCount = mapStats?.totals.areas ?? 0;
  const popCovered = mapStats?.totals.population ?? 0;
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <MapIcon size={11} /> Trade-area mapping
      </div>
      <ul className="space-y-1.5 text-xs">
        <li className="flex justify-between"><span className="text-slate-600">Projects</span><span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{formatNumber(projectCount)}</span></li>
        <li className="flex justify-between"><span className="text-slate-600">Areas drawn</span><span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{formatNumber(areaCount)}</span></li>
        <li className="flex justify-between"><span className="text-slate-600">Population covered</span><span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{formatCompact(popCovered)}</span></li>
      </ul>
      <Link to="/app" className="text-xs font-semibold text-violet-700 hover:underline mt-3 inline-block">Open map →</Link>
    </div>
  );
}

function ProductsCard() {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3 flex items-center gap-1.5">
        <Globe size={11} /> Across smappen
      </div>
      <ul className="space-y-2">
        <li><Link to="/app/restaurants" className="flex items-center gap-2 text-xs hover:text-violet-700"><ChefHat size={12} className="text-slate-400" /><span className="flex-1 text-slate-700">Restaurants</span><ArrowRight size={11} className="text-slate-300" /></Link></li>
        <li><Link to="/app/vendors" className="flex items-center gap-2 text-xs hover:text-violet-700"><Building2 size={12} className="text-slate-400" /><span className="flex-1 text-slate-700">Vendors</span><ArrowRight size={11} className="text-slate-300" /></Link></li>
        <li><Link to="/app" className="flex items-center gap-2 text-xs hover:text-violet-700"><MapIcon size={12} className="text-slate-400" /><span className="flex-1 text-slate-700">Map / trade area</span><ArrowRight size={11} className="text-slate-300" /></Link></li>
        <li><Link to="/settings/profile" className="flex items-center gap-2 text-xs hover:text-violet-700"><Crown size={12} className="text-slate-400" /><span className="flex-1 text-slate-700">Profile & settings</span><ArrowRight size={11} className="text-slate-300" /></Link></li>
      </ul>
    </div>
  );
}

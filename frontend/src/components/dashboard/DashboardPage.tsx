import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Plus, ArrowRight, BarChart3, Sparkles, Layers, Search,
  Pin, PinOff, Map as MapIcon, Upload, FileText, GitCompare, Users,
  Folder as FolderIcon, Activity, Star, Globe, TrendingUp, TrendingDown,
  Lightbulb, Keyboard, CheckCircle2, Circle, Bell, Crown, Gauge,
  Building2, Wallet, Compass, Wifi, Bookmark, ChefHat,
} from 'lucide-react';
import { projectsApi } from '../../api/projects';
import { api } from '../../api/client';
import { usageApi, formatUsd, type UsageDay } from '../../api/usage';
import { statsApi, type DashboardStats } from '../../api/stats';
import { billingApi } from '../../api/billing';
import { useAuthStore } from '../../stores/authStore';
import { useCostStore } from '../../stores/costStore';
import { formatNumber, formatCompact, formatCurrency } from '../../utils/format';
import AppNav from '../layout/AppNav';
import type { Project, PlanLimitsResponse } from '../../types';

/**
 * /dashboard — landing page after login.
 *
 * 25 widgets organized into:
 *   1. Header — time-aware greeting + day badge
 *   2. Hero KPI strip — Projects / Areas / Population / API spend
 *   3. Quick actions bar
 *   4. Resume last project
 *   5. Main grid: projects (search/pin/health) + side rail (plan, activity, spend chart)
 *   6. Insights grid — type/mode breakdown + top areas + demographics
 *   7. Footer grid — onboarding, what's new, tip, shortcuts, status
 */
export default function DashboardPage() {
  const user = useAuthStore((s) => s.user) as any;
  const totalUsdToday = useCostStore((s) => s.totalUsdToday);

  const [projects, setProjects] = useState<Project[]>([]);
  const [activity, setActivity] = useState<any[]>([]);
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [usageDays, setUsageDays] = useState<UsageDay[]>([]);
  const [sub, setSub] = useState<PlanLimitsResponse | null>(null);
  const [loadingProjects, setLoadingProjects] = useState(true);
  const [search, setSearch] = useState('');
  const [pinned, setPinned] = useState<string[]>(() => {
    try { return JSON.parse(localStorage.getItem('sm_pinned_projects') || '[]'); } catch { return []; }
  });

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const r = await projectsApi.list();
        if (!cancelled) setProjects(r.data ?? []);
      } catch (e) {
        if (!cancelled && import.meta.env.DEV) console.warn('dashboard projects load failed:', e);
      } finally { if (!cancelled) setLoadingProjects(false); }
    })();

    api.get('/api/activity')
      .then((r) => { if (!cancelled) setActivity(r.data?.data?.activity ?? []); })
      .catch((e) => { if (!cancelled && import.meta.env.DEV) console.warn('activity load failed:', e); });

    statsApi.dashboard()
      .then((s) => { if (!cancelled) setStats(s); })
      .catch((e) => { if (!cancelled && import.meta.env.DEV) console.warn('stats load failed:', e); });

    usageApi.days()
      .then((d) => { if (!cancelled) setUsageDays(d); })
      .catch((e) => { if (!cancelled && import.meta.env.DEV) console.warn('usage days failed:', e); });

    billingApi.subscription()
      .then((s) => { if (!cancelled) setSub(s); })
      .catch((e) => { if (!cancelled && import.meta.env.DEV) console.warn('subscription load failed:', e); });

    return () => { cancelled = true; };
  }, []);

  // ── derived ─────────────────────────────────────────────────────────────
  const filteredProjects = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return projects;
    return projects.filter((p) => (p.name ?? '').toLowerCase().includes(q));
  }, [projects, search]);

  const pinnedProjects = useMemo(
    () => filteredProjects.filter((p) => pinned.includes(p.id)),
    [filteredProjects, pinned]
  );
  const unpinnedProjects = useMemo(
    () => filteredProjects.filter((p) => !pinned.includes(p.id)),
    [filteredProjects, pinned]
  );

  const togglePin = (id: string) => {
    setPinned((prev) => {
      const next = prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id];
      localStorage.setItem('sm_pinned_projects', JSON.stringify(next));
      return next;
    });
  };

  // The most recently updated project — drives the "Resume" CTA.
  const lastProject = projects[0];

  const spend7d = useMemo(() => {
    return usageDays.slice(0, 7).reduce((s, d) => s + (d.cost_usd || 0), 0);
  }, [usageDays]);
  const spendPrev7d = useMemo(() => {
    return usageDays.slice(7, 14).reduce((s, d) => s + (d.cost_usd || 0), 0);
  }, [usageDays]);
  const spendDelta = spendPrev7d > 0 ? ((spend7d - spendPrev7d) / spendPrev7d) * 100 : 0;

  // ── render ──────────────────────────────────────────────────────────────
  return (
    <div className="min-h-screen bg-white">
      <AppNav />

      <main className="max-w-7xl mx-auto px-6 py-6">
        {/* ── 1) Time-aware greeting + day badge ─────────────────────────── */}
        <GreetingHeader user={user} />

        {/* ── 2-5) Hero KPI strip ────────────────────────────────────────── */}
        <KpiStrip
          projects={stats?.totals.projects ?? projects.length}
          areas={stats?.totals.areas ?? 0}
          population={stats?.totals.population ?? 0}
          spendToday={totalUsdToday}
          spend7d={spend7d}
          spendDelta={spendDelta}
          usageDays={usageDays}
        />

        {/* ── 6) Quick actions bar ───────────────────────────────────────── */}
        <QuickActions lastProjectId={lastProject?.id} />

        {/* ── 7) Resume last project ─────────────────────────────────────── */}
        {lastProject && <ResumeCard project={lastProject} />}

        <div className="grid lg:grid-cols-3 gap-6 mt-6">
          {/* Left column — projects */}
          <section className="lg:col-span-2 space-y-4">
            <div className="flex items-center justify-between">
              <h2 className="text-lg font-extrabold flex items-center gap-2" style={{ color: '#1A1A2E' }}>
                <Layers size={16} /> Your projects
                {stats?.totals.projects ? (
                  <span className="text-xs font-semibold text-slate-500">· {stats.totals.projects}</span>
                ) : null}
              </h2>
              <button className="btn btn-secondary h-8 text-xs px-3" onClick={createProject}>
                <Plus size={12} /> New project
              </button>
            </div>

            {/* 8) Global project search */}
            <div className="relative">
              <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
              <input
                className="input pl-9 h-10 text-sm"
                placeholder="Search projects by name…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>

            {loadingProjects ? (
              <div className="space-y-2">
                {[0,1,2].map((i) => <div key={i} className="skeleton h-20 w-full" />)}
              </div>
            ) : projects.length === 0 ? (
              <EmptyProjectsState />
            ) : (
              <>
                {/* 9) Pinned projects */}
                {pinnedProjects.length > 0 && (
                  <div>
                    <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-2 flex items-center gap-1.5">
                      <Pin size={11} /> Pinned
                    </div>
                    <ul className="grid sm:grid-cols-2 gap-3">
                      {pinnedProjects.map((p) => (
                        <ProjectCard key={p.id} project={p} pinned onPin={() => togglePin(p.id)} />
                      ))}
                    </ul>
                  </div>
                )}

                {/* 10) Project grid w/ health scores */}
                {unpinnedProjects.length > 0 && (
                  <div>
                    {pinnedProjects.length > 0 && (
                      <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-2 mt-4">
                        All projects
                      </div>
                    )}
                    <ul className="grid sm:grid-cols-2 gap-3">
                      {unpinnedProjects.slice(0, 8).map((p) => (
                        <ProjectCard key={p.id} project={p} onPin={() => togglePin(p.id)} />
                      ))}
                    </ul>
                  </div>
                )}

                {unpinnedProjects.length > 8 && (
                  <Link to="/projects" className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-violet-700 hover:underline">
                    See all {projects.length} projects <ArrowRight size={12} />
                  </Link>
                )}
              </>
            )}
          </section>

          {/* Right side rail */}
          <aside className="space-y-4">
            {/* 11) Subscription / Plan card */}
            <PlanCard sub={sub} />

            {/* 12) Usage progress bars */}
            <UsageProgressCard sub={sub} />

            {/* 13) API spend 7-day chart */}
            <SpendChartCard days={usageDays} totalToday={totalUsdToday} />

            {/* 14) Activity feed */}
            <ActivityCard activity={activity} />
          </aside>
        </div>

        {/* ── Insights grid ──────────────────────────────────────────────── */}
        <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
          {/* 15) Areas by type */}
          <BreakdownCard
            title="Areas by type"
            icon={Compass}
            data={stats?.areas_by_type ?? {}}
            palette={{ isochrone: '#7848BB', radius: '#10b981', manual: '#f59e0b', isodistance: '#3b82f6' }}
          />

          {/* 16) Travel mode breakdown */}
          <BreakdownCard
            title="Travel modes"
            icon={MapIcon}
            data={stats?.travel_mode ?? {}}
            palette={{
              'driving-car': '#7848BB',
              'cycling-regular': '#10b981',
              'foot-walking': '#f59e0b',
              'wheelchair': '#3b82f6',
            }}
            labelMap={{
              'driving-car': 'Driving',
              'cycling-regular': 'Cycling',
              'foot-walking': 'Walking',
              'wheelchair': 'Wheelchair',
            }}
          />

          {/* 17) Top areas by population */}
          <TopAreasCard items={stats?.top_areas ?? []} />

          {/* 18) Avg demographics across areas */}
          <DemographicsAvgCard averages={stats?.averages} totalSqKm={stats?.totals.area_sq_km ?? 0} totalPop={stats?.totals.population ?? 0} />
        </div>

        {/* ── Footer grid ────────────────────────────────────────────────── */}
        <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-4 mt-6 mb-10">
          {/* 19) Onboarding checklist */}
          <OnboardingChecklist
            hasProject={(stats?.totals.projects ?? 0) > 0}
            hasArea={(stats?.totals.areas ?? 0) > 0}
            hasReport={(stats?.totals.reports ?? 0) > 0}
            hasShared={(stats?.totals.shared_projects ?? 0) > 0}
            hasComparison={(stats?.totals.saved_comparisons ?? 0) > 0}
          />

          {/* 20) What's new */}
          <WhatsNewCard />

          {/* 21) Tip of the day */}
          <TipOfDayCard />

          {/* 22) Keyboard shortcuts */}
          <ShortcutsCard />

          {/* 23) Library — saved comparisons & searches */}
          <LibraryCard
            comparisons={stats?.totals.saved_comparisons ?? 0}
            searches={stats?.totals.saved_searches ?? 0}
            folders={stats?.totals.folders ?? 0}
            reports={stats?.totals.reports ?? 0}
          />

          {/* 24) Recent areas (where you left off) */}
          <RecentAreasCard items={stats?.recent_areas ?? []} />

          {/* 25) System status */}
          <SystemStatusCard />

          {/* Sharing & collaboration shortcut — links to vendors/restaurants/share */}
          <ProductsCard
            shared={stats?.totals.shared_projects ?? 0}
          />
        </div>
      </main>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 1 — Greeting header
// ─────────────────────────────────────────────────────────────────────────
function GreetingHeader({ user }: { user: any }) {
  const now = new Date();
  const hour = now.getHours();
  const greet = hour < 5 ? 'Working late' : hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  const first = user?.name ? user.name.split(' ')[0] : '';
  const dayLabel = now.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
  return (
    <div className="flex items-end justify-between mb-6">
      <div>
        <div className="text-xs font-bold uppercase tracking-wider text-violet-600 mb-1">{dayLabel}</div>
        <h1 className="text-3xl font-extrabold" style={{ color: '#1A1A2E' }}>
          {greet}{first ? `, ${first}` : ''}
        </h1>
        <p className="text-slate-600 mt-1">Here's what's happening with your maps today.</p>
      </div>
      <Link to="/app" className="btn btn-primary h-10 px-4 text-sm hidden sm:inline-flex">Open map →</Link>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widgets 2-5 — Hero KPI strip
// ─────────────────────────────────────────────────────────────────────────
interface KpiStripProps {
  projects: number; areas: number; population: number;
  spendToday: number; spend7d: number; spendDelta: number;
  usageDays: UsageDay[];
}
function KpiStrip(p: KpiStripProps) {
  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
      <KpiTile
        icon={Layers}
        label="Projects"
        value={formatNumber(p.projects)}
        accent="#7848BB"
        href="/projects"
      />
      <KpiTile
        icon={MapIcon}
        label="Areas drawn"
        value={formatNumber(p.areas)}
        accent="#10b981"
        href="/app"
      />
      <KpiTile
        icon={Users}
        label="Population covered"
        value={formatCompact(p.population)}
        accent="#3b82f6"
        sub={p.population > 0 ? 'across all areas' : 'add demographics to areas'}
      />
      <KpiTile
        icon={Wallet}
        label="API spend (today)"
        value={'$' + p.spendToday.toFixed(2)}
        accent="#f59e0b"
        sub={
          p.spend7d > 0 ? (
            <span className="flex items-center gap-1">
              {p.spendDelta > 0 ? <TrendingUp size={11} /> : <TrendingDown size={11} />}
              ${p.spend7d.toFixed(2)} last 7d
              {Math.abs(p.spendDelta) > 1 && (
                <span className={p.spendDelta > 0 ? 'text-amber-600' : 'text-emerald-600'}>
                  ({p.spendDelta > 0 ? '+' : ''}{p.spendDelta.toFixed(0)}%)
                </span>
              )}
            </span>
          ) : '7d trend pending'
        }
        sparkline={p.usageDays.slice(0, 14).map((d) => d.cost_usd).reverse()}
      />
    </div>
  );
}
function KpiTile({
  icon: Icon, label, value, accent, sub, sparkline, href,
}: {
  icon: any; label: string; value: string;
  accent: string; sub?: any; sparkline?: number[]; href?: string;
}) {
  const inner = (
    <div className="rounded-xl border border-slate-200 bg-white p-4 hover:border-violet-300 transition-colors relative overflow-hidden">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500">
        <Icon size={12} style={{ color: accent }} /> {label}
      </div>
      <div className="text-2xl font-extrabold mt-1 tabular-nums" style={{ color: '#1A1A2E' }}>{value}</div>
      {sub && <div className="text-[11px] text-slate-500 mt-1">{sub}</div>}
      {sparkline && sparkline.length > 1 && (
        <div className="mt-2"><Sparkline data={sparkline} stroke={accent} /></div>
      )}
    </div>
  );
  return href ? <Link to={href} className="block">{inner}</Link> : inner;
}

function Sparkline({ data, stroke }: { data: number[]; stroke: string }) {
  if (data.length < 2) return null;
  const w = 100, h = 24, pad = 1;
  const max = Math.max(...data, 0.0001);
  const min = Math.min(...data);
  const range = max - min || 1;
  const pts = data.map((v, i) => {
    const x = pad + (i / (data.length - 1)) * (w - pad * 2);
    const y = h - pad - ((v - min) / range) * (h - pad * 2);
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  }).join(' ');
  return (
    <svg viewBox={`0 0 ${w} ${h}`} className="w-full h-6">
      <polyline points={pts} fill="none" stroke={stroke} strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 6 — Quick actions bar
// ─────────────────────────────────────────────────────────────────────────
function QuickActions({ lastProjectId }: { lastProjectId?: string }) {
  return (
    <div className="flex flex-wrap gap-2 mb-5">
      <button onClick={createProject} className="btn btn-primary h-9 text-sm px-3">
        <Plus size={14} /> New project
      </button>
      <Link
        to={lastProjectId ? `/app#project=${lastProjectId}` : '/app'}
        className="btn btn-secondary h-9 text-sm px-3"
      >
        <MapIcon size={14} /> Open map
      </Link>
      <Link to="/app#import" className="btn btn-secondary h-9 text-sm px-3">
        <Upload size={14} /> Import points
      </Link>
      <Link to="/app#reports" className="btn btn-secondary h-9 text-sm px-3">
        <FileText size={14} /> Generate report
      </Link>
      <Link to="/app#compare" className="btn btn-secondary h-9 text-sm px-3">
        <GitCompare size={14} /> Compare areas
      </Link>
      <Link to="/app/restaurants" className="btn btn-secondary h-9 text-sm px-3">
        <ChefHat size={14} /> Restaurants
      </Link>
      <Link to="/app/vendors" className="btn btn-secondary h-9 text-sm px-3">
        <Building2 size={14} /> Vendors
      </Link>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 7 — Resume last project card
// ─────────────────────────────────────────────────────────────────────────
function ResumeCard({ project }: { project: Project }) {
  return (
    <Link
      to={`/app#project=${project.id}`}
      className="block rounded-2xl p-5 mb-2 transition-all"
      style={{
        background: 'linear-gradient(135deg, rgba(120,72,187,0.08) 0%, rgba(120,72,187,0.02) 100%)',
        border: '1px solid rgba(120,72,187,0.18)',
      }}
    >
      <div className="flex items-center justify-between gap-4">
        <div>
          <div className="text-[11px] uppercase tracking-wider font-bold text-violet-700 mb-1">Resume where you left off</div>
          <div className="text-xl font-extrabold" style={{ color: '#1A1A2E' }}>{project.name}</div>
          <div className="text-xs text-slate-600 mt-1">
            {project.area_count ?? 0} area{project.area_count === 1 ? '' : 's'}
            {project.updated_at ? ` · last edited ${relTime(project.updated_at)}` : ''}
          </div>
        </div>
        <div className="flex items-center gap-2 text-violet-700 font-bold text-sm">
          Continue <ArrowRight size={16} />
        </div>
      </div>
    </Link>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 10 — Project card with health score
// ─────────────────────────────────────────────────────────────────────────
function ProjectCard({
  project, pinned, onPin,
}: { project: Project; pinned?: boolean; onPin?: () => void }) {
  // Health: has-name (1) + has-areas (1) + recently-updated (1) + has-description (1)
  let score = 0;
  if (project.name && project.name.length > 1) score++;
  if ((project.area_count ?? 0) > 0) score++;
  if (project.updated_at && (Date.now() - new Date(project.updated_at).getTime()) < 30 * 86400000) score++;
  if (project.description) score++;
  const max = 4;
  const pct = Math.round((score / max) * 100);
  const tone = pct >= 75 ? 'emerald' : pct >= 50 ? 'amber' : 'slate';
  const toneClasses: Record<string, string> = {
    emerald: 'bg-emerald-50 text-emerald-700',
    amber: 'bg-amber-50 text-amber-700',
    slate: 'bg-slate-100 text-slate-600',
  };
  return (
    <li>
      <div className="block rounded-xl border border-slate-200 bg-white p-4 hover:border-violet-400 hover:shadow-sm transition-all relative group">
        <button
          onClick={(e) => { e.preventDefault(); e.stopPropagation(); onPin?.(); }}
          className="absolute top-3 right-3 text-slate-300 hover:text-violet-600 transition-colors"
          title={pinned ? 'Unpin project' : 'Pin project'}
        >
          {pinned ? <Pin size={14} className="text-violet-600" /> : <PinOff size={14} />}
        </button>
        <Link to={`/app#project=${project.id}`} className="block pr-6">
          <div className="font-bold truncate" style={{ color: '#1A1A2E' }}>{project.name}</div>
          <div className="text-xs text-slate-500 mt-1 flex items-center gap-2 flex-wrap">
            <span>{project.area_count ?? 0} area{project.area_count === 1 ? '' : 's'}</span>
            {project.updated_at && <span>· {relTime(project.updated_at)}</span>}
            {(project.is_shared as any) && <span className="inline-flex items-center gap-1 text-violet-600"><Globe size={10} />shared</span>}
          </div>
          <div className="mt-2 flex items-center gap-2">
            <div className={`text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded ${toneClasses[tone]}`}>
              {pct}% health
            </div>
            <div className="flex-1 h-1 bg-slate-100 rounded overflow-hidden">
              <div className="h-full" style={{ width: pct + '%', background: tone === 'emerald' ? '#10b981' : tone === 'amber' ? '#f59e0b' : '#94a3b8' }} />
            </div>
          </div>
        </Link>
      </div>
    </li>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 11 — Plan card
// ─────────────────────────────────────────────────────────────────────────
function PlanCard({ sub }: { sub: PlanLimitsResponse | null }) {
  const plan = sub?.plan ?? 'free';
  const isFree = plan === 'free';
  const tier = plan.charAt(0).toUpperCase() + plan.slice(1);
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-2">
        <Crown size={11} /> Subscription
      </div>
      <div className="flex items-center justify-between">
        <div>
          <div className="font-extrabold text-lg" style={{ color: '#1A1A2E' }}>{tier}</div>
          {isFree && <div className="text-[11px] text-slate-500">Limited daily usage</div>}
        </div>
        {isFree ? (
          <Link to="/settings/billing" className="btn btn-primary h-8 text-xs px-3">Upgrade</Link>
        ) : (
          <Link to="/settings/billing" className="text-xs font-semibold text-violet-700 hover:underline">Manage →</Link>
        )}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 12 — Usage progress bars
// ─────────────────────────────────────────────────────────────────────────
function UsageProgressCard({ sub }: { sub: PlanLimitsResponse | null }) {
  const isoRem = sub?.usage?.isochrones_remaining_today;
  const poiRem = sub?.usage?.poi_searches_remaining_today;
  const isoMax = (sub?.limits?.['max_isochrones_per_day'] as number) || 0;
  const poiMax = (sub?.limits?.['max_poi_searches_per_day'] as number) || 0;
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
        <Gauge size={11} /> Daily usage
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
  const pct = max > 0 ? Math.min(100, Math.round((used / max) * 100)) : 0;
  return (
    <div>
      <div className="flex justify-between text-xs">
        <span className="text-slate-600 font-medium">{label}</span>
        <span className="text-slate-700 tabular-nums font-semibold">{used} / {max}</span>
      </div>
      <div className="h-1.5 bg-slate-100 rounded mt-1 overflow-hidden">
        <div className="h-full rounded transition-all" style={{ width: pct + '%', background: accent }} />
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 13 — API spend 7-day chart
// ─────────────────────────────────────────────────────────────────────────
function SpendChartCard({ days, totalToday }: { days: UsageDay[]; totalToday: number }) {
  // Build last-7-days array (oldest → newest) even if some days have no data.
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const buckets: { day: string; cost: number; label: string }[] = [];
  for (let i = 6; i >= 0; i--) {
    const d = new Date(today); d.setDate(d.getDate() - i);
    const key = d.toISOString().slice(0, 10);
    const found = days.find((x) => x.day === key);
    const lbl = d.toLocaleDateString(undefined, { weekday: 'short' }).slice(0, 1);
    buckets.push({ day: key, cost: found?.cost_usd ?? (i === 0 ? totalToday : 0), label: lbl });
  }
  const max = Math.max(...buckets.map((b) => b.cost), 0.01);
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center justify-between mb-3">
        <div className="text-[11px] uppercase tracking-wider font-bold text-slate-500 flex items-center gap-1.5">
          <BarChart3 size={11} /> API spend · 7 days
        </div>
        <Link to="/settings/billing" className="text-[11px] font-semibold text-violet-700 hover:underline">Details →</Link>
      </div>
      <div className="flex items-end gap-1.5 h-20">
        {buckets.map((b, i) => {
          const h = b.cost > 0 ? Math.max(3, (b.cost / max) * 64) : 2;
          const isToday = i === buckets.length - 1;
          return (
            <div key={b.day} className="flex-1 flex flex-col items-center gap-1" title={b.day + ': ' + formatUsd(b.cost)}>
              <div
                className="w-full rounded-t transition-all"
                style={{ height: h + 'px', background: isToday ? '#7848BB' : '#cbd5e1' }}
              />
              <div className="text-[9px] font-bold text-slate-400">{b.label}</div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 14 — Recent activity
// ─────────────────────────────────────────────────────────────────────────
function ActivityCard({ activity }: { activity: any[] }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center justify-between mb-2">
        <h3 className="text-[11px] uppercase tracking-wider font-bold text-slate-500 flex items-center gap-1.5">
          <Activity size={11} /> Recent activity
        </h3>
        {activity.length > 0 && <span className="text-[10px] text-slate-400">{activity.length}</span>}
      </div>
      {activity.length === 0 ? (
        <div className="text-xs text-slate-500 py-3 text-center">Nothing yet — your activity log starts as you work.</div>
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

// ─────────────────────────────────────────────────────────────────────────
// Widgets 15 & 16 — Breakdown donut card
// ─────────────────────────────────────────────────────────────────────────
function BreakdownCard({
  title, icon: Icon, data, palette, labelMap,
}: {
  title: string; icon: any;
  data: Record<string, number>;
  palette: Record<string, string>;
  labelMap?: Record<string, string>;
}) {
  const entries = Object.entries(data).sort((a, b) => b[1] - a[1]);
  const total = entries.reduce((s, [, v]) => s + v, 0);
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
        <Icon size={11} /> {title}
      </div>
      {total === 0 ? (
        <div className="text-xs text-slate-400 py-3 text-center">No data yet.</div>
      ) : (
        <>
          <div className="flex items-center gap-3">
            <DonutSvg entries={entries} palette={palette} />
            <ul className="space-y-1 text-xs flex-1">
              {entries.slice(0, 4).map(([k, v]) => (
                <li key={k} className="flex items-center gap-2">
                  <span className="w-2 h-2 rounded-full" style={{ background: palette[k] ?? '#94a3b8' }} />
                  <span className="text-slate-600 truncate flex-1">{labelMap?.[k] ?? k}</span>
                  <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{v}</span>
                </li>
              ))}
            </ul>
          </div>
          <div className="text-[11px] text-slate-500 mt-2">{total} total</div>
        </>
      )}
    </div>
  );
}
function DonutSvg({ entries, palette }: { entries: [string, number][]; palette: Record<string, string> }) {
  const total = entries.reduce((s, [, v]) => s + v, 0) || 1;
  const r = 24, cx = 30, cy = 30, c = 2 * Math.PI * r;
  let offset = 0;
  return (
    <svg viewBox="0 0 60 60" className="w-16 h-16 -rotate-90 flex-shrink-0">
      <circle cx={cx} cy={cy} r={r} fill="none" stroke="#f1f5f9" strokeWidth="8" />
      {entries.map(([k, v]) => {
        const frac = v / total;
        const dash = c * frac;
        const gap = c - dash;
        const seg = (
          <circle
            key={k}
            cx={cx}
            cy={cy}
            r={r}
            fill="none"
            stroke={palette[k] ?? '#94a3b8'}
            strokeWidth="8"
            strokeDasharray={`${dash} ${gap}`}
            strokeDashoffset={-offset}
          />
        );
        offset += dash;
        return seg;
      })}
    </svg>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 17 — Top areas by population
// ─────────────────────────────────────────────────────────────────────────
function TopAreasCard({ items }: { items: { id: string; name: string; project_name: string; population: number }[] }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
        <Star size={11} /> Top areas
      </div>
      {items.length === 0 ? (
        <div className="text-xs text-slate-400 py-3 text-center">Add demographics to your areas to see rankings.</div>
      ) : (
        <ol className="space-y-2">
          {items.map((a, i) => (
            <li key={a.id} className="flex items-center gap-2 text-xs">
              <span className="w-4 h-4 inline-flex items-center justify-center text-[10px] font-bold rounded bg-violet-100 text-violet-700">{i + 1}</span>
              <Link to={`/app#area=${a.id}`} className="flex-1 truncate font-semibold hover:text-violet-700" style={{ color: '#1A1A2E' }}>{a.name}</Link>
              <span className="text-slate-500 tabular-nums">{formatCompact(a.population)}</span>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 18 — Demographics average
// ─────────────────────────────────────────────────────────────────────────
function DemographicsAvgCard({
  averages, totalSqKm, totalPop,
}: { averages?: any; totalSqKm: number; totalPop: number }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
        <Users size={11} /> Demographics avg
      </div>
      <ul className="space-y-2 text-xs">
        <li className="flex justify-between"><span className="text-slate-600">Median income</span><span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{averages?.median_income ? formatCurrency(averages.median_income) : '—'}</span></li>
        <li className="flex justify-between"><span className="text-slate-600">Density / sq km</span><span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{averages?.density_per_sq_km != null ? formatNumber(Math.round(averages.density_per_sq_km)) : '—'}</span></li>
        <li className="flex justify-between"><span className="text-slate-600">Unemployment</span><span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{averages?.unemployment_rate != null ? averages.unemployment_rate.toFixed(1) + '%' : '—'}</span></li>
        <li className="flex justify-between border-t border-slate-100 pt-2 mt-2"><span className="text-slate-600">Total area</span><span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{formatNumber(totalSqKm)} km²</span></li>
        <li className="flex justify-between"><span className="text-slate-600">Total population</span><span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{formatCompact(totalPop)}</span></li>
      </ul>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 19 — Onboarding checklist
// ─────────────────────────────────────────────────────────────────────────
function OnboardingChecklist({
  hasProject, hasArea, hasReport, hasShared, hasComparison,
}: { hasProject: boolean; hasArea: boolean; hasReport: boolean; hasShared: boolean; hasComparison: boolean }) {
  const items = [
    { done: hasProject, label: 'Create a project', to: '/dashboard' },
    { done: hasArea, label: 'Draw your first area', to: '/app' },
    { done: hasReport, label: 'Generate a report', to: '/app#reports' },
    { done: hasComparison, label: 'Compare two areas', to: '/app#compare' },
    { done: hasShared, label: 'Share a project', to: '/app#share' },
  ];
  const done = items.filter((i) => i.done).length;
  const pct = Math.round((done / items.length) * 100);
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-2">
        <CheckCircle2 size={11} /> Getting started · {pct}%
      </div>
      <div className="h-1 bg-slate-100 rounded mb-3 overflow-hidden">
        <div className="h-full bg-violet-600" style={{ width: pct + '%' }} />
      </div>
      <ul className="space-y-1.5">
        {items.map((it) => (
          <li key={it.label}>
            <Link to={it.to} className="flex items-center gap-2 text-xs hover:text-violet-700">
              {it.done
                ? <CheckCircle2 size={13} className="text-emerald-500 flex-shrink-0" />
                : <Circle size={13} className="text-slate-300 flex-shrink-0" />}
              <span className={it.done ? 'text-slate-500 line-through' : 'text-slate-700 font-medium'}>{it.label}</span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 20 — What's new
// ─────────────────────────────────────────────────────────────────────────
function WhatsNewCard() {
  const items = [
    { tag: 'New', text: 'Dashboard refreshed — KPIs, spend trends, and a tip of the day.' },
    { tag: 'Improved', text: 'Drive-time matrix now supports walking and cycling modes.' },
    { tag: 'Fix', text: 'Faster area boundary rebuilds for projects with 50+ tracts.' },
  ];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
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

// ─────────────────────────────────────────────────────────────────────────
// Widget 21 — Tip of the day
// ─────────────────────────────────────────────────────────────────────────
const TIPS = [
  'Hold ⌥/Alt and click on the map to drop an isochrone fast.',
  'Compare two areas side-by-side from the area menu → "Compare".',
  'Import a CSV of customer addresses to plot them as points.',
  'Pin your most-used projects on this dashboard for quick access.',
  'Use the rebuild-boundary button on dense areas for a tighter polygon.',
  'Drive-time matrix can compute up to 50×50 origins × destinations.',
  'Share a read-only link to a project from the share panel.',
  'Toggle dark mode in Settings → Profile.',
];
function TipOfDayCard() {
  // Rotate daily — stable within a calendar day.
  const idx = Math.floor((new Date().setHours(0,0,0,0) / 86400000)) % TIPS.length;
  return (
    <div className="rounded-xl p-4 border" style={{ background: 'linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%)', borderColor: '#fde68a' }}>
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-amber-700 mb-2">
        <Lightbulb size={11} /> Tip of the day
      </div>
      <p className="text-sm text-amber-900 font-medium leading-snug">{TIPS[idx]}</p>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 22 — Keyboard shortcuts
// ─────────────────────────────────────────────────────────────────────────
function ShortcutsCard() {
  const shortcuts = [
    { keys: ['/'], label: 'Focus search' },
    { keys: ['G', 'M'], label: 'Go to map' },
    { keys: ['G', 'D'], label: 'Go to dashboard' },
    { keys: ['N'], label: 'New area' },
    { keys: ['?'], label: 'All shortcuts' },
  ];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
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

// ─────────────────────────────────────────────────────────────────────────
// Widget 23 — Library (saved comparisons / searches / folders / reports)
// ─────────────────────────────────────────────────────────────────────────
function LibraryCard({
  comparisons, searches, folders, reports,
}: { comparisons: number; searches: number; folders: number; reports: number }) {
  const rows = [
    { icon: GitCompare, label: 'Saved comparisons', count: comparisons, to: '/app#compare' },
    { icon: Bookmark, label: 'Saved searches', count: searches, to: '/app#search' },
    { icon: FolderIcon, label: 'Folders', count: folders, to: '/app' },
    { icon: FileText, label: 'Reports generated', count: reports, to: '/app#reports' },
  ];
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
        <Bookmark size={11} /> Library
      </div>
      <ul className="space-y-2">
        {rows.map((r) => (
          <li key={r.label}>
            <Link to={r.to} className="flex items-center gap-2 text-xs hover:text-violet-700 group">
              <r.icon size={12} className="text-slate-400 group-hover:text-violet-600" />
              <span className="flex-1 text-slate-700">{r.label}</span>
              <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{r.count}</span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 24 — Recent areas (where you left off)
// ─────────────────────────────────────────────────────────────────────────
function RecentAreasCard({ items }: { items: { id: string; name: string; area_type: string; project_id: string; project_name: string; updated_at: string }[] }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
        <Sparkles size={11} /> Recently edited areas
      </div>
      {items.length === 0 ? (
        <div className="text-xs text-slate-400 py-3 text-center">No areas yet.</div>
      ) : (
        <ul className="space-y-1.5">
          {items.map((a) => (
            <li key={a.id}>
              <Link to={`/app#area=${a.id}`} className="text-xs hover:text-violet-700 flex items-center gap-2 group">
                <span className="w-1.5 h-1.5 rounded-full bg-violet-500 group-hover:bg-violet-700" />
                <span className="flex-1 truncate">
                  <span className="font-semibold" style={{ color: '#1A1A2E' }}>{a.name}</span>
                  <span className="text-slate-400"> · {a.project_name}</span>
                </span>
                <span className="text-[10px] text-slate-400">{relTime(a.updated_at)}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Widget 25 — System status
// ─────────────────────────────────────────────────────────────────────────
function SystemStatusCard() {
  const [status, setStatus] = useState<'unknown' | 'ok' | 'degraded' | 'down'>('unknown');
  const [latency, setLatency] = useState<number | null>(null);
  useEffect(() => {
    const t0 = performance.now();
    api.get('/api/health')
      .then((r) => {
        const ms = Math.round(performance.now() - t0);
        setLatency(ms);
        const ok = r.data?.status === 'ok' || r.data?.success === true;
        setStatus(ok ? (ms < 800 ? 'ok' : 'degraded') : 'degraded');
      })
      .catch(() => setStatus('down'));
  }, []);
  const tone = status === 'ok' ? '#10b981' : status === 'degraded' ? '#f59e0b' : status === 'down' ? '#dc2626' : '#94a3b8';
  const text = status === 'ok' ? 'All systems operational' : status === 'degraded' ? 'Elevated latency' : status === 'down' ? 'Connectivity issue' : 'Checking…';
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-2">
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

// ─────────────────────────────────────────────────────────────────────────
// Cross-product shortcut (vendors / restaurants / share)
// ─────────────────────────────────────────────────────────────────────────
function ProductsCard({ shared }: { shared: number }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-3">
        <Globe size={11} /> Across smappen
      </div>
      <ul className="space-y-2">
        <li><Link to="/app/restaurants" className="flex items-center gap-2 text-xs hover:text-violet-700"><ChefHat size={12} className="text-slate-400" /><span className="flex-1 text-slate-700">Restaurants</span><ArrowRight size={11} className="text-slate-300" /></Link></li>
        <li><Link to="/app/vendors" className="flex items-center gap-2 text-xs hover:text-violet-700"><Building2 size={12} className="text-slate-400" /><span className="flex-1 text-slate-700">Vendors</span><ArrowRight size={11} className="text-slate-300" /></Link></li>
        <li><Link to="/app#share" className="flex items-center gap-2 text-xs hover:text-violet-700"><Globe size={12} className="text-slate-400" /><span className="flex-1 text-slate-700">Shared projects</span><span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{shared}</span></Link></li>
        <li><Link to="/settings/profile" className="flex items-center gap-2 text-xs hover:text-violet-700"><Crown size={12} className="text-slate-400" /><span className="flex-1 text-slate-700">Profile & settings</span><ArrowRight size={11} className="text-slate-300" /></Link></li>
      </ul>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Empty-state when no projects yet
// ─────────────────────────────────────────────────────────────────────────
function EmptyProjectsState() {
  return (
    <div className="rounded-xl border border-dashed border-violet-300 p-8 text-center bg-white">
      <Sparkles size={28} className="mx-auto text-violet-500 mb-3" />
      <div className="font-bold" style={{ color: '#1A1A2E' }}>No projects yet</div>
      <p className="text-sm text-slate-500 mt-1">Create your first project, or clone our sample to explore.</p>
      <div className="flex gap-2 justify-center mt-4">
        <button className="btn btn-primary h-9" onClick={createProject}>Create project</button>
        <button className="btn btn-secondary h-9" onClick={cloneSample}>Try the demo</button>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────
function relTime(s: string): string {
  if (!s) return '';
  const t = new Date(s).getTime();
  const d = (Date.now() - t) / 1000;
  if (d < 60) return 'just now';
  if (d < 3600) return Math.round(d / 60) + 'm ago';
  if (d < 86400) return Math.round(d / 3600) + 'h ago';
  if (d < 604800) return Math.round(d / 86400) + 'd ago';
  return new Date(s).toLocaleDateString();
}

async function createProject() {
  const name = prompt('Project name:');
  if (!name) return;
  try {
    const p = await projectsApi.create({ name });
    window.location.href = '/app#project=' + p.id;
  } catch {}
}

async function cloneSample() {
  try {
    const r = await api.post('/api/onboarding/clone-sample');
    window.location.href = '/app#project=' + r.data.data.project_id;
  } catch {
    alert('Sample project not available on this server.');
  }
}

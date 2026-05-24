import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus, ArrowRight, BarChart3, Sparkles, Layers, DollarSign } from 'lucide-react';
import { projectsApi } from '../../api/projects';
import { api } from '../../api/client';
import { useAuthStore } from '../../stores/authStore';
import { useCostStore } from '../../stores/costStore';
import { formatNumber } from '../../utils/format';

/**
 * /dashboard — landing page after login.
 *
 * Three column grid:
 *   1. Project cards (search + filter + create)
 *   2. Recent activity from `activity_log`
 *   3. Usage summary (cost-today, areas this month, reports this month)
 *
 * Mobile: stacks vertically.
 */
export default function DashboardPage() {
  const user = useAuthStore((s) => s.user) as any;
  const [projects, setProjects] = useState<any[]>([]);
  const [activity, setActivity] = useState<any[]>([]);
  const [loadingProjects, setLoadingProjects] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const r = await projectsApi.list();
        if (!cancelled) setProjects(r.data ?? []);
      } catch {}
      finally { if (!cancelled) setLoadingProjects(false); }
    })();
    api.get('/api/activity').then((r) => !cancelled && setActivity(r.data?.data?.activity ?? [])).catch(() => {});
    return () => { cancelled = true; };
  }, []);

  const totalUsdToday = useCostStore((s) => s.totalUsdToday);

  return (
    <div className="min-h-screen" style={{ background: '#F9F9FB' }}>
      <header className="bg-white border-b border-slate-200">
        <div className="max-w-6xl mx-auto px-6 py-3 flex items-center justify-between">
          <Link to="/dashboard" className="flex items-center gap-2 font-extrabold text-[16px]" style={{ color: '#1A1A2E' }}>
            <span
              className="inline-flex items-center justify-center w-8 h-8 rounded-lg text-white font-extrabold text-base shadow-sm"
              style={{ background: 'linear-gradient(135deg, #F57C00 0%, #E53935 50%, #7848BB 100%)' }}
            >S</span>
            smappen
          </Link>
          <nav className="flex items-center gap-4 text-sm font-semibold text-slate-700">
            <Link to="/projects" className="hover:text-violet-700">Projects</Link>
            <Link to="/settings/profile" className="hover:text-violet-700">Settings</Link>
            <Link to="/changelog" className="hover:text-violet-700">What's new</Link>
          </nav>
        </div>
      </header>

      <main className="max-w-6xl mx-auto px-6 py-8">
        <div className="flex items-end justify-between mb-8">
          <div>
            <h1 className="text-3xl font-extrabold" style={{ color: '#1A1A2E' }}>
              Welcome back{user?.name ? `, ${user.name.split(' ')[0]}` : ''}
            </h1>
            <p className="text-slate-600 mt-1">Pick up where you left off or kick off something new.</p>
          </div>
          <Link to="/app" className="btn btn-primary h-10 px-4 text-sm">Open map →</Link>
        </div>

        <div className="grid lg:grid-cols-3 gap-6">
          {/* Column 1 — projects */}
          <section className="lg:col-span-2">
            <div className="flex items-center justify-between mb-3">
              <h2 className="text-lg font-extrabold flex items-center gap-2" style={{ color: '#1A1A2E' }}>
                <Layers size={16} /> Your projects
              </h2>
              <button className="btn btn-secondary h-8 text-xs px-3" onClick={createProject}>
                <Plus size={12} /> New project
              </button>
            </div>
            {loadingProjects ? (
              <div className="space-y-2">
                {[0,1,2].map((i) => <div key={i} className="skeleton h-20 w-full" />)}
              </div>
            ) : projects.length === 0 ? (
              <div className="rounded-xl border border-dashed border-violet-300 p-8 text-center bg-white">
                <Sparkles size={28} className="mx-auto text-violet-500 mb-3" />
                <div className="font-bold" style={{ color: '#1A1A2E' }}>No projects yet</div>
                <p className="text-sm text-slate-500 mt-1">Create your first project, or clone our sample to explore.</p>
                <div className="flex gap-2 justify-center mt-4">
                  <button className="btn btn-primary h-9" onClick={createProject}>Create project</button>
                  <button className="btn btn-secondary h-9" onClick={cloneSample}>Try the demo</button>
                </div>
              </div>
            ) : (
              <ul className="grid sm:grid-cols-2 gap-3">
                {projects.slice(0, 8).map((p) => (
                  <li key={p.id}>
                    <Link
                      to={`/app#project=${p.id}`}
                      className="block rounded-xl border border-slate-200 bg-white p-4 hover:border-violet-400 hover:shadow-sm transition-all"
                    >
                      <div className="font-bold truncate" style={{ color: '#1A1A2E' }}>{p.name}</div>
                      <div className="text-xs text-slate-500 mt-1">
                        {p.area_count ?? 0} area{p.area_count === 1 ? '' : 's'}
                        {p.updated_at ? ' · ' + relTime(p.updated_at) : ''}
                      </div>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
            {projects.length > 8 && (
              <Link to="/projects" className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-violet-700 hover:underline">
                See all {projects.length} projects <ArrowRight size={12} />
              </Link>
            )}
          </section>

          {/* Column 2 — activity + usage */}
          <aside className="space-y-4">
            <div className="rounded-xl border border-slate-200 bg-white p-4">
              <h3 className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-2 flex items-center gap-1.5">
                <BarChart3 size={11} /> Usage
              </h3>
              <div className="space-y-2 text-sm">
                <UsageRow icon={DollarSign} label="API spend today" value={'$' + totalUsdToday.toFixed(2)} />
                <UsageRow icon={Layers} label="Projects" value={String(projects.length)} />
              </div>
              <Link to="/settings/billing" className="text-xs text-violet-700 hover:underline mt-3 inline-block">Manage billing →</Link>
            </div>

            <div className="rounded-xl border border-slate-200 bg-white p-4">
              <h3 className="text-[11px] uppercase tracking-wider font-bold text-slate-500 mb-2 flex items-center gap-1.5">
                <Sparkles size={11} /> Recent activity
              </h3>
              {activity.length === 0 ? (
                <div className="text-xs text-slate-500 py-3 text-center">Nothing yet — your activity log starts as you work.</div>
              ) : (
                <ul className="space-y-2 text-xs">
                  {activity.slice(0, 8).map((a, i) => (
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
          </aside>
        </div>
      </main>
    </div>
  );
}

function UsageRow({ icon: Icon, label, value }: { icon: any; label: string; value: string }) {
  return (
    <div className="flex items-center gap-2">
      <Icon size={12} className="text-slate-400 shrink-0" />
      <span className="text-slate-600 text-xs flex-1">{label}</span>
      <span className="font-bold tabular-nums" style={{ color: '#1A1A2E' }}>{value}</span>
    </div>
  );
}

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

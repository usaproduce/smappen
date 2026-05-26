import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus, Search, Layers, Grid3x3, List as ListIcon, Trash2, Edit3, Archive, ArrowRight } from 'lucide-react';
import toast from 'react-hot-toast';
import { projectsApi } from '../../api/projects';
import { api } from '../../api/client';
import AppNav from '../layout/AppNav';

type ViewMode = 'grid' | 'list';

interface ProjectRow {
  id: string;
  name: string;
  description?: string | null;
  area_count?: number;
  updated_at?: string;
  thumbnail_url?: string | null;
}

/**
 * /projects — full project gallery. Replaces the cramped sidebar switcher
 * for the case where a user has 20+ projects and wants to triage them.
 *
 * Features:
 *   - Grid / list view toggle (persisted to localStorage)
 *   - Search + sort (recent / name / area-count)
 *   - Per-card actions: rename, archive, delete
 *   - Empty-state CTA
 *   - Server-side pagination at 24 per page
 */
export default function ProjectGalleryPage() {
  const [projects, setProjects] = useState<ProjectRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [query, setQuery] = useState('');
  const [sort, setSort] = useState<'recent' | 'name' | 'areas'>('recent');
  const [view, setView] = useState<ViewMode>(() => (localStorage.getItem('projects_view') as ViewMode) || 'grid');
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);

  async function load(p = 1, q = query) {
    setLoading(true);
    try {
      const r = await projectsApi.list({ search: q || undefined, page: p, per_page: 24 });
      setProjects(r.data as any);
      setTotal(r.meta?.total ?? r.data.length);
      setPage(p);
    } finally { setLoading(false); }
  }

  useEffect(() => { load(1); /* initial */ }, []);
  useEffect(() => { localStorage.setItem('projects_view', view); }, [view]);

  const sorted = [...projects].sort((a, b) => {
    if (sort === 'name') return a.name.localeCompare(b.name);
    if (sort === 'areas') return (b.area_count ?? 0) - (a.area_count ?? 0);
    return new Date(b.updated_at ?? 0).getTime() - new Date(a.updated_at ?? 0).getTime();
  });

  async function rename(id: string, current: string) {
    const next = prompt('Rename project', current);
    if (!next || next === current) return;
    try {
      await projectsApi.update(id, { name: next });
      setProjects((ps) => ps.map((p) => (p.id === id ? { ...p, name: next } : p)));
    } catch { toast.error('Rename failed'); }
  }

  async function archive(id: string) {
    if (!confirm('Archive this project? You can restore it from settings later.')) return;
    try {
      await api.post(`/api/projects/${id}/archive`);
      setProjects((ps) => ps.filter((p) => p.id !== id));
      toast.success('Project archived');
    } catch { toast.error('Archive failed'); }
  }

  async function destroy(id: string, name: string) {
    if (!confirm(`Delete "${name}" permanently? This can't be undone.`)) return;
    try {
      await projectsApi.delete(id);
      setProjects((ps) => ps.filter((p) => p.id !== id));
      toast.success('Project deleted');
    } catch { toast.error('Delete failed'); }
  }

  async function createNew() {
    const name = prompt('New project name');
    if (!name) return;
    try {
      const p = await projectsApi.create({ name });
      window.location.href = `/app#project=${p.id}`;
    } catch { toast.error('Create failed'); }
  }

  return (
    <div className="min-h-screen bg-white">
      <AppNav />

      <main className="max-w-7xl mx-auto px-6 py-8">
        <div className="flex items-end justify-between mb-6">
          <div>
            <h1 className="text-3xl font-extrabold" style={{ color: '#1A1A2E' }}>Projects</h1>
            <p className="text-slate-600 mt-1">{total} project{total === 1 ? '' : 's'}</p>
          </div>
          <button className="btn btn-primary h-10" onClick={createNew}>
            <Plus size={14} /> New project
          </button>
        </div>

        <div className="flex flex-wrap items-center gap-2 mb-5">
          <div className="relative flex-1 min-w-[240px]">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && load(1, query)}
              placeholder="Search projects…"
              className="input pl-9 h-9 text-sm w-full"
            />
          </div>
          <select
            value={sort}
            onChange={(e) => setSort(e.target.value as any)}
            className="input h-9 text-sm w-auto"
          >
            <option value="recent">Most recent</option>
            <option value="name">Name (A–Z)</option>
            <option value="areas">Most areas</option>
          </select>
          <div className="flex items-center bg-white border border-slate-200 rounded-lg p-0.5">
            <button
              onClick={() => setView('grid')}
              className={`p-1.5 rounded ${view === 'grid' ? 'bg-violet-100 text-violet-700' : 'text-slate-500'}`}
              title="Grid view"
            ><Grid3x3 size={14} /></button>
            <button
              onClick={() => setView('list')}
              className={`p-1.5 rounded ${view === 'list' ? 'bg-violet-100 text-violet-700' : 'text-slate-500'}`}
              title="List view"
            ><ListIcon size={14} /></button>
          </div>
        </div>

        {loading ? (
          <div className={view === 'grid' ? 'grid sm:grid-cols-2 lg:grid-cols-3 gap-4' : 'space-y-2'}>
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="skeleton h-32 w-full" />
            ))}
          </div>
        ) : sorted.length === 0 ? (
          <EmptyState onCreate={createNew} />
        ) : view === 'grid' ? (
          <ul className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {sorted.map((p) => (
              <li key={p.id} className="bg-white rounded-xl border border-slate-200 hover:border-violet-400 hover:shadow-md transition-all overflow-hidden">
                <Link to={`/app#project=${p.id}`} className="block">
                  <div className="h-32 bg-gradient-to-br from-violet-100 via-indigo-50 to-cyan-50 flex items-center justify-center">
                    {p.thumbnail_url ? (
                      <img src={p.thumbnail_url} alt="" className="w-full h-full object-cover" />
                    ) : (
                      <Layers className="text-violet-300" size={36} />
                    )}
                  </div>
                  <div className="p-3">
                    <div className="font-bold truncate" style={{ color: '#1A1A2E' }}>{p.name}</div>
                    <div className="text-xs text-slate-500 mt-1">
                      {p.area_count ?? 0} area{p.area_count === 1 ? '' : 's'}
                      {p.updated_at ? ' · ' + relTime(p.updated_at) : ''}
                    </div>
                  </div>
                </Link>
                <div className="flex border-t border-slate-100 px-2 py-1.5 gap-1">
                  <button onClick={() => rename(p.id, p.name)} className="text-xs text-slate-500 hover:text-violet-700 px-2 py-1 rounded hover:bg-violet-50 inline-flex items-center gap-1">
                    <Edit3 size={11} /> Rename
                  </button>
                  <button onClick={() => archive(p.id)} className="text-xs text-slate-500 hover:text-amber-700 px-2 py-1 rounded hover:bg-amber-50 inline-flex items-center gap-1">
                    <Archive size={11} /> Archive
                  </button>
                  <button onClick={() => destroy(p.id, p.name)} className="text-xs text-slate-500 hover:text-red-700 px-2 py-1 rounded hover:bg-red-50 inline-flex items-center gap-1 ml-auto">
                    <Trash2 size={11} />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <ul className="bg-white rounded-xl border border-slate-200 divide-y divide-slate-100">
            {sorted.map((p) => (
              <li key={p.id} className="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 group">
                <Link to={`/app#project=${p.id}`} className="flex-1 flex items-center gap-3">
                  <Layers size={16} className="text-violet-500" />
                  <div className="flex-1 min-w-0">
                    <div className="font-bold truncate" style={{ color: '#1A1A2E' }}>{p.name}</div>
                    <div className="text-xs text-slate-500">
                      {p.area_count ?? 0} area{p.area_count === 1 ? '' : 's'}
                      {p.updated_at ? ' · ' + relTime(p.updated_at) : ''}
                    </div>
                  </div>
                  <ArrowRight size={14} className="text-slate-300 group-hover:text-violet-500" />
                </Link>
                <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                  <button onClick={() => rename(p.id, p.name)} className="p-1.5 text-slate-400 hover:text-violet-700 rounded" title="Rename"><Edit3 size={13} /></button>
                  <button onClick={() => archive(p.id)} className="p-1.5 text-slate-400 hover:text-amber-700 rounded" title="Archive"><Archive size={13} /></button>
                  <button onClick={() => destroy(p.id, p.name)} className="p-1.5 text-slate-400 hover:text-red-700 rounded" title="Delete"><Trash2 size={13} /></button>
                </div>
              </li>
            ))}
          </ul>
        )}

        {total > 24 && (
          <div className="flex justify-center gap-1 mt-6">
            {Array.from({ length: Math.ceil(total / 24) }, (_, i) => i + 1).map((n) => (
              <button
                key={n}
                onClick={() => load(n)}
                className={`w-8 h-8 rounded text-sm font-semibold ${n === page ? 'bg-violet-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:border-violet-400'}`}
              >{n}</button>
            ))}
          </div>
        )}
      </main>
    </div>
  );
}

function EmptyState({ onCreate }: { onCreate: () => void }) {
  return (
    <div className="rounded-xl border border-dashed border-violet-300 p-12 text-center bg-white">
      <Layers size={36} className="mx-auto text-violet-400 mb-3" />
      <div className="text-lg font-bold" style={{ color: '#1A1A2E' }}>No projects yet</div>
      <p className="text-sm text-slate-500 mt-2">Create your first project to start drawing territories.</p>
      <button onClick={onCreate} className="btn btn-primary mt-4">
        <Plus size={14} /> New project
      </button>
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

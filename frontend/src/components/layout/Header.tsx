import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';
import { useProjectStore } from '../../stores/projectStore';
import { projectsApi } from '../../api/projects';
import { LogOut, MapPin, Plus, Settings } from 'lucide-react';
import toast from 'react-hot-toast';

export default function Header() {
  const { user, logout } = useAuthStore();
  const { currentProject, setCurrentProject } = useProjectStore();
  const [projects, setProjects] = useState<any[]>([]);
  const [showMenu, setShowMenu] = useState(false);
  const [open, setOpen] = useState(false);

  useEffect(() => { load(); }, []);
  async function load() {
    try {
      const r = await projectsApi.list();
      setProjects(r.data);
      if (!currentProject && r.data.length > 0) setCurrentProject(r.data[0]);
    } catch (e: any) {
      toast.error('Could not load projects');
    }
  }

  async function newProject() {
    const name = prompt('New project name:');
    if (!name) return;
    try {
      const p = await projectsApi.create({ name });
      setProjects([p, ...projects]);
      setCurrentProject(p);
      toast.success('Project created');
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Create failed');
    }
  }

  return (
    <header className="bg-white border-b border-slate-200 px-4 h-14 flex items-center justify-between sticky top-0 z-30">
      <div className="flex items-center gap-4">
        <Link to="/" className="flex items-center gap-2 font-extrabold" style={{ color: '#1A1A2E', fontSize: 17 }}>
          <MapPin size={20} color="#7848BB" />
          Smappen
        </Link>
        <div className="relative">
          <button className="btn btn-secondary" onClick={() => setOpen(!open)}>
            {currentProject?.name ?? 'Choose project'} ▾
          </button>
          {open && (
            <div className="absolute mt-1 bg-white border border-slate-200 rounded-md shadow-lg min-w-[240px] z-40">
              {projects.map((p) => (
                <button
                  key={p.id}
                  className="block w-full text-left px-3 py-2 hover:bg-slate-50 text-sm"
                  onClick={() => { setCurrentProject(p); setOpen(false); }}
                >
                  {p.name} <span className="text-slate-400">· {p.area_count ?? 0} areas</span>
                </button>
              ))}
              <button className="block w-full text-left px-3 py-2 hover:bg-slate-50 text-sm border-t border-slate-100" onClick={() => { setOpen(false); newProject(); }}>
                <Plus size={14} className="inline mr-1" /> New project
              </button>
            </div>
          )}
        </div>
      </div>
      <div className="flex items-center gap-3 relative">
        <span className="text-xs px-2 py-1 rounded-full font-semibold uppercase" style={{
          background: user?.plan === 'free' ? '#f1f5f9' : '#ddd6fe',
          color: user?.plan === 'free' ? '#475569' : '#5b21b6',
        }}>{user?.plan}</span>
        <button className="btn btn-ghost" onClick={() => setShowMenu(!showMenu)}>
          {user?.name}
        </button>
        {showMenu && (
          <div className="absolute right-0 top-full mt-1 bg-white border border-slate-200 rounded-md shadow-lg min-w-[200px] z-40">
            <Link to="/settings/billing" className="block px-3 py-2 hover:bg-slate-50 text-sm" onClick={() => setShowMenu(false)}>
              <Settings size={14} className="inline mr-1" /> Billing & settings
            </Link>
            <Link to="/pricing" className="block px-3 py-2 hover:bg-slate-50 text-sm" onClick={() => setShowMenu(false)}>
              Pricing
            </Link>
            <button className="block w-full text-left px-3 py-2 hover:bg-slate-50 text-sm border-t border-slate-100" onClick={() => { logout(); location.href = '/login'; }}>
              <LogOut size={14} className="inline mr-1" /> Sign out
            </button>
          </div>
        )}
      </div>
    </header>
  );
}

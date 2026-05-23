import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';
import { useProjectStore } from '../../stores/projectStore';
import { projectsApi } from '../../api/projects';
import {
  LogOut, MapPin, Plus, Settings, ChevronDown, ChevronRight,
  Share2, MoreHorizontal, Undo2, Redo2, Check, X as XIcon,
} from 'lucide-react';
import toast from 'react-hot-toast';

export default function Header() {
  const { user, logout } = useAuthStore();
  const { currentProject, setCurrentProject } = useProjectStore();
  const [projects, setProjects] = useState<any[]>([]);
  const [showUserMenu, setShowUserMenu] = useState(false);
  const [projectDropdownOpen, setProjectDropdownOpen] = useState(false);
  const [switcherQuery, setSwitcherQuery] = useState('');
  const [renaming, setRenaming] = useState(false);
  const [renameVal, setRenameVal] = useState('');
  const renameRef = useRef<HTMLInputElement>(null);

  useEffect(() => { load(); }, []);

  // Cmd/Ctrl+K opens project switcher
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setProjectDropdownOpen(true);
        setSwitcherQuery('');
      }
      if (e.key === 'Escape') {
        setProjectDropdownOpen(false);
        setShowUserMenu(false);
      }
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);
  async function load() {
    try {
      const r = await projectsApi.list();
      setProjects(r.data);
      if (!currentProject && r.data.length > 0) setCurrentProject(r.data[0]);
    } catch {
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

  function startRename() {
    if (!currentProject) return;
    setRenameVal(currentProject.name);
    setRenaming(true);
    setProjectDropdownOpen(false);
    setTimeout(() => renameRef.current?.select(), 50);
  }
  async function saveRename() {
    if (!currentProject || !renameVal.trim() || renameVal === currentProject.name) {
      setRenaming(false);
      return;
    }
    try {
      const updated = await projectsApi.update(currentProject.id, { name: renameVal.trim() });
      setCurrentProject(updated);
      setProjects(projects.map((p) => (p.id === updated.id ? { ...p, name: updated.name } : p)));
      setRenaming(false);
    } catch (e: any) {
      toast.error('Rename failed');
    }
  }

  async function share() {
    if (!currentProject) return;
    try {
      const updated = await projectsApi.update(currentProject.id, { is_shared: true });
      setCurrentProject(updated);
      const url = `${location.origin}/shared/${updated.share_token}`;
      await navigator.clipboard.writeText(url);
      toast.success('Share link copied to clipboard');
    } catch {
      toast.error('Share failed');
    }
  }

  return (
    <header className="bg-white border-b border-slate-200 h-12 flex items-center justify-between px-3 sticky top-0 z-30">
      <div className="flex items-center gap-2">
        <Link
          to="/"
          className="flex items-center gap-1.5 font-extrabold text-[15px] tracking-tight pr-3"
          style={{ color: '#1A1A2E' }}
        >
          <MapPin size={18} color="#7848BB" />
          Smappen
        </Link>

        <div className="h-6 w-px bg-slate-200 mx-1" />

        {/* Editable project name / breadcrumb */}
        <div className="relative flex items-center gap-1">
          {renaming ? (
            <div className="flex items-center gap-1">
              <input
                ref={renameRef}
                className="text-[15px] font-semibold border border-violet-400 rounded px-2 py-0.5 outline-none focus:border-violet-600"
                style={{ color: '#1A1A2E' }}
                value={renameVal}
                onChange={(e) => setRenameVal(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') saveRename();
                  if (e.key === 'Escape') setRenaming(false);
                }}
                onBlur={saveRename}
              />
              <button className="text-emerald-600 p-1 hover:bg-emerald-50 rounded" onClick={saveRename} title="Save"><Check size={14} /></button>
              <button className="text-slate-400 p-1 hover:bg-slate-50 rounded" onClick={() => setRenaming(false)} title="Cancel"><XIcon size={14} /></button>
            </div>
          ) : (
            <>
              <button
                className="text-[15px] font-semibold hover:bg-slate-50 px-2 py-1 rounded inline-flex items-center gap-1"
                style={{ color: '#1A1A2E' }}
                onClick={() => setProjectDropdownOpen(!projectDropdownOpen)}
                title="Switch project"
              >
                {currentProject?.name ?? 'Choose project'}
                <ChevronDown size={14} className="text-slate-400" />
              </button>
              <button
                className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-50"
                onClick={startRename}
                title="Rename"
              >
                <MoreHorizontal size={14} />
              </button>
            </>
          )}

          {projectDropdownOpen && (
            <div className="absolute top-full left-0 mt-1 bg-white border border-slate-200 rounded-lg shadow-lg min-w-[300px] z-40 py-1">
              <div className="px-2 py-1.5">
                <input
                  autoFocus
                  className="input h-8 text-sm"
                  placeholder="Search projects…"
                  value={switcherQuery}
                  onChange={(e) => setSwitcherQuery(e.target.value)}
                />
              </div>
              <div className="max-h-72 overflow-y-auto">
                {projects.filter((p) => !switcherQuery || p.name.toLowerCase().includes(switcherQuery.toLowerCase())).map((p) => (
                  <button
                    key={p.id}
                    className={`block w-full text-left px-3 py-2 hover:bg-slate-50 text-sm flex items-center justify-between ${p.id === currentProject?.id ? 'bg-violet-50' : ''}`}
                    onClick={() => { setCurrentProject(p); setProjectDropdownOpen(false); }}
                  >
                    <span className="font-medium truncate">{p.name}</span>
                    <span className="text-slate-400 text-xs ml-2 shrink-0">{p.area_count ?? 0} {p.area_count === 1 ? 'area' : 'areas'}</span>
                  </button>
                ))}
              </div>
              <button
                className="block w-full text-left px-3 py-2 hover:bg-slate-50 text-sm border-t border-slate-100 mt-1 font-semibold flex items-center gap-2"
                style={{ color: '#7848BB' }}
                onClick={() => { setProjectDropdownOpen(false); newProject(); }}
              >
                <Plus size={14} /> New project
              </button>
              <div className="px-3 py-1.5 border-t border-slate-100 text-[10px] text-slate-400 flex items-center justify-between">
                <span>Switch with <kbd className="bg-slate-100 px-1 py-0.5 rounded">⌘K</kbd></span>
                <span>Esc to close</span>
              </div>
            </div>
          )}
        </div>
      </div>

      <div className="flex items-center gap-1">
        {/* Undo/Redo placeholders — disabled until we wire history */}
        <button className="text-slate-300 p-1.5 cursor-not-allowed" title="Undo (coming soon)" disabled><Undo2 size={16} /></button>
        <button className="text-slate-300 p-1.5 cursor-not-allowed" title="Redo (coming soon)" disabled><Redo2 size={16} /></button>

        <div className="h-6 w-px bg-slate-200 mx-1" />

        <button
          className="text-slate-600 hover:bg-slate-50 px-3 py-1.5 rounded text-sm font-semibold inline-flex items-center gap-1.5"
          onClick={share}
          title="Copy share link"
        >
          <Share2 size={15} /> Share
        </button>

        <span
          className="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wide"
          style={{
            background: user?.plan === 'free' ? '#F3F3F7' : '#EDE5F7',
            color: user?.plan === 'free' ? '#6B6B7B' : '#5C2D91',
          }}
          title={`${user?.plan} plan`}
        >
          {user?.plan}
        </span>

        <div className="relative">
          <button
            className="ml-1 inline-flex items-center gap-1.5 text-slate-600 hover:bg-slate-50 px-2 py-1.5 rounded text-sm font-semibold"
            onClick={() => setShowUserMenu(!showUserMenu)}
          >
            <span
              className="inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold text-white"
              style={{ background: '#7848BB' }}
            >
              {(user?.name ?? '?').slice(0, 1).toUpperCase()}
            </span>
            <span className="hidden sm:inline">{user?.name}</span>
            <ChevronDown size={12} className="text-slate-400" />
          </button>
          {showUserMenu && (
            <div className="absolute right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg min-w-[220px] z-40 py-1">
              <div className="px-3 py-2 border-b border-slate-100">
                <div className="text-sm font-semibold" style={{ color: '#1A1A2E' }}>{user?.name}</div>
                <div className="text-xs text-slate-500">{user?.email}</div>
              </div>
              <Link to="/settings/billing" className="block px-3 py-2 hover:bg-slate-50 text-sm flex items-center gap-2" onClick={() => setShowUserMenu(false)}>
                <Settings size={14} /> Billing & settings
              </Link>
              <Link to="/pricing" className="block px-3 py-2 hover:bg-slate-50 text-sm flex items-center gap-2" onClick={() => setShowUserMenu(false)}>
                <ChevronRight size={14} /> Pricing
              </Link>
              <button
                className="block w-full text-left px-3 py-2 hover:bg-slate-50 text-sm border-t border-slate-100 flex items-center gap-2 text-red-600"
                onClick={() => { logout(); location.href = '/login'; }}
              >
                <LogOut size={14} /> Sign out
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}

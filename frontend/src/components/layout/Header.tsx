import { useEffect, useRef, useState } from 'react';
import { useClickOutside } from '../../hooks/useClickOutside';
import { useAuthStore } from '../../stores/authStore';
import { useProjectStore } from '../../stores/projectStore';
import { projectsApi } from '../../api/projects';
import {
  Plus, ChevronDown, Share2, MoreHorizontal, Undo2, Redo2, Check, X as XIcon,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { useUndoStore } from '../../stores/undoStore';
import SaveStatus from '../common/SaveStatus';

/**
 * Map-page action strip — rendered INSIDE AppNav's children slot on /app.
 *
 * Owns ONLY map-specific actions: project switcher · save status · undo/redo ·
 * share. The notifications bell, cost widget, and user menu are global — they
 * live in AppNav itself so every authenticated page sees them.
 */
export default function Header() {
  const { currentProject, setCurrentProject } = useProjectStore();
  const [projects, setProjects] = useState<any[]>([]);
  const [projectDropdownOpen, setProjectDropdownOpen] = useState(false);
  const [switcherQuery, setSwitcherQuery] = useState('');
  const [renaming, setRenaming] = useState(false);
  const [renameVal, setRenameVal] = useState('');
  const renameRef = useRef<HTMLInputElement>(null);
  const projectDropdownRef = useRef<HTMLDivElement>(null);
  useClickOutside(projectDropdownRef, () => setProjectDropdownOpen(false), projectDropdownOpen);

  // Project switcher shortcut — `g` then `p` within 600ms. Vim-style leader so
  // we don't collide with Edge's Ctrl+K. Esc closes.
  useEffect(() => {
    let leaderPressed = false;
    let leaderTimer: number | null = null;
    function onKey(e: KeyboardEvent) {
      const t = document.activeElement as HTMLElement | null;
      const isTyping = t?.tagName === 'INPUT' || t?.tagName === 'TEXTAREA' || t?.tagName === 'SELECT' || t?.isContentEditable;
      if (e.key === 'Escape') {
        setProjectDropdownOpen(false);
        leaderPressed = false;
        return;
      }
      if (isTyping) return;
      if (leaderPressed && e.key.toLowerCase() === 'p') {
        e.preventDefault();
        leaderPressed = false;
        setProjectDropdownOpen(true);
        setSwitcherQuery('');
        return;
      }
      if (e.key.toLowerCase() === 'g' && !e.metaKey && !e.ctrlKey && !e.altKey) {
        leaderPressed = true;
        if (leaderTimer) window.clearTimeout(leaderTimer);
        leaderTimer = window.setTimeout(() => { leaderPressed = false; }, 600);
        return;
      }
      leaderPressed = false;
    }
    window.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('keydown', onKey);
      if (leaderTimer) window.clearTimeout(leaderTimer);
    };
  }, []);

  useEffect(() => { load(); }, []);
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
    } catch {
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
    <>
      <div className="flex items-center gap-2 min-w-0 flex-1">
        <div ref={projectDropdownRef} className="relative flex items-center gap-1 min-w-0">
          {renaming ? (
            <div className="flex items-center gap-1">
              <input
                ref={renameRef}
                className="text-[15px] font-semibold border border-violet-400 rounded px-2 py-0.5 outline-none focus:border-violet-600"
                style={{ color: 'var(--nav-text-strong)' }}
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
                className="text-[13px] font-semibold hover:bg-slate-50 px-2 py-1 rounded inline-flex items-center gap-1 min-w-0 max-w-[180px]"
                style={{ color: 'var(--nav-text-strong)' }}
                onClick={() => setProjectDropdownOpen(!projectDropdownOpen)}
                title={currentProject?.name ?? 'Switch project (g then p)'}
              >
                <span className="truncate">{currentProject?.name ?? 'Choose project'}</span>
                <ChevronDown size={12} className="text-slate-400 flex-shrink-0" />
              </button>
              <button
                className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-50 hidden lg:inline-flex"
                onClick={startRename}
                title="Rename"
              >
                <MoreHorizontal size={13} />
              </button>
              <SaveStatus />
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
                style={{ color: 'var(--brand)' }}
                onClick={() => { setProjectDropdownOpen(false); newProject(); }}
              >
                <Plus size={14} /> New project
              </button>
              <div className="px-3 py-1.5 border-t border-slate-100 text-[10px] text-slate-400 flex items-center justify-between">
                <span>Open with <kbd className="bg-slate-100 px-1 py-0.5 rounded">g</kbd> then <kbd className="bg-slate-100 px-1 py-0.5 rounded">p</kbd></span>
                <span>Esc to close</span>
              </div>
            </div>
          )}
        </div>
      </div>

      <div className="flex items-center gap-1">
        <UndoRedoButtons />
        <div className="h-6 w-px bg-slate-200 mx-1" />
        <button
          className="text-slate-600 hover:bg-slate-50 px-2.5 py-1 rounded text-[12px] font-semibold inline-flex items-center gap-1"
          onClick={share}
          title="Copy share link"
        >
          <Share2 size={13} /> <span className="hidden lg:inline">Share</span>
        </button>
      </div>
    </>
  );
}

/**
 * Undo/Redo header buttons. Reads from the undoStore + listens for Cmd+Z /
 * Cmd+Shift+Z. Renders dim when nothing to undo/redo, active when there is.
 */
function UndoRedoButtons() {
  const past = useUndoStore((s) => s.past);
  const future = useUndoStore((s) => s.future);
  const busy = useUndoStore((s) => s.busy);
  const undo = useUndoStore((s) => s.undo);
  const redo = useUndoStore((s) => s.redo);
  const canU = past.length > 0 && !busy;
  const canR = future.length > 0 && !busy;
  const [histOpen, setHistOpen] = useState(false);
  const histRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    function onClick(e: MouseEvent) {
      if (histRef.current && !histRef.current.contains(e.target as Node)) setHistOpen(false);
    }
    if (histOpen) document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [histOpen]);

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      const isInput = (e.target as HTMLElement)?.tagName === 'INPUT'
        || (e.target as HTMLElement)?.tagName === 'TEXTAREA'
        || (e.target as HTMLElement)?.isContentEditable;
      if (isInput) return;
      const cmd = e.metaKey || e.ctrlKey;
      if (cmd && e.key.toLowerCase() === 'z' && !e.shiftKey) {
        e.preventDefault();
        if (canU) undo().catch(() => {});
      } else if (cmd && (e.key.toLowerCase() === 'z' && e.shiftKey) || (cmd && e.key.toLowerCase() === 'y')) {
        e.preventDefault();
        if (canR) redo().catch(() => {});
      }
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [canU, canR, undo, redo]);

  return (
    <>
      <button
        className={canU ? 'text-slate-600 hover:bg-slate-50 p-1.5 rounded' : 'text-slate-300 p-1.5 cursor-not-allowed'}
        title={canU ? `Undo: ${past[past.length - 1].label} (⌘Z)` : 'Nothing to undo'}
        onClick={() => canU && undo()}
        disabled={!canU}
      >
        <Undo2 size={16} />
      </button>
      <button
        className={canR ? 'text-slate-600 hover:bg-slate-50 p-1.5 rounded' : 'text-slate-300 p-1.5 cursor-not-allowed'}
        title={canR ? `Redo: ${future[future.length - 1].label} (⇧⌘Z)` : 'Nothing to redo'}
        onClick={() => canR && redo()}
        disabled={!canR}
      >
        <Redo2 size={16} />
      </button>

      <div ref={histRef} className="relative">
        <button
          onClick={() => setHistOpen((v) => !v)}
          disabled={past.length === 0}
          className={past.length === 0 ? 'text-slate-300 p-1 cursor-not-allowed' : 'text-slate-500 hover:bg-slate-50 p-1 rounded'}
          title="Undo history"
        >
          <ChevronDown size={11} />
        </button>
        {histOpen && past.length > 0 && (
          <div className="absolute right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg w-[240px] py-1 z-40 card-expand">
            <div className="px-3 py-1.5 text-[10px] uppercase tracking-wider font-bold text-slate-500 border-b border-slate-100">
              Recent actions
            </div>
            <ul className="max-h-72 overflow-y-auto">
              {past.slice().reverse().slice(0, 10).map((a, i) => (
                <li key={i}>
                  <button
                    className="w-full text-left px-3 py-1.5 text-xs hover:bg-violet-50 flex items-center gap-2"
                    onClick={async () => {
                      for (let k = 0; k <= i; k++) await undo();
                      setHistOpen(false);
                    }}
                  >
                    <span className="w-5 text-center text-slate-400 font-bold">{i + 1}</span>
                    <span className="flex-1 truncate text-slate-700">{a.label}</span>
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </>
  );
}

import { useEffect, useRef, useState } from 'react';
import { useClickOutside } from '../../hooks/useClickOutside';
import { Link } from 'react-router-dom';
import { useAuthStore } from '../../stores/authStore';
import { useProjectStore } from '../../stores/projectStore';
import { projectsApi } from '../../api/projects';
import {
  LogOut, MapPin, Plus, Settings, ChevronDown, ChevronRight,
  Share2, MoreHorizontal, Undo2, Redo2, Check, X as XIcon, Bell, DollarSign,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { notificationApi, type Notification } from '../../api/advanced';
import { usageApi, formatUsd } from '../../api/usage';
import { useCostStore } from '../../stores/costStore';
import { useUndoStore } from '../../stores/undoStore';
import SaveStatus from '../common/SaveStatus';

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
  const [notifsOpen, setNotifsOpen] = useState(false);
  const [notifs, setNotifs] = useState<Notification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);

  // Click-outside refs for the three dropdowns. Without these, opening one
  // and clicking on the map leaves the menu sitting there visually.
  const notifsRef = useRef<HTMLDivElement>(null);
  const userMenuRef = useRef<HTMLDivElement>(null);
  const projectDropdownRef = useRef<HTMLDivElement>(null);
  useClickOutside(notifsRef, () => setNotifsOpen(false), notifsOpen);
  useClickOutside(userMenuRef, () => setShowUserMenu(false), showUserMenu);
  useClickOutside(projectDropdownRef, () => setProjectDropdownOpen(false), projectDropdownOpen);

  // Cost widget — running Google-API spend today.
  const totalUsdToday = useCostStore((s) => s.totalUsdToday);
  const callCountToday = useCostStore((s) => s.callCountToday);
  const breakdownRef = useRef<{ api_name: string; calls: number; cost_usd: number }[]>([]);
  const [costOpen, setCostOpen] = useState(false);
  const costRef = useRef<HTMLDivElement>(null);
  useClickOutside(costRef, () => setCostOpen(false), costOpen);
  async function loadUsage() {
    if (!useAuthStore.getState().token) return;
    try {
      const r = await usageApi.today();
      useCostStore.getState().setTotals(r.total_usd, r.call_count);
      breakdownRef.current = r.breakdown;
    } catch (e: any) {
      // Don't toast — the cost widget is a passive HUD, not a primary flow.
      // Logging keeps the failure visible in DevTools so we notice if the
      // usage endpoint goes bad after a deploy.
      if (import.meta.env.DEV) console.warn('[cost-widget] usage.today failed:', e?.message ?? e);
    }
  }

  useEffect(() => { load(); loadNotifs(); loadUsage(); }, []);
  useEffect(() => {
    // Poll every 60s for notifications + usage. Skip when logged out so we
    // don't hammer 401s after sign-out.
    const t = setInterval(() => {
      if (!useAuthStore.getState().token) return;
      loadNotifs();
      loadUsage();
    }, 60_000);
    return () => clearInterval(t);
  }, []);
  async function loadNotifs() {
    // Belt-and-braces — even on first load there might not be a token if the
    // header rendered before the auth store rehydrated from localStorage.
    if (!useAuthStore.getState().token) return;
    try {
      const r = await notificationApi.list();
      setNotifs(r.notifications);
      setUnreadCount(r.unread_count);
    } catch {}
  }

  // Project switcher shortcut. ⌘K was the original binding but Edge swallows
  // Ctrl+K on Windows (opens its built-in command bar). Use a vim-style
  // "leader" sequence — press `g` then `p` within 600ms — which doesn't
  // collide with any browser chrome shortcut. Esc still closes the menu.
  useEffect(() => {
    let leaderPressed = false;
    let leaderTimer: number | null = null;
    function onKey(e: KeyboardEvent) {
      const t = document.activeElement as HTMLElement | null;
      const isTyping = t?.tagName === 'INPUT' || t?.tagName === 'TEXTAREA' || t?.tagName === 'SELECT' || t?.isContentEditable;
      if (e.key === 'Escape') {
        setProjectDropdownOpen(false);
        setShowUserMenu(false);
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

  // Header is rendered INSIDE AppNav's children slot (see AppLayout) — no
  // outer <header> wrapper, no brand, no max-width container. Just the
  // /app-specific actions: project switcher · save status · undo/redo ·
  // $cost · bell · share · user menu (AppNav still owns the user menu so
  // we skip that here).
  return (
    <>
      <div className="flex items-center gap-2 min-w-0 flex-1">
        {/* Editable project name / breadcrumb */}
        <div ref={projectDropdownRef} className="relative flex items-center gap-1 min-w-0">
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
                className="text-[13px] font-semibold hover:bg-slate-50 px-2 py-1 rounded inline-flex items-center gap-1 min-w-0 max-w-[180px]"
                style={{ color: '#1A1A2E' }}
                onClick={() => setProjectDropdownOpen(!projectDropdownOpen)}
                title={currentProject?.name ?? 'Switch project'}
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
                style={{ color: '#7848BB' }}
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
        {/* Undo/Redo placeholders — disabled until we wire history */}
        <UndoRedoButtons />

        <div className="h-6 w-px bg-slate-200 mx-1" />

        {/* Google API spend today */}
        <div ref={costRef} className="relative">
          <button
            className="text-slate-600 hover:bg-slate-50 px-2 py-1.5 rounded text-xs font-semibold inline-flex items-center gap-1"
            onClick={() => setCostOpen((v) => !v)}
            title="Google API spend today (estimate)"
          >
            <DollarSign size={13} style={{ color: totalUsdToday > 1 ? '#dc2626' : '#7848BB' }} />
            <span style={{ color: '#1A1A2E' }}>{formatUsd(totalUsdToday)}</span>
            <span className="text-slate-400">today</span>
          </button>
          {costOpen && (
            <div className="absolute right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg w-[260px] z-40 p-3">
              <div className="flex items-center justify-between mb-2">
                <span className="text-sm font-bold" style={{ color: '#1A1A2E' }}>Google API spend</span>
                <span className="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Estimate</span>
              </div>
              <div className="text-2xl font-extrabold" style={{ color: '#1A1A2E' }}>
                {formatUsd(totalUsdToday)}
              </div>
              <div className="text-xs text-slate-500 mb-2">{callCountToday} calls today</div>
              <div className="border-t border-slate-100 pt-2">
                <div className="text-[10px] font-bold uppercase text-slate-500 tracking-wider mb-1">By API</div>
                {breakdownRef.current.length === 0 && (
                  <div className="text-xs text-slate-400 italic">No spend yet today.</div>
                )}
                <ul className="space-y-0.5">
                  {breakdownRef.current.map((row) => (
                    <li key={row.api_name} className="flex items-center justify-between text-xs">
                      <span className="text-slate-600">{row.api_name}</span>
                      <span className="font-semibold" style={{ color: '#1A1A2E' }}>
                        {formatUsd(row.cost_usd)} <span className="text-slate-400 text-[10px]">· {row.calls}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
              <p className="text-[10px] text-slate-400 mt-2 leading-snug">
                Real billing lives in your Google Cloud console. These numbers are
                per-call estimates based on Maps Platform list pricing.
              </p>
            </div>
          )}
        </div>

        <div ref={notifsRef} className="relative">
          <button
            className="relative text-slate-600 hover:bg-slate-50 p-1.5 rounded"
            onClick={() => setNotifsOpen((v) => !v)}
            title="Notifications"
          >
            <Bell size={17} />
            {unreadCount > 0 && (
              <span className="absolute -top-0.5 -right-0.5 bg-rose-500 text-white rounded-full px-1 text-[9px] font-bold min-w-[14px] text-center leading-[14px]">
                {unreadCount > 99 ? '99+' : unreadCount}
              </span>
            )}
          </button>
          {notifsOpen && (
            <div className="absolute right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg w-[340px] z-40 max-h-[420px] overflow-hidden flex flex-col">
              <div className="flex items-center justify-between px-3 py-2 border-b border-slate-200">
                <span className="text-sm font-bold" style={{ color: '#1A1A2E' }}>Notifications</span>
                {unreadCount > 0 && (
                  <button
                    className="text-[11px] text-violet-700 font-semibold hover:underline"
                    onClick={async () => { await notificationApi.markAllRead(); await loadNotifs(); }}
                  >Mark all read</button>
                )}
              </div>
              <ul className="overflow-y-auto flex-1 divide-y divide-slate-100">
                {notifs.length === 0 && (
                  <li className="px-3 py-6 text-center text-sm text-slate-500">All caught up</li>
                )}
                {notifs.map((n) => (
                  <li key={n.id} className={`px-3 py-2 text-xs ${n.is_read ? 'bg-white' : 'bg-violet-50/50'}`}>
                    <div className="flex items-center justify-between mb-0.5">
                      <span className="font-semibold" style={{ color: '#1A1A2E' }}>{n.title}</span>
                      <span className="text-slate-400">{new Date(n.created_at).toLocaleDateString()}</span>
                    </div>
                    {n.body && <div className="text-slate-700">{n.body}</div>}
                    {!n.is_read && (
                      <button
                        className="mt-1 text-[11px] text-violet-700 font-semibold hover:underline"
                        onClick={async () => { await notificationApi.markRead(n.id); await loadNotifs(); }}
                      >Mark read</button>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>

        <button
          className="text-slate-600 hover:bg-slate-50 px-2.5 py-1 rounded text-[12px] font-semibold inline-flex items-center gap-1"
          onClick={share}
          title="Copy share link"
        >
          <Share2 size={13} /> <span className="hidden lg:inline">Share</span>
        </button>

        {/* The legacy in-Header user menu used to live here. AppNav now owns
            it across the whole product so we don't duplicate. */}
      </div>
    </>
  );
}

// Initials from name → "Adam Smith" → "AS", single word → first letter.
function initials(name?: string): string {
  if (!name) return '?';
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]).join('').toUpperCase();
}

// Stable hash → hue → gradient. Same email always picks the same colors so
// teammates recognize each other across sessions.
function gradientFor(seed?: string): string {
  if (!seed) return 'linear-gradient(135deg, #7848BB, #5C2D91)';
  let h = 0;
  for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) | 0;
  const hue = Math.abs(h) % 360;
  return `linear-gradient(135deg, hsl(${hue}, 65%, 55%), hsl(${(hue + 40) % 360}, 60%, 45%))`;
}

function UserAvatarChip({ user, size = 28 }: { user: any; size?: number }) {
  return (
    <span
      className="inline-flex items-center justify-center rounded-full text-xs font-bold text-white shrink-0"
      style={{
        width: size,
        height: size,
        background: gradientFor(user?.email ?? user?.name),
        fontSize: size > 30 ? 14 : 11,
      }}
    >
      {initials(user?.name)}
    </span>
  );
}

function UserAvatarButton({ user, onClick }: { user: any; onClick: () => void }) {
  return (
    <button
      className="ml-1 inline-flex items-center gap-1.5 text-slate-600 hover:bg-slate-50 px-2 py-1.5 rounded text-sm font-semibold"
      onClick={onClick}
    >
      <UserAvatarChip user={user} />
      <span className="hidden sm:inline">{user?.name}</span>
      <ChevronDown size={12} className="text-slate-400" />
    </button>
  );
}

function PlanBadge({ plan }: { plan?: string }) {
  const map: Record<string, { label: string; bg: string; fg: string }> = {
    free:       { label: 'Free',       bg: '#F1F5F9', fg: '#475569' },
    starter:    { label: 'Starter',    bg: '#DBEAFE', fg: '#1D4ED8' },
    pro:        { label: 'Pro',        bg: '#EDE5F7', fg: '#5C2D91' },
    business:   { label: 'Business',   bg: '#FCE7F3', fg: '#9D174D' },
    enterprise: { label: 'Enterprise', bg: '#FEF3C7', fg: '#92400E' },
  };
  const tier = map[(plan ?? 'free').toLowerCase()] ?? map.free;
  return (
    <span
      className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold tracking-wider uppercase shrink-0"
      style={{ background: tier.bg, color: tier.fg }}
    >
      {tier.label}
    </span>
  );
}

/**
 * Undo/Redo header buttons. Reads from the undoStore + listens for Cmd+Z /
 * Cmd+Shift+Z. Renders dim when nothing to undo/redo, active when there is.
 * Hover tooltip shows the next action's label.
 */
function UndoRedoButtons() {
  const past = useUndoStore((s) => s.past);
  const future = useUndoStore((s) => s.future);
  const busy = useUndoStore((s) => s.busy);
  const undo = useUndoStore((s) => s.undo);
  const redo = useUndoStore((s) => s.redo);
  const canU = past.length > 0 && !busy;
  const canR = future.length > 0 && !busy;
  // OP8 — undo-history dropdown. Lists the last 10 actions; clicking
  // "Undo to here" rolls back N steps at once.
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

      {/* OP8 — undo-history dropdown. Tiny chevron-only button. Lists the
          last 10 actions; clicking one rolls back to that point. */}
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
                      // Undo (i+1) times to reach this entry's state.
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

import { forwardRef, ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useMatch, useNavigate } from 'react-router-dom';
import {
  LayoutGrid, ChefHat, Building2, Map, Settings as SettingsIcon,
  LogOut, ChevronDown, Menu, X, Plus, Bell, DollarSign, Keyboard,
} from 'lucide-react';
import { useAuthStore } from '../../stores/authStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
import { useCostStore } from '../../stores/costStore';
import { useClickOutside } from '../../hooks/useClickOutside';
import { notificationApi, type Notification } from '../../api/advanced';
import { usageApi, formatUsd } from '../../api/usage';
import type { User } from '../../types';

/**
 * The ONE nav for every authenticated surface in the app.
 *
 * Layout (left → right):
 *   - smappen brand (links to /dashboard)
 *   - cross-product tabs (Restaurants / Vendors / Dashboard / Map)
 *   - quick-create (+)
 *   - {children} — optional page-specific action strip (project switcher
 *     on /app, restaurant breadcrumb on /app/restaurants/{id}/*, etc).
 *   - cost widget (admin/owner only)
 *   - notification bell
 *   - user menu (avatar → email → logout)
 *
 * Conventions:
 *   - 48px tall (h-12) + 1px border = 49px
 *   - sticky top-0 z-30
 *   - full-width row (no max-width clamp — context slot needs room)
 *   - mobile: tabs + actions collapse into a hamburger menu under 768px
 *
 * Every authenticated page should mount EXACTLY ONE AppNav at its top.
 * NEVER nest AppNav inside another layout that already renders it.
 */

interface NavItem {
  to: string;
  label: string;
  icon: typeof LayoutGrid;
  end?: boolean; // exact-match for routes like /app that overlap children
}

// Restaurants first per "Palantir for restaurants" positioning — entities
// before tools, with Map demoted to the rightmost slot.
const ITEMS: NavItem[] = [
  { to: '/app/restaurants', label: 'Restaurants', icon: ChefHat },
  { to: '/app/vendors',     label: 'Vendors',     icon: Building2 },
  { to: '/dashboard',       label: 'Dashboard',   icon: LayoutGrid },
  { to: '/app',             label: 'Map',         icon: Map, end: true },
];

export default function AppNav({
  children,
  mobileContext,
}: {
  children?: ReactNode;
  /**
   * Optional node rendered INLINE on mobile between the brand and the
   * hamburger. Use this for things operators need one-handed without
   * opening the drawer — e.g. the restaurant switcher on the war-room.
   * When unset, `children` are only visible on desktop and inside the
   * mobile drawer (original behavior).
   */
  mobileContext?: ReactNode;
}) {
  const user = useAuthStore((s) => s.user);
  const logout = useAuthStore((s) => s.logout);

  const [menuOpen, setMenuOpen] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);

  const menuRef = useRef<HTMLDivElement>(null);
  const createRef = useRef<HTMLDivElement>(null);
  const mobileToggleRef = useRef<HTMLButtonElement>(null);
  const drawerRef = useRef<HTMLDivElement>(null);

  useClickOutside(menuRef, () => setMenuOpen(false), menuOpen);
  useClickOutside(createRef, () => setCreateOpen(false), createOpen);

  const isAdmin = user?.role === 'owner' || user?.role === 'admin';
  const initial = (user?.name ?? user?.email ?? '?').charAt(0).toUpperCase();

  // ── Mobile drawer focus trap ────────────────────────────────────────────
  // Trap Tab inside the open drawer and return focus to the hamburger on
  // close, per WAI-ARIA Authoring Practices for a disclosure pattern.
  useEffect(() => {
    if (!mobileOpen) return;
    const first = drawerRef.current?.querySelector<HTMLElement>(
      'a, button, [tabindex]:not([tabindex="-1"])'
    );
    first?.focus();
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') { setMobileOpen(false); return; }
      if (e.key !== 'Tab') return;
      const focusable = drawerRef.current?.querySelectorAll<HTMLElement>(
        'a, button, [tabindex]:not([tabindex="-1"])'
      );
      if (!focusable || focusable.length === 0) return;
      const f = focusable[0];
      const l = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === f) { e.preventDefault(); l.focus(); }
      else if (!e.shiftKey && document.activeElement === l) { e.preventDefault(); f.focus(); }
    }
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      mobileToggleRef.current?.focus();
    };
  }, [mobileOpen]);

  function onSkipToMain(e: React.MouseEvent) {
    e.preventDefault();
    const el = (document.getElementById('main-content')
      ?? document.querySelector('main')) as HTMLElement | null;
    if (!el) return;
    if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '-1');
    el.focus();
    el.scrollIntoView({ block: 'start' });
  }

  return (
    <>
      <a href="#main-content" className="skip-link" onClick={onSkipToMain}>
        Skip to main content
      </a>
      <header
        className="sticky top-0 z-30 border-b"
        style={{ background: 'var(--nav-bg)', borderColor: 'var(--nav-border)' }}
      >
        <div className="px-4 h-12 flex items-center gap-2">
          {/* Brand */}
          <Link
            to="/dashboard"
            aria-label="Smappen home"
            className="flex items-center gap-2 font-extrabold text-[15px] flex-shrink-0 mr-1 rounded focus-visible:outline-none focus-visible:ring-2"
            style={{ color: 'var(--nav-text-strong)' }}
          >
            <span className="brand-logo-tile inline-flex items-center justify-center w-7 h-7 rounded-md text-white font-extrabold text-sm shadow-sm">
              S
            </span>
            <span className="hidden sm:inline">smappen</span>
          </Link>

          {/* Desktop tabs */}
          <nav aria-label="Primary" className="hidden md:flex items-center gap-0.5">
            {ITEMS.map((item) => (
              <NavTab key={item.to} item={item} />
            ))}
          </nav>

          {/* Quick-create (+) */}
          <div ref={createRef} className="hidden md:block relative flex-shrink-0 ml-1">
            <button
              type="button"
              onClick={() => setCreateOpen((v) => !v)}
              aria-haspopup="menu"
              aria-expanded={createOpen}
              aria-label="Create new"
              title="Create new (restaurant, vendor, project)"
              className="p-1.5 rounded-md text-slate-600 hover:bg-slate-50 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
            >
              <Plus size={15} />
            </button>
            {createOpen && (
              <div
                role="menu"
                className="absolute left-0 top-full mt-1 w-56 bg-white border rounded-md shadow-lg py-1 z-40"
                style={{ borderColor: 'var(--nav-border)' }}
              >
                <CreateLink to="/app/restaurants?new=1" icon={<ChefHat size={13} />}    label="New restaurant" onPick={() => setCreateOpen(false)} />
                <CreateLink to="/app/vendors?new=1"     icon={<Building2 size={13} />}  label="New vendor"     onPick={() => setCreateOpen(false)} />
                <CreateLink to="/projects?new=1"        icon={<LayoutGrid size={13} />} label="New project"    onPick={() => setCreateOpen(false)} />
              </div>
            )}
          </div>

          {/* Page-context slot (project switcher, restaurant breadcrumb, etc.) */}
          {children !== undefined ? (
            <div
              className="hidden md:flex items-center gap-2 flex-1 min-w-0 ml-2 pl-2 border-l"
              style={{ borderColor: 'var(--nav-border)' }}
            >
              {children}
            </div>
          ) : (
            <div className="hidden md:block flex-1" />
          )}

          {/* Right-side actions: cost (admin), bell, user */}
          <div className="hidden md:flex items-center gap-1 flex-shrink-0">
            {isAdmin && <CostWidget />}
            <NotificationBell />
            <UserMenu
              ref={menuRef}
              open={menuOpen}
              onToggle={() => setMenuOpen((v) => !v)}
              onClose={() => setMenuOpen(false)}
              user={user}
              initial={initial}
              onLogout={logout}
            />
          </div>

          {/* Mobile inline context — opt-in via the mobileContext prop so the
              war-room can mount the restaurant switcher one-handed. Empty
              spacer when unset, preserving the original mobile layout. */}
          {mobileContext ? (
            <div className="md:hidden flex items-center min-w-0 flex-1 ml-1">
              {mobileContext}
            </div>
          ) : (
            <div className="md:hidden ml-auto" />
          )}

          {/* Mobile menu toggle */}
          <button
            ref={mobileToggleRef}
            className="md:hidden p-1.5 rounded text-slate-600 hover:bg-slate-50 flex-shrink-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
            onClick={() => setMobileOpen((v) => !v)}
            aria-label={mobileOpen ? 'Close navigation' : 'Open navigation'}
            aria-expanded={mobileOpen}
            aria-controls="primary-mobile-drawer"
          >
            {mobileOpen ? <X size={18} /> : <Menu size={18} />}
          </button>
        </div>

        {/* Mobile drawer — order: tabs → page context → auth */}
        {mobileOpen && (
          <div
            id="primary-mobile-drawer"
            ref={drawerRef}
            className="md:hidden border-t bg-white"
            style={{ borderColor: 'var(--nav-border)' }}
          >
            <nav aria-label="Primary navigation" className="px-2 py-2 space-y-0.5">
              {ITEMS.map((item) => (
                <MobileTab key={item.to} item={item} onPick={() => setMobileOpen(false)} />
              ))}
            </nav>
            {children && (
              <div
                className="border-t px-3 py-2 flex items-center gap-2 overflow-x-auto"
                style={{ borderColor: 'var(--nav-border)' }}
              >
                {children}
              </div>
            )}
            <div className="border-t" style={{ borderColor: 'var(--nav-border)' }} />
            <div className="px-3 py-2 text-[11px] text-slate-500 truncate">{user?.email ?? '—'}</div>
            <Link
              to="/settings/profile"
              onClick={() => setMobileOpen(false)}
              className="flex items-center gap-2 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            >
              <SettingsIcon size={14} /> Settings
            </Link>
            <button
              className="w-full flex items-center gap-2 px-3 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50"
              onClick={() => { setMobileOpen(false); logout(); }}
            >
              <LogOut size={14} /> Log out
            </button>
          </div>
        )}
      </header>
    </>
  );
}

// ─── Sub-components ─────────────────────────────────────────────────────────

function NavTab({ item }: { item: NavItem }) {
  const Icon = item.icon;
  // useMatch handles params, hashes, and overlapping prefixes correctly —
  // /app/restaurants/123/menu stays "active" on Restaurants without the
  // old longest-prefix-wins sort.
  const match = useMatch({ path: item.to, end: item.end ?? false });
  const isActive = !!match;
  return (
    <Link
      to={item.to}
      title={item.label}
      aria-current={isActive ? 'page' : undefined}
      className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-[13px] font-semibold whitespace-nowrap transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
      style={{
        background: isActive ? 'var(--nav-active-bg)' : 'transparent',
        color: isActive ? 'var(--nav-active-fg)' : 'var(--nav-text)',
      }}
      onMouseEnter={(e) => {
        if (!isActive) { e.currentTarget.style.background = '#f8fafc'; e.currentTarget.style.color = '#0f172a'; }
      }}
      onMouseLeave={(e) => {
        if (!isActive) { e.currentTarget.style.background = 'transparent'; e.currentTarget.style.color = 'var(--nav-text)'; }
      }}
    >
      <Icon size={13} /> <span className="hidden lg:inline">{item.label}</span>
    </Link>
  );
}

function MobileTab({ item, onPick }: { item: NavItem; onPick: () => void }) {
  const Icon = item.icon;
  const match = useMatch({ path: item.to, end: item.end ?? false });
  const isActive = !!match;
  return (
    <Link
      to={item.to}
      onClick={onPick}
      aria-current={isActive ? 'page' : undefined}
      className="flex items-center gap-2 px-3 py-2 rounded-md text-sm font-semibold"
      style={{
        background: isActive ? 'var(--nav-active-bg)' : 'transparent',
        color: isActive ? 'var(--nav-active-fg)' : 'var(--nav-text)',
      }}
    >
      <Icon size={14} /> {item.label}
    </Link>
  );
}

function CreateLink({ to, icon, label, onPick }: { to: string; icon: ReactNode; label: string; onPick: () => void }) {
  return (
    <Link
      to={to}
      role="menuitem"
      onClick={onPick}
      className="flex items-center gap-2 px-3 py-2 text-[13px] text-slate-700 hover:bg-slate-50"
    >
      {icon} {label}
    </Link>
  );
}

// ─── Notification bell — global, polled with visibility gate ────────────────

// "Bell is for decisions and abnormal events, never routine activity logging."
// Treat a notification as "signal" if it has a click-target (link_url) or its
// type matches an actionable pattern. Pure activity (created/updated/saved)
// is muted into the Activity tab, and the badge counts signal-unread only.
const SIGNAL_TYPE = /alert|mention|approv|conflict|invite|share|review|warn|fail|error|request|expir|limit/i;
function isSignal(n: Notification): boolean {
  if (n.link_url) return true;
  return SIGNAL_TYPE.test(n.notif_type ?? '');
}

function NotificationBell() {
  const navigate = useNavigate();
  const [notifs, setNotifs] = useState<Notification[]>([]);
  const [open, setOpen] = useState(false);
  const [showActivity, setShowActivity] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useClickOutside(ref, () => setOpen(false), open);

  const loadNotifs = useCallback(async () => {
    if (!useAuthStore.getState().token) return;
    try {
      const r = await notificationApi.list();
      setNotifs(r.notifications);
    } catch {}
  }, []);

  useEffect(() => { loadNotifs(); }, [loadNotifs]);
  useEffect(() => {
    // Poll every 60s, but pause when the tab is hidden. visibilitychange
    // fires immediately so we refresh on tab refocus.
    let timer: number | undefined;
    function tick() {
      if (document.visibilityState !== 'visible') return;
      if (!useAuthStore.getState().token) return;
      loadNotifs();
    }
    function start() {
      stop();
      timer = window.setInterval(tick, 60_000);
    }
    function stop() {
      if (timer) { window.clearInterval(timer); timer = undefined; }
    }
    function onVis() { if (document.visibilityState === 'visible') { tick(); start(); } else { stop(); } }
    start();
    document.addEventListener('visibilitychange', onVis);
    return () => { stop(); document.removeEventListener('visibilitychange', onVis); };
  }, [loadNotifs]);

  const signal = useMemo(() => notifs.filter(isSignal), [notifs]);
  const activity = useMemo(() => notifs.filter((n) => !isSignal(n)), [notifs]);
  const unreadSignal = signal.filter((n) => !n.is_read).length;
  const visible = showActivity ? notifs : signal;

  async function openNotif(n: Notification) {
    try { if (!n.is_read) await notificationApi.markRead(n.id); } catch {}
    if (n.link_url) {
      setOpen(false);
      navigate(n.link_url);
    }
    loadNotifs();
  }

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={unreadSignal > 0 ? `Notifications, ${unreadSignal} unread` : 'Notifications'}
        title="Notifications"
        className="relative p-1.5 rounded text-slate-600 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
      >
        <Bell size={17} />
        {unreadSignal > 0 && (
          <span
            aria-hidden="true"
            className="absolute -top-0.5 -right-0.5 bg-rose-500 text-white rounded-full px-1 text-[9px] font-bold min-w-[14px] text-center leading-[14px]"
          >
            {unreadSignal > 99 ? '99+' : unreadSignal}
          </span>
        )}
      </button>
      {open && (
        <div
          role="menu"
          className="absolute right-0 top-full mt-1 bg-white border rounded-lg shadow-lg w-[340px] z-40 max-h-[420px] overflow-hidden flex flex-col"
          style={{ borderColor: 'var(--nav-border)' }}
        >
          <div
            className="flex items-center justify-between px-3 py-2 border-b"
            style={{ borderColor: 'var(--nav-border)' }}
          >
            <span className="text-sm font-bold" style={{ color: 'var(--nav-text-strong)' }}>
              {showActivity ? 'All notifications' : 'Needs attention'}
            </span>
            {unreadSignal > 0 && (
              <button
                className="text-[11px] text-violet-700 font-semibold hover:underline"
                onClick={async () => { await notificationApi.markAllRead(); await loadNotifs(); }}
              >Mark all read</button>
            )}
          </div>
          <ul className="overflow-y-auto flex-1 divide-y divide-slate-100">
            {visible.length === 0 && (
              <li className="px-3 py-6 text-center text-sm text-slate-500">
                {showActivity ? 'No notifications' : 'Nothing needs attention'}
              </li>
            )}
            {visible.map((n) => (
              <li key={n.id} className={`text-xs ${n.is_read ? 'bg-white' : 'bg-violet-50/50'}`}>
                <button
                  type="button"
                  onClick={() => openNotif(n)}
                  className="w-full text-left px-3 py-2 hover:bg-slate-50 focus-visible:outline-none focus-visible:bg-slate-100"
                  disabled={!n.link_url && n.is_read === 1}
                  style={{ cursor: (n.link_url || n.is_read !== 1) ? 'pointer' : 'default' }}
                >
                  <div className="flex items-center justify-between mb-0.5">
                    <span className="font-semibold" style={{ color: 'var(--nav-text-strong)' }}>{n.title}</span>
                    <span className="text-slate-400">{new Date(n.created_at).toLocaleDateString()}</span>
                  </div>
                  {n.body && <div className="text-slate-700">{n.body}</div>}
                  {n.link_url && (
                    <div className="mt-1 text-[11px] font-semibold" style={{ color: 'var(--nav-active-fg)' }}>
                      Open →
                    </div>
                  )}
                </button>
              </li>
            ))}
          </ul>
          {activity.length > 0 && (
            <button
              type="button"
              onClick={() => setShowActivity((v) => !v)}
              className="border-t px-3 py-1.5 text-[11px] text-slate-500 hover:bg-slate-50 text-left"
              style={{ borderColor: 'var(--nav-border)' }}
            >
              {showActivity
                ? `← Back to needs-attention (${signal.length})`
                : `Show activity log (${activity.length})`}
            </button>
          )}
        </div>
      )}
    </div>
  );
}

// ─── Cost widget — admin/owner only, global ─────────────────────────────────

function CostWidget() {
  const total = useCostStore((s) => s.totalUsdToday);
  const calls = useCostStore((s) => s.callCountToday);
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const breakdownRef = useRef<{ api_name: string; calls: number; cost_usd: number }[]>([]);
  useClickOutside(ref, () => setOpen(false), open);

  const load = useCallback(async () => {
    if (!useAuthStore.getState().token) return;
    try {
      const r = await usageApi.today();
      useCostStore.getState().setTotals(r.total_usd, r.call_count);
      breakdownRef.current = r.breakdown;
    } catch (e: any) {
      if (import.meta.env.DEV) console.warn('[cost-widget] usage.today failed:', e?.message ?? e);
    }
  }, []);

  useEffect(() => { load(); }, [load]);
  useEffect(() => {
    let timer: number | undefined;
    function tick() {
      if (document.visibilityState !== 'visible') return;
      if (!useAuthStore.getState().token) return;
      load();
    }
    function start() { stop(); timer = window.setInterval(tick, 60_000); }
    function stop()  { if (timer) { window.clearInterval(timer); timer = undefined; } }
    function onVis() { if (document.visibilityState === 'visible') { tick(); start(); } else { stop(); } }
    start();
    document.addEventListener('visibilitychange', onVis);
    return () => { stop(); document.removeEventListener('visibilitychange', onVis); };
  }, [load]);

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="menu"
        aria-expanded={open}
        title="Google API spend today (estimate)"
        className="text-slate-600 hover:bg-slate-50 px-2 py-1.5 rounded text-xs font-semibold inline-flex items-center gap-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
      >
        <DollarSign size={13} style={{ color: total > 1 ? '#dc2626' : 'var(--brand)' }} />
        <span style={{ color: 'var(--nav-text-strong)' }}>{formatUsd(total)}</span>
        <span className="text-slate-400 hidden lg:inline">today</span>
      </button>
      {open && (
        <div
          className="absolute right-0 top-full mt-1 bg-white border rounded-lg shadow-lg w-[260px] z-40 p-3"
          style={{ borderColor: 'var(--nav-border)' }}
        >
          <div className="flex items-center justify-between mb-2">
            <span className="text-sm font-bold" style={{ color: 'var(--nav-text-strong)' }}>Google API spend</span>
            <span className="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Estimate</span>
          </div>
          <div className="text-2xl font-extrabold" style={{ color: 'var(--nav-text-strong)' }}>{formatUsd(total)}</div>
          <div className="text-xs text-slate-500 mb-2">{calls} calls today</div>
          <div className="border-t border-slate-100 pt-2">
            <div className="text-[10px] font-bold uppercase text-slate-500 tracking-wider mb-1">By API</div>
            {breakdownRef.current.length === 0 && (
              <div className="text-xs text-slate-400 italic">No spend yet today.</div>
            )}
            <ul className="space-y-0.5">
              {breakdownRef.current.map((row) => (
                <li key={row.api_name} className="flex items-center justify-between text-xs">
                  <span className="text-slate-600">{row.api_name}</span>
                  <span className="font-semibold" style={{ color: 'var(--nav-text-strong)' }}>
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
  );
}

// ─── User menu ──────────────────────────────────────────────────────────────

interface UserMenuProps {
  open: boolean;
  onToggle: () => void;
  onClose: () => void;
  user: User | null;
  initial: string;
  onLogout: () => void;
}
const UserMenu = forwardRef<HTMLDivElement, UserMenuProps>(function UserMenu(
  { open, onToggle, onClose, user, initial, onLogout },
  ref,
) {
  const toggleShortcuts = useUiPrefsStore((s) => s.toggleShortcutsModal);
  return (
    <div ref={ref} className="relative flex-shrink-0">
        <button
          type="button"
          onClick={onToggle}
          aria-haspopup="menu"
          aria-expanded={open}
          aria-label="Account menu"
          title={user?.email ?? 'Account'}
          className="flex items-center gap-1.5 px-2 py-1 rounded-md hover:bg-slate-50 text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
        >
          <span className="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-200 text-slate-700 font-bold text-[11px]">
            {initial}
          </span>
          <span className="hidden xl:inline text-[12px] font-semibold max-w-[140px] truncate">
            {user?.name ?? user?.email ?? '—'}
          </span>
          <ChevronDown size={12} className="text-slate-400" />
        </button>
        {open && (
          <div
            role="menu"
            className="absolute right-0 top-full mt-1 w-56 bg-white border rounded-md shadow-lg py-1 z-40"
            style={{ borderColor: 'var(--nav-border)' }}
          >
            <div className="px-3 py-2 border-b" style={{ borderColor: 'var(--nav-border)' }}>
              <div className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Signed in as</div>
              <div className="text-[13px] font-semibold truncate" style={{ color: 'var(--nav-text-strong)' }}>
                {user?.email ?? '—'}
              </div>
            </div>
            <Link
              to="/settings/profile"
              role="menuitem"
              className="flex items-center gap-2 px-3 py-2 text-[13px] text-slate-700 hover:bg-slate-50"
              onClick={onClose}
            >
              <SettingsIcon size={13} /> Settings
            </Link>
            <button
              role="menuitem"
              type="button"
              className="w-full flex items-center justify-between px-3 py-2 text-[13px] text-slate-700 hover:bg-slate-50 text-left"
              onClick={() => { onClose(); toggleShortcuts(); }}
            >
              <span className="flex items-center gap-2"><Keyboard size={13} /> Keyboard shortcuts</span>
              <kbd className="bg-slate-100 px-1 rounded text-[10px]">?</kbd>
            </button>
            <button
              role="menuitem"
              type="button"
              className="w-full flex items-center gap-2 px-3 py-2 text-[13px] text-rose-600 hover:bg-rose-50 text-left"
              onClick={() => { onClose(); onLogout(); }}
            >
              <LogOut size={13} /> Log out
            </button>
          </div>
        )}
      </div>
  );
});

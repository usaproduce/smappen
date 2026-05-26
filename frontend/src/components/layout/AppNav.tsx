import { ReactNode, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import {
  LayoutGrid, ChefHat, Building2, Map, Settings as SettingsIcon,
  LogOut, ChevronDown, Menu, X,
} from 'lucide-react';
import { useAuthStore } from '../../stores/authStore';
import { useClickOutside } from '../../hooks/useClickOutside';

/**
 * The ONE nav for every authenticated surface in the app.
 *
 * Layout (left → right):
 *   - smappen brand (links to /dashboard)
 *   - cross-product tabs (Dashboard / Restaurants / Vendors / Map)
 *   - {children} — optional page-specific action strip (project switcher
 *     on /app, restaurant picker on /app/restaurants/{id}/*, etc).
 *     If a page doesn't need anything special, omit children — the user
 *     menu takes that space.
 *   - user menu (avatar → email → logout)
 *
 * Conventions:
 *   - 48px tall (h-12) + 1px border = 49px
 *   - sticky top-0 z-30
 *   - max-w-7xl content row
 *   - mobile: tabs collapse into a hamburger menu under 768px
 *
 * Every authenticated page should mount EXACTLY ONE AppNav at its top.
 * NEVER nest AppNav inside another layout that already renders it.
 */

interface NavItem {
  to: string;
  label: string;
  icon: typeof LayoutGrid;
  matchPrefix: string;
}

const ITEMS: NavItem[] = [
  { to: '/dashboard',         label: 'Dashboard',   icon: LayoutGrid,    matchPrefix: '/dashboard' },
  { to: '/app/restaurants',   label: 'Restaurants', icon: ChefHat,       matchPrefix: '/app/restaurants' },
  { to: '/app/vendors',       label: 'Vendors',     icon: Building2,     matchPrefix: '/app/vendors' },
  { to: '/app',               label: 'Map',         icon: Map,           matchPrefix: '/app' },
  { to: '/settings/profile',  label: 'Settings',    icon: SettingsIcon,  matchPrefix: '/settings' },
];

export default function AppNav({ children }: { children?: ReactNode }) {
  const location = useLocation();
  const user = useAuthStore((s) => s.user) as any;
  const logout = useAuthStore((s) => s.logout);
  const [menuOpen, setMenuOpen] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  useClickOutside(menuRef, () => setMenuOpen(false), menuOpen);

  // Longest-matching-prefix wins so /app/restaurants/{id}/menu stays "active"
  // on the Restaurants tab (otherwise /app would catch it).
  const activeTo = ITEMS
    .filter((i) => location.pathname.startsWith(i.matchPrefix))
    .sort((a, b) => b.matchPrefix.length - a.matchPrefix.length)[0]?.to;

  const initial = (user?.name ?? user?.email ?? '?').toString().charAt(0).toUpperCase();

  return (
    <header className="bg-white border-b border-slate-200 sticky top-0 z-30">
      <div className="max-w-7xl mx-auto px-4 h-12 flex items-center gap-2">
        {/* Brand */}
        <Link
          to="/dashboard"
          className="flex items-center gap-2 font-extrabold text-[15px] flex-shrink-0 mr-1"
          style={{ color: '#1A1A2E' }}
        >
          <span
            className="brand-logo-tile inline-flex items-center justify-center w-7 h-7 rounded-md text-white font-extrabold text-sm shadow-sm"
          >
            S
          </span>
          <span className="hidden sm:inline">smappen</span>
        </Link>

        {/* Desktop tabs */}
        <nav className="hidden md:flex items-center gap-0.5">
          {ITEMS.map((item) => {
            const Icon = item.icon;
            const isActive = activeTo === item.to;
            return (
              <Link
                key={item.to}
                to={item.to}
                className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-[13px] font-semibold whitespace-nowrap transition-colors ${
                  isActive
                    ? 'bg-violet-100 text-violet-800'
                    : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
                }`}
              >
                <Icon size={13} /> <span className="hidden lg:inline">{item.label}</span>
              </Link>
            );
          })}
        </nav>

        {/* Page-context slot (project switcher, restaurant picker, etc.) */}
        {children !== undefined && (
          <div className="hidden md:flex items-center gap-2 flex-1 min-w-0 ml-2 pl-2 border-l border-slate-200">
            {children}
          </div>
        )}
        {children === undefined && <div className="hidden md:block flex-1" />}

        {/* User menu — always rightmost on desktop */}
        <div ref={menuRef} className="hidden md:block relative flex-shrink-0">
          <button
            className="flex items-center gap-1.5 px-2 py-1 rounded-md hover:bg-slate-50 text-slate-700"
            onClick={() => setMenuOpen((v) => !v)}
            title={user?.email}
          >
            <span className="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-200 text-slate-700 font-bold text-[11px]">
              {initial}
            </span>
            <span className="hidden xl:inline text-[12px] font-semibold max-w-[140px] truncate">
              {user?.name ?? user?.email ?? '—'}
            </span>
            <ChevronDown size={12} className="text-slate-400" />
          </button>
          {menuOpen && (
            <div className="absolute right-0 top-full mt-1 w-56 bg-white border border-slate-200 rounded-md shadow-lg py-1 z-40">
              <div className="px-3 py-2 border-b border-slate-100">
                <div className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Signed in as</div>
                <div className="text-[13px] font-semibold truncate" style={{ color: '#1A1A2E' }}>{user?.email ?? '—'}</div>
              </div>
              <Link
                to="/settings/profile"
                className="flex items-center gap-2 px-3 py-2 text-[13px] text-slate-700 hover:bg-slate-50"
                onClick={() => setMenuOpen(false)}
              >
                <SettingsIcon size={13} /> Settings
              </Link>
              <button
                className="w-full flex items-center gap-2 px-3 py-2 text-[13px] text-rose-600 hover:bg-rose-50 text-left"
                onClick={() => { setMenuOpen(false); logout(); }}
              >
                <LogOut size={13} /> Log out
              </button>
            </div>
          )}
        </div>

        {/* Mobile menu toggle */}
        <button
          className="md:hidden p-1.5 rounded hover:bg-slate-50 text-slate-600 ml-auto"
          onClick={() => setMobileOpen((v) => !v)}
          aria-label="Toggle navigation"
        >
          {mobileOpen ? <X size={18} /> : <Menu size={18} />}
        </button>
      </div>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="md:hidden border-t border-slate-200 bg-white">
          <nav className="px-2 py-2 space-y-0.5">
            {ITEMS.map((item) => {
              const Icon = item.icon;
              const isActive = activeTo === item.to;
              return (
                <Link
                  key={item.to}
                  to={item.to}
                  onClick={() => setMobileOpen(false)}
                  className={`flex items-center gap-2 px-3 py-2 rounded-md text-sm font-semibold ${
                    isActive ? 'bg-violet-100 text-violet-800' : 'text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  <Icon size={14} /> {item.label}
                </Link>
              );
            })}
            <div className="border-t border-slate-100 my-1" />
            <div className="px-3 py-2 text-[11px] text-slate-500 truncate">{user?.email ?? '—'}</div>
            <button
              className="w-full flex items-center gap-2 px-3 py-2 rounded-md text-sm font-semibold text-rose-600 hover:bg-rose-50"
              onClick={() => { setMobileOpen(false); logout(); }}
            >
              <LogOut size={14} /> Log out
            </button>
          </nav>
          {children && (
            <div className="border-t border-slate-100 px-3 py-2 flex items-center gap-2 overflow-x-auto">
              {children}
            </div>
          )}
        </div>
      )}
    </header>
  );
}

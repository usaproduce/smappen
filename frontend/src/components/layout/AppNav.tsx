import { Link, useLocation } from 'react-router-dom';
import { LayoutGrid, ChefHat, Building2, Map, Settings, Bell } from 'lucide-react';
import { useAuthStore } from '../../stores/authStore';

/**
 * Shared top nav for all authenticated Carafe / Smappen surfaces.
 *
 * One bar across:
 *   /dashboard | /app/restaurants | /app/vendors | /app (map) | /settings
 *
 * Active item gets the violet treatment. The bell + brand wordmark match
 * the existing DashboardPage chrome so going between surfaces feels
 * continuous, not like four different products bolted together (which is
 * what the ad-hoc per-page navs looked like).
 */

interface NavItem {
  to: string;
  label: string;
  icon: typeof LayoutGrid;
  /** Match prefix — used so /app/restaurants/{id}/menu stays "active" on the Restaurants tab. */
  matchPrefix?: string;
}

const ITEMS: NavItem[] = [
  { to: '/dashboard',         label: 'Dashboard',   icon: LayoutGrid, matchPrefix: '/dashboard' },
  { to: '/app/restaurants',   label: 'Restaurants', icon: ChefHat,    matchPrefix: '/app/restaurants' },
  { to: '/app/vendors',       label: 'Vendors',     icon: Building2,  matchPrefix: '/app/vendors' },
  { to: '/app',               label: 'Map',         icon: Map,        matchPrefix: '/app' /* falls through to anything /app/* not matched above */ },
  { to: '/settings/profile',  label: 'Settings',    icon: Settings,   matchPrefix: '/settings' },
];

export default function AppNav() {
  const location = useLocation();
  const user = useAuthStore((s) => s.user) as any;

  const pathname = location.pathname;
  // Active = longest matching prefix wins (so /app/restaurants matches
  // /app/restaurants/{id}/menu before /app catches it).
  const activeTo = ITEMS
    .filter((i) => i.matchPrefix && pathname.startsWith(i.matchPrefix))
    .sort((a, b) => (b.matchPrefix?.length ?? 0) - (a.matchPrefix?.length ?? 0))[0]?.to;

  return (
    <header className="bg-white border-b border-slate-200 sticky top-0 z-30">
      <div className="max-w-7xl mx-auto px-6 py-2.5 flex items-center justify-between gap-4">
        <Link
          to="/dashboard"
          className="flex items-center gap-2 font-extrabold text-[16px] flex-shrink-0"
          style={{ color: '#1A1A2E' }}
        >
          <span
            className="inline-flex items-center justify-center w-8 h-8 rounded-lg text-white font-extrabold text-base shadow-sm"
            style={{ background: 'linear-gradient(135deg, #F57C00 0%, #E53935 50%, #7848BB 100%)' }}
          >
            S
          </span>
          smappen
        </Link>

        <nav className="flex items-center gap-0.5 overflow-x-auto">
          {ITEMS.map((item) => {
            const Icon = item.icon;
            const isActive = activeTo === item.to;
            return (
              <Link
                key={item.to}
                to={item.to}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-semibold whitespace-nowrap transition-colors ${
                  isActive
                    ? 'bg-violet-100 text-violet-800'
                    : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
                }`}
              >
                <Icon size={14} /> {item.label}
              </Link>
            );
          })}
        </nav>

        <div className="flex items-center gap-2 flex-shrink-0">
          <Link
            to="/settings/profile"
            className="hidden md:flex items-center gap-2 px-2 py-1 rounded-md text-xs font-semibold text-slate-600 hover:bg-slate-50"
            title={user?.email}
          >
            <span className="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-200 text-slate-700 font-bold text-[11px]">
              {(user?.name ?? user?.email ?? '?').toString().charAt(0).toUpperCase()}
            </span>
            <span className="hidden lg:inline max-w-[140px] truncate">{user?.name ?? user?.email ?? '—'}</span>
          </Link>
        </div>
      </div>
    </header>
  );
}

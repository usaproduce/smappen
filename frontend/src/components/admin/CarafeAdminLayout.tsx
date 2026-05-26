import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { LayoutDashboard, ListChecks, MapPin, ShieldAlert } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { carafeApi } from '../../api/carafe';

/**
 * Shared layout for /admin/carafe/*. Hand-rolled top bar — deliberately
 * NOT the global AppNav so this admin surface stays out of every other
 * user's sight line. Only operators who know the URL see it; everyone
 * else gets the regular product nav.
 *
 * Sub-nav: Home · Campaigns · Review queue · (badge shows pending count).
 */
export default function CarafeAdminLayout() {
  const location = useLocation();
  const { data: queue } = useQuery({
    queryKey: ['carafe', 'review-queue', 'counts'],
    queryFn: async () => (await carafeApi.reviewQueue(undefined, 1, 0)).counts,
    refetchInterval: 30_000,
    staleTime: 15_000,
  });

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="bg-white border-b border-slate-200">
        <div className="max-w-7xl mx-auto px-6 h-14 flex items-center justify-between">
          <div className="flex items-center gap-6">
            <Link to="/admin/carafe" className="flex items-center gap-2 font-extrabold text-slate-900">
              <ShieldAlert size={18} className="text-amber-500" />
              <span>Carafe&nbsp;Admin</span>
              <span className="text-[10px] uppercase tracking-wider font-bold text-amber-600 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded">
                Internal
              </span>
            </Link>
            <nav className="flex items-center gap-1 text-sm">
              <SubLink to="/admin/carafe"               icon={<LayoutDashboard size={14} />} label="Overview" exact />
              <SubLink to="/admin/carafe/campaigns"     icon={<MapPin size={14} />}          label="Campaigns" />
              <SubLink to="/admin/carafe/review"        icon={<ListChecks size={14} />}      label="Review" badge={queue?.total} />
            </nav>
          </div>
          <div className="text-xs text-slate-500">
            <Link to="/dashboard" className="hover:text-slate-700">← Back to product</Link>
          </div>
        </div>
      </header>
      <main className="max-w-7xl mx-auto px-6 py-8">
        <Outlet />
      </main>
    </div>
  );
}

function SubLink({ to, icon, label, badge, exact }: { to: string; icon: React.ReactNode; label: string; badge?: number; exact?: boolean }) {
  return (
    <NavLink
      to={to}
      end={exact}
      className={({ isActive }) =>
        `flex items-center gap-1.5 px-2.5 py-1.5 rounded-md font-semibold ${
          isActive ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50'
        }`
      }
    >
      {icon}
      <span>{label}</span>
      {badge && badge > 0 ? (
        <span className="ml-1 text-[10px] font-bold bg-amber-500 text-white px-1.5 py-0.5 rounded">
          {badge}
        </span>
      ) : null}
    </NavLink>
  );
}

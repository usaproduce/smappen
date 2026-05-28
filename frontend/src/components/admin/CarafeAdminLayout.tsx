import { Link, NavLink, Outlet } from 'react-router-dom';
import { LayoutDashboard, ListChecks, MapPin, ShieldAlert } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { carafeApi } from '../../api/carafe';
import AppNav from '../layout/AppNav';

/**
 * Shared layout for /admin/carafe/*. Uses the global AppNav with a Carafe
 * Admin context strip in the children slot — keeps a single nav source of
 * truth instead of a hand-rolled header.
 *
 * Sub-nav: Overview · Campaigns · Review queue (badge shows pending count).
 */
export default function CarafeAdminLayout() {
  const { data: queue } = useQuery({
    queryKey: ['carafe', 'review-queue', 'counts'],
    queryFn: async () => (await carafeApi.reviewQueue(undefined, 1, 0)).counts,
    refetchInterval: 30_000,
    staleTime: 15_000,
  });

  return (
    <div className="min-h-screen bg-slate-50">
      <AppNav>
        <div className="flex items-center gap-2 min-w-0 flex-1">
          <Link
            to="/admin/carafe"
            className="flex items-center gap-1.5 font-extrabold text-[13px]"
            style={{ color: 'var(--nav-text-strong)' }}
          >
            <ShieldAlert size={14} className="text-amber-500" />
            <span>Carafe&nbsp;Admin</span>
            <span className="text-[9px] uppercase tracking-wider font-bold text-amber-600 bg-amber-50 border border-amber-200 px-1 py-px rounded">
              Internal
            </span>
          </Link>
          <nav aria-label="Carafe admin" className="flex items-center gap-0.5 ml-2">
            <SubLink to="/admin/carafe"           icon={<LayoutDashboard size={12} />} label="Overview" exact />
            <SubLink to="/admin/carafe/campaigns" icon={<MapPin size={12} />}          label="Campaigns" />
            <SubLink to="/admin/carafe/review"    icon={<ListChecks size={12} />}      label="Review" badge={queue?.total} />
          </nav>
          <div className="ml-auto text-[11px] text-slate-500">
            <Link to="/dashboard" className="hover:text-slate-700">← Back to product</Link>
          </div>
        </div>
      </AppNav>
      <main id="main-content" tabIndex={-1} className="max-w-7xl mx-auto px-6 py-8 focus:outline-none">
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
        `flex items-center gap-1 px-2 py-1 rounded-md text-[12px] font-semibold ${
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

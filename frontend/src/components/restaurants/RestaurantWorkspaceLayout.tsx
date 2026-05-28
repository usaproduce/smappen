import { ReactNode, useEffect, useState } from 'react';
import { Link, NavLink, useLocation, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  Home, ChefHat, BookOpen, DollarSign, Users2, Target, ArrowLeft,
} from 'lucide-react';
import { restaurantsApi } from '../../api/restaurants';
import { useRestaurantStore } from '../../stores/restaurantStore';
import AppNav from '../layout/AppNav';

/**
 * Per-restaurant workspace shell. Wraps a sidebar of tabs +
 * loads the current restaurant on mount so every tab has it in the store
 * without each tab re-fetching.
 *
 * Lives under /app/restaurants/:id/* — tabs:
 *   overview   War-room (today's covers, food cost %, ROI tile, top recs)
 *   menu       Menu items + plate cost + recommendations
 *   recipes    Recipe builder (operator-essential — without recipes, no plate cost)
 *   costs      Theoretical food cost + top contributors
 *   labor      Labor analysis + daypart slow-window suggestions
 *   goals      Operator scorecard with snapshot trends
 */

const TABS: Array<{ key: string; label: string; icon: any; href: (id: string) => string }> = [
  { key: 'overview', label: 'Overview', icon: Home,      href: (id) => `/app/restaurants/${id}` },
  { key: 'menu',     label: 'Menu',     icon: ChefHat,   href: (id) => `/app/restaurants/${id}/menu` },
  { key: 'recipes',  label: 'Recipes',  icon: BookOpen,  href: (id) => `/app/restaurants/${id}/recipes` },
  { key: 'costs',    label: 'Costs',    icon: DollarSign, href: (id) => `/app/restaurants/${id}/costs` },
  { key: 'labor',    label: 'Labor',    icon: Users2,    href: (id) => `/app/restaurants/${id}/labor` },
  { key: 'goals',    label: 'Goals',    icon: Target,    href: (id) => `/app/restaurants/${id}/goals` },
];

export default function RestaurantWorkspaceLayout({ children }: { children: ReactNode }) {
  const { id } = useParams<{ id: string }>();
  const restaurantId = id ?? '';
  const location = useLocation();
  const currentRestaurant = useRestaurantStore((s) => s.currentRestaurant);
  const setCurrentRestaurant = useRestaurantStore((s) => s.setCurrentRestaurant);
  const [loadingRestaurant, setLoadingRestaurant] = useState(currentRestaurant?.id !== restaurantId);

  useEffect(() => {
    if (!restaurantId) return;
    if (currentRestaurant?.id === restaurantId) {
      setLoadingRestaurant(false);
      return;
    }
    let cancelled = false;
    (async () => {
      try {
        const r = await restaurantsApi.show(restaurantId);
        if (!cancelled) setCurrentRestaurant(r);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed to load restaurant');
      } finally {
        if (!cancelled) setLoadingRestaurant(false);
      }
    })();
    return () => { cancelled = true; };
  }, [restaurantId, currentRestaurant?.id, setCurrentRestaurant]);

  return (
    <div className="min-h-screen bg-slate-50">
      <AppNav>
        {/* Restaurant breadcrumb in AppNav's context slot — single cohesive
            top bar instead of two stacked rows. */}
        <div className="flex items-center gap-2 min-w-0">
          <Link
            to="/app/restaurants"
            className="text-[12px] font-semibold text-slate-500 hover:text-violet-700 flex items-center gap-1 flex-shrink-0"
          >
            <ArrowLeft size={12} /> Restaurants
          </Link>
          <span className="text-slate-300">/</span>
          {loadingRestaurant ? (
            <div className="skeleton h-4 w-40" />
          ) : (
            <div className="font-extrabold text-[13px] truncate" style={{ color: 'var(--nav-text-strong)' }}>
              {currentRestaurant?.name ?? 'Restaurant'}
            </div>
          )}
          {currentRestaurant?.is_sample === 1 && (
            <span className="text-[10px] font-bold uppercase tracking-wider text-violet-700 bg-violet-50 rounded px-1.5 py-0.5 flex-shrink-0">
              Sample
            </span>
          )}
        </div>
      </AppNav>

      <div className="max-w-7xl mx-auto px-6 py-6 grid grid-cols-12 gap-6">
        {/* Sidebar tabs */}
        <aside className="col-span-12 md:col-span-2">
          <nav className="bg-white border border-slate-200 rounded-xl p-1.5 md:sticky md:top-20">
            {TABS.map((t) => {
              const Icon = t.icon;
              const href = t.href(restaurantId);
              const isActive = t.key === 'overview'
                ? location.pathname === href
                : location.pathname.startsWith(href);
              return (
                <NavLink
                  key={t.key}
                  to={href}
                  end={t.key === 'overview'}
                  className={`flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold transition-colors ${
                    isActive
                      ? 'bg-violet-100 text-violet-800'
                      : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
                  }`}
                >
                  <Icon size={14} /> {t.label}
                </NavLink>
              );
            })}
          </nav>
        </aside>

        {/* Tab content */}
        <main id="main-content" tabIndex={-1} className="col-span-12 md:col-span-10 focus:outline-none">
          {children}
        </main>
      </div>
    </div>
  );
}

import { ReactNode, useEffect, useState } from 'react';
import { NavLink, useLocation, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  Home, ChefHat, BookOpen, DollarSign, Users2, Target, Sparkles,
} from 'lucide-react';
import { restaurantsApi } from '../../api/restaurants';
import { useRestaurantStore } from '../../stores/restaurantStore';
import AppNav from '../layout/AppNav';
import RestaurantSwitcher from './RestaurantSwitcher';

/**
 * Per-restaurant workspace shell.
 *   - phone   : sticky <RestaurantSwitcher /> in AppNav's mobile context;
 *               tabs become a horizontal chip rail under the nav.
 *   - desktop : same switcher in the desktop context; tabs as a vertical
 *               sidebar.
 *
 * Lives under /app/restaurants/:id/*.
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
    <div className="min-h-screen" style={{ background: 'var(--bg)' }}>
      <AppNav mobileContext={<RestaurantSwitcher />}>
        <RestaurantSwitcher />
        {currentRestaurant?.is_sample === 1 && (
          <span
            className="hidden md:inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider rounded px-1.5 py-0.5 flex-shrink-0"
            style={{ background: 'var(--carafe-accent-50)', color: 'var(--carafe-accent)' }}
          >
            <Sparkles size={9} /> Sample
          </span>
        )}
      </AppNav>

      {/* Sample-data banner — every workspace surface gets the same honest
          disclosure so a first-time operator can't mistake the demo for live
          data. Persistent (not dismissable) and links to the restaurants
          page where Remove + Connect POS live. */}
      {currentRestaurant?.is_sample === 1 && (
        <div
          role="status"
          aria-live="polite"
          className="border-b"
          style={{
            background: 'var(--carafe-accent-50)',
            borderColor: 'var(--carafe-accent-light)',
            color: 'var(--ink)',
          }}
        >
          <div className="max-w-7xl mx-auto px-4 md:px-6 py-2 flex items-center gap-3 text-xs">
            <Sparkles size={14} style={{ color: 'var(--carafe-accent)' }} />
            <span className="flex-1 min-w-0">
              <strong>Sample data.</strong> Every number on this page is synthetic — connect your POS to see yours.
            </span>
            <NavLink
              to="/app/restaurants"
              className="font-bold hover:underline whitespace-nowrap"
              style={{ color: 'var(--carafe-accent)' }}
            >
              Manage sample →
            </NavLink>
          </div>
        </div>
      )}

      {/* Mobile chip rail — sticky under AppNav so the operator can swap
          tabs without losing the dollar tile from view. */}
      <nav
        aria-label="Restaurant sections"
        className="md:hidden sticky top-12 z-20 border-b scroll-x overflow-x-auto"
        style={{ background: 'white', borderColor: 'var(--line-soft)' }}
      >
        <ul className="flex items-center gap-1 px-2 py-1.5 whitespace-nowrap">
          {TABS.map((t) => {
            const Icon = t.icon;
            const href = t.href(restaurantId);
            const isActive = t.key === 'overview'
              ? location.pathname === href
              : location.pathname.startsWith(href);
            return (
              <li key={t.key} className="flex-shrink-0">
                <NavLink
                  to={href}
                  end={t.key === 'overview'}
                  className="inline-flex items-center gap-1.5 h-11 px-3.5 rounded-full text-[13px] font-semibold"
                  style={{
                    background: isActive ? 'var(--brand-light)' : 'transparent',
                    color: isActive ? 'var(--brand)' : 'var(--slate)',
                  }}
                >
                  <Icon size={14} /> {t.label}
                </NavLink>
              </li>
            );
          })}
        </ul>
        {loadingRestaurant && <div className="skeleton h-0.5 w-full" />}
      </nav>

      <div className="max-w-7xl mx-auto px-4 md:px-6 py-4 md:py-6 md:grid md:grid-cols-12 md:gap-6">
        <aside className="hidden md:block md:col-span-2">
          <nav aria-label="Restaurant sections" className="bg-white border border-slate-200 rounded-xl p-1.5 md:sticky md:top-16">
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

        <main id="main-content" tabIndex={-1} className="md:col-span-10 focus:outline-none">
          {/* Key by pathname so each tab swap re-triggers carafeRouteFade.
              The animation is gated by prefers-reduced-motion at the CSS
              layer, so users opted out see a hard cut (still correct). */}
          <div key={location.pathname} className="carafe-route-fade">
            {children}
          </div>
        </main>
      </div>
    </div>
  );
}

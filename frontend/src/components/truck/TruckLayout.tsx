import { Suspense, useEffect, useRef, useState, type ReactNode } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import {
  CalendarDays, CalendarRange, Compass, MapPinned, NotebookPen, Route, Settings2, Store,
} from 'lucide-react';
import AppNav from '../layout/AppNav';
import ErrorBoundary from '../ErrorBoundary';

/**
 * The shell of every /truck route: the app's top nav, the Truck Planner sub-nav, and the page.
 *
 * This is the ONLY truck file in the main bundle (docs/truck-planner/05_FRONTEND.md 1.3 and 1.7).
 * It is eager so that the top nav and the sub-nav paint at once and AppNav is not remounted (it
 * polls on mount). Everything else arrives with the lazy TruckPages chunk through <Outlet />.
 *
 * Because it is eager it may import only react, react-router-dom, lucide-react, AppNav and
 * ErrorBoundary: not the truck pages, the UI kit, truck.css, the truck api, the truck stores, the
 * estimator or h3-js. A source guard (guards.eager.test.ts) fails the build otherwise.
 */

interface TruckTab {
  to: string;
  label: string;
  icon: typeof CalendarDays;
  /** Exact match only: `/truck` itself must not stay lit on every sub-route. */
  end?: boolean;
}

// The order is fixed: Today, Map, Spots, Planner, Week, Log, Scout, Settings.
const TRUCK_TABS: TruckTab[] = [
  { to: '/truck',          label: 'Today',    icon: CalendarDays, end: true },
  { to: '/truck/map',      label: 'Map',      icon: MapPinned },
  { to: '/truck/spots',    label: 'Spots',    icon: Store },
  { to: '/truck/plan',     label: 'Planner',  icon: Route },
  { to: '/truck/week',     label: 'Week',     icon: CalendarRange },
  { to: '/truck/log',      label: 'Log',      icon: NotebookPen },
  { to: '/truck/scout',    label: 'Scout',    icon: Compass },
  { to: '/truck/settings', label: 'Settings', icon: Settings2 },
];

export default function TruckLayout() {
  const { pathname } = useLocation();
  // '/truck' -> 'today', '/truck/spots/abc' -> 'spots', '/truck/plan/2026-10-08/sheet' -> 'plan'
  const section = pathname.split('/')[2] || 'today';
  // The map is locked to the viewport; every other section scrolls the page.
  const isMap = section === 'map';
  const railRef = useRef<HTMLElement>(null);

  useEffect(() => {
    const previous = document.title;
    document.title = 'Truck Planner';
    return () => { document.title = previous; };
  }, []);

  // On a phone the rail scrolls sideways: keep the active tab in view after a route change. The
  // tabs change width when the web font arrives and the rail when the screen turns, so the tab is
  // shown again then; without that the last tab ends up a few pixels off the edge on a hard load.
  useEffect(() => {
    const rail = railRef.current;
    if (rail === null) return undefined;
    const show = () => {
      const active = rail.querySelector<HTMLElement>('a[aria-current="page"]');
      if (active && typeof active.scrollIntoView === 'function') {
        active.scrollIntoView({ inline: 'center', block: 'nearest' });
      }
    };
    show();
    if (typeof ResizeObserver === 'undefined') return undefined;
    const observer = new ResizeObserver(show);
    observer.observe(rail);
    rail.querySelectorAll('li').forEach((tab) => observer.observe(tab));
    return () => observer.disconnect();
  }, [section]);

  return (
    <div
      className={isMap ? 'h-dvh flex flex-col' : 'min-h-screen flex flex-col'}
      style={{ background: 'var(--bg)' }}
    >
      <AppNav />

      <nav
        ref={railRef}
        aria-label="Truck Planner sections"
        className="sticky top-12 z-20 border-b bg-white scroll-x overflow-x-auto"
        style={{ borderColor: 'var(--nav-border)' }}
      >
        <ul className="max-w-7xl mx-auto px-2 md:px-6 py-1.5 flex items-center gap-1 whitespace-nowrap">
          {TRUCK_TABS.map((tab) => {
            const Icon = tab.icon;
            return (
              <li key={tab.to} className="flex-shrink-0">
                <NavLink
                  to={tab.to}
                  end={tab.end}
                  className="inline-flex items-center gap-1.5 h-11 md:h-9 px-3.5 md:px-3 rounded-lg text-[13px] font-semibold hover:bg-slate-50"
                  style={({ isActive }) =>
                    isActive
                      ? { background: 'var(--nav-active-bg)', color: 'var(--nav-active-fg)' }
                      : { color: 'var(--nav-text)' }
                  }
                >
                  <Icon size={14} aria-hidden="true" /> {tab.label}
                </NavLink>
              </li>
            );
          })}
        </ul>
      </nav>

      <main
        id="main-content"
        tabIndex={-1}
        className={(isMap ? 'relative flex-1 min-h-0' : 'flex-1') + ' focus:outline-none'}
      >
        {/* key={section}: a crash on one tab must not leave the next tab stuck on the error card.
            The existing ErrorBoundary class must be the boundary: it is the only code that
            recovers from a stale lazy chunk after a deploy. */}
        <ErrorBoundary key={section} scope="Truck Planner" inline>
          <Suspense fallback={<TruckPageFallback isMap={isMap} />}>
            {isMap ? <Outlet /> : <PageFrame><Outlet /></PageFrame>}
          </Suspense>
        </ErrorBoundary>
      </main>
    </div>
  );
}

const PAGE_FRAME = 'max-w-7xl mx-auto px-4 md:px-6 py-4 md:py-6';

/**
 * The page container of every section except the map, with the 160 ms route fade.
 *
 * The fade class is dropped when its animation ends: its last keyframe leaves `transform:
 * translateY(0)` on the element, and an element with a transform becomes the containing block of
 * every `position: fixed` descendant. Left on, it would pin the phone planner's bottom bar to the
 * end of the page content instead of the bottom of the screen.
 */
function PageFrame({ children }: { children: ReactNode }) {
  const [faded, setFaded] = useState(false);
  return (
    <div
      className={(faded ? '' : 'carafe-route-fade ') + PAGE_FRAME}
      onAnimationEnd={(e) => { if (e.target === e.currentTarget) setFaded(true); }}
    >
      {children}
    </div>
  );
}

/** What shows while the lazy chunk loads: plain skeleton blocks in the shape of a page. */
function TruckPageFallback({ isMap }: { isMap: boolean }) {
  if (isMap) {
    // The words of MAP_TEXT.loading in utils/truck/wording.ts, written out here: this file is in
    // the main bundle and may import no truck module.
    return (
      <div
        className="absolute inset-0 grid place-items-center text-sm font-semibold"
        style={{ color: 'var(--body)' }}
        aria-busy="true"
      >
        Loading map...
      </div>
    );
  }
  return (
    <div className={PAGE_FRAME + ' space-y-4'} aria-busy="true">
      <div className="skeleton rounded-xl" style={{ height: 96 }} />
      <div className="skeleton rounded-xl" style={{ height: 72 }} />
      <div className="skeleton rounded-xl" style={{ height: 72 }} />
      <div className="skeleton rounded-xl" style={{ height: 72 }} />
    </div>
  );
}

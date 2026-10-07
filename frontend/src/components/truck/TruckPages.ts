// The lazy boundary of Truck Planner (docs/truck-planner/05_FRONTEND.md 1.5 and 1.7).
//
// App.tsx reaches the gate and every page through ONE import() of this module, so the build emits
// the chunk TruckPages-<hash>.js. Everything that only these exports import ships with it: the
// truck stylesheets, the UI kit, the data hooks, the truck api and stores, the estimator and its
// seeds.
//
// The map page is the one exception: it is a second import() made here, so the map engine and h3-js
// (about a quarter of all truck code) are a chunk of their own, MapPage-<hash>.js, fetched when the
// map is opened. Today, the planner and the log, which are opened on a phone, do not wait for it.
//
// Nothing in the eager graph (App.tsx, AppNav, CommandPalette, ProtectedRoute, TruckLayout) may
// import from this file statically, or from anything it re-exports. Two checks keep it that way:
// utils/truck/__tests__/guards.eager.test.ts and frontend/scripts/check-truck-chunks.mjs.

import { createElement, lazy } from 'react';

// Truck CSS ships with the chunk. Both files are plain CSS, without Tailwind directives.
import './truck.css';
import './print.css';
// For its side effect: handles a Maps key that Google refuses (5.6).
import './map/authFailure';

export { default as TruckGate } from './TruckGate';

export { default as TodayPage } from './pages/TodayPage';
export { default as SpotsPage } from './pages/SpotsPage';
export { default as SpotComparePage } from './pages/SpotComparePage';
export { default as SpotDetailPage } from './pages/SpotDetailPage';
export { default as PlanIndexRedirect } from './pages/PlanIndexRedirect';
export { default as PlannerPage } from './pages/PlannerPage';
export { default as DaySheetPage } from './pages/DaySheetPage';
export { default as WeekIndexRedirect } from './pages/WeekIndexRedirect';
export { default as WeekPage } from './pages/WeekPage';
export { default as LogPage } from './pages/LogPage';
export { default as ScoutPage } from './pages/ScoutPage';
export { default as SettingsPage } from './pages/SettingsPage';

const LazyMapPage = lazy(() => import('./pages/MapPage'));
/** The map page. It suspends into the layout's map-shaped placeholder while its chunk loads. */
export function MapPage() {
  return createElement(LazyMapPage);
}

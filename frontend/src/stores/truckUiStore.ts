import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { MapLayer } from '../utils/truck/model';

/**
 * Truck Planner UI preferences (docs/truck-planner/05_FRONTEND.md 2.4).
 *
 * UI state only: no server data lives here (that is TanStack Query's job). Persisted to
 * localStorage under `smappen-truck-ui`, except `mapsAuthFailed`, which describes this page load.
 * Read with selectors: `useTruckUiStore((s) => s.mapLayer)`.
 *
 * The hour of the week is deliberately NOT here: it changes many times a second while the week
 * plays and lives in truckHourStore. Only the last hour the owner settled on is kept (`lastHow`).
 */

/** The three map layers: `opportunity`, `people`, `competition`. */
export type TruckMapLayer = MapLayer;

export interface TruckMapCamera {
  lat: number;
  lng: number;
  zoom: number;
}

export interface TruckUiState {
  /** Which map layer is shown. */
  mapLayer: TruckMapLayer;
  /** Where the map was last left, or null before the first visit. */
  mapCamera: TruckMapCamera | null;
  /** The hour of week (0..167) last settled on, or null. */
  lastHow: number | null;
  /** Milliseconds per hour while the week plays: slow, normal, fast. */
  playSpeedMs: 1200 | 600 | 300;
  showSpotPins: boolean;
  showScoutDots: boolean;
  /** Window length, in hours, of "Best windows" on the spot card. */
  windowHours: 2 | 3 | 4;
  /** Spots ticked for comparison: at most 4 ids. */
  compareIds: string[];
  /** Google refused the Maps key on this page load. Not persisted. */
  mapsAuthFailed: boolean;
  patch(p: Partial<Omit<TruckUiState, 'patch'>>): void;
}

type TruckUiValues = Omit<TruckUiState, 'patch'>;

const MAX_COMPARE = 4;

const DEFAULTS: TruckUiValues = {
  mapLayer: 'opportunity',
  mapCamera: null,
  lastHow: null,
  playSpeedMs: 600,
  showSpotPins: true,
  showScoutDots: false,
  windowHours: 3,
  compareIds: [],
  mapsAuthFailed: false,
};

function isFiniteNumber(x: unknown): x is number {
  return typeof x === 'number' && Number.isFinite(x);
}

/**
 * Keep only well-formed values of what localStorage holds. A key written by an older build, or
 * edited by hand, must never put the map in a state the screens do not expect.
 */
function sanitise(stored: unknown): Partial<TruckUiValues> {
  if (typeof stored !== 'object' || stored === null) return {};
  const s = stored as Record<string, unknown>;
  const out: Partial<TruckUiValues> = {};
  if (s.mapLayer === 'opportunity' || s.mapLayer === 'people' || s.mapLayer === 'competition') {
    out.mapLayer = s.mapLayer;
  }
  if (typeof s.mapCamera === 'object' && s.mapCamera !== null) {
    const c = s.mapCamera as Record<string, unknown>;
    if (
      isFiniteNumber(c.lat) && c.lat >= -90 && c.lat <= 90 &&
      isFiniteNumber(c.lng) && c.lng >= -180 && c.lng <= 180 &&
      isFiniteNumber(c.zoom)
    ) {
      out.mapCamera = { lat: c.lat, lng: c.lng, zoom: c.zoom };
    }
  }
  if (isFiniteNumber(s.lastHow) && Math.floor(s.lastHow) === s.lastHow && s.lastHow >= 0 && s.lastHow <= 167) {
    out.lastHow = s.lastHow;
  }
  if (s.playSpeedMs === 1200 || s.playSpeedMs === 600 || s.playSpeedMs === 300) out.playSpeedMs = s.playSpeedMs;
  if (typeof s.showSpotPins === 'boolean') out.showSpotPins = s.showSpotPins;
  if (typeof s.showScoutDots === 'boolean') out.showScoutDots = s.showScoutDots;
  if (s.windowHours === 2 || s.windowHours === 3 || s.windowHours === 4) out.windowHours = s.windowHours;
  if (Array.isArray(s.compareIds)) {
    out.compareIds = s.compareIds.filter((id): id is string => typeof id === 'string').slice(0, MAX_COMPARE);
  }
  return out;
}

export const useTruckUiStore = create<TruckUiState>()(
  persist(
    (set) => ({
      ...DEFAULTS,
      patch: (p) =>
        set(p.compareIds !== undefined ? { ...p, compareIds: p.compareIds.slice(0, MAX_COMPARE) } : p),
    }),
    {
      name: 'smappen-truck-ui',
      version: 1,
      // An explicit allow-list: a new field does not persist until it is added here.
      partialize: (s) => ({
        mapLayer: s.mapLayer,
        mapCamera: s.mapCamera,
        lastHow: s.lastHow,
        playSpeedMs: s.playSpeedMs,
        showSpotPins: s.showSpotPins,
        showScoutDots: s.showScoutDots,
        windowHours: s.windowHours,
        compareIds: s.compareIds,
      }),
      merge: (persisted, current) => ({ ...current, ...sanitise(persisted) }),
    },
  ),
);

/** Back to the defaults, in memory and in localStorage. Used when the owner deletes all truck data. */
export function resetTruckUiStore(): void {
  useTruckUiStore.setState({ ...DEFAULTS });
}

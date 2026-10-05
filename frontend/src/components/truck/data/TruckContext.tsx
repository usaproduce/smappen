import { createContext, useContext } from 'react';
import type { Assumptions, CalibrationState } from '../../../utils/truck/model';
import type {
  FuelInfo,
  RegionInfo,
  RoutingState,
  TruckCounts,
  TruckLimits,
  TruckProfileX,
  TruckRecord,
} from '../../../api/truck';

/**
 * What every Truck Planner page builds on (docs/truck-planner/05_FRONTEND.md 1.4). Everything is
 * taken from the bootstrap answer by `TruckGate`, which renders pages only when a truck exists and
 * the server runs this bundle's model version.
 */
export interface TruckContextValue {
  truck: TruckRecord;
  /** `truck.profile`. */
  profile: TruckProfileX;
  /** The server's assumptions plus this bundle's seeds: the first argument of most model functions. */
  A: Assumptions;
  /** What the owner's logged services say. Changes after every logged service. */
  cal: CalibrationState;
  /**
   * The truck's region, or null when it is `none`. A region with `usable: false` has no map data;
   * read it through `regionHasData` and `regionRebuilding`.
   */
  region: RegionInfo | null;
  fuel: FuelInfo;
  /** The truck's IANA time zone. "Today" and "now" are always read in it (`useNow`). */
  timezone: string;
  counts: TruckCounts;
  routing: { state: RoutingState };
  limits: TruckLimits;
}

export const TruckContext = createContext<TruckContextValue | null>(null);

/** The truck context. Only valid under `TruckGate`, which is every /truck page. */
export function useTruck(): TruckContextValue {
  const value = useContext(TruckContext);
  if (value === null) {
    throw new Error('useTruck must be used on a Truck Planner page (inside TruckGate)');
  }
  return value;
}

/** True when the region has usable map data: a pack to draw and points to estimate from. */
export function regionHasData(region: RegionInfo | null): region is RegionInfo {
  return region !== null && region.usable;
}

/**
 * True while the region data was built with other model constants and is being rebuilt. During
 * that time `simulate`, `fetchPack` and `refreshStaleSpots` are not sent; saved spots keep
 * evaluating from their stored vectors.
 */
export function regionRebuilding(region: RegionInfo | null): boolean {
  return region !== null && region.unusable_reason === 'build_mismatch';
}

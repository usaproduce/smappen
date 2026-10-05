import type { RegionInfo } from '../../../api/truck';
import type { CellPack, PackState } from './types';

export interface CellPackResult {
  pack: CellPack | null;
  state: PackState;
}

const IDLE: CellPackResult = { pack: null, state: 'idle' };

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 5.2 and 9.2). The map engine package replaces the body:
 * the `pack` query (`truckApi.fetchPack(region.pack.url)` under `truckKeys.pack(url)`, stale never,
 * kept ten minutes, two retries) plus `decodePack`, sent only for a usable region.
 *
 * Until then it reports `idle` and requests nothing.
 */
export function useCellPack(_region: RegionInfo | null): CellPackResult {
  return IDLE;
}

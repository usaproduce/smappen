import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiErrorStatus, truckApi, truckKeys } from '../../../api/truck';
import type { RegionInfo } from '../../../api/truck';
import { PackError, decodePack } from '../../../utils/truck/map/pack';
import { GC, STALE } from '../data/queryPolicy';
import type { CellPack, PackState } from './types';

export interface CellPackResult {
  pack: CellPack | null;
  state: PackState;
}

const IDLE: CellPackResult = { pack: null, state: 'idle' };
const LOADING: CellPackResult = { pack: null, state: 'loading' };
const FAILED: CellPackResult = { pack: null, state: 'error' };

/**
 * A request that got no answer, or a 5xx answer, is tried twice more. A 4xx answer or a 501 would
 * only fail again, and a pack that does not decode is the same bytes next time.
 */
function packRetry(failureCount: number, error: unknown): boolean {
  if (error instanceof PackError) return false;
  const status = apiErrorStatus(error);
  if (status !== null && ((status >= 400 && status < 500) || status === 501)) return false;
  return failureCount < 2;
}

async function loadPack(url: string): Promise<CellPack> {
  const buffer = await truckApi.fetchPack(url);
  const t0 = performance.now();
  const pack = decodePack(buffer);
  try {
    performance.measure('tp:pack-decode', { start: t0, end: performance.now() });
  } catch {
    // an older browser without measure options
  }
  return pack;
}

/**
 * The cell pack of a region (docs/truck-planner/05_FRONTEND.md 5.2): the `pack` query (route 8) plus
 * `decodePack`. The pack is immutable by URL, so it is never stale and never refetched; it leaves
 * memory ten minutes after the last map unmounted. The browser caches the download itself.
 *
 * Nothing is requested for a missing or unusable region (`idle`). A failed request and a pack that
 * does not decode both end as `error`: the map then shows without colours.
 */
export function useCellPack(region: RegionInfo | null): CellPackResult {
  const url = region !== null && region.usable && region.pack !== null ? region.pack.url : null;

  const query = useQuery({
    queryKey: truckKeys.pack(url ?? ''),
    queryFn: () => loadPack(url as string),
    enabled: url !== null,
    staleTime: STALE.pack,
    gcTime: GC.pack,
    retry: packRetry,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    refetchOnMount: false,
    // The decoded pack holds 12 MB of numbers and 60,000 ids: never compare it key by key.
    structuralSharing: false,
  });

  const pack = query.data ?? null;
  const failed = query.isError;
  return useMemo<CellPackResult>(() => {
    if (url === null) return IDLE;
    if (pack !== null) return { pack, state: 'ready' };
    return failed ? FAILED : LOADING;
  }, [url, pack, failed]);
}

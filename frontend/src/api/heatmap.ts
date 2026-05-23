import { api } from './client';

export type HeatmapMetric =
  | 'population'
  | 'population_density'
  | 'median_income'
  | 'median_home_value'
  | 'unemployment_rate'
  | 'housing_units';

export type HeatmapLevel = 'tract' | 'county' | 'state';

export interface HeatmapFeature {
  type: 'Feature';
  id: string;
  geometry: any;
  properties: { geoid: string; name: string | null; value: number | null };
}

export interface HeatmapResponse {
  type: 'FeatureCollection';
  features: HeatmapFeature[];
  meta: {
    metric: HeatmapMetric;
    level: HeatmapLevel;
    count: number;
    min: number;
    max: number;
    breaks?: number[];
    unit: string;
    cached?: boolean;
    bbox_q?: number[];
    note?: string;
  };
}

// In-memory tile cache (LRU-ish). Backend uses the same quantization, so neighboring
// pans hit the same key — both client and server cache work in lockstep.
const MEMORY_LIMIT = 200;
type CacheEntry = { ts: number; data: HeatmapResponse };
const memCache = new Map<string, CacheEntry>();

function levelForZoom(zoom: number): HeatmapLevel {
  if (zoom <= 7) return 'state';
  if (zoom <= 9) return 'county';
  return 'tract';
}
function quantizationFor(level: HeatmapLevel): number {
  if (level === 'state') return 5.0;
  if (level === 'county') return 1.0;
  return 0.05;
}
function quantizedKey(bbox: [number, number, number, number], metric: HeatmapMetric, zoom: number, limit: number) {
  const level = levelForZoom(zoom);
  const q = quantizationFor(level);
  const qb = [
    Math.floor(bbox[0] / q) * q,
    Math.floor(bbox[1] / q) * q,
    Math.ceil(bbox[2] / q) * q,
    Math.ceil(bbox[3] / q) * q,
  ];
  return `${level}:${metric}:${limit}:${qb.join(',')}`;
}

export const heatmapApi = {
  /** Returns from in-memory cache if available, otherwise fetches from server. */
  async tracts(
    bbox: [number, number, number, number],
    metric: HeatmapMetric,
    zoom: number,
    limit = 1000
  ) {
    const key = quantizedKey(bbox, metric, zoom, limit);
    const cached = memCache.get(key);
    if (cached) return cached.data;

    const { data } = await api.get('/api/heatmap/tracts', {
      params: { bbox: bbox.join(','), metric, zoom, limit },
    });
    const result = data.data as HeatmapResponse;

    memCache.set(key, { ts: Date.now(), data: result });
    if (memCache.size > MEMORY_LIMIT) {
      const sorted = [...memCache.entries()].sort((a, b) => a[1].ts - b[1].ts);
      for (let i = 0; i < sorted.length - MEMORY_LIMIT; i++) memCache.delete(sorted[i][0]);
    }
    return result;
  },

  /** Warm the cache with surrounding viewport tiles, no blocking. */
  prefetchAdjacent(
    bbox: [number, number, number, number],
    metric: HeatmapMetric,
    zoom: number
  ): void {
    const [w, s, e, n] = bbox;
    const dx = e - w;
    const dy = n - s;
    const neighbors: Array<[number, number, number, number]> = [
      [w - dx, s, w, n],
      [e, s, e + dx, n],
      [w, n, e, n + dy],
      [w, s - dy, e, s],
      [w - dx, n, w, n + dy],
      [e, n, e + dx, n + dy],
      [w - dx, s - dy, w, s],
      [e, s - dy, e + dx, s],
    ];
    for (const b of neighbors) {
      const key = quantizedKey(b, metric, zoom, 1000);
      if (memCache.has(key)) continue;
      this.tracts(b, metric, zoom).catch(() => {});
    }
  },

  clearCache() { memCache.clear(); },
};

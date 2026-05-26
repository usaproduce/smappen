import { api } from './client';

/**
 * Carafe Vendor-Network admin API. Wraps every /api/admin/* endpoint
 * the backend ships (Phases 1–10). Spec v3 §7 + §8.
 *
 * Not surfaced on the global navbar by design — only operators who know
 * the /admin/carafe URL can reach it. Auth gate is double-belted:
 *   - Backend: Middleware::requireRole(['admin','owner'])
 *   - Frontend: AdminOnlyRoute wrapper
 */

export type EnrichPolicy   = 'all' | 'priority_types' | 'on_demand';
export type DensityProfile = 'rural' | 'suburban' | 'dense' | 'mixed';
export type CampaignStatus =
  | 'draft' | 'estimating' | 'approved' | 'running'
  | 'paused' | 'done' | 'failed' | 'cancelled';

export interface EstimateInput {
  bbox: [number, number, number, number];  // [latMin, lngMin, latMax, lngMax]
  vendor_types: string[];
  enrich_policy?: EnrichPolicy;
  density_profile?: DensityProfile;
}

export interface EstimateResult {
  total:  { low: number; expected: number; high: number };
  sweep:  {
    calls: { low: number; expected: number; high: number };
    cost:  { low: number; expected: number; high: number };
    sku_breakdown_expected: Record<string, number>;
  };
  enrich: {
    policy: EnrichPolicy;
    vendors: { low: number; expected: number; high: number };
    calls:   { low: number; expected: number; high: number };
    cost:    { low: number; expected: number; high: number };
    sku_breakdown_expected: Record<string, number>;
  };
  free_tier_remaining: Record<string, number>;
  meta: {
    area_km2: number;
    tile_count: number;
    tile_size_km: number;
    density_profile: DensityProfile;
    enrich_policy: EnrichPolicy;
    pages_per_tile_exp: number;
  };
}

export interface SeedCampaign {
  id: string;
  name: string;
  status: CampaignStatus;
  bbox_lat_min: number;
  bbox_lng_min: number;
  bbox_lat_max: number;
  bbox_lng_max: number;
  vendor_types_json: string;
  enrich_policy: EnrichPolicy;
  density_profile: DensityProfile;
  budget_cap_usd: number | null;
  estimate_low_usd: number | null;
  estimate_expected_usd: number | null;
  estimate_high_usd: number | null;
  spent_usd: number;
  tile_count: number;
  tiles_done_count: number;
  vendor_count: number;
  created_at: string;
  updated_at: string;
  started_at: string | null;
  finished_at: string | null;
  pause_reason: string | null;
  tile_stats?: {
    total: number; done: number; failed: number; running: number; queued: number;
  };
}

export interface CreateCampaignInput {
  name: string;
  bbox: [number, number, number, number];
  vendor_types: string[];
  enrich_policy?: EnrichPolicy;
  density_profile?: DensityProfile;
  budget_cap_usd?: number | null;
  region_geojson?: object;
}

export interface DedupeQueueItem {
  id: string;
  left_vendor_id: string;
  right_vendor_id: string;
  left_name: string;
  right_name: string;
  left_category: string | null;
  right_category: string | null;
  score: number;
  distance_m: number | null;
  shared_name_tokens: number | null;
  block_key_hit: string | null;
  created_at: string;
}

export interface ClassifyQueueItem {
  id: string;
  name: string;
  type: string | null;
  primary_category: string | null;
  classification_confidence: number | null;
  classification_signals_json: string | null;
  classified_at: string | null;
}

export interface ReviewQueueResponse {
  dedupe?:   DedupeQueueItem[];
  classify?: ClassifyQueueItem[];
  counts:    { dedupe: number; classify: number; total: number };
}

export interface DeltaSummary {
  campaign_id: string;
  total_tiles: number;
  never_swept: number;
  done_tiles: number;
  done_within: number;
  resweep_eligible: number;
  stuck_running: number;
}

export const carafeApi = {
  // ─── Estimate (no side-effects, no API calls) ────────────────────
  async estimate(input: EstimateInput): Promise<{ estimate: EstimateResult; monthly_volume: { search: number; details: number } }> {
    const { data } = await api.post('/api/admin/seed-campaigns/estimate', input);
    return data.data;
  },

  // ─── Campaigns ───────────────────────────────────────────────────
  async listCampaigns(limit = 50, offset = 0): Promise<{ campaigns: SeedCampaign[] }> {
    const { data } = await api.get('/api/admin/seed-campaigns', { params: { limit, offset } });
    return data.data;
  },
  async getCampaign(id: string): Promise<{ campaign: SeedCampaign }> {
    const { data } = await api.get(`/api/admin/seed-campaigns/${id}`);
    return data.data;
  },
  async createCampaign(input: CreateCampaignInput): Promise<{ campaign: SeedCampaign }> {
    const { data } = await api.post('/api/admin/seed-campaigns', input);
    return data.data;
  },
  async runCampaign(id: string): Promise<{ campaign: SeedCampaign }> {
    const { data } = await api.post(`/api/admin/seed-campaigns/${id}/run`);
    return data.data;
  },
  async pauseCampaign(id: string, reason?: string): Promise<{ campaign: SeedCampaign }> {
    const { data } = await api.post(`/api/admin/seed-campaigns/${id}/pause`, { reason });
    return data.data;
  },
  async resumeCampaign(id: string): Promise<{ campaign: SeedCampaign }> {
    const { data } = await api.post(`/api/admin/seed-campaigns/${id}/resume`);
    return data.data;
  },
  async cancelCampaign(id: string): Promise<{ campaign: SeedCampaign }> {
    const { data } = await api.post(`/api/admin/seed-campaigns/${id}/cancel`);
    return data.data;
  },
  async enrichCampaign(id: string, batchSize?: number): Promise<{ result: any }> {
    const { data } = await api.post(`/api/admin/seed-campaigns/${id}/enrich`, { batch_size: batchSize });
    return data.data;
  },

  // ─── Per-vendor on-demand enrich ────────────────────────────────
  async enrichVendor(id: string, tier: 'full' | 'cold' | 'warm' | 'hot' = 'full'): Promise<{ result: any }> {
    const { data } = await api.post(`/api/admin/vendors/${id}/enrich`, { tier });
    return data.data;
  },

  // ─── Delta / re-sweep ───────────────────────────────────────────
  async deltaSummary(id: string, maxAgeDays = 30): Promise<{ delta: DeltaSummary }> {
    const { data } = await api.get(`/api/admin/seed-campaigns/${id}/delta`, { params: { max_age_days: maxAgeDays } });
    return data.data;
  },
  async resweepCampaign(id: string, maxAgeDays = 30): Promise<{ requeued: number }> {
    const { data } = await api.post(`/api/admin/seed-campaigns/${id}/resweep`, { max_age_days: maxAgeDays });
    return data.data;
  },

  // ─── Review queue ───────────────────────────────────────────────
  async reviewQueue(kind?: 'dedupe' | 'classify', limit = 50, offset = 0): Promise<ReviewQueueResponse> {
    const { data } = await api.get('/api/admin/review-queue', { params: { kind, limit, offset } });
    return data.data;
  },
  async dedupeMerge(id: string)  { const { data } = await api.post(`/api/admin/review-queue/dedupe/${id}/merge`);  return data.data; },
  async dedupeReject(id: string) { const { data } = await api.post(`/api/admin/review-queue/dedupe/${id}/reject`); return data.data; },
  async dedupeDefer(id: string)  { const { data } = await api.post(`/api/admin/review-queue/dedupe/${id}/defer`);  return data.data; },
  async classifyApprove(id: string)                       { const { data } = await api.post(`/api/admin/review-queue/classify/${id}/approve`); return data.data; },
  async classifyUpdate(id: string, type: string, category?: string) {
    const { data } = await api.post(`/api/admin/review-queue/classify/${id}/update`, { type, category });
    return data.data;
  },
};

/** Spec §2 vendor-type catalog as static metadata for the builder UI. */
export const VENDOR_TYPES: { key: string; label: string; description: string }[] = [
  { key: 'broadline',         label: 'Broadline distributors', description: 'Sysco, US Foods, PFG, Gordon' },
  { key: 'cash_carry',        label: 'Cash & carry',           description: 'Restaurant Depot, Costco Business, Chef\'s Warehouse' },
  { key: 'produce',           label: 'Produce',                description: 'Produce houses, terminal markets, USA Produce (affiliated)' },
  { key: 'meat',              label: 'Meat',                   description: 'Meat purveyors, butchers-to-trade' },
  { key: 'seafood',           label: 'Seafood',                description: 'Seafood houses, USA Produce & Seafood (affiliated)' },
  { key: 'dairy_bakery_bev',  label: 'Dairy / bakery / beverage', description: 'Route-delivery dairy, bakery, beverage' },
  { key: 'specialty_ethnic',  label: 'Specialty / ethnic',     description: 'Importers, ethnic/dry-goods wholesalers' },
  { key: 'local_grocery',     label: 'Local grocery',          description: 'Grocery / ethnic markets used as suppliers' },
];

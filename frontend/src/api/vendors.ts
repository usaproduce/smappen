import { api } from './client';

export interface Vendor {
  id: string;
  name: string;
  legal_name: string | null;
  hq_address: string | null;
  hq_lat: number | null;
  hq_lng: number | null;
  phone: string | null;
  website: string | null;
  primary_category: string | null;
  source: string;
  is_affiliated: number;
  claim_status: 'unclaimed' | 'pending' | 'claimed' | 'disputed';
}

export interface VendorListing {
  category: string;
  region: string | null;
  service_radius_mi: number | null;
  min_order_cents: number | null;
  notes: string | null;
  source: string;
}

export interface ComparisonRow {
  vendor_id: string;
  vendor_name: string;
  is_affiliated: boolean;
  disclosure: string | null;
  claim_status: string;
  covers_category: boolean;
  covers_region: boolean;
  min_order_cents: number | null;
  score: number;
}

export interface ComparisonResult {
  category: string;
  region: string | null;
  ranked: ComparisonRow[];
  basket_cost: {
    total_cents: number;
    lines: Array<{ ingredient_key: string; qty: number; unit: string; unit_cents: number; line_cents: number; source: string }>;
    missing: string[];
  } | null;
  methodology: { algorithm: string; no_pricing: boolean; note: string };
}

export interface BasketItem {
  ingredient_key: string;
  qty: number;
  unit?: string;
  category?: string;
}

export const vendorsApi = {
  async list(filters?: { category?: string; region?: string; q?: string }): Promise<Vendor[]> {
    const { data } = await api.get('/api/vendors', { params: filters });
    return data.data.vendors ?? [];
  },
  async show(id: string): Promise<Vendor & { listings: VendorListing[] }> {
    const { data } = await api.get(`/api/vendors/${id}`);
    return data.data.vendor;
  },
  async compare(input: { category: string; region?: string; basket?: BasketItem[] }): Promise<ComparisonResult> {
    const { data } = await api.post('/api/vendors/compare', input);
    return data.data;
  },
  async consolidate(input: { basket: BasketItem[]; region?: string }) {
    const { data } = await api.post('/api/vendors/consolidate', input);
    return data.data;
  },
  async logComparison(input: { category: string; region?: string; basket?: BasketItem[]; vendor_ids?: string[]; restaurant_id?: string }) {
    const { data } = await api.post('/api/vendors/compare/log', input);
    return data.data;
  },
  async claim(vendorId: string, contact: { contact_email: string; contact_phone?: string; message?: string }) {
    const { data } = await api.post(`/api/vendors/${vendorId}/claims`, contact);
    return data.data;
  },
};

export interface Lead {
  id: string;
  vendor_id: string;
  status: 'queued' | 'emitted' | 'acknowledged' | 'closed_won' | 'closed_lost';
  contact_email: string;
  webhook_attempts: number;
  webhook_last_at: string | null;
  webhook_last_code: number | null;
  created_at: string;
}

export const leadsApi = {
  async list(status?: Lead['status']): Promise<Lead[]> {
    const q = status ? { status } : undefined;
    const { data } = await api.get('/api/leads', { params: q });
    return data.data.leads ?? [];
  },
  async create(input: {
    vendor_id: string;
    contact_email: string;
    contact_name?: string;
    contact_phone?: string;
    message?: string;
    comparison_id?: string;
    restaurant_id?: string;
    basket?: BasketItem[];
  }): Promise<{ lead_id: string; status: string }> {
    const { data } = await api.post('/api/leads', input);
    return data.data;
  },
};

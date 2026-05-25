import { api } from './client';

export interface VendorPin {
  id: string;
  vendor_id: string;
  lat: number;
  lng: number;
  label: string | null;
  vendor_name: string;
  type: string | null;
  primary_category: string | null;
  is_affiliated: number;
  aggregate_rating: number | null;
  rating_count: number;
}

export interface VendorServesRow {
  vendor_id: string;
  vendor_name: string;
  type: string | null;
  primary_category: string | null;
  is_affiliated: number;
  aggregate_rating: number | null;
  rating_count: number;
  coverage_type: 'delivery' | 'pickup_drivetime' | 'declared_territory' | 'radius';
  confidence: number;
  primary_location: { id: string; lat: number; lng: number; address: string | null; label: string | null } | null;
  distance_miles: number | null;
}

export interface VendorDetail {
  vendor: {
    id: string;
    name: string;
    type: string | null;
    primary_category: string | null;
    brand: string | null;
    hq_address: string | null;
    hq_lat: number | null;
    hq_lng: number | null;
    phone: string | null;
    website: string | null;
    is_affiliated: number;
    claim_status: string;
    completeness_score: number;
    aggregate_rating: number | null;
    rating_count: number;
    last_verified_at: string | null;
  };
  locations: Array<{ id: string; label: string | null; address: string | null; lat: number; lng: number; phone: string | null; is_primary: number; source: string }>;
  coverage: Array<{ id: string; location_id: string; coverage_type: string; geometry: any; radius_miles: number | null; confidence: number; source: string }>;
  categories: Array<{ category: string; source: string }>;
  listings: Array<{ category: string; region: string | null; service_radius_mi: number | null; min_order_cents: number | null; notes: string | null; source: string }>;
}

export interface VendorReview {
  id: string;
  organization_id: string;
  overall: number;
  score_price: number | null;
  score_reliability: number | null;
  score_quality: number | null;
  score_accuracy: number | null;
  score_service: number | null;
  body: string | null;
  photo_url: string | null;
  volume_band: string | null;
  delivery_or_pickup: string | null;
  verification_strength: 'restaurant_exists' | 'pos_connected' | 'manual_review';
  created_at: string;
  updated_at: string;
}

export interface VendorReviewAggregate {
  count: number;
  overall: number | null;
  price: number | null;
  reliability: number | null;
  quality: number | null;
  accuracy: number | null;
  service: number | null;
}

export const vendorMapApi = {
  async bbox(args: { minLat: number; minLng: number; maxLat: number; maxLng: number; type?: string; category?: string }): Promise<VendorPin[]> {
    const { data } = await api.get('/api/vendors/map/bbox', { params: args });
    return data.data.pins ?? [];
  },
  async serves(args: { lat: number; lng: number }): Promise<{ pin: { lat: number; lng: number }; vendors: VendorServesRow[] }> {
    const { data } = await api.get('/api/vendors/map/serves', { params: args });
    return data.data;
  },
  async detail(id: string): Promise<VendorDetail> {
    const { data } = await api.get(`/api/vendors/${id}/detail`);
    return data.data;
  },
};

export const vendorReviewsApi = {
  async list(vendorId: string): Promise<{ reviews: VendorReview[]; my_review: VendorReview | null }> {
    const { data } = await api.get(`/api/vendors/${vendorId}/reviews`);
    return data.data;
  },
  async submit(vendorId: string, input: Partial<VendorReview> & { overall: number; restaurant_id?: string }): Promise<{ review_id: string; verification_strength: string }> {
    const { data } = await api.post(`/api/vendors/${vendorId}/reviews`, input);
    return data.data;
  },
  async aggregate(vendorId: string): Promise<VendorReviewAggregate> {
    const { data } = await api.get(`/api/vendors/${vendorId}/reviews/aggregate`);
    return data.data;
  },
};

export interface SavedVendor {
  vendor_id: string;
  name: string;
  type: string | null;
  primary_category: string | null;
  is_affiliated: number;
  aggregate_rating: number | null;
  rating_count: number;
  note: string | null;
  created_at: string;
}

export const savedVendorsApi = {
  async list(): Promise<SavedVendor[]> {
    const { data } = await api.get('/api/saved-vendors');
    return data.data.saved ?? [];
  },
  async save(vendorId: string, note?: string): Promise<void> {
    await api.post(`/api/vendors/${vendorId}/save`, { note });
  },
  async unsave(vendorId: string): Promise<void> {
    await api.delete(`/api/vendors/${vendorId}/save`);
  },
};

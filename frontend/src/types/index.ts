export type Plan = 'free' | 'starter' | 'pro' | 'business' | 'enterprise';
export type Role = 'owner' | 'admin' | 'member';
export type AreaType = 'isochrone' | 'isodistance' | 'manual' | 'radius';
export type TravelMode = 'driving-car' | 'cycling-regular' | 'foot-walking' | 'wheelchair';

export interface User {
  id: string;
  email: string;
  name: string;
  organization_id: string;
  organization_name?: string;
  role: Role;
  plan: Plan;
  last_login_at?: string;
}

export interface Organization {
  id: string;
  name: string;
  plan: Plan;
  max_seats: number;
  stripe_customer_id?: string | null;
  stripe_subscription_id?: string | null;
}

export interface Project {
  id: string;
  organization_id: string;
  name: string;
  description?: string | null;
  center_lat?: number | null;
  center_lng?: number | null;
  zoom_level: number;
  is_shared: 0 | 1 | boolean;
  share_token?: string | null;
  area_count?: number;
  folders?: Folder[];
  created_at?: string;
  updated_at?: string;
}

export interface Folder {
  id: string;
  project_id: string;
  name: string;
  color: string;
  sort_order: number;
  parent_folder_id?: string | null;
  area_count?: number;
  children?: Folder[];
}

export interface GeoJSONPolygon {
  type: 'Polygon';
  coordinates: number[][][];
}

export interface Area {
  id: string;
  project_id: string;
  folder_id?: string | null;
  name: string;
  area_type: AreaType;
  center_lat?: number | null;
  center_lng?: number | null;
  center_address?: string | null;
  travel_mode?: TravelMode | string | null;
  travel_time_minutes?: number | null;
  travel_distance_km?: number | null;
  geometry?: GeoJSONPolygon;
  fill_color: string;
  fill_opacity: number;
  stroke_color: string;
  stroke_weight: number;
  notes?: string | null;
  demographics_cache?: Demographics | null;
}

export interface Demographics {
  population: {
    total: number;
    male: number;
    female: number;
    density_per_sq_km: number;
  };
  age: Record<string, number>;
  income: {
    median_household: number | null;
    brackets: Record<string, number>;
  };
  employment: {
    labor_force: number;
    unemployed: number;
    unemployment_rate: number;
  };
  housing: {
    total_units: number;
    median_value: number | null;
  };
  meta?: { area_sq_km?: number; data_year?: number; tracts_intersected?: number; note?: string };
}

export interface Place {
  id: string;
  displayName?: { text: string };
  formattedAddress?: string;
  location?: { latitude: number; longitude: number };
  types?: string[];
  rating?: number;
  userRatingCount?: number;
  nationalPhoneNumber?: string;
  websiteUri?: string;
  businessStatus?: string;
}

export interface ImportedPoint {
  id: string;
  project_id: string;
  import_batch_id: string;
  label?: string | null;
  address?: string | null;
  lat: number;
  lng: number;
  custom_data?: Record<string, any> | null;
}

export interface ImportPreview {
  import_token: string;
  headers: string[];
  preview: any[][];
  total_rows: number;
}

export interface ImportResult {
  batch_id: string;
  total_rows: number;
  geocoded_count: number;
  imported: number;
  failed_count: number;
  failures: { row: number; address?: string | null; error: string }[];
}

export interface IsochroneResult {
  geojson: GeoJSONPolygon;
  area_sq_km: number;
  bbox: [number, number, number, number];
  travel_mode: string;
  time_minutes?: number;
  radius_km?: number;
  center: { lat: number; lng: number };
}

export interface PlanLimitsResponse {
  plan: Plan;
  limits: Record<string, number | boolean>;
  usage: {
    isochrones_remaining_today: number;
    poi_searches_remaining_today: number;
  };
  subscription: any;
}

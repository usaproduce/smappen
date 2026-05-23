import { api } from './client';

// ── Cannibalization ─────────────────────────────────────────────────────────
export interface CannibalizationOverlap {
  area_a_id: string;
  area_b_id: string;
  area_a_name: string;
  area_b_name: string;
  shared_population: number;
  shared_housing_units: number;
  shared_area_sq_km: number;
  pct_of_a: number;
  pct_of_b: number;
}
export interface CannibalizationArea {
  id: string;
  name: string;
  color: string;
  population: number;
  housing_units: number;
  unique_pct: number;
  cannibalized_pct: number;
}
export interface CannibalizationResponse {
  project_id: string;
  areas: CannibalizationArea[];
  overlaps: CannibalizationOverlap[];
  summary?: { pair_count: number; total_shared_population: number };
  note?: string;
}
export const cannibalizationApi = {
  async forProject(projectId: string): Promise<CannibalizationResponse> {
    const { data } = await api.get(`/api/projects/${projectId}/cannibalization`);
    return data.data;
  },
};

// ── Traffic isochrones ──────────────────────────────────────────────────────
export type WeekDay = 'sunday' | 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday';

export const trafficApi = {
  async single(opts: { lat: number; lng: number; time_minutes: number; day_of_week: WeekDay; hour_24: number; travel_mode?: string }) {
    const { data } = await api.post('/api/isochrone/traffic', opts);
    return data.data;
  },
  async grid(opts: { lat: number; lng: number; time_minutes: number; travel_mode?: string }) {
    const { data } = await api.post('/api/isochrone/traffic/grid', opts);
    return data.data;
  },
};

// ── Territory generation ────────────────────────────────────────────────────
export interface TerritorySummary {
  index: number;
  population: number;
  median_household_income: number | null;
  tract_count: number;
  pop_share_pct: number;
}
export interface TerritoryJob {
  job_id: string;
  status: string;
  territory_count: number;
  tract_count: number;
  area_ids: string[];
  territories: TerritorySummary[];
}
export const territoryApi = {
  async generate(projectId: string, opts: {
    target_count: number;
    balance_metric?: 'population' | 'income_weighted_pop' | 'housing_units';
    bbox: [number, number, number, number];
    name?: string;
    constraints?: { max_imbalance_pct?: number };
  }): Promise<TerritoryJob> {
    const { data } = await api.post(`/api/projects/${projectId}/territories/generate`, opts);
    return data.data;
  },
  async listJobs(projectId: string) {
    const { data } = await api.get(`/api/projects/${projectId}/territories/jobs`);
    return data.data.jobs as any[];
  },
};

// ── Multi-location optimization ─────────────────────────────────────────────
export interface MclpPick {
  rank: number;
  lat: number;
  lng: number;
  label: string;
  unique_demand: number;
  tracts_added: number;
  cumulative_demand: number;
}
export interface MclpResult {
  project_id: string;
  metric: string;
  radius_km: number;
  pick_count: number;
  candidate_count: number;
  picks: MclpPick[];
  total_covered: number;
  total_universe: number;
  coverage_pct: number | null;
}
export const mclpApi = {
  async optimize(projectId: string, opts: {
    candidates?: { lat: number; lng: number; label?: string }[];
    bbox?: [number, number, number, number];
    grid_step_km?: number;
    pick_count: number;
    radius_km: number;
    demand_metric?: 'population' | 'housing_units' | 'income_weighted_pop';
  }): Promise<MclpResult> {
    const { data } = await api.post(`/api/projects/${projectId}/optimize/locations`, opts);
    return data.data;
  },
};

// ── Segmentation ────────────────────────────────────────────────────────────
export interface Segment {
  id: string;
  name: string;
  color: string;
}
export interface SegmentBreakdownRow {
  segment_id: string;
  segment_name: string;
  color: string;
  population: number;
  tract_count: number;
}
export const segmentationApi = {
  async catalog(): Promise<{ segments: Segment[] }> {
    const { data } = await api.get('/api/segmentation/segments');
    return data.data;
  },
  async forArea(areaId: string): Promise<{ area_id: string; segments: SegmentBreakdownRow[]; total_population: number }> {
    const { data } = await api.get(`/api/areas/${areaId}/segments`);
    return data.data;
  },
  async forProject(projectId: string): Promise<{ project_id: string; totals: SegmentBreakdownRow[]; per_area: any[] }> {
    const { data } = await api.post(`/api/projects/${projectId}/segments`);
    return data.data;
  },
};

// ── Collaboration ───────────────────────────────────────────────────────────
export interface Version {
  id: string;
  version_number: number;
  note: string | null;
  created_at: string;
  created_by_name: string | null;
  created_by_email: string | null;
}
export interface Comment {
  id: string;
  project_id: string;
  area_id: string | null;
  parent_comment_id: string | null;
  user_id: string | null;
  body: string;
  anchor_lat: number | null;
  anchor_lng: number | null;
  resolved_at: string | null;
  resolved_by: string | null;
  created_at: string;
  updated_at: string;
  author_name: string | null;
  author_email: string | null;
}
export interface ChangeRow {
  id: number;
  user_id: string | null;
  user_name: string | null;
  entity_type: string;
  entity_id: string | null;
  action: string;
  diff_json: any | null;
  created_at: string;
}
export interface Collaborator {
  user_id: string;
  role: 'viewer' | 'editor' | 'approver' | 'owner';
  name: string;
  email: string;
  invited_at: string;
  accepted_at: string | null;
}
export interface ApprovalRequest {
  id: string;
  project_id: string;
  requested_by: string | null;
  requester_name: string | null;
  title: string;
  description: string | null;
  payload_json: any | null;
  status: 'pending' | 'approved' | 'rejected' | 'withdrawn';
  decided_by: string | null;
  decider_name: string | null;
  decided_at: string | null;
  decision_note: string | null;
  created_at: string;
}

export const collabApi = {
  // Versions
  async snapshot(projectId: string, note?: string) {
    const { data } = await api.post(`/api/projects/${projectId}/versions`, note ? { note } : {});
    return data.data;
  },
  async listVersions(projectId: string): Promise<{ versions: Version[] }> {
    const { data } = await api.get(`/api/projects/${projectId}/versions`);
    return data.data;
  },
  async showVersion(id: string) {
    const { data } = await api.get(`/api/versions/${id}`);
    return data.data;
  },
  // Comments
  async listComments(projectId: string, areaId?: string): Promise<{ comments: Comment[] }> {
    const { data } = await api.get(`/api/projects/${projectId}/comments`, { params: areaId ? { area_id: areaId } : {} });
    return data.data;
  },
  async createComment(projectId: string, body: { body: string; area_id?: string; parent_comment_id?: string; anchor_lat?: number; anchor_lng?: number }) {
    const { data } = await api.post(`/api/projects/${projectId}/comments`, body);
    return data.data;
  },
  async resolveComment(id: string) {
    const { data } = await api.post(`/api/comments/${id}/resolve`);
    return data.data;
  },
  async deleteComment(id: string) {
    const { data } = await api.delete(`/api/comments/${id}`);
    return data.data;
  },
  // Change log
  async listChanges(projectId: string): Promise<{ changes: ChangeRow[] }> {
    const { data } = await api.get(`/api/projects/${projectId}/changes`);
    return data.data;
  },
  // Collaborators
  async listCollaborators(projectId: string): Promise<{ collaborators: Collaborator[] }> {
    const { data } = await api.get(`/api/projects/${projectId}/collaborators`);
    return data.data;
  },
  async addCollaborator(projectId: string, email: string, role: Collaborator['role']) {
    const { data } = await api.post(`/api/projects/${projectId}/collaborators`, { email, role });
    return data.data;
  },
  async removeCollaborator(projectId: string, userId: string) {
    const { data } = await api.delete(`/api/projects/${projectId}/collaborators/${userId}`);
    return data.data;
  },
  // Approvals
  async createApproval(projectId: string, payload: { title: string; description?: string; payload?: any }) {
    const { data } = await api.post(`/api/projects/${projectId}/approvals`, payload);
    return data.data;
  },
  async listApprovals(projectId: string): Promise<{ approvals: ApprovalRequest[] }> {
    const { data } = await api.get(`/api/projects/${projectId}/approvals`);
    return data.data;
  },
  async decideApproval(id: string, decision: 'approved' | 'rejected', note?: string) {
    const { data } = await api.post(`/api/approvals/${id}/decide`, { decision, note });
    return data.data;
  },
};

// ── Notifications ───────────────────────────────────────────────────────────
export interface Notification {
  id: string;
  user_id: string;
  project_id: string | null;
  notif_type: string;
  title: string;
  body: string | null;
  link_url: string | null;
  payload_json: any | null;
  is_read: 0 | 1;
  created_at: string;
}
export const notificationApi = {
  async list(unreadOnly = false): Promise<{ notifications: Notification[]; unread_count: number }> {
    const { data } = await api.get('/api/notifications', { params: unreadOnly ? { unread: 1 } : {} });
    return data.data;
  },
  async markRead(id: string) {
    const { data } = await api.post(`/api/notifications/${id}/read`);
    return data.data;
  },
  async markAllRead() {
    const { data } = await api.post('/api/notifications/read-all');
    return data.data;
  },
};

// ── Competitor monitoring ───────────────────────────────────────────────────
export interface CompetitorMonitor {
  id: string;
  project_id: string;
  area_id: string | null;
  name: string;
  place_types: string[];
  keywords: string | null;
  frequency: 'daily' | 'weekly' | 'monthly';
  is_active: 0 | 1;
  last_run_at: string | null;
  next_run_at: string | null;
  created_at: string;
  active_places?: number;
  unread_alerts?: number;
}
export interface TrackedPlace {
  id: string;
  place_id: string;
  name: string | null;
  lat: number;
  lng: number;
  rating: number | null;
  user_ratings_total: number | null;
  types: string[];
  first_seen_at: string;
  last_seen_at: string;
  is_gone: 0 | 1;
}
export interface CompetitorAlert {
  id: string;
  monitor_id: string;
  scan_id: string | null;
  place_id: string | null;
  alert_type: 'new' | 'gone' | 'moved' | 'rating_drop' | 'rating_jump';
  severity: 'info' | 'warn' | 'high';
  title: string;
  detail: any | null;
  is_read: 0 | 1;
  created_at: string;
}
export const competitorApi = {
  async list(projectId: string): Promise<{ monitors: CompetitorMonitor[] }> {
    const { data } = await api.get(`/api/projects/${projectId}/competitor-monitors`);
    return data.data;
  },
  async create(projectId: string, body: { name: string; place_types: string[]; keywords?: string; frequency?: string; area_id?: string }) {
    const { data } = await api.post(`/api/projects/${projectId}/competitor-monitors`, body);
    return data.data;
  },
  async show(id: string) {
    const { data } = await api.get(`/api/competitor-monitors/${id}`);
    return data.data;
  },
  async update(id: string, body: Partial<CompetitorMonitor>) {
    const { data } = await api.put(`/api/competitor-monitors/${id}`, body);
    return data.data;
  },
  async remove(id: string) {
    const { data } = await api.delete(`/api/competitor-monitors/${id}`);
    return data.data;
  },
  async scanNow(id: string) {
    const { data } = await api.post(`/api/competitor-monitors/${id}/scan`);
    return data.data;
  },
  async places(id: string): Promise<{ places: TrackedPlace[] }> {
    const { data } = await api.get(`/api/competitor-monitors/${id}/places`);
    return data.data;
  },
  async alerts(id: string): Promise<{ alerts: CompetitorAlert[] }> {
    const { data } = await api.get(`/api/competitor-monitors/${id}/alerts`);
    return data.data;
  },
  async markAlertRead(id: string) {
    const { data } = await api.post(`/api/competitor-alerts/${id}/read`);
    return data.data;
  },
};

// ── Field notes ─────────────────────────────────────────────────────────────
export interface FieldNote {
  id: string;
  area_id: string | null;
  body: string;
  lat: number;
  lng: number;
  accuracy_m: number | null;
  photo_url: string | null;
  tags: string[] | null;
  captured_at: string;
  created_at: string;
  author_name: string | null;
}
export const fieldNoteApi = {
  async list(projectId: string, bbox?: [number, number, number, number]): Promise<{ field_notes: FieldNote[] }> {
    const params: any = {};
    if (bbox) params.bbox = bbox.join(',');
    const { data } = await api.get(`/api/projects/${projectId}/field-notes`, { params });
    return data.data;
  },
  async create(projectId: string, body: { body: string; lat: number; lng: number; accuracy_m?: number; tags?: string[]; area_id?: string }) {
    const { data } = await api.post(`/api/projects/${projectId}/field-notes`, body);
    return data.data;
  },
  async remove(id: string) {
    const { data } = await api.delete(`/api/field-notes/${id}`);
    return data.data;
  },
  async whereAmI(projectId: string, lat: number, lng: number) {
    const { data } = await api.get(`/api/projects/${projectId}/where-am-i`, { params: { lat, lng } });
    return data.data;
  },
};

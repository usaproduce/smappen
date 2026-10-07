// Truck Planner - API client.
//
// One module for every /api/truck route the screens call (docs/truck-planner/05_FRONTEND.md 2.1,
// routes and payloads in 04_BACKEND.md sections 3 and 4). House style: one exported object, each
// method unwraps the { success, data } envelope itself. Field names are snake_case, exactly as the
// server returns them. Money is dollars, times of day are minutes from local midnight, dates are
// YYYY-MM-DD.
//
// This file and utils/truck/assemble.ts are the only two that change when a payload changes.
// It is part of the lazy Truck Planner chunk: nothing in the eager import graph may import it.

import { api } from './client';
import { addDays } from '../utils/truck/model';
import type {
  AccuracyReport,
  AllowedHours,
  BestWindow,
  CalibrationState,
  CateringTerms,
  Confidence,
  DayContext,
  DayResult,
  DaypartFit,
  Estimate,
  EventTerms,
  Holiday,
  Host,
  KitchenState,
  LatLng,
  LegInput,
  LocationVectors,
  OverrideMap,
  Region,
  RivalKind,
  ScoutResult,
  SeedOverrideValue,
  SegmentKey,
  ServiceLogEntry,
  SizeSource,
  SpotTerms,
  StopKind,
  StopMoney,
  SuggestOptions,
  Suggestion,
  TreatAs,
  TruckProfile,
  Visibility,
  WeekSuggestion,
  WindowResult,
} from '../utils/truck/estimator/types';

// -------------------------------------------------------------------------------------------------
// Shapes of 04_BACKEND 4.1
// -------------------------------------------------------------------------------------------------

/** The API's name for the profile shape of 02_MODEL section 3. */
export type TruckProfileX = TruckProfile;

export interface TruckRecord {
  id: string;
  timezone: string;
  base_state: string | null;
  base_county_fips: string | null;
  profile: TruckProfileX;
  created_at: string;
  updated_at: string;
}

/** `Assumptions` without `seeds`: the browser bundles the same seed file (assemble.ts adds it). */
export interface AssumptionsInfo {
  model_version: string;
  seeds_revision: number;
  overrides: OverrideMap;
  region: Region;
}

export interface RegionCounty {
  fips: string;
  name: string;
  state: string;
}

export interface RegionPack {
  url: string;
  format_version: number;
  bytes: number;
  gz_bytes: number;
  sha256: string;
  cell_count: number;
}

export interface RegionVintages {
  census_reference_date: string;
  lodes_year: number;
  osm_snapshot_date: string;
}

export interface RegionBbox {
  lat_min: number;
  lng_min: number;
  lat_max: number;
  lng_max: number;
}

export interface RegionInfo {
  region_id: string;
  name: string;
  timezone: string;
  h3_res: number;
  center: LatLng;
  bbox: RegionBbox;
  dataset_version: string | null;
  usable: boolean;
  unusable_reason: null | 'not_loaded' | 'build_mismatch';
  pack: RegionPack | null;
  vintages: RegionVintages | null;
  counties: RegionCounty[];
}

export interface FuelInfo {
  price_per_gal: number;
  source: 'owner' | 'eia' | 'seed';
  area: string;
  product: 'EPMR' | 'EPD2D';
  period: string | null;
}

export interface Located {
  in_region: boolean;
  region_id: string | null;
  county_fips: string | null;
  state: string | null;
}

export interface OutletRow {
  place_key: string;
  name: string | null;
  place_type: string;
  rival_kind: RivalKind;
  kitchen: KitchenState;
  lat: number;
  lng: number;
  distance_m: number;
}

export interface HostHint {
  place_key: string;
  name: string;
  place_type: string;
  lat: number;
  lng: number;
  distance_m: number;
  host_segment: SegmentKey | null;
  default_size: number;
  kitchen: 'yes' | 'no';
  point_id: string | null;
}

export interface SpotHostDetails {
  place_type: string | null;
  name: string | null;
  contact: string | null;
  phone: string | null;
  website: string | null;
  place_key: string | null;
  google_place_id: string | null;
}

/** The three stored vector blocks of a spot. Read only through `spotVectors` (assemble.ts). */
export interface SpotVectors {
  hidden: LocationVectors;
  normal: LocationVectors;
  prominent: LocationVectors;
}

export type VectorsState = 'fresh' | 'stale' | 'none';

export interface Spot {
  id: string;
  name: string;
  point: LatLng;
  address: string;
  county_fips: string | null;
  notes: string | null;
  terms: SpotTerms;
  host_details: SpotHostDetails | null;
  vectors: SpotVectors | null;
  vectors_state: VectorsState;
  logs: { count: number; last_date: string | null };
  maps_url: string;
  archived: boolean;
  created_at: string;
  updated_at: string;
}

export type DriveLegSource = 'google_routes' | 'google_distance_matrix' | 'straight_line' | 'same_point';

export type FallbackReason =
  | 'no_key'
  | 'refused'
  | 'quota'
  | 'budget'
  | 'rate'
  | 'timeout'
  | 'upstream'
  | 'route_not_found'
  | 'cache_only';

/** The owner's correction as it rides on a leg. */
export interface DriveLegOverride {
  id: string;
  minutes: number | null;
  toll: number | null;
  note: string;
}

export interface DriveLeg {
  from_id: string;
  to_id: string;
  source: DriveLegSource;
  fetched_on: string | null;
  age_days: number | null;
  distance_m: number;
  duration_s: number;
  toll_state: 'not_asked' | 'none' | 'estimate' | 'unknown';
  google_toll: number | null;
  toll_source: 'owner' | 'google' | 'none';
  override: DriveLegOverride | null;
  fallback_reason: FallbackReason | null;
  leg_input: LegInput;
}

export interface PlanStop {
  id: string;
  kind: StopKind;
  spot_id: string | null;
  label: string;
  /** Null for spot stops: the point is the spot's. */
  point: LatLng | null;
  address: string;
  open_minute: number;
  close_minute: number;
  gap_before_unpaid: boolean;
  setup_minutes: number | null;
  teardown_minutes: number | null;
  fee_flat: number;
  fee_pct: number;
  fee_min: number;
  event: EventTerms | null;
  catering: CateringTerms | null;
}

export interface EvalContext {
  ctx: DayContext;
  ctx_next: DayContext;
  legs: DriveLeg[];
  calibration: { as_of: string; truck_factor: number; truck_n: number };
  fuel: FuelInfo;
  model_version: string;
  seeds_revision: number;
  dataset_version: string | null;
  uses_google_legs: boolean;
}

export type PlanStatus = 'draft' | 'planned' | 'done' | 'cancelled';
export type ResultState = 'fresh' | 'stale' | 'expired' | 'none';

export interface Plan {
  id: string;
  date: string;
  name: string;
  treat_as: TreatAs | null;
  notes: string | null;
  status: PlanStatus;
  stops: PlanStop[];
  /** The server's own snapshot. Screens evaluate in the browser and read this only where 0.2 says so. */
  result: DayResult | null;
  context: EvalContext | null;
  result_state: ResultState;
  evaluated_at: string | null;
  /** Null for a plan without stops. */
  maps_route_url: string | null;
  created_at: string;
  updated_at: string;
}

/** The server's figures for a listed plan. Not printed by any screen (0.2). */
export interface PlanSummary {
  orders: Estimate;
  take_home: Estimate;
  day_hours: number;
}

/** A row of `GET /plans` without `stops=1`. */
export interface PlanListRow {
  id: string;
  date: string;
  name: string;
  treat_as: TreatAs | null;
  status: PlanStatus;
  stop_count: number;
  result_state: ResultState;
  summary?: PlanSummary | null;
  updated_at: string;
}

/** A row of `GET /plans?stops=1`: a `Plan` without `result` and `context`. */
export type PlanWithStops = Omit<Plan, 'result' | 'context'> & {
  stop_count: number;
  summary?: PlanSummary | null;
};

export interface ServicePrediction {
  predicted_raw: number;
  predicted: number;
  low: number;
  high: number;
  confidence: Confidence;
  basis: 'plan' | 'log';
  model_version: string;
  seeds_revision: number;
  dataset_version: string | null;
  detail: Record<string, unknown>;
}

export interface ServiceLog {
  id: string;
  kind: StopKind;
  spot_id: string | null;
  plan_id: string | null;
  plan_stop_id: string | null;
  date: string;
  open_minute: number;
  close_minute: number;
  actual: number;
  sales: number | null;
  sold_out: boolean;
  notes: string | null;
  source: string;
  external_key: string | null;
  treat_as: TreatAs | null;
  prediction: ServicePrediction | null;
  created_at: string;
  updated_at: string;
}

/** One civil date of `day-context`; `context` is built with `treat_as = null`. */
export interface DayInfo {
  date: string;
  holiday: Holiday | null;
  context: DayContext;
}

export type LeadStatus = 'new' | 'shortlisted' | 'contacted' | 'booked' | 'declined' | 'hidden';

export interface LeadGoogle {
  place_id: string;
  lookup_state: 'found' | 'not_found';
  name: string | null;
  address: string | null;
  phone: string | null;
  website: string | null;
  maps_uri: string | null;
  fetched_on: string;
}

export interface Lead {
  id: string | null;
  place_key: string;
  status: LeadStatus;
  notes: string | null;
  spot_id: string | null;
  google: LeadGoogle | null;
}

export interface ScoutPlace {
  place_key: string;
  name: string;
  brand: string | null;
  place_type: string;
  lat: number;
  lng: number;
  county_fips: string;
  addr_line: string | null;
  city: string | null;
  state_code: string | null;
  postcode: string | null;
  phone: string | null;
  website: string | null;
  opening_hours_raw: string | null;
  kitchen: KitchenState;
}

export interface ScoutCandidate {
  result: ScoutResult;
  place: ScoutPlace;
  lead: Lead;
  maps_url: string;
  leg_sources: { out: DriveLegSource; back: DriveLegSource };
}

// -------------------------------------------------------------------------------------------------
// Answers and request bodies, route by route
// -------------------------------------------------------------------------------------------------

export type RoutingState = 'ok' | 'no_key' | 'refused' | 'backoff';

export interface TruckCounts {
  spots: number;
  plans: number;
  services: number;
  leads: number;
}

/** `limits` of config/truck_planner.php (04_BACKEND 7.1). */
export interface TruckLimits {
  max_spots: number;
  max_stops_per_plan: number;
  max_points_per_drive_request: number;
  max_pairs_per_drive_request: number;
  max_body_bytes: number;
  day_context_max_days: number;
  max_suggest_spots: number;
}

/** Every profile field the server fills in when the first save leaves it out. */
export type ProfileDefaults = Omit<TruckProfileX, 'name' | 'region_id' | 'base'>;

/** Route 1. Works without a truck: then `truck`, `region`, `fuel`, `timezone`, `today` and `now_minute` are null. */
export interface BootstrapAnswer {
  model_version: string;
  seeds_revision: number;
  has_truck: boolean;
  truck: TruckRecord | null;
  profile_defaults: ProfileDefaults;
  assumptions: AssumptionsInfo;
  region: RegionInfo | null;
  regions: RegionInfo[];
  calibration: CalibrationState;
  fuel: FuelInfo | null;
  timezone: string | null;
  today: string | null;
  now_minute: number | null;
  counts: TruckCounts;
  routing: { state: RoutingState };
  limits: TruckLimits;
}

/**
 * Body of route 3. An upsert: the first call creates the truck and needs `name`, `base` and
 * `avg_ticket`; later calls send only the keys that changed. Nested objects change only the keys
 * they carry.
 */
export type ProfilePatch = Partial<Omit<TruckProfileX, 'base' | 'daypart_fit'>> & {
  base?: Partial<TruckProfileX['base']> & { state?: string };
  daypart_fit?: Partial<DaypartFit>;
  /** Read by the server only when the truck's region is `none`. */
  timezone?: string;
};

export type ProfileWarning = 'timezone_assumed' | 'base_outside_region';

export interface SaveProfileAnswer {
  truck: TruckRecord;
  region: RegionInfo | null;
  fuel: FuelInfo;
  warnings: ProfileWarning[];
}

/** `{ <seed path>: value | null }`: merged on the server, null removes a path. */
export type OverrideChanges = Record<string, SeedOverrideValue | null>;

/** A host as a request describes it. The server derives `point_id` and `place_type`; they are never sent. */
export interface HostInput {
  place_key?: string;
  segment?: SegmentKey;
  size?: number;
  size_source?: SizeSource;
  only_food?: boolean;
}

/** Spot terms as a request sends them (routes 9, 11, 14). `host` and `allowed` are replaced whole. */
export interface SpotTermsInput {
  visibility?: Visibility;
  fee_flat?: number;
  fee_pct?: number;
  fee_min?: number;
  allowed?: AllowedHours | null;
  host?: HostInput | null;
}

export interface SimulateBody {
  point: LatLng;
  visibilities?: Visibility[];
  terms?: SpotTermsInput;
  spot_id?: string;
  date?: string;
  open_minute?: number;
  close_minute?: number;
  treat_as?: TreatAs | null;
}

/** The server's own estimate at the point. Read only by the development drift check (0.2). */
export interface SimulateEstimate {
  week_strip: number[];
  best_windows: BestWindow[];
  typical: { dow: number; open_minute: number; close_minute: number; window: WindowResult; money: StopMoney } | null;
  dated: { date: string; window: WindowResult; money: StopMoney; context: DayContext } | null;
}

export interface SimulateAnswer {
  located: Located;
  /** One entry per requested visibility, host exclusion applied. */
  vectors: Partial<Record<Visibility, LocationVectors>>;
  /** The host after the server's link rule and defaults, or null. */
  host: Host | null;
  outlets: OutletRow[];
  outlets_total: number;
  hosts_nearby: HostHint[];
  estimate: SimulateEstimate;
  calibration: { truck_factor: number; spot_factor: number };
  dataset_version: string | null;
  model_version: string;
  seeds_revision: number;
  attribution: string[];
}

/** Body of route 11 (create a spot). Route 14 takes any subset. */
export interface SpotBody {
  name: string;
  point: LatLng;
  address?: string;
  notes?: string | null;
  terms?: SpotTermsInput;
  host_details?: {
    name?: string | null;
    contact?: string | null;
    phone?: string | null;
    website?: string | null;
  };
}

export type SpotPatch = Partial<SpotBody>;

export interface ForecastInfo {
  state: 'fresh' | 'stale' | 'unavailable';
  generated_at: string | null;
  /** The server's reading of `generated_at` in the truck's time zone. */
  generated_local: { date: string; minute: number } | null;
  point: LatLng;
  source: string;
}

export interface DayContextAnswer {
  timezone: string;
  today: string;
  fuel: FuelInfo;
  forecast: ForecastInfo;
  days: DayInfo[];
}

/** A point of a drive-time request: id `base` for the truck's base, the stop id for a stop. */
export interface DrivePoint {
  id: string;
  lat: number;
  lng: number;
}

export type DriveMode = 'loop' | 'chain' | 'matrix' | 'pairs';

export interface DriveTimesBody {
  points: DrivePoint[];
  mode?: DriveMode;
  /** Required for mode `pairs`: `[from_id, to_id]`. */
  pairs?: [string, string][];
  tolls?: boolean;
  fetch?: boolean;
}

export interface DriveTimesAnswer {
  legs: DriveLeg[];
  routing: { state: RoutingState; route_key: string; leg_ttl_days: number; attribution: string };
}

/** The owner's correction for one directed pair of points (rounded coordinates). */
export interface DriveOverrideRecord {
  id: string;
  from: LatLng;
  to: LatLng;
  minutes: number | null;
  toll: number | null;
  note: string;
  updated_at: string;
}

export interface LegOverrideBody {
  from: LatLng;
  to: LatLng;
  minutes: number | null;
  toll: number | null;
  note?: string;
}

/** A stop as routes 23 and 26 take it. A stop without `id` is new; the server assigns the id. */
export interface PlanStopBody {
  id?: string;
  kind: StopKind;
  spot_id?: string | null;
  point?: LatLng | null;
  label?: string;
  address?: string;
  open_minute: number;
  close_minute: number;
  gap_before_unpaid?: boolean;
  setup_minutes?: number | null;
  teardown_minutes?: number | null;
  fee_flat?: number;
  fee_pct?: number;
  fee_min?: number;
  event?: EventTerms | null;
  catering?: CateringTerms | null;
}

/** Body of route 23. Array order of `stops` is the visiting order; the server never reorders. */
export interface PlanBody {
  date: string;
  name?: string;
  notes?: string | null;
  treat_as?: TreatAs | null;
  status?: PlanStatus;
  stops: PlanStopBody[];
}

export interface SuggestDayBody {
  date: string;
  treat_as?: TreatAs | null;
  spot_ids?: string[];
  options?: SuggestOptions;
}

export interface SuggestDayAnswer {
  suggestions: Suggestion[];
  spots_considered: number;
  fallback_pairs: number;
  context: DayContext;
}

export interface SuggestWeekBody {
  week_start: string;
  /** `{ "<date>": value }` for the dates the owner overrides. */
  treat_as?: Record<string, TreatAs | null>;
  spot_ids?: string[];
  options?: SuggestOptions;
}

export interface SuggestWeekAnswer {
  week: WeekSuggestion;
  spots_considered: number;
  fallback_pairs: number;
}

export interface ServiceBody {
  kind?: StopKind;
  spot_id?: string | null;
  date: string;
  open_minute: number;
  close_minute: number;
  /** Orders served. */
  actual: number;
  sales?: number | null;
  sold_out?: boolean;
  notes?: string | null;
  plan_stop_id?: string | null;
  treat_as?: TreatAs | null;
}

/** Every service write also returns the calibration that results from it. */
export interface ServiceWriteAnswer {
  service: ServiceLog;
  calibration: CalibrationState;
}

export interface ServiceDeleteAnswer {
  id: string;
  deleted: true;
  calibration: CalibrationState;
}

export interface AccuracyAnswer {
  accuracy: AccuracyReport;
  entries: ServiceLogEntry[];
  unscored_without_prediction: number;
}

export interface ScoutAnswer {
  /** At most 50, in rank order. The rank is the server's; the score is never shown. */
  candidates: ScoutCandidate[];
  screened: number;
  truncated: boolean;
  limit_minutes: number;
  licence_counties: string[];
  dataset_version: string | null;
  cached: boolean;
  attribution: string[];
}

export interface LeadPatch {
  status?: LeadStatus;
  notes?: string | null;
}

export interface ContactLookupAnswer {
  lead: Lead;
  lookup: 'found' | 'not_found' | 'cached';
}

export interface LeadSpotBody {
  name?: string;
  visibility?: Visibility;
  host_size?: number;
  only_food?: boolean;
}

export interface LeadSpotAnswer {
  spot: Spot;
  lead: Lead;
}

/** The export of route 42: one JSON document and the file name the server chose for it. */
export interface ExportFile {
  blob: Blob;
  filename: string;
}

export interface DeletedCounts {
  services: number;
  plan_stops: number;
  plans: number;
  leads: number;
  drive_overrides: number;
  spots: number;
  trucks: number;
}

/** One attribution string of 03_DATA section 14; `id` is that section's numbering. */
export interface AttributionRow {
  id: number;
  text: string;
  url: string | null;
}

export interface SourcesDataset {
  dataset_version: string;
  pipeline_version: string;
  corrections_version: string;
  places_source: string;
  vintages: RegionVintages;
  counts: { points: number; places: number; cells: number };
  totals: { residents: number; jobs: number };
  warn_gates: string[];
}

export interface SourcesAnswer {
  model_version: string;
  seeds_revision: number;
  region: RegionInfo | null;
  dataset: SourcesDataset | null;
  fuel: FuelInfo | null;
  contact: string;
  attribution: AttributionRow[];
}

// -------------------------------------------------------------------------------------------------
// Query keys (05_FRONTEND 2.2)
// -------------------------------------------------------------------------------------------------

export const truckKeys = {
  all:         ['truck'] as const,
  bootstrap:   () => ['truck', 'bootstrap'] as const,
  pack:        (url: string) => ['truck', 'pack', url] as const,
  simulate:    (version: string, lat6: string, lng6: string, hostKey: string, visKey: string) => ['truck', 'simulate', version, lat6, lng6, hostKey, visKey] as const,
  spots:       (archived: boolean) => ['truck', 'spots', 'list', archived] as const,
  spot:        (id: string) => ['truck', 'spots', 'one', id] as const,
  dayContext:  (from: string, days: number) => ['truck', 'day-context', from, days] as const,
  driveTimes:  (pointsKey: string) => ['truck', 'drive-times', pointsKey] as const,
  plans:       (from: string, to: string, stops: boolean) => ['truck', 'plans', 'list', from, to, stops] as const,
  plan:        (id: string) => ['truck', 'plans', 'one', id] as const,
  suggestDay:  (date: string, optionsKey: string) => ['truck', 'suggest', 'day', date, optionsKey] as const,
  suggestWeek: (weekStart: string, optionsKey: string) => ['truck', 'suggest', 'week', weekStart, optionsKey] as const,
  services:    (from: string, to: string, spotId: string) => ['truck', 'services', from, to, spotId] as const,
  accuracy:    (from: string, to: string) => ['truck', 'accuracy', from, to] as const,
  scout:       (hideKey: string) => ['truck', 'scout', hideKey] as const,
  sources:     () => ['truck', 'sources'] as const,
};

// -------------------------------------------------------------------------------------------------
// Errors (05_FRONTEND 2.6)
// -------------------------------------------------------------------------------------------------

/** The server's sentence when a route needs a truck and the organization has none (409). */
export const NO_TRUCK_ERROR = 'Set up your truck first';

/** The server's sentence while the region data is built with other model constants (409). */
export const REGION_REBUILD_ERROR = 'Region data was built with different model constants';

/** What the owner reads in place of `REGION_REBUILD_ERROR`. Never the server text. */
export const REGION_REBUILD_SENTENCE =
  'Map data is being rebuilt after an update. New estimates are unavailable until it finishes.';

/** What the owner reads in place of the rate-limit middleware's sentence, which names an internal counter. */
export const RATE_LIMIT_SENTENCE = 'Too many requests right now. Try again in a minute.';

interface FailedResponse {
  status?: unknown;
  data?: unknown;
}

function responseOf(e: unknown): FailedResponse | null {
  if (typeof e !== 'object' || e === null) return null;
  const response = (e as { response?: unknown }).response;
  if (typeof response !== 'object' || response === null) return null;
  return response as FailedResponse;
}

function sentenceOf(response: FailedResponse): string | null {
  const data = response.data;
  if (typeof data !== 'object' || data === null) return null;
  const error = (data as { error?: unknown }).error;
  return typeof error === 'string' && error !== '' ? error : null;
}

/** HTTP status of a failed request, or null when there was no response at all (offline). */
export function apiErrorStatus(e: unknown): number | null {
  const response = responseOf(e);
  return response !== null && typeof response.status === 'number' ? response.status : null;
}

/**
 * The sentence to show for a failed request. Null when there was no response: the shared client
 * has already toasted "Connection lost", so call sites must not toast again. Otherwise the server's
 * sentence when it sent one, else `fallback`. Three server sentences are never shown as they are:
 * the rate-limit middleware's, the region rebuild notice, and whatever a 501 says (a route that is
 * not built yet answers "Not implemented yet", which tells the owner nothing: the caller's own
 * sentence is shown, as for any request that failed without a reason).
 */
export function apiErrorMessage(e: unknown, fallback: string): string | null {
  const response = responseOf(e);
  if (response === null) return null;
  const sentence = sentenceOf(response);
  if (sentence === null || response.status === 501) return fallback;
  if (response.status === 429 && sentence.startsWith('Rate limit reached')) return RATE_LIMIT_SENTENCE;
  if (response.status === 409 && sentence === REGION_REBUILD_ERROR) return REGION_REBUILD_SENTENCE;
  return sentence;
}

/** The `details` of a failed request: the `[{ path, error }]` list of an overrides 422, or `{ field, code }`. */
export function apiErrorDetails(e: unknown): unknown {
  const response = responseOf(e);
  if (response === null) return null;
  const data = response.data;
  if (typeof data !== 'object' || data === null) return null;
  return (data as { details?: unknown }).details ?? null;
}

/** True for the 409 that means the truck was deleted (in another tab): the first-run step must come back. */
export function isNoTruckError(e: unknown): boolean {
  const response = responseOf(e);
  return response !== null && response.status === 409 && sentenceOf(response) === NO_TRUCK_ERROR;
}

/** True for the 409 that means the region data is being rebuilt. */
export function isRegionRebuildError(e: unknown): boolean {
  const response = responseOf(e);
  return response !== null && response.status === 409 && sentenceOf(response) === REGION_REBUILD_ERROR;
}

// -------------------------------------------------------------------------------------------------
// Client
// -------------------------------------------------------------------------------------------------

const PACK_URL_PREFIX = '/api/truck/regions/';
const EXPORT_FALLBACK_NAME = 'truck-planner-export.json';

/** Path segment for an id or key. */
function seg(value: string): string {
  return encodeURIComponent(value);
}

/** `filename="..."` of a Content-Disposition header, or the fallback name. */
function exportFileName(header: unknown): string {
  if (typeof header !== 'string') return EXPORT_FALLBACK_NAME;
  const match = /filename="?([^";]+)"?/i.exec(header);
  if (match === null) return EXPORT_FALLBACK_NAME;
  // Keep the last path segment only: the name is used for a download, never as a path.
  const parts = match[1].trim().split(/[\\/]/);
  const name = parts[parts.length - 1];
  return name === '' ? EXPORT_FALLBACK_NAME : name;
}

function listPlans(from: string, to: string, options: { stops: true }): Promise<PlanWithStops[]>;
function listPlans(from: string, to: string, options?: { stops?: false }): Promise<PlanListRow[]>;
async function listPlans(
  from: string,
  to: string,
  options: { stops?: boolean } = {},
): Promise<PlanWithStops[] | PlanListRow[]> {
  const { data } = await api.get('/api/truck/plans', {
    params: { from, to, stops: options.stops ? 1 : 0 },
  });
  return data.data.plans ?? [];
}

export const truckApi = {
  // Route 1
  async bootstrap(): Promise<BootstrapAnswer> {
    const { data } = await api.get('/api/truck/bootstrap');
    return data.data;
  },

  // Route 3. An upsert: the first call creates the truck (201).
  async saveProfile(patch: ProfilePatch): Promise<SaveProfileAnswer> {
    const { data } = await api.put('/api/truck/profile', patch);
    return data.data;
  },

  // Route 5
  async saveOverrides(changes: OverrideChanges): Promise<AssumptionsInfo> {
    const { data } = await api.put('/api/truck/assumptions', { overrides: changes });
    return data.data.assumptions;
  },

  // Route 6. Without `paths` every override is removed.
  async resetOverrides(paths?: string[]): Promise<AssumptionsInfo> {
    const { data } = await api.post('/api/truck/assumptions/reset', paths === undefined ? {} : { paths });
    return data.data.assumptions;
  },

  // Route 8. The binary cell pack of 03_DATA section 11, not the JSON envelope. `url` is
  // `region.pack.url`; anything that is not a pack path of this API is refused.
  async fetchPack(url: string): Promise<ArrayBuffer> {
    if (!url.startsWith(PACK_URL_PREFIX)) {
      throw new Error('fetchPack: not a pack url');
    }
    const { data } = await api.get(url, { responseType: 'arraybuffer' });
    return data as ArrayBuffer;
  },

  // Route 9
  async simulate(body: SimulateBody): Promise<SimulateAnswer> {
    const { data } = await api.post('/api/truck/simulate', body);
    return data.data;
  },

  // Route 10
  async listSpots(options: { archived?: boolean } = {}): Promise<Spot[]> {
    const { data } = await api.get('/api/truck/spots', {
      params: options.archived ? { archived: 1 } : {},
    });
    return data.data.spots ?? [];
  },

  // Route 13
  async getSpot(id: string): Promise<Spot> {
    const { data } = await api.get(`/api/truck/spots/${seg(id)}`);
    return data.data.spot;
  },

  // Route 11
  async createSpot(body: SpotBody): Promise<Spot> {
    const { data } = await api.post('/api/truck/spots', body);
    return data.data.spot;
  },

  // Route 14
  async updateSpot(id: string, patch: SpotPatch): Promise<Spot> {
    const { data } = await api.put(`/api/truck/spots/${seg(id)}`, patch);
    return data.data.spot;
  },

  // Route 15. A soft delete: logs and plans keep working.
  async archiveSpot(id: string): Promise<{ id: string; archived: true }> {
    const { data } = await api.delete(`/api/truck/spots/${seg(id)}`);
    return data.data;
  },

  // Route 12. Recomputes up to 50 spots whose vectors are not fresh.
  async refreshStaleSpots(): Promise<{ refreshed: number; remaining: number }> {
    const { data } = await api.post('/api/truck/spots/refresh', {});
    return data.data;
  },

  // Route 17. `days` is 1 to 14; the forecast is for the truck's base point.
  async dayContext(from: string, days: number): Promise<DayContextAnswer> {
    const { data } = await api.get('/api/truck/day-context', {
      params: { from, to: addDays(from, days - 1) },
    });
    return data.data;
  },

  // Route 18. Limited to 240 requests an hour, shared with plan saves: never per keystroke or click.
  async driveTimes(body: DriveTimesBody): Promise<DriveTimesAnswer> {
    const { data } = await api.post('/api/truck/drive-times', body);
    return data.data;
  },

  // Route 20
  async saveLegOverride(body: LegOverrideBody): Promise<DriveOverrideRecord> {
    const { data } = await api.put('/api/truck/drive-times/overrides', body);
    return data.data.override;
  },

  // Route 21
  async deleteLegOverride(id: string): Promise<{ id: string; deleted: true }> {
    const { data } = await api.delete(`/api/truck/drive-times/overrides/${seg(id)}`);
    return data.data;
  },

  // Route 22. With `stops: true` each row is a Plan without `result` and `context`.
  listPlans,

  // Route 25. Used only where `result` is needed (the drift check and the Log estimate).
  async getPlan(id: string): Promise<Plan> {
    const { data } = await api.get(`/api/truck/plans/${seg(id)}`);
    return data.data.plan;
  },

  // Route 23. One plan per date: a second one answers 409 and the caller then uses updatePlan.
  async createPlan(body: PlanBody): Promise<Plan> {
    const { data } = await api.post('/api/truck/plans', body);
    return data.data.plan;
  },

  // Route 26. `stops`, when sent, replaces all stops.
  async updatePlan(id: string, body: Partial<PlanBody>): Promise<Plan> {
    const { data } = await api.put(`/api/truck/plans/${seg(id)}`, body);
    return data.data.plan;
  },

  // Route 27
  async deletePlan(id: string): Promise<{ id: string; deleted: true }> {
    const { data } = await api.delete(`/api/truck/plans/${seg(id)}`);
    return data.data;
  },

  // Route 29 (30 requests an hour)
  async suggestDay(body: SuggestDayBody): Promise<SuggestDayAnswer> {
    const { data } = await api.post('/api/truck/suggest/day', body);
    return data.data;
  },

  // Route 30 (30 requests an hour)
  async suggestWeek(body: SuggestWeekBody): Promise<SuggestWeekAnswer> {
    const { data } = await api.post('/api/truck/suggest/week', body);
    return data.data;
  },

  // Route 31. Newest first. An absent end takes the server's default (the last 90 days).
  async listServices(options: { from?: string; to?: string; spot_id?: string } = {}): Promise<ServiceLog[]> {
    const params: Record<string, string> = {};
    if (options.from) params.from = options.from;
    if (options.to) params.to = options.to;
    if (options.spot_id) params.spot_id = options.spot_id;
    const { data } = await api.get('/api/truck/services', { params });
    return data.data.services ?? [];
  },

  // Route 32
  async createService(body: ServiceBody): Promise<ServiceWriteAnswer> {
    const { data } = await api.post('/api/truck/services', body);
    return data.data;
  },

  // Route 34
  async updateService(id: string, patch: Partial<ServiceBody>): Promise<ServiceWriteAnswer> {
    const { data } = await api.put(`/api/truck/services/${seg(id)}`, patch);
    return data.data;
  },

  // Route 35
  async deleteService(id: string): Promise<ServiceDeleteAnswer> {
    const { data } = await api.delete(`/api/truck/services/${seg(id)}`);
    return data.data;
  },

  // Route 37. Both ends are optional.
  async accuracy(options: { from?: string; to?: string } = {}): Promise<AccuracyAnswer> {
    const params: Record<string, string> = {};
    if (options.from) params.from = options.from;
    if (options.to) params.to = options.to;
    const { data } = await api.get('/api/truck/accuracy', { params });
    return data.data;
  },

  // Route 38 (60 requests an hour). `hide` lists the lead statuses removed before ranking.
  async scout(options: { hide: LeadStatus[]; refresh?: boolean }): Promise<ScoutAnswer> {
    const params: Record<string, string> = { hide: options.hide.join(',') };
    if (options.refresh) params.refresh = '1';
    const { data } = await api.get('/api/truck/scout', { params });
    return data.data;
  },

  // Route 39. Creates the lead on first touch.
  async saveLead(placeKey: string, patch: LeadPatch): Promise<Lead> {
    const { data } = await api.put(`/api/truck/scout/leads/${seg(placeKey)}`, patch);
    return data.data.lead;
  },

  // Route 40 (20 requests an hour). Only ever sent from its own button.
  async lookupContact(placeKey: string, force = false): Promise<ContactLookupAnswer> {
    const { data } = await api.post(`/api/truck/scout/leads/${seg(placeKey)}/contact`, force ? { force: true } : {});
    return data.data;
  },

  // Route 41
  async saveLeadAsSpot(placeKey: string, body: LeadSpotBody): Promise<LeadSpotAnswer> {
    const { data } = await api.post(`/api/truck/scout/leads/${seg(placeKey)}/spot`, body);
    return data.data;
  },

  // Route 42. One JSON document, not the envelope; the file name comes from Content-Disposition.
  async exportAll(): Promise<ExportFile> {
    const response = await api.get('/api/truck/export', { responseType: 'blob' });
    return {
      blob: response.data as Blob,
      filename: exportFileName(response.headers?.['content-disposition']),
    };
  },

  // Route 43. Owner or admin only; the server wants the phrase word for word.
  async deleteAllData(): Promise<DeletedCounts> {
    const { data } = await api.post('/api/truck/data/delete', { confirm: 'delete my truck data' });
    return data.data.deleted;
  },

  // Route 44
  async sources(): Promise<SourcesAnswer> {
    const { data } = await api.get('/api/truck/sources');
    return data.data;
  },
};

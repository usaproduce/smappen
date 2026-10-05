// Truck Planner estimator - data shapes (model tps-0.1.0).
//
// Every JSON shape of docs/truck-planner/02_MODEL.md section 3, plus the records its functions return.
// Field names are snake_case and are the contract shared by the API, the PHP estimator and this port.
// Conventions (02_MODEL 1.2): money is US dollars (real), fractions are fractions of 1, clock times are
// integer minutes from local midnight of the service date, dates are "YYYY-MM-DD" civil dates,
// hour of week is dow * 24 + hour with dow 0 = Monday. A "segment vector" is an array of 16 numbers in
// segment index order. Types only: this file has no runtime content.

// -------------------------------------------------------------------------------------------------
// 1.1 Fixed vocabulary
// -------------------------------------------------------------------------------------------------

/** The sixteen segments, in index order 0..15. */
export type SegmentKey =
  | 'res'
  | 'w_office'
  | 'w_health'
  | 'w_edu'
  | 'w_retail'
  | 'w_industrial'
  | 'w_hospitality'
  | 'w_public'
  | 'v_nightlife'
  | 'v_shopping'
  | 'v_leisure'
  | 'v_campus'
  | 'v_hospital'
  | 'v_transit'
  | 'v_events'
  | 'v_lodging';

/** Competition regime: day = hours 05..15, eve = hours 16..23 and 00..04. */
export type Regime = 'day' | 'eve';

/** Kind of a food outlet that competes with the truck. */
export type RivalKind = 'quick' | 'full' | 'cafe' | 'bar' | 'convenience';

/** Which curve arrays apply to a segment on a civil date. */
export type DayType = 'weekday' | 'saturday' | 'sunday';

/** Day-of-week keys in index order: dow 0 = mon ... 6 = sun. */
export type DowKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

/** Meal period of a clock hour; indexes TruckProfile.daypart_fit. */
export type Daypart = 'breakfast' | 'lunch' | 'dinner' | 'late';

/** How visible the truck is at a spot. */
export type Visibility = 'hidden' | 'normal' | 'prominent';

/** Confidence label of an estimate, weakest first: very_rough, rough, fair, good, fixed. */
export type Confidence = 'very_rough' | 'rough' | 'fair' | 'good' | 'fixed';

/** Place types of the places table (OpenStreetMap-derived), in seed order. */
export type PlaceType =
  | 'taproom'
  | 'bar'
  | 'restaurant'
  | 'fast_food'
  | 'cafe'
  | 'convenience'
  | 'gym'
  | 'park'
  | 'shopping_centre'
  | 'big_box'
  | 'campus'
  | 'hospital'
  | 'transit_station'
  | 'events_venue'
  | 'stadium'
  | 'hotel'
  | 'attraction'
  | 'farmers_market'
  | 'office_park'
  | 'apartment_community'
  | 'industrial_site'
  | 'car_dealership';

/** The model version string carried by every stored result. */
export type ModelVersion = 'tps-0.1.0';

/** Group of a segment: decides the base unit and the host exclusion rule. */
export type SegmentGroup = 'residents' | 'workers' | 'visitors';

/** How a host's share is set: flat share inside a venue (captive) or the kernel at distance zero (open). */
export type HostMode = 'captive' | 'open';

/** Where a host size came from. */
export type SizeSource = 'owner' | 'default';

/** Whether a place sells its own food; unknown resolves to the place type's default. */
export type KitchenState = 'yes' | 'no' | 'unknown';

/** Which weather table applies: people outdoors (open) or already inside a venue (captive). */
export type WeatherSetting = 'open' | 'captive';

/** How the weather factor of an hour was obtained. */
export type WeatherState = 'forecast' | 'missing' | 'typical';

/** Class of a federal holiday. */
export type HolidayClass = 'major' | 'minor';

/** The owner's "treat this day as" override; null means automatic. */
export type TreatAs = 'normal' | 'holiday' | DowKey;

/** Fuel the truck burns. */
export type FuelType = 'gasoline' | 'diesel';

/** Where the fuel price of a context came from. */
export type FuelPriceSource = 'owner' | 'eia' | 'seed';

/** Which time-of-day traffic matrix a region uses. */
export type TrafficMatrixId = 'dc' | 'us_mean';

/** Kind of a planned stop. */
export type StopKind = 'spot' | 'event' | 'catering';

/** Kind of event; selects the seed events.p_buy.<type>. */
export type EventType = 'general' | 'food_focused' | 'evening_show' | 'incidental';

/** Source of a drive leg handed to the model. */
export type LegSource = 'google' | 'fallback';

/** Source of an evaluated leg: the input source, or the owner's override. */
export type LegResultSource = 'google' | 'fallback' | 'override';

/** Kind of a timeline event. */
export type TimelineEventKind =
  | 'start_prep'
  | 'leave_base'
  | 'arrive'
  | 'setup_start'
  | 'open'
  | 'close'
  | 'leave'
  | 'back_at_base'
  | 'done';

/** Severity of a plan warning. */
export type WarningLevel = 'info' | 'warn' | 'error';

/** The 21 plan warning codes, in the order day_plan emits them. */
export type WarningCode =
  | 'invalid_window'
  | 'stops_overlap'
  | 'stop_unreachable'
  | 'late_arrival'
  | 'outside_region'
  | 'stale_vectors'
  | 'outside_allowed_hours'
  | 'fallback_drive_time'
  | 'long_gap'
  | 'long_day'
  | 'fee_high'
  | 'below_break_even'
  | 'event_thin_crowd'
  | 'weak_day_loss'
  | 'capacity_bound'
  | 'early_start'
  | 'ends_after_midnight'
  | 'no_forecast'
  | 'holiday'
  | 'weak_seed'
  | 'default_host_size';

/** Error codes the model raises (ModelError.code). */
export type ModelErrorCode = 'invalid_date' | 'invalid_window' | 'missing_context';

/** Error codes of validate_overrides, in the order its steps find them. */
export type OverrideErrorCode =
  | 'unknown_path'
  | 'not_a_seed'
  | 'not_overridable'
  | 'not_a_leaf'
  | 'wrong_shape'
  | 'out_of_bounds'
  | 'not_allowed';

/** Evidence tag of a seed. */
export type SeedTag = 'measured' | 'derived' | 'assumed' | 'tuned';

/** Who may change a seed (02_MODEL 2.2). */
export type SeedScope = 'build' | 'fixed' | 'owner' | 'profile_default';

// -------------------------------------------------------------------------------------------------
// Small building blocks
// -------------------------------------------------------------------------------------------------

/** Sixteen numbers in segment index order. */
export type SegmentVector = number[];

/** One number per competition regime. */
export interface RegimePair {
  day: number;
  eve: number;
}

/** A point in decimal degrees, WGS84. */
export interface LatLng {
  lat: number;
  lng: number;
}

/** A civil date as [year, month, day]. */
export type CivilDate = [number, number, number];

/** An expected value with its 80 % range and a confidence label; invariant low <= value <= high. */
export interface Estimate {
  value: number;
  low: number;
  high: number;
  confidence: Confidence;
}

// -------------------------------------------------------------------------------------------------
// Seeds (02_MODEL section 2)
// -------------------------------------------------------------------------------------------------

/** A scalar seed entry: one value with its metadata. */
export interface SeedEntry<T> {
  value: T;
  unit: string;
  tag: SeedTag;
  scope: SeedScope;
  min?: number;
  max?: number;
  source: string;
}

/** A curve group of one segment: 24 numbers per day type, by local clock hour. */
export interface SeedCurves {
  unit: string;
  tag: SeedTag;
  scope: SeedScope;
  min: number;
  max: number;
  weekday: number[];
  saturday: number[];
  sunday: number[];
  source: string;
}

/** Seeds of one segment; index, label, group, base_unit, host_mode, weak and lodes_cns are structural. */
export interface SegmentSeed {
  index: number;
  label: string;
  group: SegmentGroup;
  base_unit: string;
  host_mode: HostMode;
  weak: boolean;
  lodes_cns?: string[];
  presence: SeedCurves;
  intent: SeedCurves;
  dow_factor: SeedEntry<number[]>;
  holiday_day_type: {
    unit: string;
    tag: SeedTag;
    scope: SeedScope;
    allowed: DayType[];
    major: DayType;
    minor: DayType;
    source: string;
  };
}

/** Weight of one rival kind per regime. */
export interface RivalWeightSeed {
  day: number;
  eve: number;
  tag: SeedTag;
  source: string;
}

/** One row of seeds.place_types.rows. */
export interface PlaceTypeSeed {
  visitor_segment: SegmentKey | null;
  default_size: number;
  rival_kind: RivalKind | null;
  host_fit: number;
  kitchen_default: 'yes' | 'no';
  host_segment: SegmentKey | null;
  tag: SeedTag;
  source: string;
}

/** One federal holiday rule (5 U.S.C. 6103); rule order is the position in the list, from 1. */
export interface HolidayRule {
  id: string;
  name: string;
  rule: 'fixed' | 'nth_weekday' | 'last_weekday' | 'inauguration';
  month: number;
  day?: number;
  dow?: number;
  n?: number;
  from_year?: number;
  class: HolidayClass;
  region_flag?: string;
}

/** One weather band or class row: multipliers for the two settings. */
export interface WeatherRowSeed {
  open: number;
  captive: number;
  tag: SeedTag;
  source: string;
  upper_f?: number | null;
  upper_mph?: number | null;
  match?: string[];
}

/** A banded weather table: row ids in test order, and the rows. */
export interface WeatherTableSeed {
  unit: string;
  scope: SeedScope;
  min: number;
  max: number;
  match_rule?: string;
  order: string[];
  rows: Record<string, WeatherRowSeed>;
}

/** The tp_seeds.json object (the generated copy is seeds.generated.ts). */
export interface SeedFile {
  model_version: string;
  seeds_revision: number;
  as_of: string;
  about: string;
  entry_format: Record<string, string>;
  vocabulary: {
    segments: SegmentKey[];
    regimes: Regime[];
    rival_kinds: RivalKind[];
    day_types: DayType[];
    dayparts: Daypart[];
    visibility_levels: Visibility[];
    place_types: PlaceType[];
    confidence_labels: Confidence[];
    dow: DowKey[];
    structural_keys: string[];
  };
  constants: {
    earth_radius_m: SeedEntry<number>;
    pi: SeedEntry<number>;
    ln2: SeedEntry<number>;
    z80: SeedEntry<number>;
    meters_per_mile: SeedEntry<number>;
    round_half: SeedEntry<number>;
    qkey_scale: SeedEntry<number>;
  };
  hours: {
    regime_of_hour: SeedEntry<Regime[]>;
    daypart_of_hour: SeedEntry<Daypart[]>;
  };
  kernel: {
    walk_decay_m: SeedEntry<number>;
    walk_cutoff_m: SeedEntry<number>;
    outside_option_a0: SeedEntry<number>;
    rival_weight: { unit: string; scope: SeedScope } & Record<RivalKind, RivalWeightSeed>;
    visibility: Record<Visibility, SeedEntry<number>>;
  };
  segments: Record<SegmentKey, SegmentSeed>;
  etl: {
    cns04_weight: SeedEntry<number>;
    cell_min_nearby: SeedEntry<number>;
    cell_min_venue: SeedEntry<number>;
  };
  host: {
    captive_share: SeedEntry<number>;
    shared_kitchen_share: SeedEntry<number>;
    onsite_kitchen_weight: SeedEntry<number>;
    exclusion_radius_m: SeedEntry<number>;
    venue_link_radius_m: SeedEntry<number>;
  };
  place_types: {
    unit: string;
    scope: SeedScope;
    note: string;
    order: PlaceType[];
    rows: Record<PlaceType, PlaceTypeSeed>;
  };
  holidays: {
    unit: string;
    tag: SeedTag;
    scope: SeedScope;
    source: string;
    rules: HolidayRule[];
  };
  weather: {
    floor: SeedEntry<number>;
    pop_when_missing: SeedEntry<number>;
    temperature_bands: WeatherTableSeed;
    precip_classes: WeatherTableSeed;
    wind_bands: WeatherTableSeed;
  };
  traffic: {
    dc: SeedEntry<number[][]>;
    us_mean: SeedEntry<number[][]>;
    dc_typical: SeedEntry<number>;
    us_mean_typical: SeedEntry<number>;
  };
  drive_fallback: {
    detour_factor: SeedEntry<number>;
    local_miles: SeedEntry<number>;
    local_mph: SeedEntry<number>;
    trunk_mph: SeedEntry<number>;
  };
  profile_defaults: {
    avg_ticket: SeedEntry<number>;
    capacity_orders_per_hour: SeedEntry<number>;
    paid_crew: SeedEntry<number>;
    wage_per_hour: SeedEntry<number>;
    payroll_burden_pct: SeedEntry<number>;
    food_cost_pct: SeedEntry<number>;
    packaging_per_order: SeedEntry<number>;
    card_fee_pct: SeedEntry<number>;
    card_fee_fixed: SeedEntry<number>;
    card_share: SeedEntry<number>;
    tips_include: SeedEntry<boolean>;
    tips_pct_of_card_sales: SeedEntry<number>;
    mpg: SeedEntry<number>;
    fuel_type: SeedEntry<FuelType>;
    generator_gal_per_hour: SeedEntry<number>;
    prep_minutes: SeedEntry<number>;
    setup_minutes: SeedEntry<number>;
    teardown_minutes: SeedEntry<number>;
    closeout_minutes: SeedEntry<number>;
    fixed_cost_per_service_day: SeedEntry<number>;
    daypart_fit: {
      unit: string;
      tag: SeedTag;
      scope: SeedScope;
      min: number;
      max: number;
      breakfast: number;
      lunch: number;
      dinner: number;
      late: number;
      source: string;
    };
    avoid_tolls: SeedEntry<boolean>;
    avoid_highways: SeedEntry<boolean>;
    truck_time_factor: SeedEntry<number>;
    scout_drive_minutes_limit: SeedEntry<number>;
  };
  money: {
    fee_warn_share: SeedEntry<number>;
    fuel_price_fallback: {
      unit: string;
      tag: SeedTag;
      scope: SeedScope;
      as_of: string;
      gasoline: Record<string, number>;
      diesel: Record<string, number>;
      source: string;
    };
  };
  events: {
    attendance_haircut: SeedEntry<number>;
    p_buy: {
      unit: string;
      tag: SeedTag;
      scope: SeedScope;
      min: number;
      max: number;
      source: string;
    } & Record<EventType, number>;
    min_attendees_per_vendor: SeedEntry<number>;
    suggested_fee_pct: SeedEntry<number>;
    suggested_fee_min: SeedEntry<number>;
  };
  uncertainty: {
    sd_truck: SeedEntry<number>;
    sd_spot: SeedEntry<number>;
    sd_day: SeedEntry<number>;
    sd_weak: SeedEntry<number>;
    sd_default_size: SeedEntry<number>;
    sd_event: SeedEntry<number>;
    count_dispersion: SeedEntry<number>;
    resid_prior_weight: SeedEntry<number>;
    label_good_below: SeedEntry<number>;
    label_fair_below: SeedEntry<number>;
    label_rough_below: SeedEntry<number>;
  };
  calibration: {
    k_truck: SeedEntry<number>;
    k_spot: SeedEntry<number>;
    half_life_days: SeedEntry<number>;
    ratio_clamp: SeedEntry<number>;
    spot_ratio_clamp: SeedEntry<number>;
    min_predicted: SeedEntry<number>;
    min_actual: SeedEntry<number>;
    min_resid_n: SeedEntry<number>;
  };
  timeline: {
    long_gap_minutes: SeedEntry<number>;
    long_day_minutes: SeedEntry<number>;
    early_start_minute: SeedEntry<number>;
  };
  suggest: {
    service_minutes: SeedEntry<number>;
    earliest_open_minute: SeedEntry<number>;
    latest_close_minute: SeedEntry<number>;
    windows_per_spot: SeedEntry<number>;
    max_candidates: SeedEntry<number>;
    max_stops_per_day: SeedEntry<number>;
    max_day_minutes: SeedEntry<number>;
    min_stop_orders: SeedEntry<number>;
    day_results: SeedEntry<number>;
    week_day_options: SeedEntry<number>;
    max_days_per_week: SeedEntry<number>;
    max_visits_per_spot_per_week: SeedEntry<number>;
    min_day_take_home: SeedEntry<number>;
  };
  scout: {
    window_minutes: SeedEntry<number>;
    max_results: SeedEntry<number>;
  };
  map: {
    opportunity_hi: SeedEntry<number>;
    people_hi: SeedEntry<number>;
    competition_hi: SeedEntry<number>;
  };
}

/** A value an override may carry: it replaces the seed value wholesale. */
export type SeedOverrideValue = number | string | number[] | string[];

/** Sparse map from seed path (dot-separated keys from the root) to the owner's value. */
export type OverrideMap = Record<string, SeedOverrideValue>;

/** One problem found by validate_overrides. */
export interface OverrideProblem {
  path: string;
  error: OverrideErrorCode;
}

/** The region part of an Assumptions record (from tp_regions.config_json). */
export interface Region {
  id: string;
  traffic_matrix: TrafficMatrixId;
  flags: { inauguration_day: boolean };
}

/** Seeds, the owner's overrides and the region: the first argument of most model functions. */
export interface Assumptions {
  model_version: ModelVersion;
  seeds_revision: number;
  seeds: SeedFile;
  overrides: OverrideMap;
  region: Region;
}

// -------------------------------------------------------------------------------------------------
// Truck, spots, locations
// -------------------------------------------------------------------------------------------------

/** Multiplier on meal intent per daypart (how well the menu fits it), 0..1. */
export interface DaypartFit {
  breakfast: number;
  lunch: number;
  dinner: number;
  late: number;
}

/** The truck: prices, speed, crew, costs, timings and routing options. */
export interface TruckProfile {
  name: string;
  region_id: string;
  base: { lat: number; lng: number; address: string };
  avg_ticket: number;
  capacity_orders_per_hour: number;
  paid_crew: number;
  wage_per_hour: number;
  payroll_burden_pct: number;
  food_cost_pct: number;
  packaging_per_order: number;
  card_fee_pct: number;
  card_fee_fixed: number;
  card_share: number;
  tips_include: boolean;
  tips_pct_of_card_sales: number;
  mpg: number;
  fuel_type: FuelType;
  fuel_price_override: number | null;
  generator_gal_per_hour: number;
  prep_minutes: number;
  setup_minutes: number;
  teardown_minutes: number;
  closeout_minutes: number;
  fixed_cost_per_service_day: number;
  daypart_fit: DaypartFit;
  avoid_tolls: boolean;
  avoid_highways: boolean;
  truck_time_factor: number;
  licence_counties: string[];
  scout_drive_minutes_limit: number;
}

/** A census block or a place with visitors: base count per segment and the rival pull at its position. */
export interface SourcePoint {
  id: string;
  lat: number;
  lng: number;
  base: SegmentVector;
  rivals: RegimePair;
}

/** A food outlet that competes for the same meal. */
export interface Outlet {
  id: string;
  lat: number;
  lng: number;
  kind: RivalKind;
}

/** What a declared host removes from the catchment so its people are not counted twice. */
export interface Exclusion {
  point_ids: string[];
  segment: SegmentKey | null;
  amount: number;
}

/** A spot's host; size is in the base unit of the segment (headcount, jobs or residents). */
export interface Host {
  segment: SegmentKey;
  size: number;
  size_source: SizeSource;
  only_food: boolean;
  point_id: string | null;
  place_type: string | null;
}

/** Days and hours the owner entered for a spot; the model only warns when a stop falls outside them. */
export interface AllowedHours {
  days: boolean[];
  open_minute: number;
  close_minute: number;
}

/** The owner's terms at a spot: visibility, host and fee. */
export interface SpotTerms {
  spot_id: string | null;
  visibility: Visibility;
  host: Host | null;
  fee_flat: number;
  fee_pct: number;
  fee_min: number;
  allowed: AllowedHours | null;
}

/** The vectors that describe a location; within and points_used are null when decoded from 50 stored numbers. */
export interface LocationVectors {
  capture: { day: SegmentVector; eve: SegmentVector };
  nearby: SegmentVector;
  within: SegmentVector | null;
  rivals: RegimePair;
  visibility: Visibility;
  in_region: boolean;
  region_id: string | null;
  exclusion: Exclusion;
  excluded_amount: number;
  points_used: number | null;
  dataset_version: string | null;
  model_version: ModelVersion;
}

// -------------------------------------------------------------------------------------------------
// Dates and day context
// -------------------------------------------------------------------------------------------------

/** One forecast hour; precip_prob is 0..100, wind_mph the largest number in the service's wind text. */
export interface HourForecast {
  hour: number;
  temp_f: number | null;
  precip_prob: number | null;
  short_forecast: string | null;
  wind_mph: number | null;
}

/** A federal holiday with its actual and observed dates (observed is null when there is no day in lieu). */
export interface Holiday {
  id: string;
  name: string;
  class: HolidayClass;
  date: string;
  observed: string | null;
}

/** Everything the model needs to know about one civil date (or one day of a typical week). */
export interface DayContext {
  date: string | null;
  typical: boolean;
  dow: number;
  eff_dow: number;
  holiday: Holiday | null;
  holiday_class: HolidayClass | null;
  treat_as: TreatAs | null;
  day_type: DayType[];
  dow_factor: number[];
  traffic_dow: number;
  forecast: (HourForecast | null)[] | null;
  fuel_price_per_gal: number | null;
  fuel_price_source: FuelPriceSource | null;
}

/** presence[s][h] and intent[s][h] for the 24 clock hours of one context (or the 168 of a typical week). */
export interface HourWeights {
  presence: number[][];
  intent: number[][];
}

// -------------------------------------------------------------------------------------------------
// Calibration and evidence
// -------------------------------------------------------------------------------------------------

/** What the logs say about one spot. */
export interface CalibrationSpot {
  factor: number;
  log_factor: number;
  n: number;
  weight: number;
}

/** What the owner's logged services say: one factor for the truck, one per spot. */
export interface CalibrationState {
  model_version: string;
  seeds_revision: number;
  as_of: string;
  truck_factor: number;
  truck_log_factor: number;
  bias_log: number;
  truck_n: number;
  truck_weight: number;
  spots: Record<string, CalibrationSpot>;
  resid_sd: number | null;
  resid_n: number;
  resid_weight: number;
}

/** How far to trust an estimate: log weights and the shares of weak inputs. */
export interface Evidence {
  truck_weight: number;
  spot_weight: number;
  resid_sd: number | null;
  resid_weight: number;
  weak_share: number;
  default_size_share: number;
  event: boolean;
  fixed: boolean;
}

/** The parts of an estimate's spread, in natural-log variance units (sigma values are standard deviations). */
export interface Spread {
  sigma_model: number;
  sigma: number;
  v_truck: number;
  v_spot: number;
  v_day: number;
  v_weak: number;
  v_size: number;
  v_event: number;
  v_count: number;
}

// -------------------------------------------------------------------------------------------------
// Demand and orders
// -------------------------------------------------------------------------------------------------

/** One segment's row of an hour: who is here, who buys, what the truck wins. */
export interface HourSegment {
  segment: SegmentKey;
  nearby_present: number;
  within_present: number | null;
  capture: number;
  presence: number;
  intent: number;
  demand_raw: number;
  before_cap: number;
  orders: number;
}

/** The host's row of an hour. */
export interface HourHost {
  segment: SegmentKey;
  mode: HostMode;
  size: number;
  share: number;
  people_present: number;
  presence: number;
  intent: number;
  demand_raw: number;
  weather: number;
  before_cap: number;
  orders: number;
}

/** The weather multiplier of one forecast hour and its parts. */
export interface WeatherDetail {
  multiplier: number;
  missing: boolean;
  temp: number;
  precip: number;
  wind: number;
  temp_band: string | null;
  precip_class: string | null;
  precip_p: number | null;
  wind_band: string | null;
}

/** The factors applied to an hour's demand. */
export interface HourFactors {
  menu_fit: number;
  weather_open: number;
  weather_captive: number;
  weather_state: WeatherState;
  weather_detail: WeatherDetail | null;
  weather_detail_captive: WeatherDetail | null;
  truck_factor: number;
  spot_factor: number;
}

/** Expected orders in one clock hour, with every step of the breakdown. */
export interface HourResult {
  date: string | null;
  hour: number;
  how: number;
  regime: Regime;
  daypart: Daypart;
  segments: HourSegment[];
  host: HourHost | null;
  factors: HourFactors;
  demand_raw: number;
  demand_adj: number;
  capacity: number;
  orders: number;
  capped: boolean;
  weak_part: number;
  default_size_part: number;
}

/** One clock hour of a window: which civil date (0 = the service date, 1 = the next), and the share served. */
export interface WindowHour {
  day_index: 0 | 1;
  hour: number;
  fraction: number;
  result: HourResult;
}

/** Expected orders over a service window [open, close). */
export interface WindowResult {
  date: string | null;
  open_minute: number;
  close_minute: number;
  minutes: number;
  hours: WindowHour[];
  orders: Estimate;
  by_segment: SegmentVector;
  host_orders: number;
  demand_adj: number;
  capacity_total: number;
  capped_hours: number;
  evidence: Evidence;
  spread: Spread;
}

/** One clock hour overlapped by a window: the loop of window_orders (day_index = whole days after the service date). */
export interface ClockHour {
  day_index: number;
  hour: number;
  start: number;
  end: number;
  fraction: number;
}

/** A run of consecutive hours picked by best_windows. */
export interface BestWindow {
  start: number;
  length: number;
  total: number;
}

/** The host's own people the truck would win per unit of presence and intent; mode is null without a host. */
export interface HostCapture {
  day: number;
  eve: number;
  share: RegimePair;
  mode: HostMode | null;
}

// -------------------------------------------------------------------------------------------------
// Money
// -------------------------------------------------------------------------------------------------

/** The money lines of a stop at one number of orders. */
export interface MoneyLines {
  orders: number;
  sales: number;
  food_cost: number;
  packaging: number;
  card_fees: number;
  spot_fee: number;
  tips: number;
  contribution: number;
}

/** Contribution per extra order: while the minimum fee is paid, and once flat plus percentage is. */
export interface UnitMargins {
  at_minimum: number;
  at_percentage: number;
}

/** The money lines of a stop as estimates, and its unit margins. */
export interface StopMoney {
  orders: Estimate;
  sales: Estimate;
  food_cost: Estimate;
  packaging: Estimate;
  card_fees: Estimate;
  spot_fee: Estimate;
  tips: Estimate;
  contribution: Estimate;
  unit_margin: UnitMargins;
}

/** The day's own costs. */
export interface DayCosts {
  labour: number;
  fuel: number;
  tolls: number;
  fixed: number;
  total: number;
  paid_hours: number;
  drive_gallons: number;
  generator_gallons: number;
}

// -------------------------------------------------------------------------------------------------
// Driving and timeline
// -------------------------------------------------------------------------------------------------

/** A drive leg as the backend hands it over: Google's traffic-unaware duration, or the straight-line fallback. */
export interface LegInput {
  source: LegSource;
  distance_m: number;
  duration_s: number;
  override_minutes: number | null;
  toll: number;
}

/** Map of drive legs keyed "<from_id>><to_id>"; ids are stop ids and the literal "base". */
export type LegMap = Record<string, LegInput>;

/** An evaluated leg; from_id, to_id and depart_minute are null in a bare leg_minutes result. */
export interface Leg {
  from_id: string | null;
  to_id: string | null;
  source: LegResultSource;
  distance_m: number;
  miles: number;
  base_minutes: number;
  depart_minute: number | null;
  traffic_lookup_minute: number;
  traffic_dow: number;
  traffic_hour: number;
  traffic_factor: number;
  time_factor: number;
  truck_time_factor: number;
  raw_minutes: number;
  minutes: number;
  toll: number;
}

/** One moment of the day; stop_index is null for the events of the day itself. */
export interface TimelineEvent {
  kind: TimelineEventKind;
  minute: number;
  stop_index: number | null;
}

/** One stop on the clock; open and close are the owner's entered minutes, never moved. */
export interface TimelineStop {
  stop_index: number;
  arrive: number;
  setup_start: number;
  open: number;
  effective_open: number;
  close: number;
  leave: number;
  gap_before_minutes: number;
  gap_unpaid: boolean;
  late_minutes: number;
}

/** The day's clock in whole minutes; the four day marks are null for an empty plan. */
export interface Timeline {
  events: TimelineEvent[];
  stops: TimelineStop[];
  legs: Leg[];
  start_prep: number | null;
  leave_base: number | null;
  back_at_base: number | null;
  done: number | null;
  day_minutes: number;
  paid_minutes: number;
  unpaid_gap_minutes: number;
  drive_minutes: number;
  service_minutes: number;
  generator_minutes: number;
  miles: number;
  tolls: number;
}

// -------------------------------------------------------------------------------------------------
// Events, catering, plans
// -------------------------------------------------------------------------------------------------

/** Terms of an attendance-based stop; vendors counts every food vendor including this truck. */
export interface EventTerms {
  attendance: number;
  vendors: number;
  event_type: EventType;
}

/** One clock hour of an event stop; day_index 0 = the service date, 1 = the next. */
export interface EventHour {
  day_index: 0 | 1;
  hour: number;
  fraction: number;
  demand: number;
  capacity: number;
  weather: number;
  orders: number;
}

/** An attendance-based estimate. */
export interface EventResult {
  orders: Estimate;
  buyers: number;
  demand: number;
  hours: EventHour[];
  spread: Spread;
}

/** Terms of a contracted stop. */
export interface CateringTerms {
  headcount: number;
  price_per_head: number | null;
  guarantee: number | null;
  food_cost: number | null;
}

/** One planned stop; id is unique in the plan and never "base"; terms is required for spot and event. */
export interface StopInput {
  id: string;
  kind: StopKind;
  spot_id: string | null;
  point: LatLng;
  open_minute: number;
  close_minute: number;
  gap_before_unpaid: boolean;
  setup_minutes: number | null;
  teardown_minutes: number | null;
  terms: SpotTerms | null;
  vectors: LocationVectors | null;
  event: EventTerms | null;
  catering: CateringTerms | null;
}

/** The part of a stop the timeline reads. */
export type TimelineStopInput = Pick<
  StopInput,
  'id' | 'point' | 'open_minute' | 'close_minute' | 'gap_before_unpaid' | 'setup_minutes' | 'teardown_minutes'
>;

/** A day as the owner ordered it. */
export interface PlanInput {
  date: string;
  stops: StopInput[];
}

/** What a stop adds: the whole day with it minus the whole day without it. */
export interface StopAdds {
  take_home: Estimate;
  hours: number;
  per_hour: Estimate | null;
  added_costs: number;
  break_even_orders: number | null;
  uses_fallback_leg: boolean;
}

/** The event part of an evaluated event stop. */
export interface DayStopEvent {
  buyers: number;
  demand: number;
  hours: EventHour[];
  spread: Spread;
}

/** An evaluated stop before "what it adds" is known (the stops of the internal evaluate result). */
export interface EvaluatedStop {
  stop_index: number;
  id: string;
  kind: StopKind;
  spot_id: string | null;
  window: WindowResult | null;
  event: DayStopEvent | null;
  orders: Estimate;
  money: StopMoney;
}

/** An evaluated stop; window is null unless kind is spot, event is null unless kind is event. */
export interface DayStop extends EvaluatedStop {
  adds: StopAdds;
}

/** The day's totals. */
export interface DayTotals {
  orders: Estimate;
  sales: Estimate;
  food_cost: Estimate;
  packaging: Estimate;
  card_fees: Estimate;
  spot_fees: Estimate;
  tips: Estimate;
  contribution: Estimate;
  labour: Estimate;
  fuel: Estimate;
  tolls: Estimate;
  fixed_cost: Estimate;
  take_home: Estimate;
  take_home_per_hour: Estimate;
  day_hours: number;
  paid_hours: number;
  work_hours: number;
  unpaid_gap_hours: number;
  drive_minutes: number;
  miles: number;
  drive_gallons: number;
  generator_gallons: number;
}

/** The data each warning code carries (02_MODEL 4.12). */
export interface WarningDataMap {
  invalid_window: { open_minute: number; close_minute: number };
  stops_overlap: { open_minute: number; previous_close_minute: number };
  stop_unreachable: { arrive: number; effective_open: number; close_minute: number };
  late_arrival: { late_minutes: number; effective_open: number };
  outside_region: Record<string, never>;
  stale_vectors: Record<string, never>;
  outside_allowed_hours: { dow: number; open_minute: number; close_minute: number };
  fallback_drive_time: { legs: string[] };
  long_gap: { gap_before_minutes: number };
  long_day: { day_minutes: number };
  fee_high: { spot_fee: number; sales: number };
  below_break_even: { take_home: number };
  event_thin_crowd: { attendees_per_vendor: number };
  weak_day_loss: { take_home_low: number };
  capacity_bound: { capped_hours: number };
  early_start: { start_prep: number };
  ends_after_midnight: { done: number };
  no_forecast: { hours: number };
  holiday: { holiday_id: string | null; holiday_class: HolidayClass };
  weak_seed: { weak_share: number };
  default_host_size: { size: number };
}

/** A plan warning of one code; stop_index is null for a warning about the day. */
export interface WarningOf<C extends WarningCode> {
  code: C;
  level: WarningLevel;
  stop_index: number | null;
  data: WarningDataMap[C];
}

/** A plan warning (a union over the 21 codes, so `code` selects the type of `data`). */
export type Warning = { [C in WarningCode]: WarningOf<C> }[WarningCode];

/** What the day would clear if every paid gap were an unpaid break. */
export interface UnpaidGapAlternative {
  take_home: Estimate;
  take_home_per_hour: Estimate;
  work_hours: number;
  labour_saved: number;
}

/** A day evaluated as the owner ordered it. */
export interface DayResult {
  model_version: string;
  seeds_revision: number;
  date: string;
  timeline: Timeline;
  stops: DayStop[];
  totals: DayTotals;
  unpaid_gap_alternative: UnpaidGapAlternative | null;
  warnings: Warning[];
}

/** The internal result of evaluate: one whole day as planned, before adds and warnings. */
export interface EvaluateResult {
  timeline: Timeline;
  stops: EvaluatedStop[];
  costs: DayCosts;
  totals: DayTotals;
  take_home: Estimate;
  take_home_per_hour: Estimate;
  work_hours: number;
}

// -------------------------------------------------------------------------------------------------
// Suggestions and scouting
// -------------------------------------------------------------------------------------------------

/** A saved spot as the suggestion functions read it. */
export interface SpotInput {
  spot_id: string;
  point: LatLng;
  terms: SpotTerms;
  vectors: LocationVectors;
}

/** Limits of a suggestion request; a null option takes its seed default. */
export interface SuggestOptions {
  service_minutes?: number | null;
  max_stops_per_day?: number | null;
  max_days_per_week?: number | null;
  max_visits_per_spot_per_week?: number | null;
  limit?: number | null;
}

/** One stop of a suggested day. */
export interface SuggestionStop {
  spot_id: string;
  open_minute: number;
  close_minute: number;
}

/** A suggested day plan with its rank (position 1 = best) and the full day result. */
export interface Suggestion {
  date: string;
  position: number;
  stops: SuggestionStop[];
  take_home: Estimate;
  orders: Estimate;
  day_minutes: number;
  result: DayResult;
}

/** One day of a suggested week; suggestion is null for a day off. */
export interface WeekDay {
  date: string;
  suggestion: Suggestion | null;
}

/** The best week found from the day suggestions. */
export interface WeekSuggestion {
  week_start: string;
  days: WeekDay[];
  total_take_home: Estimate;
  visits: Record<string, number>;
  leaves_visited: number;
}

/** A candidate host as scouting reads it; vectors are decoded from tp_places.host_vec. */
export interface PlaceInput {
  place_id: string;
  place_type: string;
  point: LatLng;
  point_id: string | null;
  size_default: number;
  kitchen: KitchenState | null;
  vectors: LocationVectors;
}

/** The best window of a scouted place in a typical week. */
export interface ScoutBestWindow {
  dow: number;
  open_minute: number;
  close_minute: number;
}

/** The drive from the base to a scouted place and back. */
export interface RoundTrip {
  minutes: number;
  miles: number;
  cost: number;
}

/** One scouted place; the score ranks and is never shown as money. */
export interface ScoutResult {
  place_id: string;
  place_type: string;
  position: number;
  host_fit: number;
  kitchen: 'yes' | 'no';
  host_segment: SegmentKey | null;
  host_size: number;
  size_source: 'default';
  best_window: ScoutBestWindow | null;
  orders: Estimate;
  contribution: Estimate;
  round_trip: RoundTrip;
  score: number;
}

// -------------------------------------------------------------------------------------------------
// Service log and accuracy
// -------------------------------------------------------------------------------------------------

/** One logged service with the prediction it is judged against. */
export interface ServiceLogEntry {
  service_id: string;
  kind: StopKind;
  spot_id: string | null;
  date: string;
  open_minute: number;
  close_minute: number;
  actual: number;
  sold_out: boolean;
  predicted_raw: number;
  predicted: number;
  low: number;
  high: number;
}

/** Accuracy metrics over a set of logged services; the metrics are null when nothing can be scored. */
export interface AccuracyBlock {
  n_total: number;
  n_scored: number;
  n_sold_out: number;
  bias: number | null;
  mape: number | null;
  coverage: number | null;
  raw_bias: number | null;
  raw_mape: number | null;
}

/** The accuracy block of one spot. */
export interface SpotAccuracyBlock extends AccuracyBlock {
  spot_id: string;
}

/** How the estimates did against the logged services, overall and per spot (spots ascending). */
export interface AccuracyReport extends AccuracyBlock {
  by_spot: SpotAccuracyBlock[];
}

// -------------------------------------------------------------------------------------------------
// Map fast path (02_MODEL 4.17)
// -------------------------------------------------------------------------------------------------

/** Per hour and segment: w_opp turns a capture vector into expected orders, w_people a nearby vector into people present. */
export interface MapWeightRows {
  w_opp: number[][];
  w_people: number[][];
}

/** Scores of n map cells for one hour, one array per layer. */
export interface CellScores {
  opportunity: number[];
  people: number[];
  competition: number[];
}

/** The three map layers. */
export type MapLayer = 'opportunity' | 'people' | 'competition';

/** The fixed top of each layer's colour scale: the same for every hour, region and truck. */
export interface MapDomain {
  opportunity: number;
  people: number;
  competition: number;
}

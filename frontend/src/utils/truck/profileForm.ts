// Truck Planner - the Settings drafts (docs/truck-planner/05_FRONTEND.md 4.9).
//
// Pure functions, no I/O, no clock, no React. Two drafts live here:
//
//   1. "Truck and costs": the truck profile (route 3). Every valid range is read from the seed file
//      (`profile_defaults.<field>.min` and `.max`), which is what the server enforces; no range is
//      written in a component. A value outside its range is refused with the range message and
//      never clamped, and a save sends only the keys that changed.
//   2. "Assumptions": the owner's overrides of the owner-scope seeds (routes 5 and 6). The bounds
//      are the inherited `min` and `max` of each seed; the merged draft goes through the model's
//      own `validateOverrides` before it is sent, and only the changed paths travel.

import type { FuelInfo, OverrideChanges, ProfilePatch, TruckProfileX } from '../../api/truck';
import {
  DAYPARTS,
  DAY_TYPES,
  SEEDS,
  SEGMENTS,
  dayCosts,
  emptyTimeline,
  roundHalfAway,
  unitMargins,
  validateOverrides,
} from './model';
import type {
  Assumptions,
  DayType,
  Daypart,
  FuelType,
  OverrideMap,
  PlaceType,
  SeedEntry,
  SeedFile,
  SeedOverrideValue,
  SeedScope,
  SeedTag,
  SegmentKey,
  SpotTerms,
} from './model';
import { seedInfo } from './breakdown';
import { fmtCount, fmtDay, fmtMoneyCents, fmtPlain } from './format';
import {
  DAYPART_LABELS,
  DAY_TYPE_LABELS,
  EVENT_TYPE_LABELS,
  FIELD,
  PLACEHOLDER_SEED,
  TEMPERATURE_BAND_LABELS,
  WIND_BAND_LABELS,
  numberRangeMessage,
  overrideErrorMessage,
  placeTypeLabel,
  precipClassWord,
  seedLabel,
  segmentLabel,
} from './wording';

// -------------------------------------------------------------------------------------------------
// 1. Truck and costs
// -------------------------------------------------------------------------------------------------

/** The profile fields that are one number with a range in the seed file. */
export const PROFILE_NUMBER_KEYS = [
  'avg_ticket',
  'capacity_orders_per_hour',
  'paid_crew',
  'wage_per_hour',
  'payroll_burden_pct',
  'food_cost_pct',
  'packaging_per_order',
  'card_fee_pct',
  'card_fee_fixed',
  'card_share',
  'tips_pct_of_card_sales',
  'mpg',
  'generator_gal_per_hour',
  'prep_minutes',
  'setup_minutes',
  'teardown_minutes',
  'closeout_minutes',
  'fixed_cost_per_service_day',
  'truck_time_factor',
  'scout_drive_minutes_limit',
] as const;

export type ProfileNumberKey = (typeof PROFILE_NUMBER_KEYS)[number];

/** Whole numbers in the profile shape (02_MODEL section 3): people and minutes. */
const WHOLE_NUMBER_KEYS: readonly ProfileNumberKey[] = [
  'paid_crew',
  'prep_minutes',
  'setup_minutes',
  'teardown_minutes',
  'closeout_minutes',
  'scout_drive_minutes_limit',
];

/** The range of a number field, as the seed file gives it. */
export interface NumberRule {
  min: number;
  max: number;
  integer: boolean;
}

function entryRule(entry: { min?: number; max?: number }, path: string, integer: boolean): NumberRule {
  if (typeof entry.min !== 'number' || typeof entry.max !== 'number') {
    // Every numeric profile seed carries both bounds; a seed file without them is a build error.
    throw new Error('seed without a range: ' + path);
  }
  return { min: entry.min, max: entry.max, integer };
}

/** `profile_defaults.<key>.min` and `.max`: the range the form enforces and the server checks. */
export function profileRule(key: ProfileNumberKey): NumberRule {
  return entryRule(SEEDS.profile_defaults[key], 'profile_defaults.' + key, WHOLE_NUMBER_KEYS.includes(key));
}

/** The range of each of the four menu-fit values (`profile_defaults.daypart_fit`). */
export function daypartRule(): NumberRule {
  return entryRule(SEEDS.profile_defaults.daypart_fit, 'profile_defaults.daypart_fit', false);
}

/**
 * The owner's own fuel price is the one profile number without a seed: it replaces a price, it is
 * not an assumption. Its range is the server's (04_BACKEND 4.4).
 */
export const FUEL_PRICE_OVERRIDE_RULE: NumberRule = { min: 0.5, max: 20, integer: false };

/** Text limits of the profile (04_BACKEND 4.4). */
export const PROFILE_TEXT_LIMITS = { nameMax: 120, addressMax: 255, countiesMax: 60 } as const;

/**
 * "Drives take this much longer than in a car" shows the factor as the extra share: 1.10 is 10 %.
 * The field therefore holds factor - 1, and so do its bounds, which still come from the seed.
 */
export function extraShareOfFactor(factor: number): number {
  return roundHalfAway(factor - 1, 9);
}

export function factorOfExtraShare(share: number): number {
  return roundHalfAway(1 + share, 9);
}

export function extraShareRule(): NumberRule {
  const rule = profileRule('truck_time_factor');
  return { min: extraShareOfFactor(rule.min), max: extraShareOfFactor(rule.max), integer: false };
}

/** The profile as the form holds it: a number field is null while it is empty. */
export interface ProfileDraft {
  name: string;
  base: { lat: number; lng: number; address: string };
  avg_ticket: number | null;
  capacity_orders_per_hour: number | null;
  paid_crew: number | null;
  wage_per_hour: number | null;
  payroll_burden_pct: number | null;
  food_cost_pct: number | null;
  packaging_per_order: number | null;
  card_fee_pct: number | null;
  card_fee_fixed: number | null;
  card_share: number | null;
  tips_include: boolean;
  tips_pct_of_card_sales: number | null;
  mpg: number | null;
  fuel_type: FuelType;
  fuel_price_override: number | null;
  generator_gal_per_hour: number | null;
  prep_minutes: number | null;
  setup_minutes: number | null;
  teardown_minutes: number | null;
  closeout_minutes: number | null;
  fixed_cost_per_service_day: number | null;
  daypart_fit: Record<Daypart, number | null>;
  avoid_tolls: boolean;
  avoid_highways: boolean;
  truck_time_factor: number | null;
  licence_counties: string[];
  scout_drive_minutes_limit: number | null;
}

export function profileDraftOf(profile: TruckProfileX): ProfileDraft {
  return {
    name: profile.name,
    base: { lat: profile.base.lat, lng: profile.base.lng, address: profile.base.address },
    avg_ticket: profile.avg_ticket,
    capacity_orders_per_hour: profile.capacity_orders_per_hour,
    paid_crew: profile.paid_crew,
    wage_per_hour: profile.wage_per_hour,
    payroll_burden_pct: profile.payroll_burden_pct,
    food_cost_pct: profile.food_cost_pct,
    packaging_per_order: profile.packaging_per_order,
    card_fee_pct: profile.card_fee_pct,
    card_fee_fixed: profile.card_fee_fixed,
    card_share: profile.card_share,
    tips_include: profile.tips_include,
    tips_pct_of_card_sales: profile.tips_pct_of_card_sales,
    mpg: profile.mpg,
    fuel_type: profile.fuel_type,
    fuel_price_override: profile.fuel_price_override,
    generator_gal_per_hour: profile.generator_gal_per_hour,
    prep_minutes: profile.prep_minutes,
    setup_minutes: profile.setup_minutes,
    teardown_minutes: profile.teardown_minutes,
    closeout_minutes: profile.closeout_minutes,
    fixed_cost_per_service_day: profile.fixed_cost_per_service_day,
    daypart_fit: {
      breakfast: profile.daypart_fit.breakfast,
      lunch: profile.daypart_fit.lunch,
      dinner: profile.daypart_fit.dinner,
      late: profile.daypart_fit.late,
    },
    avoid_tolls: profile.avoid_tolls,
    avoid_highways: profile.avoid_highways,
    truck_time_factor: profile.truck_time_factor,
    licence_counties: profile.licence_counties.slice(),
    scout_drive_minutes_limit: profile.scout_drive_minutes_limit,
  };
}

/** Why a number is refused, or null when it may be saved. The bounds are printed as the field shows them. */
export function numberProblem(value: number | null, rule: NumberRule, shownScale = 1): string | null {
  if (value === null) return FIELD.required;
  const whole = rule.integer && Math.floor(value) !== value;
  if (!(value === value) || value < rule.min || value > rule.max || whole) {
    return numberRangeMessage(roundHalfAway(rule.min * shownScale, 6), roundHalfAway(rule.max * shownScale, 6), rule.integer);
  }
  return null;
}

/** The fields shown as a percent: a range message prints their bounds times 100. */
const PERCENT_KEYS: readonly ProfileNumberKey[] = [
  'payroll_burden_pct',
  'food_cost_pct',
  'card_fee_pct',
  'card_share',
  'tips_pct_of_card_sales',
];

/**
 * Every reason the draft cannot be saved, by field (`daypart_fit.lunch` for a menu-fit value). The
 * ranges are the seed file's. Nothing is moved into range: a refused value stays what the owner
 * typed and the save is held back.
 */
export function validateProfileDraft(draft: ProfileDraft): Record<string, string> {
  const errors: Record<string, string> = {};
  const name = draft.name.trim();
  if (name === '') errors.name = FIELD.required;
  else if (name.length > PROFILE_TEXT_LIMITS.nameMax) errors.name = 'Use at most ' + fmtCount(PROFILE_TEXT_LIMITS.nameMax) + ' characters.';
  const base = draft.base;
  if (!(base.lat >= -90 && base.lat <= 90 && base.lng >= -180 && base.lng <= 180)) {
    errors.base = 'Enter latitude and longitude, like 38.9696, -77.3861.';
  } else if (base.address.trim().length > PROFILE_TEXT_LIMITS.addressMax) {
    errors.base = 'Use at most ' + fmtCount(PROFILE_TEXT_LIMITS.addressMax) + ' characters for the address.';
  }
  for (const key of PROFILE_NUMBER_KEYS) {
    const problem =
      key === 'truck_time_factor'
        ? numberProblem(draft[key] === null ? null : extraShareOfFactor(draft[key] as number), extraShareRule(), 100)
        : numberProblem(draft[key], profileRule(key), PERCENT_KEYS.includes(key) ? 100 : 1);
    if (problem !== null) errors[key] = problem;
  }
  for (const part of DAYPARTS) {
    const problem = numberProblem(draft.daypart_fit[part], daypartRule(), 100);
    if (problem !== null) errors['daypart_fit.' + part] = problem;
  }
  if (draft.fuel_price_override !== null) {
    const problem = numberProblem(draft.fuel_price_override, FUEL_PRICE_OVERRIDE_RULE);
    if (problem !== null) errors.fuel_price_override = problem;
  }
  if (draft.licence_counties.length > PROFILE_TEXT_LIMITS.countiesMax) {
    errors.licence_counties = 'Tick at most ' + fmtCount(PROFILE_TEXT_LIMITS.countiesMax) + ' counties.';
  }
  return errors;
}

function sameCounties(a: readonly string[], b: readonly string[]): boolean {
  if (a.length !== b.length) return false;
  const left = a.slice().sort();
  const right = b.slice().sort();
  return left.every((fips, i) => fips === right[i]);
}

/**
 * The body of route 3 for a later save: only the keys whose value differs from the saved profile.
 * `base` travels whole when any part of it changed (the server wants latitude and longitude
 * together); `daypart_fit` carries only its changed keys. A number field left empty is not sent:
 * `validateProfileDraft` reports it instead. An empty object means there is nothing to save.
 */
export function profilePatch(saved: TruckProfileX, draft: ProfileDraft): ProfilePatch {
  const patch: ProfilePatch = {};
  if (draft.name.trim() !== saved.name) patch.name = draft.name.trim();
  if (draft.base.lat !== saved.base.lat || draft.base.lng !== saved.base.lng || draft.base.address.trim() !== saved.base.address) {
    patch.base = { lat: draft.base.lat, lng: draft.base.lng, address: draft.base.address.trim() };
  }
  for (const key of PROFILE_NUMBER_KEYS) {
    const value = draft[key];
    if (value !== null && value !== saved[key]) patch[key] = value;
  }
  const fit: Partial<Record<Daypart, number>> = {};
  for (const part of DAYPARTS) {
    const value = draft.daypart_fit[part];
    if (value !== null && value !== saved.daypart_fit[part]) fit[part] = value;
  }
  if (Object.keys(fit).length > 0) patch.daypart_fit = fit;
  if (draft.tips_include !== saved.tips_include) patch.tips_include = draft.tips_include;
  if (draft.avoid_tolls !== saved.avoid_tolls) patch.avoid_tolls = draft.avoid_tolls;
  if (draft.avoid_highways !== saved.avoid_highways) patch.avoid_highways = draft.avoid_highways;
  if (draft.fuel_type !== saved.fuel_type) patch.fuel_type = draft.fuel_type;
  if (draft.fuel_price_override !== saved.fuel_price_override) patch.fuel_price_override = draft.fuel_price_override;
  if (!sameCounties(draft.licence_counties, saved.licence_counties)) patch.licence_counties = draft.licence_counties.slice().sort();
  return patch;
}

/** True when the draft differs from the saved profile in any way, an emptied number field included. */
export function profileDraftDirty(saved: TruckProfileX, draft: ProfileDraft): boolean {
  if (Object.keys(profilePatch(saved, draft)).length > 0) return true;
  for (const key of PROFILE_NUMBER_KEYS) if (draft[key] === null) return true;
  for (const part of DAYPARTS) if (draft.daypart_fit[part] === null) return true;
  return false;
}

/**
 * The draft as a profile, for the preview that is computed from the draft. Null while any number is
 * missing or out of range: nothing is estimated from a profile the server would refuse.
 */
export function profileFromDraft(saved: TruckProfileX, draft: ProfileDraft): TruckProfileX | null {
  if (Object.keys(validateProfileDraft(draft)).length > 0) return null;
  const numbers = {} as Record<ProfileNumberKey, number>;
  for (const key of PROFILE_NUMBER_KEYS) {
    const value = draft[key];
    if (value === null) return null;
    numbers[key] = value;
  }
  const fit = {} as Record<Daypart, number>;
  for (const part of DAYPARTS) {
    const value = draft.daypart_fit[part];
    if (value === null) return null;
    fit[part] = value;
  }
  return {
    ...saved,
    ...numbers,
    name: draft.name.trim(),
    base: { lat: draft.base.lat, lng: draft.base.lng, address: draft.base.address.trim() },
    tips_include: draft.tips_include,
    fuel_type: draft.fuel_type,
    fuel_price_override: draft.fuel_price_override,
    daypart_fit: fit,
    avoid_tolls: draft.avoid_tolls,
    avoid_highways: draft.avoid_highways,
    licence_counties: draft.licence_counties.slice(),
  };
}

const NO_FEE_TERMS: SpotTerms = {
  spot_id: null,
  visibility: 'normal',
  host: null,
  fee_flat: 0,
  fee_pct: 0,
  fee_min: 0,
  allowed: null,
};

/** The four figures of "What these settings mean". */
export interface SettingsMeaning {
  /** What one order leaves after food, packaging and card fees (no spot fee). */
  perOrder: number;
  crewPerPaidHour: number;
  /** Null when no fuel price is known for the draft's fuel. */
  fuelPerMile: number | null;
  generatorPerHour: number | null;
}

/**
 * What the profile means in dollars. Each figure is the model's own arithmetic on a one-unit day:
 * `unitMargins` without a fee, and `dayCosts` for one paid hour, one mile and one generator hour.
 */
export function settingsMeaning(profile: TruckProfileX, fuelPricePerGal: number | null): SettingsMeaning {
  const hour = { ...emptyTimeline(), paid_minutes: 60 };
  const crew = dayCosts(profile, hour, 0).labour;
  let fuelPerMile: number | null = null;
  let generatorPerHour: number | null = null;
  if (fuelPricePerGal !== null) {
    fuelPerMile = dayCosts(profile, { ...emptyTimeline(), miles: 1 }, fuelPricePerGal).fuel;
    generatorPerHour = dayCosts(profile, { ...emptyTimeline(), generator_minutes: 60 }, fuelPricePerGal).fuel;
  }
  return { perOrder: unitMargins(profile, NO_FEE_TERMS).at_minimum, crewPerPaidHour: crew, fuelPerMile, generatorPerHour };
}

/**
 * The fuel price a draft is previewed with: the owner's own price when the draft has one, else the
 * current price when it is for the draft's fuel. Null when the draft changes the fuel and gives no
 * price: the price of the other fuel is only known after the save.
 */
export function previewFuelPrice(saved: TruckProfileX, draft: ProfileDraft, fuel: FuelInfo): number | null {
  if (draft.fuel_price_override !== null) return draft.fuel_price_override;
  if (draft.fuel_type !== saved.fuel_type) return null;
  if (saved.fuel_price_override !== null) return null; // the current price is the owner's old one, which the draft drops
  return fuel.price_per_gal;
}

/** The label of each profile field: the form and the list of starting values print the same words. */
export const PROFILE_LABELS: Readonly<Record<ProfileNumberKey | 'tips_include' | 'fuel_type' | 'avoid_tolls' | 'avoid_highways', string>> = {
  avg_ticket: 'Average ticket',
  capacity_orders_per_hour: 'Orders per hour at full speed',
  paid_crew: 'Paid crew',
  wage_per_hour: 'Wage per hour',
  payroll_burden_pct: 'Payroll taxes and extras',
  food_cost_pct: 'Food cost',
  packaging_per_order: 'Packaging per order',
  card_fee_pct: 'Card fee',
  card_fee_fixed: 'plus, per card order',
  card_share: 'Share of sales paid by card',
  tips_include: 'Count tips as take-home',
  tips_pct_of_card_sales: 'Tips as a share of card sales',
  mpg: 'Miles per gallon',
  fuel_type: 'Fuel',
  generator_gal_per_hour: 'Generator fuel per hour',
  prep_minutes: 'Prep before leaving',
  setup_minutes: 'Setup at a stop',
  teardown_minutes: 'Pack-up at a stop',
  closeout_minutes: 'Close-out back at base',
  fixed_cost_per_service_day: 'Fixed cost per service day',
  avoid_tolls: 'Avoid toll roads',
  avoid_highways: 'Avoid highways',
  truck_time_factor: 'Drives take this much longer than in a car',
  scout_drive_minutes_limit: 'Longest drive for Scout',
};

export const FUEL_TYPE_LABELS: Readonly<Record<FuelType, string>> = { gasoline: 'Gasoline', diesel: 'Diesel' };

/** The fuel as the price line names it, by the product code of the price (`FuelInfo.product`). */
export function fuelProductLabel(product: FuelInfo['product']): string {
  return product === 'EPD2D' ? 'Diesel' : 'Regular gasoline';
}

/** Where the current fuel price comes from, as on Today. */
export function fuelSourceLine(fuel: FuelInfo): string {
  if (fuel.source === 'owner') return 'Your price';
  if (fuel.source === 'eia') return fuel.period === null ? 'EIA weekly average' : 'EIA weekly average, week of ' + fmtDay(fuel.period, 'short');
  return fuel.period === null
    ? 'Default price. No current figure.'
    : 'Default price from the week of ' + fmtDay(fuel.period, 'short') + '. No current figure.';
}

/** How each money-like profile seed prints in the list of starting values. */
const MONEY_KEYS: readonly string[] = ['avg_ticket', 'wage_per_hour', 'packaging_per_order', 'card_fee_fixed', 'fixed_cost_per_service_day'];

export interface StartingValue {
  key: string;
  label: string;
  value: string;
  unit: string;
  tag: SeedTag;
  source: string;
}

function startingText(key: string, value: unknown): string {
  if (typeof value === 'boolean') return value ? 'On' : 'Off';
  if (typeof value === 'string') return Object.prototype.hasOwnProperty.call(FUEL_TYPE_LABELS, value) ? FUEL_TYPE_LABELS[value as FuelType] : value;
  if (typeof value !== 'number') return '';
  if (MONEY_KEYS.includes(key)) return fmtMoneyCents(value);
  if ((PERCENT_KEYS as readonly string[]).includes(key)) return fmtPlain(roundHalfAway(value * 100, 6), 2) + '%';
  if (key === 'truck_time_factor') return fmtPlain(roundHalfAway(extraShareOfFactor(value) * 100, 6), 2) + '%';
  return fmtPlain(value, 3);
}

/** Every `profile_defaults` seed with its value, unit, tag and source note, in the order of the form. */
export function startingValues(): StartingValue[] {
  const defaults = SEEDS.profile_defaults;
  const out: StartingValue[] = [];
  const push = (key: string, label: string, entry: SeedEntry<unknown>) => {
    out.push({ key, label, value: startingText(key, entry.value), unit: entry.unit, tag: entry.tag, source: entry.source });
  };
  push('avg_ticket', PROFILE_LABELS.avg_ticket, defaults.avg_ticket);
  push('capacity_orders_per_hour', PROFILE_LABELS.capacity_orders_per_hour, defaults.capacity_orders_per_hour);
  for (const part of DAYPARTS) {
    out.push({
      key: 'daypart_fit.' + part,
      label: 'Menu fit: ' + DAYPART_LABELS[part],
      value: fmtPlain(roundHalfAway(defaults.daypart_fit[part] * 100, 6), 2) + '%',
      unit: defaults.daypart_fit.unit,
      tag: defaults.daypart_fit.tag,
      source: defaults.daypart_fit.source,
    });
  }
  const rest: (keyof typeof PROFILE_LABELS)[] = [
    'paid_crew',
    'wage_per_hour',
    'payroll_burden_pct',
    'food_cost_pct',
    'packaging_per_order',
    'card_fee_pct',
    'card_fee_fixed',
    'card_share',
    'tips_include',
    'tips_pct_of_card_sales',
    'mpg',
    'fuel_type',
    'generator_gal_per_hour',
    'truck_time_factor',
    'avoid_tolls',
    'avoid_highways',
    'prep_minutes',
    'setup_minutes',
    'teardown_minutes',
    'closeout_minutes',
    'fixed_cost_per_service_day',
    'scout_drive_minutes_limit',
  ];
  for (const key of rest) {
    // In the form this field sits beside "Card fee"; on its own line it needs the whole phrase.
    push(key, key === 'card_fee_fixed' ? 'Card fee per card order' : PROFILE_LABELS[key], defaults[key] as SeedEntry<unknown>);
  }
  return out;
}

// -------------------------------------------------------------------------------------------------
// 2. Assumptions
// -------------------------------------------------------------------------------------------------

/** A seed with the metadata it inherits from the nearest enclosing object that defines it (02_MODEL 2.1). */
export interface SeedMeta {
  path: string;
  /** The seed file's own value. */
  value: unknown;
  unit: string;
  tag: SeedTag | null;
  scope: SeedScope | null;
  min: number | null;
  max: number | null;
  allowed: string[] | null;
  source: string;
}

function isRecord(x: unknown): x is Record<string, unknown> {
  return typeof x === 'object' && x !== null && !Array.isArray(x);
}

const metaCache = new Map<string, SeedMeta | null>();

/** The metadata of one seed path, or null for a path the seed file does not have. */
export function seedMeta(path: string, seeds: SeedFile = SEEDS): SeedMeta | null {
  const cached = seeds === SEEDS ? metaCache.get(path) : undefined;
  if (cached !== undefined) return cached;
  const meta: SeedMeta = { path, value: null, unit: '', tag: null, scope: null, min: null, max: null, allowed: null, source: '' };
  const inherit = (node: Record<string, unknown>) => {
    if (typeof node.unit === 'string') meta.unit = node.unit;
    if (node.tag === 'measured' || node.tag === 'derived' || node.tag === 'assumed' || node.tag === 'tuned') meta.tag = node.tag;
    if (node.scope === 'build' || node.scope === 'fixed' || node.scope === 'owner' || node.scope === 'profile_default') meta.scope = node.scope;
    if (typeof node.min === 'number') meta.min = node.min;
    if (typeof node.max === 'number') meta.max = node.max;
    if (Array.isArray(node.allowed)) meta.allowed = node.allowed.filter((x): x is string => typeof x === 'string');
    if (typeof node.source === 'string') meta.source = node.source;
  };
  let node: unknown = seeds;
  let found = true;
  for (const key of path.split('.')) {
    if (!isRecord(node) || !Object.prototype.hasOwnProperty.call(node, key)) {
      found = false;
      break;
    }
    inherit(node);
    node = node[key];
  }
  let result: SeedMeta | null = null;
  if (found) {
    if (isRecord(node)) inherit(node);
    meta.value = isRecord(node) && Object.prototype.hasOwnProperty.call(node, 'value') ? node.value : node;
    result = meta;
  }
  if (seeds === SEEDS) metaCache.set(path, result);
  return result;
}

/** How a seed is edited. A percent editor holds a fraction and shows it times 100. */
export type AssumptionEditor = 'percent' | 'number' | 'curve' | 'weekdays' | 'choice';

export interface AssumptionRow {
  path: string;
  label: string;
  editor: AssumptionEditor;
  meta: SeedMeta;
}

function row(path: string, editor: AssumptionEditor, label?: string): AssumptionRow {
  const meta = seedMeta(path);
  if (meta === null) throw new Error('unknown seed path: ' + path);
  return { path, label: label ?? seedLabel(path), editor, meta };
}

export interface WeatherTableRows {
  id: 'temperature_bands' | 'precip_classes' | 'wind_bands';
  title: string;
  rows: { id: string; label: string; open: AssumptionRow; captive: AssumptionRow }[];
}

export interface SegmentRows {
  segment: SegmentKey;
  label: string;
  curves: { kind: 'presence' | 'intent'; dayType: DayType; row: AssumptionRow }[];
  weekdays: AssumptionRow;
  holidays: { cls: 'major' | 'minor'; row: AssumptionRow }[];
}

export interface AssumptionGroups {
  hosts: AssumptionRow[];
  weather: AssumptionRow[];
  weatherTables: WeatherTableRows[];
  events: AssumptionRow[];
  people: SegmentRows[];
}

const WEATHER_TABLE_TITLES: Readonly<Record<WeatherTableRows['id'], string>> = {
  temperature_bands: 'Temperature',
  precip_classes: 'Rain and snow',
  wind_bands: 'Wind',
};

function weatherRowLabel(table: WeatherTableRows['id'], id: string): string {
  if (table === 'temperature_bands' && Object.prototype.hasOwnProperty.call(TEMPERATURE_BAND_LABELS, id)) return TEMPERATURE_BAND_LABELS[id];
  if (table === 'wind_bands' && Object.prototype.hasOwnProperty.call(WIND_BAND_LABELS, id)) return WIND_BAND_LABELS[id];
  if (table === 'precip_classes') return precipClassWord(id);
  return id;
}

let groupsCache: AssumptionGroups | null = null;

/** Every seed the owner may change, in the four groups of the page, each with its editor and metadata. */
export function assumptionGroups(): AssumptionGroups {
  if (groupsCache !== null) return groupsCache;
  const tables: WeatherTableRows[] = (['temperature_bands', 'precip_classes', 'wind_bands'] as const).map((id) => ({
    id,
    title: WEATHER_TABLE_TITLES[id],
    rows: SEEDS.weather[id].order.map((rowId) => ({
      id: rowId,
      label: weatherRowLabel(id, rowId),
      open: row('weather.' + id + '.rows.' + rowId + '.open', 'percent'),
      captive: row('weather.' + id + '.rows.' + rowId + '.captive', 'percent'),
    })),
  }));
  const people: SegmentRows[] = SEGMENTS.map((segment) => {
    const curves: SegmentRows['curves'] = [];
    for (const kind of ['presence', 'intent'] as const) {
      for (const dayType of DAY_TYPES) {
        curves.push({ kind, dayType, row: row('segments.' + segment + '.' + kind + '.' + dayType, 'curve') });
      }
    }
    return {
      segment,
      label: segmentLabel(segment),
      curves,
      weekdays: row('segments.' + segment + '.dow_factor', 'weekdays'),
      holidays: (['major', 'minor'] as const).map((cls) => ({ cls, row: row('segments.' + segment + '.holiday_day_type.' + cls, 'choice') })),
    };
  });
  groupsCache = {
    hosts: [row('host.captive_share', 'percent'), row('host.shared_kitchen_share', 'percent'), row('host.onsite_kitchen_weight', 'number')],
    weather: [row('weather.floor', 'percent'), row('weather.pop_when_missing', 'percent')],
    weatherTables: tables,
    events: [
      row('events.attendance_haircut', 'percent'),
      ...(Object.keys(EVENT_TYPE_LABELS) as (keyof typeof EVENT_TYPE_LABELS)[]).map((type) => row('events.p_buy.' + type, 'percent')),
    ],
    people,
  };
  return groupsCache;
}

/** Every path of `assumptionGroups`, flat. */
export function editablePaths(): string[] {
  const g = assumptionGroups();
  const out: string[] = [];
  for (const r of g.hosts) out.push(r.path);
  for (const r of g.weather) out.push(r.path);
  for (const t of g.weatherTables) for (const r of t.rows) out.push(r.open.path, r.captive.path);
  for (const r of g.events) out.push(r.path);
  for (const s of g.people) {
    for (const c of s.curves) out.push(c.row.path);
    out.push(s.weekdays.path);
    for (const h of s.holidays) out.push(h.row.path);
  }
  return out;
}

function sameValue(a: unknown, b: unknown): boolean {
  if (Array.isArray(a) && Array.isArray(b)) return a.length === b.length && a.every((x, i) => x === b[i]);
  return a === b;
}

/** The value a path has in a draft: the owner's override, else the seed's own value. */
export function draftValue(draft: OverrideMap, path: string): unknown {
  if (Object.prototype.hasOwnProperty.call(draft, path)) return draft[path];
  const meta = seedMeta(path);
  return meta === null ? null : meta.value;
}

export function isOverridden(draft: OverrideMap, path: string): boolean {
  return Object.prototype.hasOwnProperty.call(draft, path);
}

/**
 * A draft with one path set. A value equal to the seed's own takes the override away: typing the
 * starting value back is the same as "Reset". The draft handed in is never changed.
 */
export function setDraftValue(draft: OverrideMap, path: string, value: SeedOverrideValue): OverrideMap {
  const next: OverrideMap = { ...draft };
  const meta = seedMeta(path);
  if (meta !== null && sameValue(meta.value, value)) delete next[path];
  else next[path] = value;
  return next;
}

/** A draft without the override of one path. */
export function resetDraftValue(draft: OverrideMap, path: string): OverrideMap {
  if (!Object.prototype.hasOwnProperty.call(draft, path)) return draft;
  const next: OverrideMap = { ...draft };
  delete next[path];
  return next;
}

/**
 * The body of route 5: only the paths that differ between the saved overrides and the draft. A
 * path the draft no longer overrides travels as null, which removes it on the server.
 */
export function overrideChanges(saved: OverrideMap, draft: OverrideMap): OverrideChanges {
  const changes: OverrideChanges = {};
  for (const path of Object.keys(draft)) {
    if (!Object.prototype.hasOwnProperty.call(saved, path) || !sameValue(saved[path], draft[path])) changes[path] = draft[path];
  }
  for (const path of Object.keys(saved)) {
    if (!Object.prototype.hasOwnProperty.call(draft, path)) changes[path] = null;
  }
  return changes;
}

/** The bounds of a row as its editor shows them: a percent editor prints them times 100. */
export function shownBounds(r: AssumptionRow): { min: number | null; max: number | null } {
  const scale = r.editor === 'percent' || r.editor === 'curve' ? 100 : 1;
  return {
    min: r.meta.min === null ? null : roundHalfAway(r.meta.min * scale, 6),
    max: r.meta.max === null ? null : roundHalfAway(r.meta.max * scale, 6),
  };
}

/** "Range: 5% to 100%", "Range: 0 to 10": the bounds as the editor shows them. Empty for a choice. */
export function rangeText(r: AssumptionRow): string {
  const { min, max } = shownBounds(r);
  if (min === null || max === null) return '';
  const unit = r.editor === 'percent' || r.editor === 'curve' ? '%' : '';
  return 'Range: ' + fmtPlain(min, 4) + unit + ' to ' + fmtPlain(max, 4) + unit;
}

function messageFor(path: string, code: string): string {
  const meta = seedMeta(path);
  if (meta === null) return overrideErrorMessage(code);
  const percent = editorOf(path) === 'percent' || editorOf(path) === 'curve';
  const scale = percent ? 100 : 1;
  return overrideErrorMessage(
    code,
    meta.min === null ? null : roundHalfAway(meta.min * scale, 6),
    meta.max === null ? null : roundHalfAway(meta.max * scale, 6),
  );
}

let editorCache: Record<string, AssumptionEditor> | null = null;

/** The editor of an editable path; `number` for any other path. */
export function editorOf(path: string): AssumptionEditor {
  if (editorCache === null) {
    const g = assumptionGroups();
    const map: Record<string, AssumptionEditor> = {};
    const add = (r: AssumptionRow) => {
      map[r.path] = r.editor;
    };
    g.hosts.forEach(add);
    g.weather.forEach(add);
    g.weatherTables.forEach((t) => t.rows.forEach((r) => (add(r.open), add(r.captive))));
    g.events.forEach(add);
    g.people.forEach((s) => {
      s.curves.forEach((c) => add(c.row));
      add(s.weekdays);
      s.holidays.forEach((h) => add(h.row));
    });
    editorCache = map;
  }
  return Object.prototype.hasOwnProperty.call(editorCache, path) ? editorCache[path] : 'number';
}

/**
 * The problems of a merged draft, by path, as sentences: the model's own `validateOverrides`
 * decides, so the page refuses exactly what the server would. Nothing is clamped.
 */
export function overrideErrors(draft: OverrideMap): Record<string, string> {
  const out: Record<string, string> = {};
  for (const problem of validateOverrides(SEEDS, draft)) out[problem.path] = messageFor(problem.path, problem.error);
  return out;
}

/** The `details` of a 422 of route 5 (`[{ path, error }]`) as sentences by path. Anything else gives no entry. */
export function serverOverrideErrors(details: unknown): Record<string, string> {
  const out: Record<string, string> = {};
  if (!Array.isArray(details)) return out;
  for (const item of details) {
    if (!isRecord(item) || typeof item.path !== 'string' || typeof item.error !== 'string') continue;
    out[item.path] = messageFor(item.path, item.error);
  }
  return out;
}

/** A 24-hour curve as the editor draws it: percents, a round top for the chart, and its peak in words. */
export interface CurveView {
  percents: number[];
  yMax: number;
  peakHour: number;
  peakPercent: number;
}

const CHART_TOPS: readonly number[] = [3, 6, 12, 30, 60, 120, 240];

export function curveView(values: readonly number[]): CurveView {
  const percents: number[] = [];
  let peakHour = 0;
  for (let h = 0; h < 24; h++) {
    const v = typeof values[h] === 'number' && values[h] === values[h] ? values[h] : 0;
    percents.push(roundHalfAway(v * 100, 6));
    if (percents[h] > percents[peakHour]) peakHour = h;
  }
  const peak = percents[peakHour];
  let yMax = CHART_TOPS[CHART_TOPS.length - 1];
  for (const top of CHART_TOPS) {
    if (peak <= top) {
      yMax = top;
      break;
    }
  }
  return { percents, yMax, peakHour, peakPercent: peak };
}

/** One seed of the closed section "Fixed in this version". */
export interface FixedSeedRow {
  key: string;
  label: string;
  value: string;
  unit: string;
  tag: SeedTag | null;
  source: string;
  /** "Placeholder until you log services" for a seed tagged `tuned`, else null. */
  note: string | null;
}

export interface FixedSeedGroup {
  id: string;
  title: string;
  rows: FixedSeedRow[];
}

const FIXED_GROUP_TITLES: Readonly<Record<string, string>> = {
  kernel: 'Walking distance and competition',
  host: 'Hosts',
  place_types: 'Kinds of place',
  holidays: 'Federal holidays',
  hours: 'Parts of the day',
  traffic: 'Traffic',
  drive_fallback: 'Drive times without Google',
  money: 'Money',
  events: 'Events',
  uncertainty: 'Ranges and labels',
  calibration: 'Learning from your logged services',
  timeline: 'Day plan notes',
  suggest: 'Suggestions',
  scout: 'Scout',
  map: 'Map colours',
  etl: 'How the map data was built',
  constants: 'Constants',
};

const FIXED_GROUP_ORDER: readonly string[] = [
  'kernel',
  'host',
  'place_types',
  'hours',
  'holidays',
  'traffic',
  'drive_fallback',
  'money',
  'events',
  'uncertainty',
  'calibration',
  'timeline',
  'suggest',
  'scout',
  'map',
  'etl',
  'constants',
];

/** A seed path without a plain-language label still reads as words: "suggest.max_candidates" -> "Max candidates". */
function wordsOfPath(path: string): string {
  const keys = path.split('.');
  const words = keys.slice(1).join(' ').split('_').join(' ');
  return words === '' ? path : words.charAt(0).toUpperCase() + words.slice(1);
}

function fixedRow(A: Assumptions, path: string): FixedSeedRow {
  const info = seedInfo(A, path);
  return {
    key: path,
    label: info.label === path ? wordsOfPath(path) : info.label,
    value: info.value,
    unit: info.unit,
    tag: info.tag,
    source: info.source,
    note: info.placeholder ? PLACEHOLDER_SEED : null,
  };
}

function collectFixed(node: unknown, path: string[], scope: string | null, structural: readonly string[], out: string[]): void {
  if (!isRecord(node)) {
    if (scope === 'build' || scope === 'fixed') out.push(path.join('.'));
    return;
  }
  const own = typeof node.scope === 'string' ? node.scope : scope;
  if (Object.prototype.hasOwnProperty.call(node, 'value')) {
    if (own === 'build' || own === 'fixed') out.push(path.join('.'));
    return;
  }
  for (const key of Object.keys(node)) {
    if (structural.includes(key)) continue;
    collectFixed(node[key], [...path, key], own, structural, out);
  }
}

/**
 * The seeds nobody can change at runtime (scope `build` or `fixed`), read-only, group by group, each
 * with its value in use, its tag and its source note. The two big tables are told row by row: one
 * line per kind of place and one per holiday.
 */
export function fixedSeedGroups(A: Assumptions): FixedSeedGroup[] {
  const seeds = A.seeds as unknown as Record<string, unknown>;
  const structural = A.seeds.vocabulary.structural_keys;
  const groups: FixedSeedGroup[] = [];
  for (const id of FIXED_GROUP_ORDER) {
    if (!Object.prototype.hasOwnProperty.call(seeds, id)) continue;
    const rows: FixedSeedRow[] = [];
    if (id === 'place_types') {
      const table = A.seeds.place_types;
      for (const type of table.order) {
        const r = table.rows[type as PlaceType];
        const parts: string[] = [];
        if (r.default_size > 0) parts.push('typical size ' + fmtPlain(r.default_size, 1));
        if (r.host_segment !== null) parts.push('hosts ' + segmentLabel(r.host_segment).toLowerCase());
        parts.push(r.kitchen_default === 'yes' ? 'sells its own food' : 'no food of its own');
        if (r.rival_kind !== null) parts.push('counts as competition');
        rows.push({
          key: 'place_types.rows.' + type,
          label: placeTypeLabel(type),
          value: parts.join(', '),
          unit: '',
          tag: r.tag,
          source: r.source,
          note: r.tag === 'tuned' ? PLACEHOLDER_SEED : null,
        });
      }
    } else if (id === 'holidays') {
      const table = A.seeds.holidays;
      for (const rule of table.rules) {
        rows.push({
          key: 'holidays.rules.' + rule.id,
          label: rule.name,
          value: rule.class === 'major' ? 'Major holiday' : 'Minor holiday',
          unit: '',
          tag: table.tag,
          source: table.source,
          note: null,
        });
      }
    } else {
      const paths: string[] = [];
      collectFixed(seeds[id], [id], null, structural, paths);
      for (const path of paths) rows.push(fixedRow(A, path));
    }
    if (rows.length > 0) groups.push({ id, title: FIXED_GROUP_TITLES[id] ?? id, rows });
  }
  return groups;
}

/** The label of a day type in a choice editor ("Weekday", "Saturday", "Sunday"). */
export function dayTypeLabel(value: string): string {
  return Object.prototype.hasOwnProperty.call(DAY_TYPE_LABELS, value) ? DAY_TYPE_LABELS[value as DayType] : value;
}

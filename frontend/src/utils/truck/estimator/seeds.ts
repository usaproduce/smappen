// Truck Planner estimator - seeds (02_MODEL section 2).
//
// The seed file travels as a generated copy (seeds.generated.ts, written by
// docs/truck-planner/reference/generate_seed_copies.py). This module gives it its documented type and
// holds the two functions that read and validate seeds.

import { SEEDS as GENERATED_SEEDS } from './seeds.generated';
import { MODEL_VERSION, SEGMENTS, cmpStr, hasOwn, isDict } from './core';
import type {
  Assumptions,
  DayType,
  HolidayClass,
  OverrideErrorCode,
  OverrideMap,
  OverrideProblem,
  Region,
  SeedFile,
} from './types';

// The seed copy is shared by every estimate of a session. It is frozen all the way down, so a caller
// that writes to it by accident (sorting a curve in place, say) fails at once instead of quietly
// changing every later number. To try other values, pass overrides or a copy of the object.
function deepFreeze(node: unknown): void {
  if (typeof node !== 'object' || node === null || Object.isFrozen(node)) return;
  Object.freeze(node);
  const values = Array.isArray(node) ? node : Object.values(node);
  for (let i = 0; i < values.length; i++) deepFreeze(values[i]);
}
deepFreeze(GENERATED_SEEDS);

/** Every seed assumption: the parsed content of tp_seeds.json. Read-only. */
export const SEEDS: SeedFile = GENERATED_SEEDS as unknown as SeedFile;

/** Revision of the seed copy this port carries. */
export const SEEDS_REVISION: number = SEEDS.seeds_revision;

/** The region record of a point outside every loaded region. */
export const REGION_NONE: Region = Object.freeze({
  id: 'none',
  traffic_matrix: 'us_mean',
  flags: Object.freeze({ inauguration_day: false }),
}) as Region;

/** An Assumptions record around this port's seed copy. */
export function makeAssumptions(overrides: OverrideMap, region: Region): Assumptions {
  return {
    model_version: MODEL_VERSION,
    seeds_revision: SEEDS.seeds_revision,
    seeds: SEEDS,
    overrides,
    region,
  };
}

// The keys of a path. A path is split once and remembered: model code reads the same few hundred
// paths on every hour of every estimate. The memory is bounded, so paths made up by a caller cannot
// grow it without limit.
const PATH_KEYS = new Map<string, readonly string[]>();
const PATH_KEYS_LIMIT = 4096;

function keysOf(path: string): readonly string[] {
  let keys = PATH_KEYS.get(path);
  if (keys === undefined) {
    keys = path.split('.');
    if (PATH_KEYS.size < PATH_KEYS_LIMIT) PATH_KEYS.set(path, keys);
  }
  return keys;
}

/**
 * Read a seed by its dot-separated path from the root. An override under exactly that path wins; a
 * node that carries a "value" key yields that value; any other node is returned as it is.
 */
export function seed<T = unknown>(A: Assumptions, path: string): T {
  const overrides: Record<string, unknown> = A.overrides;
  if (hasOwn(overrides, path)) return overrides[path] as T;
  let node: unknown = A.seeds;
  const keys = keysOf(path);
  for (let i = 0; i < keys.length; i++) {
    if (!isDict(node) || !hasOwn(node, keys[i])) {
      throw new Error('unknown seed path: ' + path); // a missing key is a programming error
    }
    node = node[keys[i]];
  }
  if (isDict(node) && hasOwn(node, 'value')) return node.value as T;
  return node as T;
}

// The curve arrays, the Monday-Friday factors and the holiday day types are read for every segment on
// every hour of every estimate. Their paths are fixed by the vocabulary, so the path strings are built
// once here, and the four readers below go straight to the node. Each returns exactly what seed(A, path)
// returns for its path: the override under that path if there is one, otherwise the seed.

function segmentPaths(suffix: string): string[] {
  const paths: string[] = [];
  for (let s = 0; s < SEGMENTS.length; s++) paths.push('segments.' + SEGMENTS[s] + suffix);
  return paths;
}

const PRESENCE_PATHS: Record<DayType, string[]> = {
  weekday: segmentPaths('.presence.weekday'),
  saturday: segmentPaths('.presence.saturday'),
  sunday: segmentPaths('.presence.sunday'),
};
const INTENT_PATHS: Record<DayType, string[]> = {
  weekday: segmentPaths('.intent.weekday'),
  saturday: segmentPaths('.intent.saturday'),
  sunday: segmentPaths('.intent.sunday'),
};
const DOW_FACTOR_PATHS: string[] = segmentPaths('.dow_factor');
const HOLIDAY_TYPE_PATHS: Record<HolidayClass, string[]> = {
  major: segmentPaths('.holiday_day_type.major'),
  minor: segmentPaths('.holiday_day_type.minor'),
};

/** seed(A, "segments.<s>.presence.<dayType>") for segment index s: 24 numbers. */
export function presenceCurve(A: Assumptions, s: number, dayType: DayType): number[] {
  const path = PRESENCE_PATHS[dayType][s];
  const overrides: Record<string, unknown> = A.overrides;
  if (hasOwn(overrides, path)) return overrides[path] as number[];
  return A.seeds.segments[SEGMENTS[s]].presence[dayType];
}

/** seed(A, "segments.<s>.intent.<dayType>") for segment index s: 24 numbers. */
export function intentCurve(A: Assumptions, s: number, dayType: DayType): number[] {
  const path = INTENT_PATHS[dayType][s];
  const overrides: Record<string, unknown> = A.overrides;
  if (hasOwn(overrides, path)) return overrides[path] as number[];
  return A.seeds.segments[SEGMENTS[s]].intent[dayType];
}

/** seed(A, "segments.<s>.dow_factor") for segment index s: five numbers, Monday to Friday. */
export function dowFactors(A: Assumptions, s: number): number[] {
  const path = DOW_FACTOR_PATHS[s];
  const overrides: Record<string, unknown> = A.overrides;
  if (hasOwn(overrides, path)) return overrides[path] as number[];
  return A.seeds.segments[SEGMENTS[s]].dow_factor.value;
}

/** seed(A, "segments.<s>.holiday_day_type.<cls>") for segment index s. */
export function holidayDayType(A: Assumptions, s: number, cls: HolidayClass): DayType {
  const path = HOLIDAY_TYPE_PATHS[cls][s];
  const overrides: Record<string, unknown> = A.overrides;
  if (hasOwn(overrides, path)) return overrides[path] as DayType;
  return A.seeds.segments[SEGMENTS[s]].holiday_day_type[cls];
}

function isNumber(x: unknown): x is number {
  return typeof x === 'number';
}

// 2.2 step 5: number for number (finite), string for string, array of the same length whose elements
// have the same type as the seed's.
function sameShape(value: unknown, target: unknown): boolean {
  if (isNumber(target)) return isNumber(value) && Number.isFinite(value);
  if (typeof target === 'string') return typeof value === 'string';
  if (typeof target === 'boolean') return typeof value === 'boolean';
  if (Array.isArray(target)) {
    if (!Array.isArray(value) || value.length !== target.length) return false;
    for (let k = 0; k < target.length; k++) {
      const t: unknown = target[k];
      if (Array.isArray(t) || isDict(t) || !sameShape(value[k], t)) return false;
    }
    return true;
  }
  return false;
}

const INHERITED_KEYS = ['scope', 'min', 'max', 'allowed'] as const;

interface Inherited {
  scope?: unknown;
  min?: unknown;
  max?: unknown;
  allowed?: unknown;
}

function inherit(node: Record<string, unknown>, inherited: Inherited): void {
  for (const name of INHERITED_KEYS) {
    if (hasOwn(node, name)) inherited[name] = node[name];
  }
}

function overrideError(seeds: SeedFile, path: string, value: unknown): OverrideErrorCode | null {
  const structural = seeds.vocabulary.structural_keys;
  const keys = path.split('.');
  let node: unknown = seeds;
  const inherited: Inherited = {}; // scope, min, max, allowed of the nearest enclosing object
  for (let i = 0; i < keys.length; i++) {
    if (!isDict(node) || !hasOwn(node, keys[i])) return 'unknown_path'; // step 1
    inherit(node, inherited);
    node = node[keys[i]];
  }
  if (isDict(node)) inherit(node, inherited);
  if (structural.indexOf(keys[keys.length - 1]) >= 0) return 'not_a_seed'; // step 2
  if (inherited.scope !== 'owner') return 'not_overridable'; // step 3
  const target: unknown = isDict(node) && hasOwn(node, 'value') ? node.value : node;
  if (isDict(target)) return 'not_a_leaf'; // step 4
  if (!sameShape(value, target)) return 'wrong_shape'; // step 5
  const items: unknown[] = Array.isArray(value) ? value : [value];
  for (let i = 0; i < items.length; i++) {
    const x = items[i]; // step 6
    if (isNumber(x)) {
      if (hasOwn(inherited, 'min') && x < (inherited.min as number)) return 'out_of_bounds';
      if (hasOwn(inherited, 'max') && x > (inherited.max as number)) return 'out_of_bounds';
    }
  }
  for (let i = 0; i < items.length; i++) {
    const x = items[i]; // step 7
    if (typeof x === 'string' && hasOwn(inherited, 'allowed') && (inherited.allowed as unknown[]).indexOf(x) < 0) {
      return 'not_allowed';
    }
  }
  return null;
}

/**
 * The problems of a sparse override map: one entry per offending path, in ascending path order, with
 * the first error the steps of 02_MODEL 2.2 find for it. An empty list means the map is valid.
 */
export function validateOverrides(seeds: SeedFile, overrides: Record<string, unknown>): OverrideProblem[] {
  const problems: OverrideProblem[] = [];
  const paths = Object.keys(overrides).sort(cmpStr);
  for (let i = 0; i < paths.length; i++) {
    const error = overrideError(seeds, paths[i], overrides[paths[i]]);
    if (error !== null) problems.push({ path: paths[i], error });
  }
  return problems;
}

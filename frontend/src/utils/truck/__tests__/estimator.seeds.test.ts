// Truck Planner estimator - the seed copy (docs/truck-planner/02_MODEL.md 2.1, 8.1).
//
// seeds.generated.ts is written by docs/truck-planner/reference/generate_seed_copies.py from
// tp_seeds.json. This test fails when the two differ (parsed values, not file bytes: line endings
// differ between checkouts), and when the vocabulary or the constants in code drift from the file.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { SEEDS as GENERATED } from '../estimator/seeds.generated';
import {
  CONFIDENCE_LABELS,
  DAYPARTS,
  DAY_TYPES,
  DOW_KEYS,
  EARTH_RADIUS_M,
  LN2,
  MAP_DOMAIN,
  METERS_PER_MILE,
  MODEL_VERSION,
  NSEG,
  PI,
  PLACE_TYPES,
  QKEY_SCALE,
  REGIMES,
  REGION_NONE,
  RIVAL_KINDS,
  ROUND_HALF,
  SEEDS,
  SEEDS_REVISION,
  SEGMENTS,
  VISIBILITY_LEVELS,
  Z80,
  makeAssumptions,
  seed,
} from '../estimator/index';

const SEEDS_PATH = fileURLToPath(
  new URL('../../../../../docs/truck-planner/reference/tp_seeds.json', import.meta.url),
);
const fromDisk = JSON.parse(readFileSync(SEEDS_PATH, 'utf8'));

function isRecord(x: unknown): x is Record<string, unknown> {
  return typeof x === 'object' && x !== null && !Array.isArray(x);
}

// Every keyed node of the seed tree, with its dotted path.
function walk(node: unknown, path: string, visit: (node: Record<string, unknown>, path: string) => void): void {
  if (Array.isArray(node)) {
    node.forEach((item, i) => walk(item, `${path}.${i}`, visit));
  } else if (isRecord(node)) {
    visit(node, path);
    for (const key of Object.keys(node)) walk(node[key], path === '' ? key : `${path}.${key}`, visit);
  }
}

describe('seed copy', () => {
  it('seeds.generated.ts deep-equals tp_seeds.json read from disk', () => {
    expect(GENERATED).toStrictEqual(fromDisk);
    // the same keys in the same order, the same numbers to the last bit
    expect(JSON.stringify(GENERATED)).toBe(JSON.stringify(fromDisk));
  });

  it('the port serves that copy, under its model version and revision', () => {
    expect(SEEDS).toBe(GENERATED);
    expect(SEEDS.model_version).toBe(MODEL_VERSION);
    expect(MODEL_VERSION).toBe('tps-0.1.0');
    expect(SEEDS_REVISION).toBe(fromDisk.seeds_revision);
    const A = makeAssumptions({}, REGION_NONE);
    expect(A.seeds).toBe(SEEDS);
    expect(A.model_version).toBe(MODEL_VERSION);
    expect(A.seeds_revision).toBe(fromDisk.seeds_revision);
    expect(REGION_NONE).toEqual({ id: 'none', traffic_matrix: 'us_mean', flags: { inauguration_day: false } });
  });

  it('is read-only all the way down, so no caller can change the numbers behind every estimate', () => {
    let nodes = 0;
    walk(SEEDS, '', (node, path) => {
      expect(Object.isFrozen(node), path).toBe(true);
      for (const key of Object.keys(node)) {
        const value = node[key];
        if (Array.isArray(value)) {
          expect(Object.isFrozen(value), `${path}.${key}`).toBe(true);
          for (const item of value) if (Array.isArray(item)) expect(Object.isFrozen(item)).toBe(true);
        }
      }
      nodes += 1;
    });
    expect(nodes).toBeGreaterThan(250); // every keyed node of the seed tree
    expect(() => {
      SEEDS.kernel.outside_option_a0.value = 2.0;
    }).toThrow(TypeError);
    expect(() => {
      SEEDS.segments.w_office.presence.weekday[12] = 1.0;
    }).toThrow(TypeError);
    expect(() => SEEDS.segments.res.dow_factor.value.sort()).toThrow(TypeError);
    expect(() => SEEDS.traffic.dc.value[3].reverse()).toThrow(TypeError);
    expect(SEEDS.kernel.outside_option_a0.value).toBe(1.6);
    // a copy is an ordinary object
    const copy = JSON.parse(JSON.stringify(SEEDS)) as typeof SEEDS;
    copy.kernel.outside_option_a0.value = 2.0;
    expect(copy.kernel.outside_option_a0.value).toBe(2.0);
  });

  it('holds the top-level groups of 02_MODEL 2.1, in order', () => {
    expect(Object.keys(SEEDS)).toEqual([
      'model_version', 'seeds_revision', 'as_of', 'about', 'entry_format', 'vocabulary', 'constants', 'hours',
      'kernel', 'segments', 'etl', 'host', 'place_types', 'holidays', 'weather', 'traffic', 'drive_fallback',
      'profile_defaults', 'money', 'events', 'uncertainty', 'calibration', 'timeline', 'suggest', 'scout', 'map',
    ]);
  });
});

describe('vocabulary in code equals seeds.vocabulary', () => {
  it('the index-ordered lists', () => {
    const v = fromDisk.vocabulary;
    expect([...SEGMENTS]).toEqual(v.segments);
    expect([...REGIMES]).toEqual(v.regimes);
    expect([...RIVAL_KINDS]).toEqual(v.rival_kinds);
    expect([...DAY_TYPES]).toEqual(v.day_types);
    expect([...DAYPARTS]).toEqual(v.dayparts);
    expect([...VISIBILITY_LEVELS]).toEqual(v.visibility_levels);
    expect([...PLACE_TYPES]).toEqual(v.place_types);
    expect([...CONFIDENCE_LABELS]).toEqual(v.confidence_labels);
    expect([...DOW_KEYS]).toEqual(v.dow);
    expect(NSEG).toBe(16);
    expect(SEGMENTS.length).toBe(NSEG);
  });

  it('segments and place types are keyed in that order', () => {
    expect(Object.keys(fromDisk.segments)).toEqual([...SEGMENTS]);
    SEGMENTS.forEach((name, i) => expect(fromDisk.segments[name].index).toBe(i));
    expect(fromDisk.place_types.order).toEqual([...PLACE_TYPES]);
    expect(Object.keys(fromDisk.place_types.rows)).toEqual([...PLACE_TYPES]);
    expect(Object.keys(fromDisk.kernel.rival_weight).filter((k) => k !== 'unit' && k !== 'scope')).toEqual([
      ...RIVAL_KINDS,
    ]);
    expect(Object.keys(fromDisk.kernel.visibility)).toEqual([...VISIBILITY_LEVELS]);
  });

  it('every tag, scope, group, host mode and day type is one the types name', () => {
    const tags = ['measured', 'derived', 'assumed', 'tuned'];
    const scopes = ['build', 'fixed', 'owner', 'profile_default'];
    let tagged = 0;
    walk(fromDisk, '', (node, path) => {
      if (path.startsWith('entry_format')) return; // prose about the format, not entries
      if ('tag' in node) {
        expect(tags, `tag at ${path}`).toContain(node.tag);
        tagged += 1;
      }
      if ('scope' in node) expect(scopes, `scope at ${path}`).toContain(node.scope);
    });
    expect(tagged).toBeGreaterThan(150);
    for (const name of SEGMENTS) {
      const s = SEEDS.segments[name];
      expect(['residents', 'workers', 'visitors']).toContain(s.group);
      expect(['captive', 'open']).toContain(s.host_mode);
      expect(typeof s.weak).toBe('boolean');
      expect([...DAY_TYPES]).toContain(s.holiday_day_type.major);
      expect([...DAY_TYPES]).toContain(s.holiday_day_type.minor);
      expect(s.holiday_day_type.allowed).toEqual([...DAY_TYPES]);
    }
    for (const type of PLACE_TYPES) {
      const row = SEEDS.place_types.rows[type];
      expect([null, ...SEGMENTS]).toContain(row.visitor_segment);
      expect([null, ...SEGMENTS]).toContain(row.host_segment);
      expect([null, ...RIVAL_KINDS]).toContain(row.rival_kind);
      expect(['yes', 'no']).toContain(row.kitchen_default);
    }
    expect(SEEDS.hours.regime_of_hour.value.every((r) => REGIMES.includes(r))).toBe(true);
    expect(SEEDS.hours.daypart_of_hour.value.every((d) => DAYPARTS.includes(d))).toBe(true);
  });
});

describe('constants in code equal seeds.constants', () => {
  it('the seven literals of 02_MODEL 1.2', () => {
    const c = fromDisk.constants;
    expect(EARTH_RADIUS_M).toBe(c.earth_radius_m.value);
    expect(PI).toBe(c.pi.value);
    expect(LN2).toBe(c.ln2.value);
    expect(Z80).toBe(c.z80.value);
    expect(METERS_PER_MILE).toBe(c.meters_per_mile.value);
    expect(ROUND_HALF).toBe(c.round_half.value);
    expect(QKEY_SCALE).toBe(c.qkey_scale.value);
    expect(EARTH_RADIUS_M).toBe(6371008.8);
    expect(PI).toBe(3.141592653589793);
    expect(LN2).toBe(0.6931471805599453);
    expect(Z80).toBe(1.2815515655446004);
  });

  it('the map colour domain', () => {
    expect(MAP_DOMAIN.opportunity).toBe(fromDisk.map.opportunity_hi.value);
    expect(MAP_DOMAIN.people).toBe(fromDisk.map.people_hi.value);
    expect(MAP_DOMAIN.competition).toBe(fromDisk.map.competition_hi.value);
  });
});

describe('the SeedFile type describes the file', () => {
  // types.ts gives the seed copy its documented type by a cast. These checks hold the file to that
  // type: the same keys, the same kinds of value. A seed revision that reshapes the file fails here.
  const ENTRY = ['scope', 'source', 'tag', 'unit', 'value'];
  const keysOf = (node: unknown): string[] => Object.keys(node as object).sort();
  const isEntry = (node: unknown): boolean => {
    const keys = keysOf(node).filter((k) => k !== 'min' && k !== 'max');
    return JSON.stringify(keys) === JSON.stringify(ENTRY);
  };

  it('groups of scalar entries hold { value, unit, tag, scope, source } with optional min and max', () => {
    const groups: Record<string, string[]> = {
      constants: ['earth_radius_m', 'pi', 'ln2', 'z80', 'meters_per_mile', 'round_half', 'qkey_scale'],
      hours: ['regime_of_hour', 'daypart_of_hour'],
      etl: ['cns04_weight', 'cell_min_nearby', 'cell_min_venue'],
      host: ['captive_share', 'shared_kitchen_share', 'onsite_kitchen_weight', 'exclusion_radius_m', 'venue_link_radius_m'],
      traffic: ['dc', 'us_mean', 'dc_typical', 'us_mean_typical'],
      drive_fallback: ['detour_factor', 'local_miles', 'local_mph', 'trunk_mph'],
      uncertainty: [
        'sd_truck', 'sd_spot', 'sd_day', 'sd_weak', 'sd_default_size', 'sd_event', 'count_dispersion',
        'resid_prior_weight', 'label_good_below', 'label_fair_below', 'label_rough_below',
      ],
      calibration: [
        'k_truck', 'k_spot', 'half_life_days', 'ratio_clamp', 'spot_ratio_clamp', 'min_predicted', 'min_actual',
        'min_resid_n',
      ],
      timeline: ['long_gap_minutes', 'long_day_minutes', 'early_start_minute'],
      suggest: [
        'service_minutes', 'earliest_open_minute', 'latest_close_minute', 'windows_per_spot', 'max_candidates',
        'max_stops_per_day', 'max_day_minutes', 'min_stop_orders', 'day_results', 'week_day_options',
        'max_days_per_week', 'max_visits_per_spot_per_week', 'min_day_take_home',
      ],
      scout: ['window_minutes', 'max_results'],
      map: ['opportunity_hi', 'people_hi', 'competition_hi'],
    };
    for (const [group, names] of Object.entries(groups)) {
      expect(Object.keys(fromDisk[group]), group).toEqual(names);
      for (const name of names) expect(isEntry(fromDisk[group][name]), `${group}.${name}`).toBe(true);
    }
    expect(Object.keys(fromDisk.kernel)).toEqual([
      'walk_decay_m', 'walk_cutoff_m', 'outside_option_a0', 'rival_weight', 'visibility',
    ]);
    for (const name of ['walk_decay_m', 'walk_cutoff_m', 'outside_option_a0']) {
      expect(isEntry(fromDisk.kernel[name]), name).toBe(true);
    }
    for (const level of VISIBILITY_LEVELS) expect(isEntry(fromDisk.kernel.visibility[level]), level).toBe(true);
    for (const kind of RIVAL_KINDS) {
      expect(keysOf(fromDisk.kernel.rival_weight[kind]), kind).toEqual(['day', 'eve', 'source', 'tag']);
    }
    expect(Object.keys(fromDisk.weather)).toEqual([
      'floor', 'pop_when_missing', 'temperature_bands', 'precip_classes', 'wind_bands',
    ]);
    expect(isEntry(fromDisk.weather.floor)).toBe(true);
    expect(isEntry(fromDisk.weather.pop_when_missing)).toBe(true);
    expect(Object.keys(fromDisk.money)).toEqual(['fee_warn_share', 'fuel_price_fallback']);
    expect(isEntry(fromDisk.money.fee_warn_share)).toBe(true);
    expect(Object.keys(fromDisk.events)).toEqual([
      'attendance_haircut', 'p_buy', 'min_attendees_per_vendor', 'suggested_fee_pct', 'suggested_fee_min',
    ]);
    for (const name of ['attendance_haircut', 'min_attendees_per_vendor', 'suggested_fee_pct', 'suggested_fee_min']) {
      expect(isEntry(fromDisk.events[name]), name).toBe(true);
    }
  });

  it('profile defaults: one entry per TruckProfile field that has a default', () => {
    const fields = [
      'avg_ticket', 'capacity_orders_per_hour', 'paid_crew', 'wage_per_hour', 'payroll_burden_pct', 'food_cost_pct',
      'packaging_per_order', 'card_fee_pct', 'card_fee_fixed', 'card_share', 'tips_include',
      'tips_pct_of_card_sales', 'mpg', 'fuel_type', 'generator_gal_per_hour', 'prep_minutes', 'setup_minutes',
      'teardown_minutes', 'closeout_minutes', 'fixed_cost_per_service_day', 'daypart_fit', 'avoid_tolls',
      'avoid_highways', 'truck_time_factor', 'scout_drive_minutes_limit',
    ];
    expect(Object.keys(fromDisk.profile_defaults)).toEqual(fields);
    for (const field of fields) {
      const node = fromDisk.profile_defaults[field];
      if (field === 'daypart_fit') {
        expect(keysOf(node)).toEqual(
          ['breakfast', 'dinner', 'late', 'lunch', 'max', 'min', 'scope', 'source', 'tag', 'unit'].sort(),
        );
      } else {
        expect(isEntry(node), field).toBe(true);
        expect(node.scope).toBe('profile_default');
        const kind = typeof node.value;
        if (kind === 'number') {
          expect(typeof node.min, field).toBe('number');
          expect(typeof node.max, field).toBe('number');
          expect(node.value).toBeGreaterThanOrEqual(node.min);
          expect(node.value).toBeLessThanOrEqual(node.max);
        } else {
          expect(['boolean', 'string'], field).toContain(kind);
        }
      }
    }
    expect(['gasoline', 'diesel']).toContain(SEEDS.profile_defaults.fuel_type.value);
  });

  it('compound entries: segments, place types, weather tables, fuel prices, buy rates, holidays', () => {
    const curveKeys = ['max', 'min', 'saturday', 'scope', 'source', 'sunday', 'tag', 'unit', 'weekday'];
    for (const name of SEGMENTS) {
      const s = fromDisk.segments[name];
      const structural = ['index', 'label', 'group', 'base_unit', 'host_mode', 'weak'];
      const expected = s.group === 'workers' ? [...structural, 'lodes_cns'] : structural;
      expect(Object.keys(s), name).toEqual([...expected, 'presence', 'intent', 'dow_factor', 'holiday_day_type']);
      expect(keysOf(s.presence), name).toEqual(curveKeys);
      expect(keysOf(s.intent), name).toEqual(curveKeys);
      expect(keysOf(s.dow_factor), name).toEqual(['max', 'min', 'scope', 'source', 'tag', 'unit', 'value']);
      expect(keysOf(s.holiday_day_type), name).toEqual(['allowed', 'major', 'minor', 'scope', 'source', 'tag', 'unit']);
      expect(typeof s.label).toBe('string');
      expect(typeof s.base_unit).toBe('string');
    }
    expect(Object.keys(fromDisk.place_types)).toEqual(['unit', 'scope', 'note', 'order', 'rows']);
    for (const type of PLACE_TYPES) {
      const row = fromDisk.place_types.rows[type];
      expect(keysOf(row), type).toEqual([
        'default_size', 'host_fit', 'host_segment', 'kitchen_default', 'rival_kind', 'source', 'tag', 'visitor_segment',
      ]);
      expect(typeof row.default_size).toBe('number');
      expect(typeof row.host_fit).toBe('number');
    }
    const w = fromDisk.weather;
    expect(keysOf(w.temperature_bands)).toEqual(['max', 'min', 'order', 'rows', 'scope', 'unit']);
    expect(keysOf(w.wind_bands)).toEqual(['max', 'min', 'order', 'rows', 'scope', 'unit']);
    expect(keysOf(w.precip_classes)).toEqual(['match_rule', 'max', 'min', 'order', 'rows', 'scope', 'unit']);
    for (const id of w.temperature_bands.order) {
      expect(keysOf(w.temperature_bands.rows[id]), id).toEqual(['captive', 'open', 'source', 'tag', 'upper_f']);
    }
    for (const id of w.wind_bands.order) {
      expect(keysOf(w.wind_bands.rows[id]), id).toEqual(['captive', 'open', 'source', 'tag', 'upper_mph']);
    }
    for (const id of w.precip_classes.order) {
      expect(keysOf(w.precip_classes.rows[id]), id).toEqual(['captive', 'match', 'open', 'source', 'tag']);
      expect(Array.isArray(w.precip_classes.rows[id].match)).toBe(true);
    }
    const fuel = fromDisk.money.fuel_price_fallback;
    expect(keysOf(fuel)).toEqual(['as_of', 'diesel', 'gasoline', 'scope', 'source', 'tag', 'unit']);
    expect(keysOf(fuel.gasoline)).toEqual(['NUS', 'R1Y', 'R1Z']);
    expect(keysOf(fuel.diesel)).toEqual(['NUS', 'R1Y', 'R1Z']);
    expect(SEEDS.money.fuel_price_fallback.gasoline.R1Z).toBe(4.195);
    expect(keysOf(fromDisk.events.p_buy)).toEqual([
      'evening_show', 'food_focused', 'general', 'incidental', 'max', 'min', 'scope', 'source', 'tag', 'unit',
    ]);
    expect(keysOf(fromDisk.holidays)).toEqual(['rules', 'scope', 'source', 'tag', 'unit']);
    for (const rule of fromDisk.holidays.rules) {
      const allowed = ['id', 'name', 'rule', 'month', 'day', 'dow', 'n', 'from_year', 'class', 'region_flag'];
      for (const key of Object.keys(rule)) expect(allowed, `${rule.id}.${key}`).toContain(key);
      expect(['fixed', 'nth_weekday', 'last_weekday', 'inauguration']).toContain(rule.rule);
      expect(['major', 'minor']).toContain(rule.class);
    }
    expect(keysOf(fromDisk.vocabulary)).toEqual([
      'confidence_labels', 'day_types', 'dayparts', 'dow', 'place_types', 'regimes', 'rival_kinds', 'segments',
      'structural_keys', 'visibility_levels',
    ]);
  });
});

describe('shape of what the model reads', () => {
  it('curves: 24 numbers each, presence in [0, 1], intent in [0, 0.5], a visitor base is the busiest hour', () => {
    for (const name of SEGMENTS) {
      const s = SEEDS.segments[name];
      let peak = 0;
      for (const dayType of DAY_TYPES) {
        const presence = s.presence[dayType];
        const intent = s.intent[dayType];
        expect(presence.length).toBe(24);
        expect(intent.length).toBe(24);
        for (let h = 0; h < 24; h++) {
          expect(presence[h]).toBeGreaterThanOrEqual(0);
          expect(presence[h]).toBeLessThanOrEqual(1);
          expect(intent[h]).toBeGreaterThanOrEqual(0);
          expect(intent[h]).toBeLessThanOrEqual(0.5);
          if (presence[h] > peak) peak = presence[h];
        }
      }
      expect(s.dow_factor.value.length).toBe(5);
      if (s.group === 'visitors') expect(peak).toBe(1.0);
    }
  });

  it('hour tables, traffic matrices, weather tables and holiday rules', () => {
    expect(SEEDS.hours.regime_of_hour.value.length).toBe(24);
    expect(SEEDS.hours.daypart_of_hour.value.length).toBe(24);
    for (const id of ['dc', 'us_mean'] as const) {
      const matrix = SEEDS.traffic[id].value;
      expect(matrix.length).toBe(7);
      for (const row of matrix) expect(row.length).toBe(24);
    }
    expect(SEEDS.traffic.dc.value[3][17]).toBe(1.72); // Thursday 17:00, the check value of 2.3
    expect(SEEDS.traffic.dc_typical.value).toBe(1.265);
    for (const table of [SEEDS.weather.temperature_bands, SEEDS.weather.precip_classes, SEEDS.weather.wind_bands]) {
      expect(Object.keys(table.rows)).toEqual(table.order);
    }
    expect(SEEDS.weather.temperature_bands.rows['95_up'].upper_f).toBeNull();
    expect(SEEDS.weather.wind_bands.rows.very_windy.upper_mph).toBeNull();
    expect(SEEDS.weather.precip_classes.order[SEEDS.weather.precip_classes.order.length - 1]).toBe('dry');
    expect(SEEDS.holidays.rules.length).toBe(12);
    expect(SEEDS.holidays.rules.map((r) => r.id)).toEqual([
      'new_year', 'mlk', 'washington', 'memorial', 'juneteenth', 'independence', 'labor', 'columbus', 'veterans',
      'thanksgiving', 'christmas', 'inauguration',
    ]);
  });

  it('seed() reads the examples of 02_MODEL 2.1', () => {
    const A = makeAssumptions({}, REGION_NONE);
    expect(seed(A, 'kernel.outside_option_a0')).toBe(1.6);
    expect(seed<number[]>(A, 'segments.w_office.presence.weekday').length).toBe(24);
    expect(seed(A, 'segments.w_office.dow_factor')).toEqual([0.9, 1.19, 1.16, 1.08, 0.67]);
    expect(seed(A, 'weather.temperature_bands.rows.50_59.open')).toBe(0.9);
    expect(seed(A, 'place_types.rows.taproom')).toBe(SEEDS.place_types.rows.taproom);
    expect(seed(makeAssumptions({ 'host.captive_share': 0.6 }, REGION_NONE), 'host.captive_share')).toBe(0.6);
    expect(() => seed(A, 'no.such.path')).toThrow();
    expect(() => seed(A, 'segments.w_office.presence.weekday.3')).toThrow();
  });
});

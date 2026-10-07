// Truck Planner - the Settings drafts (docs/truck-planner/05_FRONTEND.md 4.9 and 8.2).
//
// What is held here: the profile draft round-trips; every range of the form is the seed file's;
// a value outside its range is refused with the range message and never clamped; a save carries
// only what changed; and the assumptions draft is checked by the model's own validate_overrides.

import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import type { TruckProfileX } from '../../../api/truck';
import { commitNumber, numberFieldRangeMessage } from '../../../components/truck/ui/kit';
import { SEEDS, makeAssumptions, validateOverrides } from '../model';
import type { OverrideMap } from '../model';
import {
  FUEL_PRICE_OVERRIDE_RULE,
  PROFILE_LABELS,
  PROFILE_NUMBER_KEYS,
  assumptionGroups,
  curveView,
  daypartRule,
  draftValue,
  editablePaths,
  editorOf,
  extraShareOfFactor,
  extraShareRule,
  factorOfExtraShare,
  fixedSeedGroups,
  fuelProductLabel,
  fuelSourceLine,
  isOverridden,
  numberProblem,
  overrideChanges,
  overrideErrors,
  previewFuelPrice,
  profileDraftDirty,
  profileDraftOf,
  profileFromDraft,
  profilePatch,
  profileRule,
  rangeText,
  resetDraftValue,
  seedMeta,
  serverOverrideErrors,
  setDraftValue,
  settingsMeaning,
  shownBounds,
  startingValues,
  validateProfileDraft,
} from '../profileForm';
import { PLACEHOLDER_SEED, seedLabel } from '../wording';

/** The profile the server makes from the defaults: every `profile_defaults` value, a name and a base. */
function defaultProfile(): TruckProfileX {
  const d = SEEDS.profile_defaults;
  return {
    name: 'Smoke & Ember',
    region_id: 'dc',
    base: { lat: 39.003, lng: -77.405, address: 'Sterling, VA' },
    avg_ticket: d.avg_ticket.value,
    capacity_orders_per_hour: d.capacity_orders_per_hour.value,
    paid_crew: d.paid_crew.value,
    wage_per_hour: d.wage_per_hour.value,
    payroll_burden_pct: d.payroll_burden_pct.value,
    food_cost_pct: d.food_cost_pct.value,
    packaging_per_order: d.packaging_per_order.value,
    card_fee_pct: d.card_fee_pct.value,
    card_fee_fixed: d.card_fee_fixed.value,
    card_share: d.card_share.value,
    tips_include: d.tips_include.value,
    tips_pct_of_card_sales: d.tips_pct_of_card_sales.value,
    mpg: d.mpg.value,
    fuel_type: d.fuel_type.value,
    fuel_price_override: null,
    generator_gal_per_hour: d.generator_gal_per_hour.value,
    prep_minutes: d.prep_minutes.value,
    setup_minutes: d.setup_minutes.value,
    teardown_minutes: d.teardown_minutes.value,
    closeout_minutes: d.closeout_minutes.value,
    fixed_cost_per_service_day: d.fixed_cost_per_service_day.value,
    daypart_fit: { breakfast: d.daypart_fit.breakfast, lunch: d.daypart_fit.lunch, dinner: d.daypart_fit.dinner, late: d.daypart_fit.late },
    avoid_tolls: d.avoid_tolls.value,
    avoid_highways: d.avoid_highways.value,
    truck_time_factor: d.truck_time_factor.value,
    licence_counties: ['51059', '51107'],
    scout_drive_minutes_limit: d.scout_drive_minutes_limit.value,
  };
}

const saved = defaultProfile();
const FUEL = { price_per_gal: 4.195, source: 'eia' as const, area: 'R1Z', product: 'EPMR' as const, period: '2026-09-28' };

// -------------------------------------------------------------------------------------------------
// Truck and costs
// -------------------------------------------------------------------------------------------------

describe('profile draft', () => {
  it('round-trips: an untouched draft has nothing to send and gives the profile back', () => {
    const draft = profileDraftOf(saved);
    expect(validateProfileDraft(draft)).toEqual({});
    expect(profilePatch(saved, draft)).toEqual({});
    expect(profileDraftDirty(saved, draft)).toBe(false);
    expect(profileFromDraft(saved, draft)).toEqual(saved);
  });

  it('does not share its lists with the saved profile', () => {
    const draft = profileDraftOf(saved);
    draft.licence_counties.push('11001');
    draft.daypart_fit.lunch = 0.5;
    draft.base.lat = 1;
    expect(saved.licence_counties).toEqual(['51059', '51107']);
    expect(saved.daypart_fit.lunch).toBe(1);
    expect(saved.base.lat).toBe(39.003);
  });

  it('sends only the keys that changed', () => {
    const draft = profileDraftOf(saved);
    draft.avg_ticket = 12;
    expect(profilePatch(saved, draft)).toEqual({ avg_ticket: 12 });
    draft.daypart_fit.breakfast = 0.6;
    expect(profilePatch(saved, draft)).toEqual({ avg_ticket: 12, daypart_fit: { breakfast: 0.6 } });
    draft.tips_include = true;
    draft.fuel_type = 'diesel';
    draft.fuel_price_override = 5.199;
    draft.avoid_tolls = true;
    expect(profilePatch(saved, draft)).toEqual({
      avg_ticket: 12,
      daypart_fit: { breakfast: 0.6 },
      tips_include: true,
      fuel_type: 'diesel',
      fuel_price_override: 5.199,
      avoid_tolls: true,
    });
    expect(profileDraftDirty(saved, draft)).toBe(true);
  });

  it('sends the base whole, and the name trimmed', () => {
    const draft = profileDraftOf(saved);
    draft.base = { lat: 38.9, lng: -77.03, address: ' 14th St NW ' };
    draft.name = '  Ember II ';
    expect(profilePatch(saved, draft)).toEqual({ name: 'Ember II', base: { lat: 38.9, lng: -77.03, address: '14th St NW' } });
    const sameName = profileDraftOf(saved);
    sameName.name = ' ' + saved.name + ' ';
    expect(profilePatch(saved, sameName)).toEqual({});
  });

  it('sends the counties as a set: order alone is no change', () => {
    const draft = profileDraftOf(saved);
    draft.licence_counties = ['51107', '51059'];
    expect(profilePatch(saved, draft)).toEqual({});
    draft.licence_counties = ['51107', '11001', '51059'];
    expect(profilePatch(saved, draft)).toEqual({ licence_counties: ['11001', '51059', '51107'] });
    draft.licence_counties = [];
    expect(profilePatch(saved, draft)).toEqual({ licence_counties: [] });
  });

  it('clearing the own fuel price sends null', () => {
    const withPrice = { ...saved, fuel_price_override: 4.5 };
    const draft = profileDraftOf(withPrice);
    draft.fuel_price_override = null;
    expect(profilePatch(withPrice, draft)).toEqual({ fuel_price_override: null });
  });

  it('an emptied number is never sent: it is reported, and the draft counts as changed', () => {
    const draft = profileDraftOf(saved);
    draft.avg_ticket = null;
    draft.daypart_fit.lunch = null;
    expect(profilePatch(saved, draft)).toEqual({});
    expect(profileDraftDirty(saved, draft)).toBe(true);
    expect(validateProfileDraft(draft)).toEqual({ avg_ticket: 'Required', 'daypart_fit.lunch': 'Required' });
    expect(profileFromDraft(saved, draft)).toBeNull();
  });
});

describe('every range of the form is read from the seed metadata', () => {
  it('each number field takes min and max from profile_defaults', () => {
    expect(PROFILE_NUMBER_KEYS).toHaveLength(20);
    for (const key of PROFILE_NUMBER_KEYS) {
      const entry = SEEDS.profile_defaults[key];
      const rule = profileRule(key);
      expect(typeof entry.min, key).toBe('number');
      expect(typeof entry.max, key).toBe('number');
      expect(rule.min, key).toBe(entry.min);
      expect(rule.max, key).toBe(entry.max);
      expect(typeof PROFILE_LABELS[key], key).toBe('string');
    }
    // 02_MODEL 2.3, the table "Truck profile defaults"
    expect(profileRule('avg_ticket')).toEqual({ min: 1, max: 200, integer: false });
    expect(profileRule('capacity_orders_per_hour')).toEqual({ min: 0, max: 400, integer: false });
    expect(profileRule('paid_crew')).toEqual({ min: 0, max: 12, integer: true });
    expect(profileRule('food_cost_pct')).toEqual({ min: 0, max: 0.95, integer: false });
    expect(profileRule('mpg')).toEqual({ min: 1, max: 60, integer: false });
    expect(profileRule('prep_minutes')).toEqual({ min: 0, max: 600, integer: true });
    expect(profileRule('scout_drive_minutes_limit')).toEqual({ min: 5, max: 60, integer: true });
    expect(daypartRule()).toEqual({ min: SEEDS.profile_defaults.daypart_fit.min, max: SEEDS.profile_defaults.daypart_fit.max, integer: false });
  });

  it('whole numbers are the people and minutes of the profile shape', () => {
    const whole = PROFILE_NUMBER_KEYS.filter((key) => profileRule(key).integer);
    expect(whole).toEqual(['paid_crew', 'prep_minutes', 'setup_minutes', 'teardown_minutes', 'closeout_minutes', 'scout_drive_minutes_limit']);
    for (const key of whole) expect(['people', 'minutes'], key).toContain(SEEDS.profile_defaults[key].unit);
  });

  it('the truck time factor is shown as the extra share, with the seed bounds moved the same way', () => {
    expect(extraShareOfFactor(1.1)).toBe(0.1);
    expect(factorOfExtraShare(0.1)).toBe(1.1);
    expect(factorOfExtraShare(extraShareOfFactor(1.35))).toBe(1.35);
    const seedRule = profileRule('truck_time_factor');
    expect(extraShareRule()).toEqual({ min: seedRule.min - 1, max: seedRule.max - 1, integer: false });
    expect(extraShareRule()).toEqual({ min: -0.5, max: 2, integer: false });
  });

  it('the own fuel price is the one range without a seed: the server range of 04_BACKEND 4.4', () => {
    expect(FUEL_PRICE_OVERRIDE_RULE).toEqual({ min: 0.5, max: 20, integer: false });
    expect('fuel_price_override' in SEEDS.profile_defaults).toBe(false);
  });

  it('no component of the Spots and Settings screens writes a range as a number', () => {
    const src = fileURLToPath(new URL('../../../components/truck/', import.meta.url));
    const files: string[] = [];
    for (const dir of ['settings', 'spots']) {
      for (const name of readdirSync(src + dir)) if (name.endsWith('.tsx')) files.push(dir + '/' + name);
    }
    for (const name of ['spot/SpotForm.tsx', 'spot/HostPicker.tsx', 'pages/SettingsPage.tsx', 'pages/SpotsPage.tsx', 'pages/SpotDetailPage.tsx', 'pages/SpotComparePage.tsx']) {
      files.push(name);
    }
    expect(files).toContain('settings/TruckCostsTab.tsx');
    expect(files).toContain('settings/AssumptionsTab.tsx');
    expect(files).toContain('settings/CurveEditor.tsx');
    const literalBound = /\b(?:min|max)\s*[=:]\s*\{?\s*-?\d/;
    for (const file of files) {
      const lines = readFileSync(src + file, 'utf8').split('\n');
      const hits = lines.filter((line) => literalBound.test(line) && !/\b(?:minWidth|maxWidth|minHeight|maxHeight|minmax)\b/.test(line));
      expect(hits, file).toEqual([]);
    }
    // the pattern does catch a typed range
    expect(literalBound.test('<NumberField min={1} max={200} />')).toBe(true);
    expect(literalBound.test('const rule = { min: 0.5, max: 20 };')).toBe(true);
    expect(literalBound.test('<NumberField min={rule.min} max={rule.max} />')).toBe(false);
  });
});

describe('a value outside its range is refused with the range message, never clamped', () => {
  it('each number field refuses the value just outside and takes the bound itself', () => {
    for (const key of PROFILE_NUMBER_KEYS) {
      const rule = profileRule(key);
      const step = rule.integer ? 1 : 0.001;
      for (const [value, ok] of [
        [rule.min, true],
        [rule.max, true],
        [rule.min - step, false],
        [rule.max + step, false],
      ] as [number, boolean][]) {
        const draft = profileDraftOf(saved);
        draft[key] = value;
        const errors = validateProfileDraft(draft);
        expect(key in errors, key + ' = ' + String(value)).toBe(!ok);
        // never clamped: the draft still holds what was typed
        expect(draft[key], key).toBe(value);
        if (!ok) expect(profileFromDraft(saved, draft), key).toBeNull();
      }
    }
  });

  it('prints the bounds as the field shows them', () => {
    const draft = profileDraftOf(saved);
    draft.avg_ticket = 500;
    draft.food_cost_pct = 0.99;
    draft.paid_crew = 1.5;
    draft.truck_time_factor = 4;
    draft.daypart_fit.late = 1.2;
    draft.fuel_price_override = 0.2;
    expect(validateProfileDraft(draft)).toEqual({
      avg_ticket: 'Enter a number from 1 to 200.',
      food_cost_pct: 'Enter a number from 0 to 95.',
      paid_crew: 'Enter a whole number from 0 to 12.',
      truck_time_factor: 'Enter a number from -50 to 200.',
      'daypart_fit.late': 'Enter a number from 0 to 100.',
      fuel_price_override: 'Enter a number from 0.5 to 20.',
    });
  });

  it('is the same refusal the number field gives while typing', () => {
    const rule = profileRule('avg_ticket');
    const typed = commitNumber('500', { min: rule.min, max: rule.max });
    expect(typed.ok).toBe(false);
    expect(typed.error).toBe(numberProblem(500, rule));
    expect(commitNumber('200', { min: rule.min, max: rule.max })).toEqual({ ok: true, value: 200, error: null });
    const food = profileRule('food_cost_pct');
    expect(commitNumber('99', { min: food.min, max: food.max, format: 'percent' })).toEqual({ ok: false, error: numberProblem(0.99, food, 100) });
    expect(numberFieldRangeMessage({ min: food.min, max: food.max, format: 'percent' })).toBe('Enter a number from 0 to 95.');
    const crew = profileRule('paid_crew');
    expect(commitNumber('1.5', { min: crew.min, max: crew.max, integer: true })).toEqual({ ok: false, error: numberProblem(1.5, crew) });
  });

  it('checks the name, the base and the number of counties', () => {
    const draft = profileDraftOf(saved);
    draft.name = '  ';
    expect(validateProfileDraft(draft)).toEqual({ name: 'Required' });
    draft.name = 'x'.repeat(121);
    expect(validateProfileDraft(draft)).toEqual({ name: 'Use at most 120 characters.' });
    draft.name = 'ok';
    draft.base.lat = 91;
    expect(validateProfileDraft(draft).base).toBeDefined();
    draft.base.lat = 39;
    draft.licence_counties = Array.from({ length: 61 }, (_, i) => String(10000 + i));
    expect(validateProfileDraft(draft)).toEqual({ licence_counties: 'Tick at most 60 counties.' });
  });
});

describe('what these settings mean', () => {
  it('is the arithmetic of the model on the default profile', () => {
    const meaning = settingsMeaning(saved, 4.195);
    // 02_MODEL 4.9: 15 x (1 - 0.30 - 0.85 x 0.026) - 0.50 - 0.85 x 0.15 = 9.541
    expect(meaning.perOrder).toBeCloseTo(9.541, 9);
    // two paid crew at $18 with 10 % payroll extras
    expect(meaning.crewPerPaidHour).toBeCloseTo(39.6, 9);
    expect(meaning.fuelPerMile).toBeCloseTo(4.195 / 9, 9);
    expect(meaning.generatorPerHour).toBeCloseTo(0.6 * 4.195, 9);
  });

  it('follows the draft', () => {
    const draft = profileDraftOf(saved);
    draft.avg_ticket = 12;
    draft.paid_crew = 3;
    const profile = profileFromDraft(saved, draft) as TruckProfileX;
    const meaning = settingsMeaning(profile, 4.195);
    expect(meaning.perOrder).toBeCloseTo(12 * (1 - 0.3 - 0.85 * 0.026) - 0.5 - 0.85 * 0.15, 9);
    expect(meaning.crewPerPaidHour).toBeCloseTo(3 * 18 * 1.1, 9);
  });

  it('has no fuel figure without a fuel price', () => {
    const meaning = settingsMeaning(saved, null);
    expect(meaning.fuelPerMile).toBeNull();
    expect(meaning.generatorPerHour).toBeNull();
    expect(meaning.crewPerPaidHour).toBeCloseTo(39.6, 9);
  });

  it('previews with the fuel price that belongs to the draft', () => {
    const draft = profileDraftOf(saved);
    expect(previewFuelPrice(saved, draft, FUEL)).toBe(4.195);
    draft.fuel_price_override = 5.25;
    expect(previewFuelPrice(saved, draft, FUEL)).toBe(5.25);
    draft.fuel_price_override = null;
    draft.fuel_type = 'diesel';
    expect(previewFuelPrice(saved, draft, FUEL)).toBeNull(); // the diesel price is only known after the save
    const owned = { ...saved, fuel_price_override: 4.5 };
    const cleared = profileDraftOf(owned);
    cleared.fuel_price_override = null;
    expect(previewFuelPrice(owned, cleared, { ...FUEL, price_per_gal: 4.5, source: 'owner' })).toBeNull();
  });

  it('says where the fuel price comes from', () => {
    expect(fuelProductLabel('EPMR')).toBe('Regular gasoline');
    expect(fuelProductLabel('EPD2D')).toBe('Diesel');
    expect(fuelSourceLine(FUEL)).toBe('EIA weekly average, week of Sep 28');
    expect(fuelSourceLine({ ...FUEL, source: 'owner' })).toBe('Your price');
    expect(fuelSourceLine({ ...FUEL, source: 'seed' })).toBe('Default price from the week of Sep 28. No current figure.');
  });
});

describe('starting values', () => {
  it('lists every profile default with its value, unit, tag and source', () => {
    const rows = startingValues();
    const keys = rows.map((r) => r.key);
    for (const key of Object.keys(SEEDS.profile_defaults)) {
      if (key === 'daypart_fit') continue;
      expect(keys, key).toContain(key);
    }
    expect(keys.filter((k) => k.startsWith('daypart_fit.'))).toEqual(['daypart_fit.breakfast', 'daypart_fit.lunch', 'daypart_fit.dinner', 'daypart_fit.late']);
    expect(new Set(keys).size).toBe(keys.length);
    for (const r of rows) {
      expect(r.label, r.key).not.toBe('');
      expect(r.value, r.key).not.toBe('');
      expect(r.source, r.key).not.toBe('');
      expect(['measured', 'derived', 'assumed', 'tuned'], r.key).toContain(r.tag);
    }
    const byKey = Object.fromEntries(rows.map((r) => [r.key, r.value]));
    expect(byKey.avg_ticket).toBe('$15.00');
    expect(byKey.food_cost_pct).toBe('30%');
    expect(byKey.card_fee_pct).toBe('2.6%');
    expect(byKey.truck_time_factor).toBe('10%');
    expect(byKey['daypart_fit.breakfast']).toBe('30%');
    expect(byKey.fuel_type).toBe('Gasoline');
    expect(byKey.tips_include).toBe('Off');
    expect(byKey.paid_crew).toBe('2');
  });
});

// -------------------------------------------------------------------------------------------------
// Assumptions
// -------------------------------------------------------------------------------------------------

describe('seed metadata', () => {
  it('inherits scope, bounds, tag, unit and source from the nearest enclosing object', () => {
    expect(seedMeta('host.captive_share')).toMatchObject({ value: 0.75, scope: 'owner', tag: 'tuned', min: 0.05, max: 1 });
    expect(seedMeta('segments.w_office.presence.weekday')).toMatchObject({ scope: 'owner', tag: 'derived', min: 0, max: 2 });
    expect((seedMeta('segments.w_office.presence.weekday')?.value as number[]).length).toBe(24);
    expect(seedMeta('segments.w_office.dow_factor')).toMatchObject({ value: [0.9, 1.19, 1.16, 1.08, 0.67], min: 0, max: 3 });
    expect(seedMeta('segments.res.holiday_day_type.major')).toMatchObject({ value: 'sunday', allowed: ['weekday', 'saturday', 'sunday'], min: null, max: null });
    // the bounds of a weather cell are the table's, its tag and source the row's
    expect(seedMeta('weather.temperature_bands.rows.50_59.open')).toMatchObject({
      value: 0.9,
      scope: 'owner',
      tag: 'derived',
      min: SEEDS.weather.temperature_bands.min,
      max: SEEDS.weather.temperature_bands.max,
    });
    expect(seedMeta('weather.temperature_bands.rows.50_59.open')?.source).toBe(SEEDS.weather.temperature_bands.rows['50_59'].source);
    expect(seedMeta('kernel.outside_option_a0')).toMatchObject({ value: 1.6, scope: 'build', tag: 'tuned' });
    expect(seedMeta('no.such.path')).toBeNull();
    expect(seedMeta('host.captive_share.nothing')).toBeNull();
    expect(seedMeta('host.captive_share')?.source).not.toBe('');
  });
});

describe('the seeds the owner may change', () => {
  const groups = assumptionGroups();
  const paths = editablePaths();

  it('are the overridable paths of 02_MODEL 2.2, each once', () => {
    // 16 segments x (6 curves + weekday factors + 2 holiday day types) + 3 host + 2 weather + 40 table cells + 5 events
    expect(paths).toHaveLength(16 * 9 + 3 + 2 + (9 + 8 + 3) * 2 + 5);
    expect(new Set(paths).size).toBe(paths.length);
    expect(groups.hosts.map((r) => r.path)).toEqual(['host.captive_share', 'host.shared_kitchen_share', 'host.onsite_kitchen_weight']);
    expect(groups.hosts.map((r) => r.editor)).toEqual(['percent', 'percent', 'number']);
    expect(groups.weather.map((r) => r.path)).toEqual(['weather.floor', 'weather.pop_when_missing']);
    expect(groups.weatherTables.map((t) => [t.id, t.rows.length])).toEqual([['temperature_bands', 9], ['precip_classes', 8], ['wind_bands', 3]]);
    expect(groups.events.map((r) => r.path)).toEqual([
      'events.attendance_haircut',
      'events.p_buy.general',
      'events.p_buy.food_focused',
      'events.p_buy.evening_show',
      'events.p_buy.incidental',
    ]);
    expect(groups.people).toHaveLength(16);
    expect(groups.people[1].label).toBe(SEEDS.segments.w_office.label);
    expect(groups.people[1].curves.map((c) => c.row.path)).toEqual([
      'segments.w_office.presence.weekday',
      'segments.w_office.presence.saturday',
      'segments.w_office.presence.sunday',
      'segments.w_office.intent.weekday',
      'segments.w_office.intent.saturday',
      'segments.w_office.intent.sunday',
    ]);
  });

  it('are all owner scope: the model accepts an override of each, and of no other seed', () => {
    for (const path of paths) {
      const meta = seedMeta(path);
      expect(meta?.scope, path).toBe('owner');
      expect(validateOverrides(SEEDS, { [path]: meta?.value as never }), path).toEqual([]);
    }
    expect(validateOverrides(SEEDS, { 'kernel.outside_option_a0': 2 })).toEqual([{ path: 'kernel.outside_option_a0', error: 'not_overridable' }]);
  });

  it('carry the bounds the model enforces', () => {
    for (const path of paths) {
      const meta = seedMeta(path);
      if (meta === null || meta.min === null || meta.max === null) continue;
      const base = meta.value;
      const outside = (x: number) => (Array.isArray(base) ? base.map((_, i) => (i === 0 ? x : (base[i] as number))) : x);
      expect(validateOverrides(SEEDS, { [path]: outside(meta.max + 0.001) as never }), path).toEqual([{ path, error: 'out_of_bounds' }]);
      expect(validateOverrides(SEEDS, { [path]: outside(meta.min - 0.001) as never }), path).toEqual([{ path, error: 'out_of_bounds' }]);
      expect(validateOverrides(SEEDS, { [path]: outside(meta.max) as never }), path).toEqual([]);
      expect(validateOverrides(SEEDS, { [path]: outside(meta.min) as never }), path).toEqual([]);
    }
  });

  it('show their range as the editor shows the value', () => {
    expect(rangeText(groups.hosts[0])).toBe('Range: 5% to 100%');
    expect(shownBounds(groups.hosts[0])).toEqual({ min: 5, max: 100 });
    expect(rangeText(groups.hosts[2])).toBe('Range: 0 to 10');
    expect(rangeText(groups.people[1].curves[0].row)).toBe('Range: 0% to 200%');
    expect(rangeText(groups.people[1].weekdays)).toBe('Range: 0 to 3');
    expect(rangeText(groups.people[1].holidays[0].row)).toBe('');
    expect(editorOf('host.captive_share')).toBe('percent');
    expect(editorOf('segments.res.dow_factor')).toBe('weekdays');
    expect(editorOf('segments.res.holiday_day_type.minor')).toBe('choice');
    expect(editorOf('kernel.walk_decay_m')).toBe('number');
  });
});

describe('the assumptions draft', () => {
  const savedOverrides: OverrideMap = { 'host.captive_share': 0.6, 'weather.floor': 0.2 };

  it('reads a value from the override, else from the seed', () => {
    expect(draftValue(savedOverrides, 'host.captive_share')).toBe(0.6);
    expect(draftValue(savedOverrides, 'host.shared_kitchen_share')).toBe(0.3);
    expect(isOverridden(savedOverrides, 'host.captive_share')).toBe(true);
    expect(isOverridden(savedOverrides, 'host.shared_kitchen_share')).toBe(false);
  });

  it('sends only the changed paths, and null for a reset', () => {
    expect(overrideChanges(savedOverrides, savedOverrides)).toEqual({});
    let draft = setDraftValue(savedOverrides, 'events.attendance_haircut', 0.5);
    expect(overrideChanges(savedOverrides, draft)).toEqual({ 'events.attendance_haircut': 0.5 });
    draft = resetDraftValue(draft, 'weather.floor');
    expect(overrideChanges(savedOverrides, draft)).toEqual({ 'events.attendance_haircut': 0.5, 'weather.floor': null });
    draft = setDraftValue(draft, 'host.captive_share', 0.65);
    expect(overrideChanges(savedOverrides, draft)).toEqual({ 'events.attendance_haircut': 0.5, 'weather.floor': null, 'host.captive_share': 0.65 });
    // the saved map is never changed in place
    expect(savedOverrides).toEqual({ 'host.captive_share': 0.6, 'weather.floor': 0.2 });
  });

  it('typing the starting value back takes the override away', () => {
    const draft = setDraftValue(savedOverrides, 'host.captive_share', 0.75);
    expect(isOverridden(draft, 'host.captive_share')).toBe(false);
    expect(overrideChanges(savedOverrides, draft)).toEqual({ 'host.captive_share': null });
    const curve = (seedMeta('segments.w_office.presence.weekday')?.value as number[]).slice();
    expect(setDraftValue({}, 'segments.w_office.presence.weekday', curve)).toEqual({});
    curve[12] = 0.5;
    expect(setDraftValue({}, 'segments.w_office.presence.weekday', curve)).toEqual({ 'segments.w_office.presence.weekday': curve });
    expect(resetDraftValue(savedOverrides, 'events.attendance_haircut')).toBe(savedOverrides);
  });

  it('is checked by validate_overrides, with a sentence per code', () => {
    expect(overrideErrors(savedOverrides)).toEqual({});
    expect(overrideErrors({ 'host.captive_share': 1.5 })).toEqual({ 'host.captive_share': 'Enter a number from 5 to 100.' });
    expect(overrideErrors({ 'host.onsite_kitchen_weight': 11 })).toEqual({ 'host.onsite_kitchen_weight': 'Enter a number from 0 to 10.' });
    expect(overrideErrors({ 'segments.w_office.presence.weekday': [0.1, 0.2] })).toEqual({ 'segments.w_office.presence.weekday': 'Enter all 24 values.' });
    expect(overrideErrors({ 'segments.res.holiday_day_type.major': 'monday' })).toEqual({ 'segments.res.holiday_day_type.major': 'Choose one of the listed options.' });
    expect(overrideErrors({ 'kernel.outside_option_a0': 2 })).toEqual({ 'kernel.outside_option_a0': 'This value cannot be changed.' });
    const curve = (seedMeta('segments.w_office.intent.weekday')?.value as number[]).slice();
    curve[3] = 1.4;
    expect(overrideErrors({ 'segments.w_office.intent.weekday': curve })).toEqual({ 'segments.w_office.intent.weekday': 'Enter a number from 0 to 100.' });
  });

  it('maps the details of a 422 onto the fields', () => {
    expect(serverOverrideErrors([{ path: 'host.captive_share', error: 'out_of_bounds' }, { path: 'weather.floor', error: 'wrong_shape' }])).toEqual({
      'host.captive_share': 'Enter a number from 5 to 100.',
      'weather.floor': 'Enter all 24 values.',
    });
    expect(serverOverrideErrors(null)).toEqual({});
    expect(serverOverrideErrors({ field: 'overrides', code: 'V9' })).toEqual({});
    expect(serverOverrideErrors([{ path: 1 }, 'x'])).toEqual({});
  });
});

describe('a curve in the editor', () => {
  it('is drawn in percent with a round top and names its peak', () => {
    const view = curveView(SEEDS.segments.w_office.presence.weekday);
    expect(view.percents).toHaveLength(24);
    // 02_MODEL 2.3: w_office weekday presence peaks at 0.369 at 10:00
    expect(view.peakHour).toBe(10);
    expect(view.peakPercent).toBe(36.9);
    expect(view.yMax).toBe(60);
    expect(curveView(SEEDS.segments.v_nightlife.presence.saturday).yMax).toBe(120);
    expect(curveView(new Array(24).fill(0)).yMax).toBe(3);
    expect(curveView([0.02]).percents).toEqual([2, ...new Array(23).fill(0)]);
  });
});

describe('fixed in this version', () => {
  const A = makeAssumptions({}, { id: 'dc', traffic_matrix: 'dc', flags: { inauguration_day: true } });
  const groups = fixedSeedGroups(A);
  const rows = groups.flatMap((g) => g.rows);

  it('lists the seeds nobody can change at runtime, and none the owner can', () => {
    const keys = rows.map((r) => r.key);
    expect(new Set(keys).size).toBe(keys.length);
    for (const path of editablePaths()) expect(keys, path).not.toContain(path);
    for (const key of keys) expect(key.startsWith('profile_defaults.'), key).toBe(false);
    for (const path of ['kernel.walk_decay_m', 'kernel.outside_option_a0', 'kernel.rival_weight.quick.day', 'kernel.visibility.prominent', 'host.venue_link_radius_m', 'uncertainty.sd_truck', 'calibration.k_truck', 'map.opportunity_hi', 'traffic.dc', 'money.fuel_price_fallback.gasoline.R1Z', 'constants.z80']) {
      expect(keys, path).toContain(path);
      expect(seedMeta(path)?.scope === 'build' || seedMeta(path)?.scope === 'fixed', path).toBe(true);
    }
    expect(groups.find((g) => g.id === 'place_types')?.rows).toHaveLength(22);
    expect(groups.find((g) => g.id === 'holidays')?.rows).toHaveLength(SEEDS.holidays.rules.length);
  });

  it('every row has a label, a value, a tag and a source; placeholders say so', () => {
    for (const r of rows) {
      expect(r.label, r.key).not.toBe('');
      expect(r.label, r.key).not.toBe(r.key);
      expect(r.value, r.key).not.toBe('');
      expect(r.tag, r.key).not.toBeNull();
      expect(r.source, r.key).not.toBe('');
      expect(r.note, r.key).toBe(r.tag === 'tuned' ? PLACEHOLDER_SEED : null);
    }
    const a0 = rows.find((r) => r.key === 'kernel.outside_option_a0');
    expect(a0).toMatchObject({ label: 'Pull of every other way to eat', value: '1.6', tag: 'tuned', note: 'Placeholder until you log services' });
    expect(rows.find((r) => r.key === 'place_types.rows.taproom')).toMatchObject({
      label: 'Brewery or taproom',
      value: 'typical size 40, hosts taproom and bar patrons, no food of its own',
    });
    expect(rows.find((r) => r.key === 'holidays.rules.thanksgiving')).toMatchObject({ label: 'Thanksgiving Day', value: 'Major holiday' });
  });

  it('names every seed in plain language: none falls back to the words of its path', () => {
    const labels: Record<string, string> = {};
    for (const g of groups) {
      if (g.id === 'place_types' || g.id === 'holidays') continue; // told row by row, from the tables themselves
      for (const r of g.rows) {
        expect(seedLabel(r.key), r.key).not.toBe(r.key);
        expect(r.label, r.key).toBe(seedLabel(r.key));
        expect(r.label.includes('_'), r.key).toBe(false);
        expect(labels[r.label], r.key + ' and ' + labels[r.label]).toBeUndefined();
        labels[r.label] = r.key;
      }
    }
    expect(rows.find((r) => r.key === 'money.fuel_price_fallback.gasoline.R1Z')?.label).toBe('Default gasoline price, Lower Atlantic (Virginia, West Virginia)');
    expect(rows.find((r) => r.key === 'suggest.max_stops_per_day')?.label).toBe('Most stops in a suggested day');
    expect(rows.find((r) => r.key === 'etl.cell_min_nearby')?.label).toBe('Fewest people nearby for a map hexagon to be kept');
    expect(rows.find((r) => r.key === 'constants.meters_per_mile')?.label).toBe('Metres in a mile');
  });
});

// Fixed strings (docs/truck-planner/05_FRONTEND.md section 6, 8.2).
//
// Every label of the vocabularies the product shows has a string, the strings the documents fix are
// the documents' strings character for character, and nothing in the kit says or implies that a spot
// may be used (6.9). The banned patterns live here, not in wording.ts: a truck file must not even
// contain them.

import { readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { CONFIDENCE_LABELS, PLACE_TYPES, SEEDS, SEGMENTS, WARNING_CODES, makeAssumptions, seed, validateOverrides } from '../model';
import type { DayType, EventType } from '../model';
import * as wording from '../wording';
import {
  ATTRIBUTION,
  CONFIDENCE_TEXT,
  DRIVE_SOURCE,
  HOST_SIZE_LABEL,
  MAP_TEXT,
  PROFILE_WARNING_TEXT,
  OSM_COPYRIGHT_URL,
  LEHD_URL,
  PLACE_TYPE_LABELS,
  SEED_TAG_TEXT,
  SIZE_UNIT_PHRASE,
  STANDING,
  VERDICT_WORDS,
  WARNING_PREFIX,
  WARNING_TEXT,
  WARNING_UNKNOWN,
  WHY_STEP_TITLES,
  captionSpoken,
  confidenceLabel,
  confidenceSentence,
  confidenceSpoken,
  driveFallbackReason,
  driveSourceLabel,
  mapSourceLine,
  mapSourceParts,
  numberRangeMessage,
  overrideErrorMessage,
  placeTypeLabel,
  precipClassWord,
  profileWarningTexts,
  seedLabel,
  segmentGroup,
  segmentLabel,
  sheetDriveLine,
  timeRangeMessage,
  timeStepLabel,
  treatedAsDay,
  vintagesLine,
} from '../wording';
import { repoText, sourceText, spec, specCount } from './_kitFixtures';

// 6.9, case-insensitive. "permit" does not match "permission".
const BANNED: RegExp[] = [
  /\b(il)?legal(ly|ity)?\b/i,
  /\bpermit\w*/i,
  /allowed to (park|trade|sell|vend|operate)/i,
  /\bapproved\b/i,
  /\bauthori[sz]ed\b/i,
  /\bcompliant\b/i,
  /\blawful(ly)?\b/i,
  /\bzoned\b/i,
  /\bok to park\b/i,
];

// Section 6, tone: no hype words on deterministic output.
const HYPE: RegExp[] = [/\bAI\b/, /\bsmart\b/i, /\bmagic/i, /powered by/i, /\binsights\b/i];

const DC_VINTAGES = { census_reference_date: '2020-04-01', lodes_year: 2023, osm_snapshot_date: '2026-10-03' };

// ---- source scanning -----------------------------------------------------------------------------

/** Source text without comments; string literals are kept (a "//" inside a string is not a comment). */
function stripComments(source: string): string {
  let out = '';
  let i = 0;
  let quote = '';
  while (i < source.length) {
    const c = source[i];
    const next = source[i + 1];
    if (quote !== '') {
      out += c;
      if (c === '\\') {
        out += next;
        i += 2;
        continue;
      }
      if (c === quote) quote = '';
      i += 1;
      continue;
    }
    if (c === '/' && next === '/') {
      while (i < source.length && source[i] !== '\n') i += 1;
      continue;
    }
    if (c === '/' && next === '*') {
      const end = source.indexOf('*/', i + 2);
      i = end < 0 ? source.length : end + 2;
      continue;
    }
    if (c === "'" || c === '"' || c === '`') quote = c;
    out += c;
    i += 1;
  }
  return out;
}

/** The string literals of a source text (single, double and template quotes), unescaped enough to read. */
function stringLiterals(source: string): string[] {
  const text = stripComments(source);
  const out: string[] = [];
  let i = 0;
  while (i < text.length) {
    const c = text[i];
    if (c === "'" || c === '"' || c === '`') {
      let j = i + 1;
      let value = '';
      while (j < text.length && text[j] !== c) {
        if (text[j] === '\\') {
          value += text[j + 1];
          j += 2;
          continue;
        }
        value += text[j];
        j += 1;
      }
      out.push(value);
      i = j + 1;
      continue;
    }
    i += 1;
  }
  return out;
}

function kitFiles(): string[] {
  const utils = ['format', 'time', 'links', 'wording', 'warnings', 'breakdown', 'timelineView', 'palette', 'logView'].map((n) => 'utils/truck/' + n + '.ts');
  const uiDir = fileURLToPath(new URL('../../../components/truck/ui/', import.meta.url));
  const ui = readdirSync(uiDir)
    .filter((n) => n.endsWith('.ts') || n.endsWith('.tsx'))
    .map((n) => 'components/truck/ui/' + n);
  return [...utils, ...ui, 'components/truck/truck.css'];
}

/** Every string a value holds, however deep. */
function stringsIn(value: unknown, out: string[]): void {
  if (typeof value === 'string') out.push(value);
  else if (Array.isArray(value)) for (const v of value) stringsIn(v, out);
  else if (value !== null && typeof value === 'object') for (const v of Object.values(value)) stringsIn(v, out);
}

// ---- 6.2 confidence -----------------------------------------------------------------------------

describe('confidence labels (6.2)', () => {
  it('has the fixed label and sentence for every label of the model', () => {
    expect(Object.keys(CONFIDENCE_TEXT).sort()).toEqual(CONFIDENCE_LABELS.slice().sort());
    expect(spec(confidenceLabel('very_rough'))).toBe('Very rough');
    expect(spec(confidenceSentence('very_rough'))).toBe('A guess from generic assumptions. Treat it as a ranking only.');
    expect(spec(confidenceLabel('rough'))).toBe('Rough');
    expect(spec(confidenceSentence('rough'))).toBe('Not yet checked against your own sales.');
    expect(spec(confidenceLabel('fair'))).toBe('Fair');
    expect(spec(confidenceSentence('fair'))).toBe('Adjusted with your logged services.');
    expect(spec(confidenceLabel('good'))).toBe('Good');
    expect(spec(confidenceSentence('good'))).toBe('Backed by your results at this spot.');
    expect(spec(confidenceLabel('fixed'))).toBe('Fixed');
    expect(spec(confidenceSentence('fixed'))).toBe('Set by your terms, not estimated.');
  });

  it('joins label and sentence for a spoken line', () => {
    expect(spec(confidenceSpoken('rough'))).toBe('Rough: not yet checked against your own sales.');
    expect(confidenceSpoken('very_rough')).toBe('Very rough: a guess from generic assumptions. Treat it as a ranking only.');
    expect(captionSpoken('TAKE-HOME')).toBe('Take-home');
    expect(captionSpoken('LEFT AFTER FOOD AND FEES')).toBe('Left after food and fees');
    expect(captionSpoken('Orders')).toBe('Orders');
  });
});

// ---- 6.3 standing notice -------------------------------------------------------------------------

describe('the standing notice and the standing lines (6.3)', () => {
  it('are the exact texts', () => {
    expect(spec(STANDING.noticeLine)).toBe('Permission to trade here and local rules are yours to check.');
    expect(spec(STANDING.noticeBlock)).toBe(
      'Truck Planner estimates demand. It does not know who owns this land or what the local rules say. Permission to trade here and local rules are yours to check.',
    );
    expect(spec(STANDING.underBreakdown)).toBe('Estimates rank places and times. Before you log services they are poor at predicting dollars.');
    expect(spec(STANDING.deleteSpot)).toBe('It leaves your list. Days already planned there and its logged services are kept.');
    expect(spec(STANDING.deleteEverything)).toBe(
      'This deletes your truck, spots, plans, logged services, drive-time corrections and Scout notes. It cannot be undone. Your smappen account stays.',
    );
    expect(spec(STANDING.deletePhrase)).toBe('delete my truck data');
    expect(STANDING.noticeBlock.endsWith(STANDING.noticeLine)).toBe(true);
  });

  it('prints the data vintages from the region', () => {
    expect(spec(vintagesLine(DC_VINTAGES))).toBe('Residents: April 2020. Jobs: 2023. Places: OpenStreetMap, Sat, Oct 3, 2026.');
  });

  it('has the two toasts of a profile save and says nothing for a code it does not know', () => {
    expect(spec(PROFILE_WARNING_TEXT.timezone_assumed)).toBe('We assumed Eastern time for this truck.');
    expect(spec(PROFILE_WARNING_TEXT.base_outside_region)).toBe(
      'Your base is outside the counties we have data for. Nothing can be estimated near it; the rest of the map works.',
    );
    expect(profileWarningTexts(['base_outside_region', 'timezone_assumed'])).toEqual([
      PROFILE_WARNING_TEXT.base_outside_region,
      PROFILE_WARNING_TEXT.timezone_assumed,
    ]);
    expect(profileWarningTexts([])).toEqual([]);
    expect(profileWarningTexts(['a_code_of_a_later_server', 'toString', 'timezone_assumed'])).toEqual([PROFILE_WARNING_TEXT.timezone_assumed]);
  });

  it("has the map's own strings", () => {
    expect(spec(MAP_TEXT.loading)).toBe('Loading map...');
    expect(spec(MAP_TEXT.googleFailed)).toBe('The Google map could not load, so the background map is hidden. Estimates and saved spots still work.');
    expect(spec(MAP_TEXT.zoomIn)).toBe('Zoom in');
    expect(spec(MAP_TEXT.zoomOut)).toBe('Zoom out');
    expect(spec(MAP_TEXT.blankLabel)).toBe('Background grid. Arrow keys move it, plus and minus zoom.');
  });
});

// ---- 6.4 attribution -----------------------------------------------------------------------------

describe('attribution strings (6.4)', () => {
  it('are the strings of 03_DATA section 14, character for character', () => {
    const data = repoText('docs/truck-planner/03_DATA.md');
    const section = data.slice(data.indexOf('## 14. Terms and the strings the UI must show'), data.indexOf('## 15. '));
    expect(section.length).toBeGreaterThan(1000);
    for (const text of [ATTRIBUTION.osm, ATTRIBUTION.osmSentence, ATTRIBUTION.weather, ATTRIBUTION.drive, ATTRIBUTION.mapSource, ATTRIBUTION.googleContact]) {
      expect(section.includes('`' + spec(text) + '`')).toBe(true);
    }
  });

  it('fills the map source line from the vintages of the region', () => {
    expect(spec(mapSourceLine(DC_VINTAGES))).toBe('People: US Census 2020, LEHD 2023 · Venues: © OpenStreetMap contributors');
    expect(mapSourceParts(DC_VINTAGES)).toEqual({ lead: 'People: US Census 2020, LEHD 2023 · Venues: ', linked: '© OpenStreetMap contributors' });
    // a string whose placeholder cannot be filled is left out
    expect(mapSourceLine(null)).toBeNull();
    expect(mapSourceLine(undefined)).toBeNull();
    expect(mapSourceLine({ census_reference_date: '', lodes_year: 2023, osm_snapshot_date: '2026-10-03' })).toBeNull();
    expect(mapSourceLine({ census_reference_date: '2020-04-01', lodes_year: 0, osm_snapshot_date: '2026-10-03' })).toBeNull();
  });

  it('prints the day sheet drive line by what the legs are', () => {
    expect(spec(sheetDriveLine(false, false))).toBe('Drive times: Google Maps Platform, adjusted for the time of day.');
    expect(spec(sheetDriveLine(false, true))).toBe('Drive times: Google Maps Platform.');
    expect(spec(sheetDriveLine(true, false))).toBe('Some drive times are straight-line estimates, not Google drive times.');
    expect(sheetDriveLine(true, true)).toBe(ATTRIBUTION.sheetDriveStraight);
  });

  it('links only to the two source pages', () => {
    expect(spec(OSM_COPYRIGHT_URL)).toBe('https://www.openstreetmap.org/copyright');
    expect(spec(LEHD_URL)).toBe('https://lehd.ces.census.gov/data/');
  });
});

// ---- 6.5 warnings --------------------------------------------------------------------------------

describe('planner warnings (6.5)', () => {
  it('has a sentence for each of the 21 codes and for nothing else', () => {
    expect(WARNING_CODES.length).toBe(21);
    expect(Object.keys(WARNING_TEXT).sort()).toEqual(WARNING_CODES.slice().sort());
    for (const code of WARNING_CODES) {
      expect(WARNING_TEXT[code].length).toBeGreaterThan(10);
      expect(WARNING_TEXT[code].endsWith('.')).toBe(true);
    }
  });

  it('has the fixed prefixes and the fallback', () => {
    expect(spec(WARNING_PREFIX.error)).toBe('Problem:');
    expect(spec(WARNING_PREFIX.warn)).toBe('Check:');
    expect(spec(WARNING_PREFIX.info)).toBe('Note:');
    expect(spec(WARNING_UNKNOWN)).toBe('Check this stop.');
  });
});

// ---- 6.6 seed tags -------------------------------------------------------------------------------

describe('seed tags (6.6)', () => {
  it('has the chip and the hint of every tag', () => {
    expect(Object.keys(SEED_TAG_TEXT).sort()).toEqual(['assumed', 'derived', 'measured', 'tuned']);
    expect(spec(SEED_TAG_TEXT.measured)).toEqual({ chip: 'Measured', hint: 'Published by a source we opened.' });
    expect(spec(SEED_TAG_TEXT.derived)).toEqual({ chip: 'Derived', hint: 'Worked out from measured figures.' });
    expect(spec(SEED_TAG_TEXT.assumed)).toEqual({ chip: 'Assumed', hint: 'Our judgement. Not measured.' });
    expect(spec(SEED_TAG_TEXT.tuned)).toEqual({ chip: 'Placeholder', hint: 'Placeholder until you log services.' });
    expect(spec(wording.PLACEHOLDER_SEED)).toBe('Placeholder until you log services');
  });
});

// ---- 6.7 drive-time source labels ----------------------------------------------------------------

describe('drive-time source labels (6.7)', () => {
  it('labels a leg by its source', () => {
    expect(spec(driveSourceLabel({ legSource: 'override', driveSource: 'google_routes', departMinute: 619, trafficNeutral: false }))).toBe('Your time');
    expect(spec(driveSourceLabel({ legSource: 'google', driveSource: 'google_routes', departMinute: 619, trafficNeutral: false }))).toBe(
      'Google drive time, adjusted for 10:19 AM traffic',
    );
    expect(driveSourceLabel({ legSource: 'google', driveSource: 'google_distance_matrix', departMinute: 1020, trafficNeutral: false })).toBe(
      'Google drive time, adjusted for 5 PM traffic',
    );
    expect(spec(driveSourceLabel({ legSource: 'google', driveSource: 'google_routes', departMinute: 619, trafficNeutral: true }))).toBe('Google drive time');
    expect(spec(driveSourceLabel({ legSource: 'google', driveSource: 'same_point', departMinute: 860, trafficNeutral: false }))).toBe('Same place');
    expect(spec(driveSourceLabel({ legSource: 'fallback', driveSource: 'straight_line', departMinute: 619, trafficNeutral: false }))).toBe('Straight-line estimate');
    // no leg at all: the model filled it with its straight-line fallback
    expect(driveSourceLabel({ legSource: 'fallback', driveSource: null, departMinute: 619, trafficNeutral: false })).toBe('Straight-line estimate');
    expect(Object.values(DRIVE_SOURCE).length).toBe(4);
  });

  it('gives the reason of a straight line', () => {
    expect(spec(driveFallbackReason('no_key'))).toBe('Google drive times are not switched on for this server.');
    expect(driveFallbackReason('refused')).toBe('Google drive times are not switched on for this server.');
    expect(spec(driveFallbackReason('quota'))).toBe('The Google drive-time allowance is used up for now.');
    expect(driveFallbackReason('budget')).toBe('The Google drive-time allowance is used up for now.');
    expect(driveFallbackReason('rate')).toBe('The Google drive-time allowance is used up for now.');
    expect(spec(driveFallbackReason('timeout'))).toBe('Google did not answer in time.');
    expect(driveFallbackReason('upstream')).toBe('Google did not answer in time.');
    expect(spec(driveFallbackReason('route_not_found'))).toBe('Google found no route.');
    expect(driveFallbackReason('cache_only')).toBeNull();
    expect(driveFallbackReason(null)).toBeNull();
    expect(driveFallbackReason(undefined)).toBeNull();
  });
});

// ---- 6.8 place types and segments ----------------------------------------------------------------

describe('place type and segment labels (6.8)', () => {
  it('has the label of every place type of the seed file', () => {
    expect(Object.keys(PLACE_TYPE_LABELS).sort()).toEqual(PLACE_TYPES.slice().sort());
    expect(PLACE_TYPES.length).toBe(22);
    const expected: Record<string, string> = {
      taproom: 'Brewery or taproom',
      bar: 'Bar or pub',
      restaurant: 'Restaurant',
      fast_food: 'Fast food',
      cafe: 'Cafe',
      convenience: 'Convenience or grocery store',
      gym: 'Gym or sports centre',
      park: 'Park',
      shopping_centre: 'Shopping centre',
      big_box: 'Big-box store',
      campus: 'College campus',
      hospital: 'Hospital',
      transit_station: 'Transit station',
      events_venue: 'Events venue',
      stadium: 'Stadium',
      hotel: 'Hotel',
      attraction: 'Museum or attraction',
      farmers_market: 'Farmers market',
      office_park: 'Office park',
      apartment_community: 'Apartment community',
      industrial_site: 'Industrial site',
      car_dealership: 'Car dealership',
    };
    for (const type of PLACE_TYPES) expect(spec(placeTypeLabel(type))).toBe(expected[type]);
    expect(placeTypeLabel('something_new')).toBe('Place');
    expect(placeTypeLabel(null)).toBe('Place');
  });

  it('takes segment labels from the seed file', () => {
    expect(SEGMENTS.length).toBe(16);
    for (const s of SEGMENTS) {
      expect(segmentLabel(s)).toBe(SEEDS.segments[s].label);
      expect(segmentLabel(s).length).toBeGreaterThan(3);
      expect(['residents', 'workers', 'visitors']).toContain(segmentGroup(s));
    }
    expect(segmentLabel('w_office')).toBe('Office workers');
    expect(segmentLabel('v_nightlife')).toBe('Taproom and bar patrons');
  });

  it('has the unit phrase and the field label of each group', () => {
    expect(spec(SIZE_UNIT_PHRASE.visitors)).toBe('people in its busiest hour');
    expect(spec(SIZE_UNIT_PHRASE.workers)).toBe('people working there');
    expect(spec(SIZE_UNIT_PHRASE.residents)).toBe('people living there');
    expect(spec(HOST_SIZE_LABEL.visitors)).toBe('People there in its busiest hour');
    expect(spec(HOST_SIZE_LABEL.workers)).toBe('People who work there');
    expect(spec(HOST_SIZE_LABEL.residents)).toBe('People who live there');
  });
});

// ---- the rest of the fixed vocabulary -------------------------------------------------------------

describe('kit strings', () => {
  it('has the thirteen step titles in order', () => {
    expect(spec(WHY_STEP_TITLES.slice())).toEqual([
      'Who is within walking distance',
      'How many of them buy a meal this hour',
      'What share the truck wins',
      'Menu fit',
      'Host',
      'Subtotal before adjustments',
      'Weather',
      'Your own results',
      'Capacity',
      'Window',
      'Range and label',
      'Money',
      'Where the assumptions come from',
    ]);
  });

  it('has the verdict words, the field messages and the chip words', () => {
    expect(Object.keys(VERDICT_WORDS)).toEqual(['inside', 'above', 'below', 'sold_out']);
    expect(spec(numberRangeMessage(1, 200))).toBe('Enter a number from 1 to 200.');
    expect(numberRangeMessage(0.5, 20)).toBe('Enter a number from 0.5 to 20.');
    expect(numberRangeMessage(1, 200000)).toBe('Enter a number from 1 to 200,000.');
    expect(numberRangeMessage(1, 600, true)).toBe('Enter a whole number from 1 to 600.');
    expect(numberRangeMessage(0, null)).toBe('Enter a number of 0 or more.');
    expect(numberRangeMessage(undefined, 5)).toBe('Enter a number of 5 or less.');
    expect(numberRangeMessage(null, null)).toBe('Enter a number.');
    expect(spec(timeRangeMessage('11:00 AM', '2:00 PM'))).toBe('Enter a time from 11:00 AM to 2:00 PM.');
    expect(spec(timeStepLabel(15, false))).toBe('15 minutes earlier');
    expect(spec(timeStepLabel(15, true))).toBe('15 minutes later');
    expect(spec(wording.FIELD.timeHelp)).toBe('Type a time, like 11 or 2:30 pm.');
    expect(spec(wording.FIELD.required)).toBe('Required');
    expect(spec(overrideErrorMessage('out_of_bounds', 0.05, 1))).toBe('Enter a number from 0.05 to 1.');
    expect(spec(overrideErrorMessage('wrong_shape'))).toBe('Enter all 24 values.');
    expect(spec(overrideErrorMessage('not_allowed'))).toBe('Choose one of the listed options.');
    expect(spec(overrideErrorMessage('not_overridable'))).toBe('This value cannot be changed.');
    expect(spec(wording.OPEN_IN_MAPS.point)).toBe('Open in Google Maps');
    expect(spec(wording.OPEN_IN_MAPS.route)).toBe('Open route in Google Maps');
    expect(spec(wording.CHART.truckLimit)).toBe('Truck limit');
    expect(spec(precipClassWord('rain'))).toBe('Rain');
    expect(spec(precipClassWord('storm'))).toBe('Storms');
    expect(spec(precipClassWord('snow'))).toBe('Snow');
    expect(spec(precipClassWord('dry'))).toBe('Dry');
    expect(spec(wording.WEATHER.none)).toBe('No forecast yet');
    expect(spec(wording.WEATHER.noneHelp)).toBe('Forecasts cover about six days.');
    expect(spec(wording.WEATHER.title)).toBe('Forecast for the area around your base');
    expect(spec(wording.WEATHER.noChance)).toBe('The forecast gives no chance for these hours.');
    expect(spec(treatedAsDay('Saturday'))).toBe('Treated as a Saturday');
    expect(spec(wording.HOLIDAY_CHIP.ignored)).toBe('Holiday ignored');
    for (const cls of SEEDS.weather.precip_classes.order) expect(precipClassWord(cls)).not.toBe('Rain or snow');
    for (const band of SEEDS.weather.temperature_bands.order) expect(Object.keys(wording.TEMPERATURE_BAND_LABELS)).toContain(band);
    for (const band of SEEDS.weather.wind_bands.order) expect(Object.keys(wording.WIND_BAND_LABELS)).toContain(band);
  });

  it('has the timeline labels of 3.10', () => {
    expect(spec(Object.values(wording.TIMELINE_EVENT_LABELS))).toEqual([
      'Start prep',
      'Leave base',
      'Arrive at {stop}',
      'Start setting up',
      'Open',
      'Close',
      'Leave {stop}',
      'Back at base',
      'Done',
    ]);
    expect(spec(wording.TIMELINE_SEGMENT_LABELS.wait)).toBe('Waiting, paid');
    expect(spec(wording.TIMELINE_SEGMENT_LABELS.wait_unpaid)).toBe('Break, unpaid');
  });

  it('names every seed the owner may change in plain language', () => {
    const A = makeAssumptions({}, { id: 'dc', traffic_matrix: 'dc', flags: { inauguration_day: true } });
    const paths: string[] = ['host.captive_share', 'host.shared_kitchen_share', 'host.onsite_kitchen_weight', 'weather.floor', 'weather.pop_when_missing', 'events.attendance_haircut'];
    for (const s of SEGMENTS) {
      for (const d of ['weekday', 'saturday', 'sunday'] as DayType[]) paths.push('segments.' + s + '.presence.' + d, 'segments.' + s + '.intent.' + d);
      paths.push('segments.' + s + '.dow_factor', 'segments.' + s + '.holiday_day_type.major', 'segments.' + s + '.holiday_day_type.minor');
    }
    for (const table of ['temperature_bands', 'precip_classes', 'wind_bands'] as const) {
      for (const row of SEEDS.weather[table].order) paths.push('weather.' + table + '.rows.' + row + '.open', 'weather.' + table + '.rows.' + row + '.captive');
    }
    for (const type of ['general', 'food_focused', 'evening_show', 'incidental'] as EventType[]) paths.push('events.p_buy.' + type);
    expect(paths.length).toBe(6 + 16 * 9 + (9 + 8 + 3) * 2 + 4);
    const labels: Record<string, string> = {};
    for (const path of paths) {
      // the path is a real, overridable seed ...
      expect(validateOverrides(SEEDS, { [path]: seed(A, path) })).toEqual([]);
      // ... and it has a label of its own
      const label = seedLabel(path);
      expect(label).not.toBe(path);
      expect(label.includes('_')).toBe(false);
      expect(labels[label]).toBeUndefined();
      labels[label] = path;
    }
    expect(seedLabel('segments.w_office.presence.weekday')).toBe('Office workers: people present by hour, weekday');
    expect(seedLabel('weather.temperature_bands.rows.50_59.open')).toBe('50 to 59°F: open-air spots');
    expect(seedLabel('events.p_buy.food_focused')).toBe('Share of people who buy a meal: food is the draw');
    expect(seedLabel('no.such.path')).toBe('no.such.path');
  });
});

// ---- 6.9 banned wording ---------------------------------------------------------------------------

describe('banned wording (6.9)', () => {
  it('finds the banned patterns where they are (the test of the test)', () => {
    const hit = (text: string) => BANNED.some((re) => re.test(text));
    for (const text of ['This spot is legal.', 'illegally parked', 'A permit is needed', 'permitted hours', 'You are allowed to park here', 'Approved by the county', 'an authorised spot', 'authorized', 'fully compliant', 'lawful', 'zoned for vending', 'OK to park']) {
      expect(hit(text)).toBe(true);
    }
    for (const text of [STANDING.noticeLine, STANDING.noticeBlock, 'Permission is yours to check.', 'outside_allowed_hours', 'the days or hours you set', 'a paralegal', 'legalese', 'not_allowed']) {
      expect(hit(text), text).toBe(false);
    }
  });

  it('no string of wording.ts matches a banned pattern', () => {
    const all: string[] = [];
    stringsIn(wording, all);
    // the functions, for representative inputs
    all.push(
      vintagesLine(DC_VINTAGES),
      mapSourceLine(DC_VINTAGES) as string,
      numberRangeMessage(1, 2),
      numberRangeMessage(1, null),
      numberRangeMessage(null, 2, true),
      timeRangeMessage('a', 'b'),
      timeStepLabel(5, true),
      treatedAsDay('Monday'),
      placeTypeLabel('x'),
      precipClassWord('x'),
      wording.stopFallbackName(0),
      sheetDriveLine(true, true),
    );
    for (const code of ['out_of_bounds', 'wrong_shape', 'not_allowed', 'unknown_path']) all.push(overrideErrorMessage(code, 0, 1));
    for (const reason of ['no_key', 'quota', 'timeout', 'route_not_found'] as const) all.push(driveFallbackReason(reason) as string);
    for (const c of CONFIDENCE_LABELS) all.push(confidenceSpoken(c));
    all.push(...stringLiterals(sourceText('utils/truck/wording.ts')));
    expect(all.length).toBeGreaterThan(400);
    for (const text of all) {
      for (const re of BANNED) expect(re.test(text), text).toBe(false);
      for (const re of HYPE) expect(re.test(text), text).toBe(false);
    }
  });

  it('nothing in the kit matches a banned pattern, in code, text or style', () => {
    const files = kitFiles();
    expect(files.length).toBeGreaterThan(20);
    for (const file of files) {
      const text = stripComments(sourceText(file));
      for (const re of BANNED) expect(re.test(text), file + ' ' + String(re)).toBe(false);
      for (const literal of stringLiterals(sourceText(file))) {
        for (const re of HYPE) expect(re.test(literal), file + ': ' + literal).toBe(false);
      }
    }
  });

  it('"permission" appears only inside the standing notice, and "licence" nowhere in the kit', () => {
    for (const file of kitFiles()) {
      for (const literal of stringLiterals(sourceText(file))) {
        // text for people has a space; an import path such as './PermissionNotice' is a file name
        if (/permission/i.test(literal) && literal.includes(' ')) expect([STANDING.noticeLine, STANDING.noticeBlock], file).toContain(literal);
        // the trading kind; the "License" of the Open Database License is the name of a document
        expect(/licence/i.test(literal), file + ': ' + literal).toBe(false);
      }
    }
  });

  it('has no exclamation mark, no emoji and no dingbat in any string of the kit', () => {
    for (const file of kitFiles()) {
      if (file.endsWith('.css')) continue;
      for (const literal of stringLiterals(sourceText(file))) {
        // a string that is text for people: it has a space and a letter
        if (/[A-Za-z]/.test(literal) && literal.includes(' ') && !literal.includes('${') && !/[=<>|&]/.test(literal)) {
          expect(literal.includes('!'), file + ': ' + literal).toBe(false);
        }
      }
      const text = sourceText(file);
      for (const ch of text) {
        const cp = ch.codePointAt(0) as number;
        expect((cp >= 0x1f300 && cp <= 0x1faff) || (cp >= 0x2600 && cp <= 0x27bf), file + ' U+' + cp.toString(16)).toBe(false);
      }
    }
  });
});

describe('worked examples', () => {
  it('this file asserts 110 values the specification prints', () => {
    expect(specCount()).toBe(110);
  });
});

// Truck Planner - "Why this number" (docs/truck-planner/05_FRONTEND.md 3.4, 02_MODEL section 5).
//
// whySteps turns a result of the model into the thirteen steps of the breakdown, always in the same
// order. A step may be collapsed by the drawer, never reordered, merged or dropped; a step with
// nothing to say for a subject prints one line saying so. Nothing is recomputed here: every figure is
// read from the result (the two exceptions are calls into the model itself: the uncapped range for
// the "limited by capacity" line, and the money of a window that arrives without it).

import { SEGMENTS, evidenceFrom, interval, qkey, seed, stopMoney } from './model';
import type {
  Assumptions,
  CalibrationState,
  DayContext,
  DayResult,
  DayStop,
  Estimate,
  EventHour,
  EventTerms,
  HourForecast,
  HourResult,
  HourSegment,
  Leg,
  LocationVectors,
  SeedTag,
  SegmentKey,
  SpotTerms,
  Spread,
  StopMoney,
  TruckProfile,
  WeatherDetail,
  WindowHour,
  WindowResult,
} from './model';
import {
  fmtAbout,
  fmtCeil,
  fmtClockShort,
  fmtCount,
  fmtCount1,
  fmtDuration,
  fmtEstimate,
  fmtFuel,
  fmtHours,
  fmtMiles,
  fmtMoney,
  fmtMoneyCents,
  fmtNumber,
  fmtPercent,
  fmtPlain,
  fmtRange,
  fmtTemp,
  fmtWeekday,
  fmtWindow,
} from './format';
import type { EstimateUnit } from './format';
import {
  CONFIDENCE_TEXT,
  DAYPART_LABELS,
  DAYPART_WORDS,
  DAY_TYPE_LABELS,
  EVENT_TYPE_LABELS,
  FUEL_SOURCE_PHRASE,
  GROUP_BASE_PHRASE,
  GROUP_PRESENT_PHRASE,
  MONEY_LINE_LABELS,
  PLACEHOLDER_SEED,
  SIZE_UNIT_PHRASE,
  SPREAD_PART_LABELS,
  TEMPERATURE_BAND_LABELS,
  VISIBILITY_TEXT,
  WHY,
  WHY_STEP_TITLES,
  WIND_BAND_LABELS,
  precipClassWord,
  seedLabel,
  segmentGroup,
  segmentLabel,
  stopFallbackName,
} from './wording';

// -------------------------------------------------------------------------------------------------
// Shapes
// -------------------------------------------------------------------------------------------------

/** What the drawer explains. `ctxNext` is the next date's context, needed only past midnight. */
export type WhySubject =
  | {
      kind: 'window';
      title: string;
      window: WindowResult;
      vectors: LocationVectors;
      terms: SpotTerms;
      ctx: DayContext;
      ctxNext?: DayContext | null;
      money?: StopMoney;
    }
  | { kind: 'event'; title: string; stop: DayStop; event: EventTerms; ctx: DayContext; ctxNext?: DayContext | null }
  | { kind: 'day'; title: string; result: DayResult; stopNames: string[]; ctx: DayContext };

export interface WhyRow {
  label: string;
  value: string;
  note?: string;
}

/** A closed disclosure under a step: "Show all 16", "Technical detail". */
export interface WhyMore {
  label: string;
  rows: WhyRow[];
}

/** One seed of step 13. */
export interface WhySeed {
  path: string;
  /** Plain-language name of the seed. */
  label: string;
  /** The value in use: the owner's when overridden. */
  value: string;
  unit: string;
  tag: SeedTag | null;
  source: string;
  overridden: boolean;
  /** The seed file's own value, set only when the owner overrode it. */
  startingValue: string | null;
  /** True for a seed tagged "tuned": it must never read as a finding. */
  placeholder: boolean;
  /** "Placeholder until you log services" for a placeholder, else null. */
  note: string | null;
}

export interface WhyStep {
  /** 1..13. */
  n: number;
  title: string;
  /** Whole sentences, printed above the figures. */
  lines: string[];
  /** Figures: a two-column list. */
  rows: WhyRow[];
  more: WhyMore | null;
  /** The seeds this step rests on. */
  seedPaths: string[];
  /** Filled for step 13 only: every seed of steps 1 to 12. */
  seeds: WhySeed[];
}

export interface WhyOptions {
  /** Which hour of a window steps 1 to 9 describe; the busiest hour when left out. */
  hourIndex?: number;
}

// -------------------------------------------------------------------------------------------------
// Small helpers
// -------------------------------------------------------------------------------------------------

function step(n: number): WhyStep {
  return { n, title: WHY_STEP_TITLES[n - 1], lines: [], rows: [], more: null, seedPaths: [], seeds: [] };
}

function lowerFirst(text: string): string {
  if (text.length === 0) return text;
  const code = text.charCodeAt(0);
  return code >= 65 && code <= 90 ? String.fromCharCode(code + 32) + text.slice(1) : text;
}

/** A count of people or meals: one decimal under 10, else as fmtAbout rounds it. */
function about(x: number): string {
  return x < 10 ? fmtCount1(x) : fmtAbout(x);
}

/** A share: one decimal under 10 %, so a small presence does not print as 0 %. */
function pct(p: number): string {
  return p > 0 && p < 0.1 ? fmtPercent(p, 1) : fmtPercent(p);
}

function factor(x: number): string {
  return 'x' + fmtNumber(x, 2);
}

function orders1(x: number): string {
  return fmtCount1(x) + ' orders';
}

function hasKey(map: object, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(map, key);
}

function stopNameOf(names: readonly string[], index: number): string {
  const name = names[index];
  return typeof name === 'string' && name !== '' ? name : stopFallbackName(index);
}

/** The minutes an hour of a window covers, as the owner reads them: "11:30 AM to 12 PM". */
function hourSpan(open: number, close: number, dayIndex: number, hour: number): { from: number; to: number } {
  const start = (dayIndex * 24 + hour) * 60;
  return { from: start < open ? open : start, to: start + 60 > close ? close : start + 60 };
}

/** The busiest hour of a window (most orders after the fraction); the first hour on a tie. */
export function defaultHourIndex(window: WindowResult): number {
  let best = 0;
  let bestKey = -1;
  for (let i = 0; i < window.hours.length; i++) {
    const key = qkey(window.hours[i].result.orders * window.hours[i].fraction);
    if (key > bestKey) {
      bestKey = key;
      best = i;
    }
  }
  return best;
}

/** The label of each hour of a window, for the hour picker: "11 AM", "12 PM", "1 PM". */
export function whyHourLabels(window: WindowResult): string[] {
  const out: string[] = [];
  for (let i = 0; i < window.hours.length; i++) {
    const h = window.hours[i];
    out.push(fmtClockShort(hourSpan(window.open_minute, window.close_minute, h.day_index, h.hour).from));
  }
  return out;
}

// -------------------------------------------------------------------------------------------------
// Step 13: where the assumptions come from
// -------------------------------------------------------------------------------------------------

interface SeedHint {
  hour: number | null;
  dow: number | null;
}

function seedValueText(value: unknown, hint: SeedHint): string {
  if (typeof value === 'number') return fmtPlain(value, 4);
  if (typeof value === 'string') return value;
  if (typeof value === 'boolean') return value ? 'yes' : 'no';
  if (Array.isArray(value)) {
    if (value.length > 0 && Array.isArray(value[0])) {
      return 'a table by day and hour';
    }
    if (value.length === 24 && hint.hour !== null && typeof value[hint.hour] === 'number') {
      return fmtPlain(value[hint.hour] as number, 4) + ' at ' + fmtClockShort(hint.hour * 60) + ' (one of 24 hourly values)';
    }
    if (value.length === 5 && typeof value[0] === 'number') {
      let text = '';
      for (let i = 0; i < 5; i++) text += (i > 0 ? ', ' : '') + fmtWeekday(i, 'short') + ' ' + fmtPlain(value[i] as number, 4);
      return text;
    }
    return String(value.length) + ' values';
  }
  return 'a table';
}

/** One seed with its inherited unit, tag and source note, and the owner's value when overridden. */
export function seedInfo(A: Assumptions, path: string, hint: SeedHint = { hour: null, dow: null }): WhySeed {
  let node: unknown = A.seeds;
  let unit = '';
  let tag: SeedTag | null = null;
  let source = '';
  const keys = path.split('.');
  let found = true;
  for (let i = 0; i <= keys.length; i++) {
    if (node !== null && typeof node === 'object' && !Array.isArray(node)) {
      const meta = node as Record<string, unknown>;
      if (typeof meta.unit === 'string') unit = meta.unit;
      if (meta.tag === 'measured' || meta.tag === 'derived' || meta.tag === 'assumed' || meta.tag === 'tuned') tag = meta.tag;
      if (typeof meta.source === 'string') source = meta.source;
    }
    if (i === keys.length) break;
    if (node === null || typeof node !== 'object' || Array.isArray(node) || !hasKey(node, keys[i])) {
      found = false;
      break;
    }
    node = (node as Record<string, unknown>)[keys[i]];
  }
  let own: unknown = node;
  if (found && own !== null && typeof own === 'object' && !Array.isArray(own) && hasKey(own, 'value')) {
    own = (own as Record<string, unknown>).value;
  }
  const overridden = hasKey(A.overrides, path);
  const inUse: unknown = found || overridden ? seed<unknown>(A, path) : null;
  const placeholder = tag === 'tuned';
  return {
    path,
    label: seedLabel(path),
    value: found || overridden ? seedValueText(inUse, hint) : '',
    unit,
    tag,
    source,
    overridden,
    startingValue: overridden && found ? seedValueText(own, hint) : null,
    placeholder,
    note: placeholder ? PLACEHOLDER_SEED : null,
  };
}

function finish(steps: WhyStep[], A: Assumptions, hint: SeedHint, extraSeeds: string[] = []): WhyStep[] {
  const last = steps[12];
  const seen: Record<string, true> = {};
  const paths: string[] = [];
  const add = (p: string) => {
    if (seen[p] === true) return;
    seen[p] = true;
    paths.push(p);
  };
  for (let i = 0; i < 12; i++) for (const p of steps[i].seedPaths) add(p);
  for (const p of extraSeeds) add(p);
  last.seedPaths = paths;
  for (const p of paths) last.seeds.push(seedInfo(A, p, hint));
  if (paths.length === 0) last.lines.push('No model assumption is involved: every figure here comes from your own terms.');
  return steps;
}

// -------------------------------------------------------------------------------------------------
// Pieces shared by the subjects
// -------------------------------------------------------------------------------------------------

function estimateText(e: Estimate, unit: EstimateUnit): string {
  return fmtEstimate(e, unit);
}

/** Step 11 for one spread: a row per part with its share, the range, the label, the capacity line. */
function rangeStep(
  out: WhyStep,
  spread: Spread,
  e: Estimate,
  unit: EstimateUnit,
  capped: boolean,
  usesEvent: boolean,
): void {
  const total = spread.sigma * spread.sigma;
  const parts: (keyof typeof SPREAD_PART_LABELS)[] = ['v_truck', 'v_spot', 'v_day', 'v_weak', 'v_size', 'v_event', 'v_count'];
  for (const key of parts) {
    out.rows.push({ label: SPREAD_PART_LABELS[key], value: fmtPercent(total > 0 ? spread[key] / total : 0) });
  }
  const p = fmtRange(e, unit);
  const value = unit === 'orders' ? fmtCount(e.value) : fmtMoney(e.value);
  out.lines.push('Together: ' + p + ' around ' + value + '.');
  out.lines.push(CONFIDENCE_TEXT[e.confidence].label + '. ' + CONFIDENCE_TEXT[e.confidence].sentence);
  if (capped) out.lines.push(WHY.cappedRange);
  out.more = {
    label: WHY.technicalDetail,
    rows: [
      { label: 'sigma_model', value: fmtNumber(spread.sigma_model, 4) },
      { label: 'sigma', value: fmtNumber(spread.sigma, 4) },
    ],
  };
  out.seedPaths.push('uncertainty.sd_truck', 'uncertainty.sd_spot', 'uncertainty.sd_day');
  if (spread.v_weak > 0) out.seedPaths.push('uncertainty.sd_weak');
  if (spread.v_size > 0) out.seedPaths.push('uncertainty.sd_default_size');
  if (usesEvent || spread.v_event > 0) out.seedPaths.push('uncertainty.sd_event');
  out.seedPaths.push('uncertainty.count_dispersion', 'calibration.k_truck', 'calibration.k_spot');
}

/** The money lines of a stop, each as value and range. */
function moneyRows(out: WhyStep, money: StopMoney, tipsCounted: boolean): void {
  out.rows.push({ label: MONEY_LINE_LABELS.sales, value: estimateText(money.sales, 'money') });
  out.rows.push({ label: MONEY_LINE_LABELS.food_cost, value: estimateText(money.food_cost, 'money') });
  out.rows.push({ label: MONEY_LINE_LABELS.packaging, value: estimateText(money.packaging, 'money') });
  out.rows.push({ label: MONEY_LINE_LABELS.card_fees, value: estimateText(money.card_fees, 'money') });
  out.rows.push({ label: MONEY_LINE_LABELS.spot_fee, value: estimateText(money.spot_fee, 'money') });
  if (tipsCounted) out.rows.push({ label: MONEY_LINE_LABELS.tips, value: estimateText(money.tips, 'money') });
  out.rows.push({ label: MONEY_LINE_LABELS.contribution, value: estimateText(money.contribution, 'money') });
}

function calibrationStep(out: WhyStep, cal: CalibrationState | null, truckFactor: number, spotFactor: number | null, spotId: string | null): void {
  const hasLogs = cal !== null && (cal.truck_n > 0 || Object.keys(cal.spots).length > 0);
  if (!hasLogs || cal === null) {
    out.lines.push(WHY.noLogs);
    return;
  }
  out.rows.push({
    label: 'Truck factor',
    value: factor(truckFactor),
    note:
      cal.truck_n === 0
        ? 'No logged service counts for the whole truck yet.'
        : 'From ' + fmtCount(cal.truck_n) + (cal.truck_n === 1 ? ' logged service' : ' logged services') + '. Newer ones weigh more.',
  });
  if (spotFactor !== null) {
    const here = spotId !== null && hasKey(cal.spots, spotId) ? cal.spots[spotId] : null;
    if (here === null) out.lines.push(WHY.noLogsHere);
    else {
      out.rows.push({
        label: 'This spot',
        value: factor(spotFactor),
        note: 'From ' + fmtCount(here.n) + (here.n === 1 ? ' service here.' : ' services here.'),
      });
    }
  }
  out.seedPaths.push('calibration.k_truck', 'calibration.k_spot', 'calibration.half_life_days');
}

/** The parts of one weather factor: temperature, precipitation, wind. */
function weatherRows(
  out: WhyStep,
  detail: WeatherDetail,
  setting: 'open' | 'captive',
  chance: 'given' | 'assumed' | 'skip',
  A: Assumptions,
  prefix: string,
  fc: HourForecast | null,
): void {
  const tempBand = detail.temp_band;
  const tempBandText = tempBand === null ? '' : hasKey(TEMPERATURE_BAND_LABELS, tempBand) ? TEMPERATURE_BAND_LABELS[tempBand] : tempBand;
  out.rows.push({
    label: prefix + 'Temperature',
    value: factor(detail.temp),
    note:
      tempBand === null
        ? 'Not given in the forecast.'
        : fc !== null && fc.temp_f !== null
          ? fmtTemp(fc.temp_f) + '. Band: ' + lowerFirst(tempBandText) + '.'
          : tempBandText + '.',
  });
  if (tempBand !== null) out.seedPaths.push('weather.temperature_bands.rows.' + tempBand + '.' + setting);

  const cls = detail.precip_class === null ? 'dry' : detail.precip_class;
  const word = precipClassWord(cls);
  const said = fc !== null && fc.short_forecast !== null && fc.short_forecast !== '' ? 'The forecast says "' + fc.short_forecast + '". ' : '';
  if (cls === 'dry') {
    out.rows.push({ label: prefix + 'Rain or snow', value: factor(detail.precip), note: said + word + '.' });
  } else {
    if (chance === 'assumed') {
      out.lines.push(
        'Chance of ' + lowerFirst(word) + ': not given in the forecast. Assumed ' + fmtPercent(seed<number>(A, 'weather.pop_when_missing')) + '.',
      );
      out.seedPaths.push('weather.pop_when_missing');
    } else if (chance === 'given') {
      out.rows.push({ label: prefix + 'Chance of ' + lowerFirst(word), value: fmtPercent(detail.precip_p === null ? 0 : detail.precip_p) });
    }
    out.rows.push({
      label: prefix + word,
      value: factor(detail.precip),
      note: said + 'If it comes: ' + factor(seed<number>(A, 'weather.precip_classes.rows.' + cls + '.' + setting)) + '.',
    });
    out.seedPaths.push('weather.precip_classes.rows.' + cls + '.' + setting);
  }

  const windBand = detail.wind_band;
  const windBandText = windBand === null ? '' : hasKey(WIND_BAND_LABELS, windBand) ? WIND_BAND_LABELS[windBand] : windBand;
  out.rows.push({
    label: prefix + 'Wind',
    value: factor(detail.wind),
    note:
      windBand === null
        ? 'Not given in the forecast.'
        : fc !== null && fc.wind_mph !== null
          ? fmtCount(fc.wind_mph) + ' mph. Band: ' + lowerFirst(windBandText) + '.'
          : windBandText + '.',
  });
  if (windBand !== null) out.seedPaths.push('weather.wind_bands.rows.' + windBand + '.' + setting);
  out.seedPaths.push('weather.floor');
}

// -------------------------------------------------------------------------------------------------
// A window at a spot
// -------------------------------------------------------------------------------------------------

interface SegmentPick {
  index: number;
  key: SegmentKey;
  seg: HourSegment;
  share: number;
  present: number;
}

function pickSegments(hr: HourResult): { main: SegmentPick[]; rest: SegmentPick[]; byPeople: boolean } {
  const all: SegmentPick[] = [];
  for (let i = 0; i < hr.segments.length; i++) {
    const seg = hr.segments[i];
    all.push({
      index: i,
      key: seg.segment,
      seg,
      share: hr.demand_raw > 0 ? seg.demand_raw / hr.demand_raw : 0,
      present: seg.within_present !== null ? seg.within_present : seg.nearby_present,
    });
  }
  let main = all.filter((s) => s.share >= 0.01);
  let byPeople = false;
  if (main.length === 0) {
    // No demand at this hour: show who is around instead, so the step is never empty by accident.
    byPeople = true;
    main = all.filter((s) => s.present >= 1);
    main.sort((a, b) => (qkey(a.present) !== qkey(b.present) ? qkey(b.present) - qkey(a.present) : a.index - b.index));
    main = main.slice(0, 6);
  } else {
    main.sort((a, b) => (qkey(a.share) !== qkey(b.share) ? qkey(b.share) - qkey(a.share) : a.index - b.index));
  }
  const taken: Record<number, true> = {};
  for (const s of main) taken[s.index] = true;
  return { main, rest: all.filter((s) => taken[s.index] !== true), byPeople };
}

function whoRow(s: SegmentPick, vectors: LocationVectors, ctx: DayContext | null): WhyRow {
  const group = segmentGroup(s.key);
  const weighted = s.seg.within_present === null || vectors.within === null;
  const base = weighted ? vectors.nearby[s.index] : (vectors.within as number[])[s.index];
  if (!(base > 0)) return { label: segmentLabel(s.key), value: 'Nobody nearby' };
  let note =
    about(base) + ' ' + GROUP_BASE_PHRASE[group] + (weighted ? ' (distance-weighted)' : '') + ' x ' + pct(s.seg.presence) + ' ' + GROUP_PRESENT_PHRASE[group];
  if (ctx !== null) {
    const dayType = ctx.day_type[s.index];
    const dowFactor = ctx.dow_factor[s.index];
    if (hasKey(DAY_TYPE_LABELS, dayType)) {
      note += ' (' + lowerFirst(DAY_TYPE_LABELS[dayType]) + ' pattern';
      if (dowFactor !== 1) note += ', ' + fmtWeekday(ctx.eff_dow) + ' factor ' + factor(dowFactor);
      note += ')';
    }
  }
  return {
    label: segmentLabel(s.key),
    value: 'about ' + about(s.present) + ' people' + (weighted ? ', distance-weighted' : ''),
    note: note + '.',
  };
}

function windowSteps(
  subject: Extract<WhySubject, { kind: 'window' }>,
  A: Assumptions,
  profile: TruckProfile,
  cal: CalibrationState | null,
  opts: WhyOptions,
): WhyStep[] {
  const w = subject.window;
  const { vectors, terms } = subject;
  const steps: WhyStep[] = [];
  for (let n = 1; n <= 13; n++) steps.push(step(n));
  const hint: SeedHint = { hour: null, dow: subject.ctx.eff_dow };

  let index = opts.hourIndex === undefined ? defaultHourIndex(w) : Math.floor(opts.hourIndex);
  if (!(index >= 0)) index = 0;
  if (index >= w.hours.length) index = w.hours.length - 1;
  const wh: WindowHour | null = w.hours.length > 0 ? w.hours[index] : null;

  if (wh === null) {
    for (let n = 1; n <= 9; n++) steps[n - 1].lines.push(WHY.noHours);
  } else {
    const hr = wh.result;
    hint.hour = hr.hour;
    const ctxOfHour: DayContext | null = wh.day_index === 0 ? subject.ctx : subject.ctxNext === undefined ? null : subject.ctxNext;
    const picked = pickSegments(hr);
    const cutoff = fmtCount(seed<number>(A, 'kernel.walk_cutoff_m'));

    // 1. Who is within walking distance
    const s1 = steps[0];
    if (picked.main.length === 0) s1.lines.push(WHY.nobodyNearby);
    else {
      s1.lines.push(
        vectors.within === null
          ? 'People around this point at this hour. Nearer people count more.'
          : 'People within ' + cutoff + ' m at this hour.',
      );
      if (picked.byPeople) s1.lines.push('No orders are expected at this hour, so the groups are listed by how many people are here.');
      for (const s of picked.main) {
        s1.rows.push(whoRow(s, vectors, ctxOfHour));
        if (ctxOfHour !== null) s1.seedPaths.push('segments.' + s.key + '.presence.' + ctxOfHour.day_type[s.index]);
        if (ctxOfHour !== null && ctxOfHour.day_type[s.index] === 'weekday') s1.seedPaths.push('segments.' + s.key + '.dow_factor');
      }
    }
    if (hr.host !== null) s1.lines.push("The host's own people are counted separately, in the host step.");
    s1.more = { label: WHY.showAllSegments, rows: picked.rest.map((s) => whoRow(s, vectors, ctxOfHour)) };
    s1.seedPaths.push('kernel.walk_cutoff_m', 'kernel.walk_decay_m');

    // 2. How many of them buy a meal this hour
    const s2 = steps[1];
    if (picked.main.length === 0) s2.lines.push(WHY.nobodyNearby);
    else {
      s2.lines.push('Meals bought this hour from any outlet, not only from your truck.');
      for (const s of picked.main) {
        s2.rows.push({
          label: segmentLabel(s.key),
          value: pct(s.seg.intent) + ' buy a meal: about ' + about(s.present * s.seg.intent) + ' meals',
        });
        if (ctxOfHour !== null) s2.seedPaths.push('segments.' + s.key + '.intent.' + ctxOfHour.day_type[s.index]);
      }
    }

    // 3. What share the truck wins
    const s3 = steps[2];
    s3.lines.push('After walking distance, other ways to eat and the food outlets nearby.');
    if (vectors.within === null) s3.lines.push(WHY.sharesNotStored);
    else {
      for (const s of picked.main) {
        const within = vectors.within[s.index];
        if (within > 0) s3.rows.push({ label: segmentLabel(s.key), value: pct(s.seg.capture / within) });
      }
    }
    s3.rows.push({
      label: 'Pull of nearby food outlets',
      value: fmtCount1(vectors.rivals[hr.regime]),
      note: hr.regime === 'day' ? 'Day weights. 1 equals one quick-service outlet at this exact point.' : 'Evening weights. 1 equals one quick-service outlet at this exact point.',
    });
    s3.rows.push({ label: 'How easy the truck is to see', value: VISIBILITY_TEXT[terms.visibility].label });
    s3.seedPaths.push('kernel.outside_option_a0', 'kernel.visibility.' + terms.visibility);

    // 4. Menu fit
    const s4 = steps[3];
    s4.rows.push({
      label: DAYPART_LABELS[hr.daypart],
      value: fmtPercent(hr.factors.menu_fit),
      note: 'From your truck settings: how well your menu fits this part of the day.',
    });

    // 5. Host
    const s5 = steps[4];
    const hh = hr.host;
    if (hh === null) s5.lines.push(WHY.noHost);
    else {
      const group = segmentGroup(hh.segment);
      const byDefault = terms.host !== null && terms.host.size_source === 'default';
      const onlyFood = terms.host === null ? true : terms.host.only_food;
      s5.rows.push({ label: "The host's people", value: segmentLabel(hh.segment) });
      s5.rows.push({
        label: 'Size',
        value: fmtCount(hh.size) + ' ' + SIZE_UNIT_PHRASE[group],
        note: byDefault ? 'A typical figure for this kind of place, not yours.' : 'Your figure.',
      });
      s5.rows.push({ label: 'There at this hour', value: 'about ' + about(hh.people_present) + ' people', note: pct(hh.presence) + ' of its size.' });
      s5.rows.push({ label: 'Buying a meal this hour', value: pct(hh.intent) });
      s5.rows.push({
        label: "The truck's share of those meals",
        value: pct(hh.share),
        note:
          hh.mode === 'captive'
            ? onlyFood
              ? 'People inside the venue. Your truck is the only food.'
              : 'People inside the venue. The host sells its own food.'
            : onlyFood
              ? "The host's people choose between your truck, nearby outlets and other ways to eat."
              : "The host's people choose between your truck, the host's own kitchen, nearby outlets and other ways to eat.",
      });
      if (hh.mode === 'captive') s5.seedPaths.push(onlyFood ? 'host.captive_share' : 'host.shared_kitchen_share');
      else {
        s5.seedPaths.push('kernel.outside_option_a0', 'kernel.visibility.' + terms.visibility);
        if (!onlyFood) s5.seedPaths.push('host.onsite_kitchen_weight');
      }
      if (ctxOfHour !== null) {
        const hi = hr.segments.findIndex((seg) => seg.segment === hh.segment);
        if (hi >= 0) {
          s5.seedPaths.push('segments.' + hh.segment + '.presence.' + ctxOfHour.day_type[hi], 'segments.' + hh.segment + '.intent.' + ctxOfHour.day_type[hi]);
        }
      }
    }

    // 6. Subtotal before adjustments
    const s6 = steps[5];
    s6.lines.push("Orders wanted this hour before weather, your own results and the truck's limit.");
    for (const s of picked.main) {
      if (!picked.byPeople) s6.rows.push({ label: segmentLabel(s.key), value: orders1(s.seg.demand_raw) });
    }
    if (hh !== null) s6.rows.push({ label: "The host's people", value: orders1(hh.demand_raw) });
    s6.rows.push({ label: 'Together', value: orders1(hr.demand_raw) });

    // 7. Weather
    const s7 = steps[6];
    const state = hr.factors.weather_state;
    if (state === 'typical') s7.lines.push(WHY.typicalWeek);
    else if (state === 'missing' || hr.factors.weather_detail === null) s7.lines.push(WHY.noForecast);
    else {
      // The forecast record says whether the chance was given; without the hour's own context it reads as given.
      let chance: 'given' | 'assumed' = 'given';
      let fc: HourForecast | null = null;
      if (ctxOfHour !== null && ctxOfHour.forecast !== null) {
        const record = ctxOfHour.forecast[hr.hour];
        if (record !== null && record !== undefined) {
          fc = record;
          if (record.precip_prob === null) chance = 'assumed';
        }
      }
      weatherRows(s7, hr.factors.weather_detail, 'open', chance, A, '', fc);
      s7.rows.push({ label: 'Weather adjustment for this hour', value: factor(hr.factors.weather_open) });
      if (hh !== null && hh.mode === 'captive' && hr.factors.weather_detail_captive !== null) {
        s7.lines.push("The host's people are inside the venue, so the weather counts less for them.");
        weatherRows(s7, hr.factors.weather_detail_captive, 'captive', 'skip', A, 'Inside the venue: ', null);
        s7.rows.push({ label: 'Inside the venue: weather adjustment', value: factor(hr.factors.weather_captive) });
      }
    }

    // 8. Your own results
    calibrationStep(steps[7], cal, hr.factors.truck_factor, hr.factors.spot_factor, terms.spot_id);

    // 9. Capacity
    const s9 = steps[8];
    s9.rows.push({ label: 'Orders wanted this hour', value: fmtCount1(hr.demand_adj) });
    s9.rows.push({ label: 'The truck can serve', value: fmtCount(hr.capacity) + ' an hour' });
    if (hr.capped) {
      s9.rows.push({ label: 'Given up', value: fmtCount1(hr.demand_adj - hr.orders) });
      s9.lines.push('Demand is above what the truck can serve this hour.');
    } else {
      s9.lines.push("Under the truck's limit this hour.");
    }
    s9.rows.push({ label: 'Orders this hour', value: fmtCount1(hr.orders) });
  }

  // 10. Window
  const s10 = steps[9];
  if (w.hours.length === 0) s10.lines.push(WHY.noHours);
  for (const h of w.hours) {
    const span = hourSpan(w.open_minute, w.close_minute, h.day_index, h.hour);
    let note = '';
    if (h.fraction < 1) note = fmtPercent(h.fraction) + ' of the hour.';
    if (h.result.capped) note += (note === '' ? '' : ' ') + "At the truck's limit.";
    const row: WhyRow = { label: fmtWindow(span.from, span.to), value: orders1(h.result.orders * h.fraction) };
    if (note !== '') row.note = note;
    s10.rows.push(row);
  }
  s10.rows.push({ label: 'Together', value: orders1(w.orders.value) });
  if (w.orders.value > 0) {
    const who: { label: string; share: number; order: number }[] = [];
    for (let i = 0; i < w.by_segment.length; i++) {
      const share = w.by_segment[i] / w.orders.value;
      if (share >= 0.01 && i < SEGMENTS.length) who.push({ label: segmentLabel(SEGMENTS[i]), share, order: i });
    }
    if (w.host_orders / w.orders.value >= 0.01) who.push({ label: "The host's people", share: w.host_orders / w.orders.value, order: -1 });
    who.sort((a, b) => (qkey(a.share) !== qkey(b.share) ? qkey(b.share) - qkey(a.share) : a.order - b.order));
    if (who.length > 0) s10.lines.push('Orders hour by hour, then who the customers would be.');
    for (const x of who) s10.rows.push({ label: 'Customers: ' + lowerFirst(x.label), value: pct(x.share) });
  }

  // 11. Range and label
  const uncapped = interval(A, w.demand_adj, w.evidence)[0];
  rangeStep(steps[10], w.spread, w.orders, 'orders', qkey(w.orders.high) < qkey(uncapped.high), false);

  // 12. Money
  const s12 = steps[11];
  const money = subject.money === undefined ? stopMoney(profile, terms, w.orders) : subject.money;
  moneyRows(s12, money, profile.tips_include);
  const noFee = terms.fee_flat === 0 && terms.fee_pct === 0 && terms.fee_min === 0;
  // The minimum is what is paid while flat plus share stays at or under it (02_MODEL 4.9).
  const minimumApplies = noFee || terms.fee_flat + terms.fee_pct * money.sales.value <= terms.fee_min;
  s12.lines.push(noFee ? WHY.noFee : minimumApplies ? WHY.feeMinimum : WHY.feeFlatPlusShare);
  s12.rows.push({
    label: 'Each order leaves',
    value: fmtMoneyCents(minimumApplies ? money.unit_margin.at_minimum : money.unit_margin.at_percentage),
    note: 'After food, packaging and card fees' + (minimumApplies ? '.' : ', and the share of sales paid as the fee.'),
  });

  return finish(steps, A, hint);
}

// -------------------------------------------------------------------------------------------------
// An event stop
// -------------------------------------------------------------------------------------------------

/** An event stop carries its hours, not its window: an hour is named by its clock hour. */
function eventHourLabel(h: EventHour): string {
  return fmtClockShort((h.day_index * 24 + h.hour) * 60);
}

function eventSteps(
  subject: Extract<WhySubject, { kind: 'event' }>,
  A: Assumptions,
  profile: TruckProfile,
  cal: CalibrationState | null,
): WhyStep[] {
  const steps: WhyStep[] = [];
  for (let n = 1; n <= 13; n++) steps.push(step(n));
  const { stop, event } = subject;
  const data = stop.event;
  const hint: SeedHint = { hour: null, dow: subject.ctx.eff_dow };
  if (data === null) {
    for (let n = 1; n <= 12; n++) steps[n - 1].lines.push('This stop is not an event.');
    return finish(steps, A, hint);
  }
  const haircut = seed<number>(A, 'events.attendance_haircut');
  const pBuyPath = 'events.p_buy.' + event.event_type;
  const pBuy = seed<number>(A, pBuyPath);
  const vendors = event.vendors < 1 ? 1 : event.vendors;
  const perVendor = data.buyers / vendors;
  const truckFactor = cal === null ? 1 : cal.truck_factor;

  steps[0].lines.push(
    'Expected attendance during your stop: ' + fmtCount(event.attendance) + '. Counted at ' + fmtPercent(haircut) + ': ' + fmtCount(event.attendance * haircut) + '.',
  );
  steps[0].seedPaths.push('events.attendance_haircut');

  steps[1].lines.push(fmtPercent(pBuy) + ' buy a meal (' + EVENT_TYPE_LABELS[event.event_type] + '): ' + fmtCount(data.buyers) + '.');
  steps[1].seedPaths.push(pBuyPath);

  steps[2].lines.push(
    'Shared between ' + fmtCount(event.vendors) + (event.vendors === 1 ? ' food vendor' : ' food vendors') + ', counting you: ' + about(perVendor) + ' each.',
  );

  steps[3].lines.push(WHY.eventMenuFit);
  steps[4].lines.push(WHY.eventNoHost);

  steps[5].rows.push({ label: 'Orders wanted before adjustments', value: about(perVendor) });

  const s7 = steps[6];
  if (data.hours.length === 0) s7.lines.push(WHY.noHours);
  if (subject.ctx.typical) s7.lines.push(WHY.typicalWeek);
  for (const h of data.hours) s7.rows.push({ label: eventHourLabel(h), value: factor(h.weather) });

  const s8 = steps[7];
  s8.lines.push('Truck factor ' + factor(truckFactor) + ': ' + about(data.demand) + ' orders wanted. ' + WHY.eventSpotResults);
  if (cal === null || cal.truck_n === 0) s8.lines.push(WHY.noLogs);
  else s8.seedPaths.push('calibration.k_truck', 'calibration.half_life_days');

  const s9 = steps[8];
  if (data.hours.length === 0) s9.lines.push(WHY.noHours);
  let cappedHours = 0;
  for (const h of data.hours) {
    if (h.demand > h.capacity) cappedHours += 1;
    s9.rows.push({
      label: eventHourLabel(h),
      value: fmtCount1(h.demand) + ' wanted, ' + fmtCount1(h.capacity) + ' can be served',
    });
  }
  if (cappedHours > 0) s9.lines.push('Demand is above what the truck can serve for part of the time.');

  const s10 = steps[9];
  if (data.hours.length === 0) s10.lines.push(WHY.noHours);
  for (const h of data.hours) {
    const row: WhyRow = { label: eventHourLabel(h), value: orders1(h.orders) };
    if (h.fraction < 1) row.note = fmtPercent(h.fraction) + ' of the hour.';
    s10.rows.push(row);
  }
  s10.rows.push({ label: 'Together', value: orders1(stop.orders.value) });

  let total = 0;
  for (const h of data.hours) total += h.demand;
  const evidence = evidenceFrom(cal, null);
  evidence.event = true;
  const uncapped = interval(A, total, evidence)[0];
  rangeStep(steps[10], data.spread, stop.orders, 'orders', qkey(stop.orders.high) < qkey(uncapped.high), true);

  moneyRows(steps[11], stop.money, profile.tips_include);

  return finish(steps, A, hint, ['events.attendance_haircut', pBuyPath, 'uncertainty.sd_event']);
}

// -------------------------------------------------------------------------------------------------
// A whole day
// -------------------------------------------------------------------------------------------------

function busiestHour(stop: DayStop): WindowHour | null {
  if (stop.window === null || stop.window.hours.length === 0) return null;
  return stop.window.hours[defaultHourIndex(stop.window)];
}

function legName(id: string | null, result: DayResult, names: readonly string[], first: boolean): string {
  if (id === null || id === 'base') return first ? 'Base' : 'base';
  for (let i = 0; i < result.stops.length; i++) if (result.stops[i].id === id) return stopNameOf(names, i);
  return first ? 'A stop' : 'a stop';
}

function legRow(leg: Leg, result: DayResult, names: readonly string[]): WhyRow {
  let value = fmtDuration(leg.minutes) + ', ' + fmtMiles(leg.miles);
  if (leg.toll > 0) value += ', toll ' + fmtMoneyCents(leg.toll);
  let note: string;
  const base = fmtNumber(leg.base_minutes, 1) + ' min';
  const truck = leg.truck_time_factor === 1 ? '' : ' ' + factor(leg.truck_time_factor) + ' for the truck';
  if (leg.source === 'override') note = 'Your time.';
  else if (leg.source === 'google') {
    note = 'Google drive time ' + base + (leg.time_factor === 1 ? '' : ' ' + factor(leg.time_factor) + ' for the time of day') + truck + '.';
  } else {
    note = 'Straight-line estimate ' + base + (leg.traffic_factor === 1 ? '' : ' ' + factor(leg.traffic_factor) + ' for the time of day') + truck + '.';
  }
  return { label: legName(leg.from_id, result, names, true) + ' to ' + legName(leg.to_id, result, names, false), value, note };
}

function daySteps(
  subject: Extract<WhySubject, { kind: 'day' }>,
  A: Assumptions,
  profile: TruckProfile,
  cal: CalibrationState | null,
): WhyStep[] {
  const steps: WhyStep[] = [];
  for (let n = 1; n <= 13; n++) steps.push(step(n));
  const { result, stopNames, ctx } = subject;
  const hint: SeedHint = { hour: null, dow: ctx.eff_dow };
  if (result.stops.length === 0) {
    for (let n = 1; n <= 12; n++) steps[n - 1].lines.push('This day has no stops to explain.');
    return finish(steps, A, hint);
  }
  for (let n = 1; n <= 5; n++) steps[n - 1].lines.push("One line per stop, for its busiest hour. Open a stop's own breakdown for the full detail.");
  for (let n = 6; n <= 9; n++) steps[n - 1].lines.push("One line per stop, for its whole window. Open a stop's own breakdown for the full detail.");

  let anyLogs = false;
  for (let i = 0; i < result.stops.length; i++) {
    const st = result.stops[i];
    const name = stopNameOf(stopNames, i);
    if (st.kind === 'catering') {
      for (let n = 1; n <= 9; n++) steps[n - 1].rows.push({ label: name, value: WHY.cateringFixed });
      continue;
    }
    if (st.kind === 'event' || st.window === null) {
      for (let n = 1; n <= 7; n++) steps[n - 1].rows.push({ label: name, value: WHY.eventFromAttendance });
      if (cal !== null && cal.truck_n > 0) anyLogs = true;
      steps[7].rows.push({ label: name, value: cal === null || cal.truck_n === 0 ? WHY.noLogs : 'Truck factor ' + factor(cal.truck_factor) });
      let capped = 0;
      if (st.event !== null) for (const h of st.event.hours) if (h.demand > h.capacity) capped += 1;
      steps[8].rows.push({ label: name, value: capped > 0 ? fmtCount(capped) + (capped === 1 ? ' hour' : ' hours') + " at the truck's limit" : "Under the truck's limit" });
      continue;
    }
    const w = st.window;
    const wh = busiestHour(st);
    if (wh === null) {
      for (let n = 1; n <= 9; n++) steps[n - 1].rows.push({ label: name, value: WHY.noHours });
      continue;
    }
    const hr = wh.result;
    const at = fmtClockShort(hourSpan(w.open_minute, w.close_minute, wh.day_index, wh.hour).from);
    let people = 0;
    let meals = 0;
    let won = 0;
    let weighted = false;
    for (const seg of hr.segments) {
      const present = seg.within_present !== null ? seg.within_present : seg.nearby_present;
      if (seg.within_present === null) weighted = true;
      people += present;
      meals += present * seg.intent;
      won += seg.capture * seg.presence * seg.intent;
    }
    const host = hr.host;
    const hostPeople = host === null ? 0 : host.people_present;
    const hostMeals = host === null ? 0 : host.people_present * host.intent;
    const nearbyText = people >= 1 || host === null ? 'about ' + about(people) + ' people nearby' + (weighted && people >= 1 ? ' (distance-weighted)' : '') : '';
    const hostText = host === null ? '' : 'about ' + about(hostPeople) + ' at the host';
    steps[0].rows.push({ label: name, value: nearbyText + (nearbyText !== '' && hostText !== '' ? ', ' : '') + hostText + ' at ' + at });
    steps[1].rows.push({ label: name, value: 'about ' + about(meals + hostMeals) + ' meals bought in that hour, from any outlet' });
    let shareText = '';
    if (meals > 0 && !weighted) shareText = pct(won / meals) + ' of the meals nearby';
    if (host !== null) shareText += (shareText === '' ? '' : ', ') + pct(host.share) + " of the host's meals";
    steps[2].rows.push({ label: name, value: shareText === '' ? (meals > 0 ? WHY.sharesNotStored : 'Nobody nearby buys a meal in that hour.') : shareText });
    steps[3].rows.push({ label: name, value: fmtPercent(hr.factors.menu_fit) + ' for ' + DAYPART_WORDS[hr.daypart] });
    steps[4].rows.push({
      label: name,
      value:
        hr.host === null
          ? WHY.noHost
          : segmentLabel(hr.host.segment) + ': ' + (w.orders.value > 0 ? pct(w.host_orders / w.orders.value) : fmtPercent(0)) + " of this stop's orders",
    });
    let raw = 0;
    for (const h of w.hours) raw += h.result.demand_raw * h.fraction;
    steps[5].rows.push({ label: name, value: orders1(raw) });
    if (hr.factors.weather_state === 'typical') steps[6].rows.push({ label: name, value: WHY.typicalWeek });
    else {
      let lo = Infinity;
      let hi = -Infinity;
      let missing = 0;
      for (const h of w.hours) {
        if (h.result.factors.weather_state !== 'forecast') {
          missing += 1;
          continue;
        }
        const f = h.result.factors.weather_open;
        if (f < lo) lo = f;
        if (f > hi) hi = f;
      }
      if (missing === w.hours.length) steps[6].rows.push({ label: name, value: 'No forecast for these hours: no weather adjustment.' });
      else {
        const text = qkey(lo) === qkey(hi) ? factor(lo) : factor(lo) + ' to ' + factor(hi);
        steps[6].rows.push({ label: name, value: text + (missing > 0 ? ', no forecast for ' + fmtCount(missing) + (missing === 1 ? ' hour' : ' hours') : '') });
      }
    }
    const spotKnown = cal !== null && st.spot_id !== null && hasKey(cal.spots, st.spot_id);
    if (cal === null || (cal.truck_n === 0 && !spotKnown)) steps[7].rows.push({ label: name, value: WHY.noLogs });
    else {
      anyLogs = true;
      steps[7].rows.push({
        label: name,
        value: 'Truck ' + factor(hr.factors.truck_factor) + (spotKnown ? ', this spot ' + factor(hr.factors.spot_factor) : ', no services logged here yet'),
      });
    }
    steps[8].rows.push({
      label: name,
      value: w.capped_hours > 0 ? fmtCount(w.capped_hours) + ' of ' + fmtCount(w.hours.length) + " hours at the truck's limit" : "Under the truck's limit",
    });
  }
  if (anyLogs) steps[7].seedPaths.push('calibration.k_truck', 'calibration.k_spot', 'calibration.half_life_days');

  // 10. Window: each stop's orders, then the day
  for (let i = 0; i < result.stops.length; i++) {
    steps[9].rows.push({ label: stopNameOf(stopNames, i), value: estimateText(result.stops[i].orders, 'orders') });
  }
  steps[9].rows.push({ label: 'The day', value: estimateText(result.totals.orders, 'orders') });

  // 11. Range and label
  const s11 = steps[10];
  s11.lines.push(WHY.dayRange);
  for (let i = 0; i < result.stops.length; i++) {
    s11.rows.push({ label: stopNameOf(stopNames, i), value: CONFIDENCE_TEXT[result.stops[i].orders.confidence].label });
  }
  const th = result.totals.take_home;
  s11.lines.push('Together: ' + fmtRange(th, 'money') + ' around ' + fmtMoney(th.value) + '.');
  s11.lines.push(CONFIDENCE_TEXT[th.confidence].label + '. ' + CONFIDENCE_TEXT[th.confidence].sentence);
  s11.lines.push('The day carries the weakest label among its stops.');
  s11.seedPaths.push('uncertainty.sd_truck', 'uncertainty.sd_spot', 'uncertainty.sd_day', 'uncertainty.count_dispersion');

  // 12. Money
  const s12 = steps[11];
  const t = result.totals;
  s12.rows.push({ label: MONEY_LINE_LABELS.sales, value: estimateText(t.sales, 'money') });
  s12.rows.push({ label: MONEY_LINE_LABELS.food_cost, value: estimateText(t.food_cost, 'money') });
  s12.rows.push({ label: MONEY_LINE_LABELS.packaging, value: estimateText(t.packaging, 'money') });
  s12.rows.push({ label: MONEY_LINE_LABELS.card_fees, value: estimateText(t.card_fees, 'money') });
  s12.rows.push({ label: MONEY_LINE_LABELS.spot_fees, value: estimateText(t.spot_fees, 'money') });
  if (profile.tips_include) s12.rows.push({ label: MONEY_LINE_LABELS.tips, value: estimateText(t.tips, 'money') });
  s12.rows.push({ label: MONEY_LINE_LABELS.contribution, value: estimateText(t.contribution, 'money') });
  s12.rows.push({
    label: MONEY_LINE_LABELS.labour,
    value: fmtMoney(t.labour.value),
    note:
      fmtNumber(t.paid_hours, 1) +
      ' paid hours x ' +
      fmtCount(profile.paid_crew) +
      ' crew x ' +
      fmtMoneyCents(profile.wage_per_hour) +
      (profile.payroll_burden_pct > 0 ? ' + ' + fmtPercent(profile.payroll_burden_pct) : '') +
      '.',
  });
  s12.rows.push({
    label: MONEY_LINE_LABELS.fuel,
    value: fmtMoneyCents(t.fuel.value),
    note:
      fmtNumber(t.drive_gallons, 3) +
      ' gal driving + ' +
      fmtNumber(t.generator_gallons, 3) +
      ' gal generator at ' +
      fmtFuel(ctx.fuel_price_per_gal) +
      (ctx.fuel_price_source === null ? '' : ' (' + FUEL_SOURCE_PHRASE[ctx.fuel_price_source] + ')') +
      '.',
  });
  s12.rows.push({ label: MONEY_LINE_LABELS.tolls, value: fmtMoneyCents(t.tolls.value) });
  s12.rows.push({ label: MONEY_LINE_LABELS.fixed_cost, value: fmtMoney(t.fixed_cost.value) });
  s12.rows.push({ label: MONEY_LINE_LABELS.take_home, value: estimateText(t.take_home, 'money') });
  s12.rows.push({
    label: MONEY_LINE_LABELS.take_home_per_hour,
    value: estimateText(t.take_home_per_hour, 'money_per_hour'),
    note: fmtHours(t.work_hours) + ' worked.',
  });
  let anyGoogle = false;
  let anyFallback = false;
  for (const leg of result.timeline.legs) {
    s12.rows.push(legRow(leg, result, stopNames));
    if (leg.source === 'google') anyGoogle = true;
    if (leg.source === 'fallback') anyFallback = true;
  }
  for (let i = 0; i < result.stops.length; i++) {
    const st = result.stops[i];
    const row: WhyRow = { label: stopNameOf(stopNames, i) + ' adds', value: estimateText(st.adds.take_home, 'money') };
    if (st.kind !== 'catering') {
      row.note =
        st.adds.break_even_orders === null
          ? 'It cannot pay for itself at these terms.'
          : 'Needs ' + fmtCeil(st.adds.break_even_orders) + ' orders to pay for itself.';
    }
    s12.rows.push(row);
  }
  if (anyGoogle || anyFallback) s12.seedPaths.push('traffic.' + A.region.traffic_matrix);
  if (anyGoogle) s12.seedPaths.push('traffic.' + A.region.traffic_matrix + '_typical');
  if (anyFallback) {
    s12.seedPaths.push('drive_fallback.detour_factor', 'drive_fallback.local_miles', 'drive_fallback.local_mph', 'drive_fallback.trunk_mph');
  }

  return finish(steps, A, hint);
}

// -------------------------------------------------------------------------------------------------
// Entry point
// -------------------------------------------------------------------------------------------------

/**
 * The thirteen steps for a subject, in their fixed order. `A`, `profile` and `cal` are the ones the
 * result was computed with.
 */
export function whySteps(
  subject: WhySubject,
  A: Assumptions,
  profile: TruckProfile,
  cal: CalibrationState | null,
  opts: WhyOptions = {},
): WhyStep[] {
  if (subject.kind === 'window') return windowSteps(subject, A, profile, cal, opts);
  if (subject.kind === 'event') return eventSteps(subject, A, profile, cal);
  return daySteps(subject, A, profile, cal);
}

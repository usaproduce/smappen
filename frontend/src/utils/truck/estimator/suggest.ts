// Truck Planner estimator - suggestions (02_MODEL 4.15).
//
// Both searches are exhaustive within exact limits (at most 2,324 plan evaluations for a day, at most
// 6^7 leaves for a week). No early exit may be added: it would change which equal-valued result is
// found first.

import { DAYPARTS, cmpStr, floorDiv, hasOwn, putOwn, qkey } from './core';
import { addDays } from './dates';
import { bestWindows, hourlyOrders } from './demand';
import { estSum } from './estimate';
import { dayPlan, evaluate } from './plan';
import { seed } from './seeds';
import type {
  Assumptions,
  CalibrationState,
  DayContext,
  Daypart,
  Estimate,
  LegMap,
  PlanInput,
  SpotInput,
  StopInput,
  SuggestOptions,
  Suggestion,
  SuggestionStop,
  TruckProfile,
  WeekDay,
  WeekSuggestion,
} from './types';

// An option that is null (or absent) takes its default.
function option(options: SuggestOptions | null, name: keyof SuggestOptions, fallback: number): number {
  if (options != null) {
    const v = options[name];
    if (v != null) return v;
  }
  return fallback;
}

interface Candidate {
  spot: SpotInput;
  spot_id: string;
  open: number;
  close: number;
  single: number;
  key: number;
}

interface FeasiblePlan {
  plan: PlanInput;
  take_home: number;
  key: number;
  day_minutes: number;
  stops: Candidate[];
}

/**
 * The best day plans from the owner's saved spots for one civil date, ranked by expected take-home.
 * Candidate windows per spot (kept per daypart, so lunch cannot crowd out the evening), then every
 * subset of 1..max_stops candidates that can be driven without arriving late. `legs` is keyed by spot
 * ids and "base"; ctx is the context of a date (ctx.date becomes the date of every plan and
 * suggestion) and must carry a fuel price. An empty list when nothing is feasible.
 */
export function suggestDay(
  A: Assumptions,
  profile: TruckProfile,
  ctx: DayContext,
  ctxNext: DayContext | null,
  spots: readonly SpotInput[],
  legs: LegMap,
  cal: CalibrationState | null,
  options: SuggestOptions | null,
): Suggestion[] {
  const service = option(options, 'service_minutes', seed<number>(A, 'suggest.service_minutes')); // a multiple of 60
  const maxStops = option(options, 'max_stops_per_day', seed<number>(A, 'suggest.max_stops_per_day'));
  const limit = option(options, 'limit', seed<number>(A, 'suggest.day_results'));
  const L = floorDiv(service, 60);
  const first = floorDiv(seed<number>(A, 'suggest.earliest_open_minute'), 60);
  const last = floorDiv(seed<number>(A, 'suggest.latest_close_minute'), 60); // hours [first, last)
  const daypartOfHour = seed<Daypart[]>(A, 'hours.daypart_of_hour');
  const planDate = ctx.date as string;

  const planOf = (cands: readonly Candidate[]): PlanInput => {
    const stops: StopInput[] = [];
    for (let k = 0; k < cands.length; k++) {
      const spot = cands[k].spot;
      stops.push({
        id: spot.spot_id,
        kind: 'spot',
        spot_id: spot.spot_id,
        point: spot.point,
        open_minute: cands[k].open,
        close_minute: cands[k].close,
        gap_before_unpaid: false,
        setup_minutes: null,
        teardown_minutes: null,
        terms: spot.terms,
        vectors: spot.vectors,
        event: null,
        catering: null,
      });
    }
    return { date: planDate, stops };
  };

  // 1. Candidates: the best windows of each spot.
  const candidates: Candidate[] = [];
  const sortedSpots = spots.slice().sort((a, b) => cmpStr(a.spot_id, b.spot_id));
  for (let j = 0; j < sortedSpots.length; j++) {
    const spot = sortedSpots[j];
    const values: number[] = [];
    const allowed: boolean[] = [];
    const rule = spot.terms.allowed;
    for (let k = 0; k < last - first; k++) {
      const hour = first + k;
      values.push(hourlyOrders(A, profile, spot.terms, spot.vectors, cal, ctx, hour).orders);
      if (rule == null) {
        allowed.push(true);
      } else {
        allowed.push(Boolean(rule.days[ctx.dow]) && hour * 60 >= rule.open_minute && (hour + 1) * 60 <= rule.close_minute);
      }
    }
    const windows = bestWindows(values, L, seed<number>(A, 'suggest.windows_per_spot'), false, allowed);
    for (let k = 0; k < windows.length; k++) {
      const b = windows[k];
      if (b.total < seed<number>(A, 'suggest.min_stop_orders')) continue;
      const open = (first + b.start) * 60;
      const cand: Candidate = { spot, spot_id: spot.spot_id, open, close: open + service, single: 0.0, key: 0 };
      cand.single = evaluate(A, profile, planOf([cand]), ctx, ctxNext, legs, cal).take_home.value;
      cand.key = qkey(cand.single);
      candidates.push(cand);
    }
  }
  candidates.sort((a, b) => {
    if (a.key !== b.key) return a.key > b.key ? -1 : 1;
    const c = cmpStr(a.spot_id, b.spot_id);
    return c !== 0 ? c : a.open - b.open;
  });
  const maxCandidates = seed<number>(A, 'suggest.max_candidates');
  const perDaypart = floorDiv(maxCandidates, 4);
  const keep = new Array<boolean>(candidates.length).fill(false);
  let kept = 0;
  for (let p = 0; p < DAYPARTS.length; p++) {
    // so lunch cannot crowd out the evening
    let got = 0;
    for (let j = 0; j < candidates.length; j++) {
      if (got === perDaypart) break;
      if (!keep[j] && daypartOfHour[floorDiv(candidates[j].open, 60)] === DAYPARTS[p]) {
        keep[j] = true;
        got += 1;
        kept += 1;
      }
    }
  }
  for (let j = 0; j < candidates.length; j++) {
    if (kept >= maxCandidates) break;
    if (!keep[j]) {
      keep[j] = true;
      kept += 1;
    }
  }
  const pool: Candidate[] = [];
  for (let j = 0; j < candidates.length; j++) {
    if (keep[j]) pool.push(candidates[j]);
  }
  pool.sort((a, b) => (a.open !== b.open ? a.open - b.open : cmpStr(a.spot_id, b.spot_id)));

  // 2. Plans: every subset of 1..maxStops candidates, in (open, spot_id) order.
  const feasible: FeasiblePlan[] = [];
  const consider = (chosen: readonly Candidate[]): void => {
    for (let a = 0; a < chosen.length; a++) {
      for (let b = a + 1; b < chosen.length; b++) {
        if (chosen[a].spot_id === chosen[b].spot_id) return; // a spot appears at most once per day
      }
    }
    for (let a = 1; a < chosen.length; a++) {
      if (chosen[a].open < chosen[a - 1].close) return;
    }
    const plan = planOf(chosen);
    const R = evaluate(A, profile, plan, ctx, ctxNext, legs, cal);
    for (let k = 0; k < R.timeline.stops.length; k++) {
      if (R.timeline.stops[k].late_minutes !== 0) return;
    }
    if (R.timeline.day_minutes > seed<number>(A, 'suggest.max_day_minutes')) return;
    feasible.push({
      plan,
      take_home: R.take_home.value,
      key: qkey(R.take_home.value),
      day_minutes: R.timeline.day_minutes,
      stops: chosen.slice(),
    });
  };
  const extend = (start: number, chosen: readonly Candidate[]): void => {
    if (chosen.length > 0) consider(chosen);
    if (chosen.length === maxStops) return;
    for (let j = start; j < pool.length; j++) extend(j + 1, chosen.concat([pool[j]]));
  };
  extend(0, []);

  // 3. Rank: take-home (whole millionths) descending, fewer stops, shorter day, then the stops themselves.
  feasible.sort((a, b) => {
    if (a.key !== b.key) return a.key > b.key ? -1 : 1;
    if (a.stops.length !== b.stops.length) return a.stops.length - b.stops.length;
    if (a.day_minutes !== b.day_minutes) return a.day_minutes - b.day_minutes;
    for (let k = 0; k < a.stops.length; k++) {
      const c = cmpStr(a.stops[k].spot_id, b.stops[k].spot_id);
      if (c !== 0) return c;
      if (a.stops[k].open !== b.stops[k].open) return a.stops[k].open - b.stops[k].open;
    }
    return 0;
  });
  const out: Suggestion[] = [];
  const top = feasible.slice(0, limit);
  for (let j = 0; j < top.length; j++) {
    const result = dayPlan(A, profile, top[j].plan, ctx, ctxNext, legs, cal);
    const stops: SuggestionStop[] = [];
    for (let k = 0; k < top[j].plan.stops.length; k++) {
      const s = top[j].plan.stops[k];
      stops.push({ spot_id: s.spot_id as string, open_minute: s.open_minute, close_minute: s.close_minute });
    }
    out.push({
      date: planDate,
      position: out.length + 1,
      stops,
      take_home: result.totals.take_home,
      orders: result.totals.orders,
      day_minutes: result.timeline.day_minutes,
      result,
    });
  }
  return out;
}

/**
 * The best week from the day suggestions: which days to work and which plan on each, under a limit on
 * working days and on visits per spot. weekStart is a Monday; contexts holds its seven days and the
 * Monday after (eight in all). Among equal totals the first one found wins: earlier days prefer
 * higher-ranked plans and working over resting.
 */
export function suggestWeek(
  A: Assumptions,
  profile: TruckProfile,
  weekStart: string,
  contexts: readonly DayContext[],
  spots: readonly SpotInput[],
  legs: LegMap,
  cal: CalibrationState | null,
  options: SuggestOptions | null,
): WeekSuggestion {
  const dayOptions: SuggestOptions = {
    service_minutes: options != null && options.service_minutes != null ? options.service_minutes : null,
    max_stops_per_day: options != null && options.max_stops_per_day != null ? options.max_stops_per_day : null,
    limit: seed<number>(A, 'suggest.week_day_options'),
  };
  const opts: Suggestion[][] = [];
  for (let d = 0; d < 7; d++) {
    const plans = suggestDay(A, profile, contexts[d], contexts[d + 1], spots, legs, cal, dayOptions);
    const worth: Suggestion[] = [];
    for (let k = 0; k < plans.length; k++) {
      if (plans[k].take_home.value > seed<number>(A, 'suggest.min_day_take_home')) worth.push(plans[k]);
    }
    opts.push(worth);
  }
  const maxDays = option(options, 'max_days_per_week', seed<number>(A, 'suggest.max_days_per_week'));
  const maxVisits = option(
    options,
    'max_visits_per_spot_per_week',
    seed<number>(A, 'suggest.max_visits_per_spot_per_week'),
  );

  let found = false;
  let bestTotal = 0.0;
  let bestPicks: (number | null)[] = [];
  let leaves = 0;
  const visits: Record<string, number> = {};
  const visitsOf = (spotId: string): number => (hasOwn(visits, spotId) ? visits[spotId] : 0);

  const search = (d: number, total: number, picks: readonly (number | null)[], daysUsed: number): void => {
    if (d === 7) {
      leaves += 1;
      if (!found || qkey(total) > qkey(bestTotal)) {
        found = true;
        bestTotal = total;
        bestPicks = picks.slice();
      }
      return;
    }
    for (let idx = 0; idx < opts[d].length; idx++) {
      // work options first, best first
      const plan = opts[d][idx];
      let ok = daysUsed < maxDays;
      for (let k = 0; k < plan.stops.length; k++) {
        if (visitsOf(plan.stops[k].spot_id) + 1 > maxVisits) ok = false;
      }
      if (!ok) continue;
      for (let k = 0; k < plan.stops.length; k++) {
        putOwn(visits, plan.stops[k].spot_id, visitsOf(plan.stops[k].spot_id) + 1);
      }
      search(d + 1, total + plan.take_home.value, picks.concat([idx]), daysUsed + 1);
      for (let k = 0; k < plan.stops.length; k++) {
        putOwn(visits, plan.stops[k].spot_id, visitsOf(plan.stops[k].spot_id) - 1);
      }
    }
    search(d + 1, total, picks.concat([null]), daysUsed); // then the day off
  };
  search(0, 0.0, [], 0);

  const days: WeekDay[] = [];
  const chosen: Estimate[] = [];
  const counts: Record<string, number> = {};
  for (let d = 0; d < 7; d++) {
    const idx = bestPicks[d];
    const suggestion = idx == null ? null : opts[d][idx];
    if (suggestion !== null) {
      chosen.push(suggestion.take_home);
      for (let k = 0; k < suggestion.stops.length; k++) {
        const id = suggestion.stops[k].spot_id;
        putOwn(counts, id, (hasOwn(counts, id) ? counts[id] : 0) + 1);
      }
    }
    days.push({ date: addDays(weekStart, d), suggestion });
  }
  return {
    week_start: weekStart,
    days,
    total_take_home: estSum(chosen),
    visits: counts,
    leaves_visited: leaves,
  };
}

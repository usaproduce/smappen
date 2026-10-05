// "Why this number" (docs/truck-planner/05_FRONTEND.md 3.4 and 8.2, 02_MODEL section 5).
//
// Every subject is a result the model produced from golden inputs: the two anchors (g12, g24), a
// window with a forecast, a typical week, a calibrated spot, the worked day of 02_MODEL 4.12 and an
// event stop. The figures asserted are the ones the model document prints for them.

import { describe, expect, it } from 'vitest';
import { dayPlan, stopMoney, windowOrders } from '../model';
import type { Assumptions, CalibrationState, DayContext, DayResult, TruckProfile, WindowResult } from '../model';
import { defaultHourIndex, seedInfo, whyHourLabels, whySteps } from '../breakdown';
import type { WhyStep, WhySubject } from '../breakdown';
import { WHY_STEP_TITLES } from '../wording';
import { assume, clone, goldenCase, spec, specCount } from './_kitFixtures';

/* eslint-disable @typescript-eslint/no-explicit-any */

interface WindowCase {
  A: Assumptions;
  profile: TruckProfile;
  cal: CalibrationState | null;
  subject: Extract<WhySubject, { kind: 'window' }>;
  window: WindowResult;
}

/** A window subject from a golden window_orders case; `change` may edit a copy of its arguments first. */
function windowCase(id: string, change?: (args: any) => void): WindowCase {
  const a = clone(goldenCase(id).args);
  if (change) change(a);
  const A = assume(a.A);
  const window = windowOrders(A, a.profile, a.terms, a.vectors, a.cal, a.ctx, a.ctx_next, a.open, a.close);
  return {
    A,
    profile: a.profile,
    cal: a.cal,
    window,
    subject: { kind: 'window', title: 'Reston office park', window, vectors: a.vectors, terms: a.terms, ctx: a.ctx, ctxNext: a.ctx_next },
  };
}

interface DayCase {
  A: Assumptions;
  profile: TruckProfile;
  cal: CalibrationState | null;
  ctx: DayContext;
  result: DayResult;
  names: string[];
  args: any;
}

function dayCase(id: string, change?: (args: any) => void): DayCase {
  const a = clone(goldenCase(id).args);
  if (change) change(a);
  const A = assume(a.A);
  return {
    A,
    profile: a.profile,
    cal: a.cal,
    ctx: a.ctx,
    result: dayPlan(A, a.profile, a.plan, a.ctx, a.ctx_next, a.legs, a.cal),
    names: a.plan.stops.map((s: { id: string }) => 'The ' + s.id),
    args: a,
  };
}

const rowOf = (s: WhyStep, label: string) => {
  const row = s.rows.find((r) => r.label === label);
  if (!row) throw new Error('step ' + s.n + ' has no row "' + label + '": ' + s.rows.map((r) => r.label).join(' | '));
  return row;
};

function expectThirteen(steps: WhyStep[]): void {
  expect(steps.length).toBe(13);
  expect(steps.map((s) => s.n)).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13]);
  expect(steps.map((s) => s.title)).toEqual(WHY_STEP_TITLES.slice());
  for (const s of steps) {
    // a step is never empty: it has figures, or one line saying why not
    expect(s.lines.length + s.rows.length + s.seeds.length, 'step ' + s.n).toBeGreaterThan(0);
    for (const line of s.lines) expect(/undefined|NaN|[{}]/.test(line), line).toBe(false);
    for (const r of s.rows) expect(/undefined|NaN|[{}]/.test(r.label + r.value + (r.note || '')), r.label).toBe(false);
  }
}

const TITLES = [
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
];

// ---- a window ------------------------------------------------------------------------------------

describe('a window: anchor A1, the office park on Thursday 11 AM to 2 PM', () => {
  const c = windowCase('g12-026');
  const steps = whySteps(c.subject, c.A, c.profile, c.cal);

  it('has the thirteen steps, in order, with the exact titles', () => {
    expectThirteen(steps);
    expect(spec(steps.map((s) => s.title))).toEqual(TITLES);
  });

  it('describes the busiest hour first and names the hours for the picker', () => {
    expect(whyHourLabels(c.window)).toEqual(['11 AM', '12 PM', '1 PM']);
    expect(defaultHourIndex(c.window)).toBe(1);
    expect(steps).toEqual(whySteps(c.subject, c.A, c.profile, c.cal, { hourIndex: 1 }));
    const first = whySteps(c.subject, c.A, c.profile, c.cal, { hourIndex: 0 });
    expect(rowOf(first[8], 'Orders this hour').value).toBe('13.1');
    // an index outside the window is brought back into it
    expect(whySteps(c.subject, c.A, c.profile, c.cal, { hourIndex: 99 })).toEqual(whySteps(c.subject, c.A, c.profile, c.cal, { hourIndex: 2 }));
    expect(whySteps(c.subject, c.A, c.profile, c.cal, { hourIndex: -4 })).toEqual(first);
  });

  it('steps 1 to 3: who is here, who buys, what share the truck wins (02_MODEL 4.7, hour 12)', () => {
    // within_present 784.08 = 2,000 jobs x presence 0.39204 (0.363 x Thursday factor 1.08)
    const who = rowOf(steps[0], 'Office workers');
    expect(spec(who.value)).toBe('about 780 people');
    expect(spec(who.note)).toBe('2,000 jobs nearby x 39% on site at this hour (weekday pattern, Thursday factor x1.08).');
    expect(steps[0].lines).toEqual(['People within 1,200 m at this hour.']);
    // only segments holding at least 1 % of the hour's demand are listed; the rest sit behind "Show all 16"
    expect(steps[0].rows.length).toBe(1);
    expect(steps[0].more?.label).toBe('Show all 16');
    expect(steps[0].rows.length + (steps[0].more?.rows.length ?? 0)).toBe(16);
    // intent 0.18: 784.08 x 0.18 = 141 meals from any outlet
    expect(spec(rowOf(steps[1], 'Office workers').value)).toBe('18% buy a meal: about 140 meals');
    // average share = capture / within = 0.208567
    expect(spec(rowOf(steps[2], 'Office workers').value)).toBe('21%');
    expect(rowOf(steps[2], 'How easy the truck is to see').value).toBe('Normal');
    expect(rowOf(steps[2], 'Pull of nearby food outlets').note).toContain('Day weights');
  });

  it('steps 4 to 6: menu fit, no host, the subtotal', () => {
    expect(rowOf(steps[3], 'Lunch (11 AM to 4 PM)').value).toBe('100%');
    expect(spec(steps[4].lines)).toEqual(['No host at this spot.']);
    expect(steps[4].rows).toEqual([]);
    // demand_raw 29.436015
    expect(spec(rowOf(steps[5], 'Together').value)).toBe('29.4 orders');
    expect(rowOf(steps[5], 'Office workers').value).toBe('29.4 orders');
  });

  it('step 7 says there is no forecast, step 8 that nothing is logged yet', () => {
    expect(spec(steps[6].lines)).toEqual(['No forecast for this hour: no weather adjustment.']);
    expect(steps[6].rows).toEqual([]);
    expect(spec(steps[7].lines)).toEqual(['No logged services yet.']);
    expect(steps[7].rows).toEqual([]);
  });

  it('step 9: under the limit of 45 an hour', () => {
    expect(rowOf(steps[8], 'Orders wanted this hour').value).toBe('29.4');
    expect(rowOf(steps[8], 'The truck can serve').value).toBe('45 an hour');
    expect(rowOf(steps[8], 'Orders this hour').value).toBe('29.4');
    expect(steps[8].rows.some((r) => r.label === 'Given up')).toBe(false);
  });

  it('step 10: the hours of the window add up to the estimate (13.119, 29.436, 17.939 = 60.49)', () => {
    expect(spec(steps[9].rows.slice(0, 4).map((r) => [r.label, r.value]))).toEqual([
      ['11 AM to 12 PM', '13.1 orders'],
      ['12 PM to 1 PM', '29.4 orders'],
      ['1 PM to 2 PM', '17.9 orders'],
      ['Together', '60.5 orders'],
    ]);
    expect(rowOf(steps[9], 'Customers: office workers').value).toBe('100%');
  });

  it('step 11: one row per part of the spread with its share, the range, the label, the capacity line', () => {
    const s = steps[10];
    expect(spec(s.rows.map((r) => r.label))).toEqual([
      'How well the model fits your truck overall',
      'How this spot differs from your average',
      'Normal day-to-day swings',
      'Weak figures for hospitals, campuses and stations',
      "The host size is a typical figure, not yours",
      "Attendance is the organiser's guess",
      'Small numbers bounce around',
    ]);
    // 0.0576, 0.0361, 0.04 and 0.032526 of sigma^2 = 0.166226
    expect(spec(s.rows.map((r) => r.value))).toEqual(['35%', '22%', '24%', '0%', '0%', '0%', '20%']);
    expect(spec(s.lines[0])).toBe('Together: 33 to 93 around 60.');
    expect(spec(s.lines[1])).toBe('Rough. Not yet checked against your own sales.');
    // at the strong-day factor the 12:00 hour would need 45.7 orders and is held at 45
    expect(spec(s.lines[2])).toBe('The strong-day figure is limited by how fast the truck can serve.');
    expect(s.lines.length).toBe(3);
    // sigma_model and sigma appear only in the closed disclosure
    expect(s.more?.label).toBe('Technical detail');
    expect(spec(s.more?.rows)).toEqual([
      { label: 'sigma_model', value: '0.3657' },
      { label: 'sigma', value: '0.4077' },
    ]);
    expect(JSON.stringify(s.rows).includes('sigma')).toBe(false);
    expect(s.lines.join(' ').includes('sigma')).toBe(false);
  });

  it('step 12: the money lines of 02_MODEL 4.9, computed when the subject carries none', () => {
    const s = steps[11];
    expect(spec(rowOf(s, 'Sales').value)).toBe('$907 ($495 to $1,398)');
    expect(spec(rowOf(s, 'Food cost').value)).toBe('$272 ($149 to $419)');
    expect(spec(rowOf(s, 'Packaging').value)).toBe('$30 ($17 to $47)');
    expect(spec(rowOf(s, 'Card fees').value)).toBe('$28 ($15 to $43)');
    expect(spec(rowOf(s, 'Spot fee').value)).toBe('$0');
    expect(spec(rowOf(s, 'Left after food and fees').value)).toBe('$577 ($315 to $889)');
    expect(spec(rowOf(s, 'Each order leaves').value)).toBe('$9.54');
    expect(s.lines).toEqual(['No fee at this stop.']);
    expect(s.rows.some((r) => r.label === 'Tips')).toBe(false);
  });

  it('step 13 lists every seed of steps 1 to 12 and flags the tuned ones', () => {
    const s = steps[12];
    expect(s.seeds.length).toBeGreaterThan(8);
    expect(s.seeds.map((x) => x.path)).toEqual(s.seedPaths);
    expect(new Set(s.seedPaths).size).toBe(s.seedPaths.length);
    for (let n = 0; n < 12; n++) for (const p of steps[n].seedPaths) expect(s.seedPaths).toContain(p);
    const a0 = s.seeds.find((x) => x.path === 'kernel.outside_option_a0');
    expect(a0).toMatchObject({ value: '1.6', tag: 'tuned', placeholder: true, note: 'Placeholder until you log services', overridden: false, startingValue: null });
    const flagged = s.seeds.filter((x) => x.placeholder).map((x) => x.path);
    expect(spec(flagged)).toEqual(['kernel.outside_option_a0']);
    for (const x of s.seeds) {
      expect(x.placeholder).toBe(x.tag === 'tuned');
      expect(x.note).toBe(x.tag === 'tuned' ? 'Placeholder until you log services' : null);
      expect(['measured', 'derived', 'assumed', 'tuned']).toContain(x.tag);
      expect(x.source.length).toBeGreaterThan(0);
      expect(x.value.length).toBeGreaterThan(0);
      expect(x.label).not.toBe(x.path);
    }
    const presence = s.seeds.find((x) => x.path === 'segments.w_office.presence.weekday');
    expect(presence?.value).toBe('0.363 at 12 PM (one of 24 hourly values)');
    expect(s.seeds.find((x) => x.path === 'segments.w_office.dow_factor')?.value).toBe('Mon 0.9, Tue 1.19, Wed 1.16, Thu 1.08, Fri 0.67');
  });

  it('uses the money the subject carries when it has one', () => {
    const money = stopMoney(c.profile, c.subject.terms, c.window.orders);
    money.sales = { value: 1, low: 1, high: 1, confidence: 'fixed' };
    const withMoney = whySteps({ ...c.subject, money }, c.A, c.profile, c.cal);
    expect(rowOf(withMoney[11], 'Sales').value).toBe('$1');
    expect(rowOf(withMoney[11], 'Food cost').value).toBe('$272 ($149 to $419)');
  });
});

describe('a window: anchor A2, the taproom host on Thursday 5 PM to 8 PM', () => {
  const c = windowCase('g12-018');
  const steps = whySteps(c.subject, c.A, c.profile, c.cal);

  it('has the thirteen steps', () => {
    expectThirteen(steps);
  });

  it('step 1 has nobody nearby and points at the host; step 5 is the host (02_MODEL 4.7, hour 18)', () => {
    expect(steps[0].lines).toEqual(['Almost nobody is within walking distance at this hour.', "The host's own people are counted separately, in the host step."]);
    const host = steps[4];
    expect(host.lines).toEqual([]);
    expect(rowOf(host, "The host's people").value).toBe('Taproom and bar patrons');
    expect(spec(rowOf(host, 'Size').value)).toBe('120 people in its busiest hour');
    expect(rowOf(host, 'Size').note).toBe('Your figure.');
    // people in the venue = 120 x 0.62 = 74.4
    expect(spec(rowOf(host, 'There at this hour').value)).toBe('about 74 people');
    expect(spec(rowOf(host, 'There at this hour').note)).toBe('62% of its size.');
    expect(spec(rowOf(host, 'Buying a meal this hour').value)).toBe('28%');
    expect(spec(rowOf(host, "The truck's share of those meals").value)).toBe('75%');
    expect(rowOf(host, "The truck's share of those meals").note).toBe('People inside the venue. Your truck is the only food.');
    // demand_raw = 90 x 0.62 x 0.28 = 15.624
    expect(spec(rowOf(steps[5], "The host's people").value)).toBe('15.6 orders');
  });

  it('adds up to 39 orders (21 to 62), rough', () => {
    expect(spec(rowOf(steps[9], 'Together').value)).toBe('39.4 orders');
    expect(spec(steps[10].lines[0])).toBe('Together: 21 to 62 around 39.');
    expect(steps[10].lines.includes('The strong-day figure is limited by how fast the truck can serve.')).toBe(false);
    expect(rowOf(steps[9], "Customers: the host's people").value).toBe('100%');
  });

  it('flags the tuned seeds a captive host rests on', () => {
    const flagged = steps[12].seeds.filter((x) => x.placeholder).map((x) => x.path);
    expect(flagged).toContain('host.captive_share');
    expect(flagged).toContain('segments.v_nightlife.intent.weekday');
    expect(steps[12].seeds.find((x) => x.path === 'host.captive_share')).toMatchObject({ value: '0.75', tag: 'tuned', placeholder: true });
  });

  it('shows the owner\'s value when a seed is overridden', () => {
    const o = windowCase('g12-018', (a) => {
      a.A.overrides = { 'host.captive_share': 0.6 };
    });
    const seeds = whySteps(o.subject, o.A, o.profile, o.cal)[12].seeds;
    expect(seeds.find((x) => x.path === 'host.captive_share')).toMatchObject({ value: '0.6', overridden: true, startingValue: '0.75', placeholder: true });
    expect(seeds.filter((x) => x.overridden).length).toBe(1);
    expect(seedInfo(o.A, 'host.shared_kitchen_share')).toMatchObject({ value: '0.3', overridden: false, startingValue: null, tag: 'assumed' });
  });

  it('says so when the host size is a typical figure and when the host sells its own food', () => {
    const byDefault = windowCase('g12-013'); // size 40 from the place-type default
    const d = whySteps(byDefault.subject, byDefault.A, byDefault.profile, byDefault.cal);
    expect(rowOf(d[4], 'Size').note).toBe('A typical figure for this kind of place, not yours.');
    expect(d[10].rows.find((r) => r.label === 'The host size is a typical figure, not yours')?.value).not.toBe('0%');
    expect(d[10].lines[1]).toBe('Very rough. A guess from generic assumptions. Treat it as a ranking only.');
    expect(d[12].seedPaths).toContain('uncertainty.sd_default_size');
    const kitchen = windowCase('g12-012'); // the taproom has its own kitchen: share 0.30
    const k = whySteps(kitchen.subject, kitchen.A, kitchen.profile, kitchen.cal);
    expect(rowOf(k[4], "The truck's share of those meals").value).toBe('30%');
    expect(rowOf(k[4], "The truck's share of those meals").note).toBe('People inside the venue. The host sells its own food.');
    expect(k[12].seedPaths).toContain('host.shared_kitchen_share');
  });
});

describe('a window that is over capacity in every hour', () => {
  const c = windowCase('g12-015'); // size-400 taproom on a Saturday: 72.0, 79.8, 63.4 wanted against 45
  const steps = whySteps(c.subject, c.A, c.profile, c.cal, { hourIndex: 0 });

  it('says how many orders are given up and that the range is held back', () => {
    expectThirteen(steps);
    expect(spec(rowOf(steps[8], 'Orders wanted this hour').value)).toBe('72.0');
    expect(rowOf(steps[8], 'Given up').value).toBe('27.0');
    expect(rowOf(steps[8], 'Orders this hour').value).toBe('45.0');
    expect(steps[8].lines).toEqual(['Demand is above what the truck can serve this hour.']);
    expect(steps[9].rows[0]).toEqual({ label: '5 PM to 6 PM', value: '45.0 orders', note: "At the truck's limit." });
    expect(spec(steps[10].lines[0])).toBe('Together: 123 to 135 around 135.');
    expect(steps[10].lines).toContain('The strong-day figure is limited by how fast the truck can serve.');
  });
});

describe('a window with a forecast', () => {
  it('prints the chance of rain the forecast gives (55 F, 70 %, "Rain Showers Likely")', () => {
    const c = windowCase('g12-009');
    const steps = whySteps(c.subject, c.A, c.profile, c.cal, { hourIndex: 1 });
    expectThirteen(steps);
    const s = steps[6];
    expect(s.lines).toEqual([]);
    expect(spec(rowOf(s, 'Chance of rain').value)).toBe('70%');
    expect(rowOf(s, 'Temperature').value).toBe('x0.90');
    expect(rowOf(s, 'Temperature').note).toBe('56°F. Band: 50 to 59°F.');
    expect(rowOf(s, 'Rain').note).toBe('The forecast says "Rain Showers Likely". If it comes: x0.55.');
    expect(rowOf(s, 'Wind').value).toBe('x1.00');
    // the open-setting multiplier of that hour is 0.6165
    expect(spec(rowOf(s, 'Weather adjustment for this hour').value)).toBe('x0.62');
    expect(steps[12].seedPaths).toContain('weather.precip_classes.rows.rain.open');
    expect(steps[12].seedPaths).toContain('weather.temperature_bands.rows.50_59.open');
    expect(steps[12].seedPaths).not.toContain('weather.pop_when_missing');
    // 9.682 + 18.147 + 13.966 = 41.79 orders (22.16 to 65.82)
    expect(spec(rowOf(steps[9], 'Together').value)).toBe('41.8 orders');
    expect(spec(steps[10].lines[0])).toBe('Together: 22 to 66 around 42.');
    // the other hours carry their own forecast
    const first = whySteps(c.subject, c.A, c.profile, c.cal, { hourIndex: 0 });
    expect(rowOf(first[6], 'Chance of rain').value).toBe('40%');
    expect(spec(rowOf(first[6], 'Weather adjustment for this hour').value)).toBe('x0.74');
  });

  it('says so when the forecast gives no chance and the class is not dry, and lists the seed it assumed', () => {
    const c = windowCase('g12-009', (a) => {
      a.ctx.forecast[12].precip_prob = null;
    });
    const steps = whySteps(c.subject, c.A, c.profile, c.cal, { hourIndex: 1 });
    const s = steps[6];
    expect(spec(s.lines)).toEqual(['Chance of rain: not given in the forecast. Assumed 50%.']);
    expect(s.rows.some((r) => r.label === 'Chance of rain')).toBe(false);
    // precip = 1 - 0.5 x (1 - 0.55) = 0.775
    expect(rowOf(s, 'Rain').value).toBe('x0.78');
    expect(steps[12].seedPaths).toContain('weather.pop_when_missing');
    expect(steps[12].seeds.find((x) => x.path === 'weather.pop_when_missing')).toMatchObject({ value: '0.5', tag: 'assumed' });
    // an owner who changed that seed sees their own figure
    const o = windowCase('g12-009', (a) => {
      a.ctx.forecast[12].precip_prob = null;
      a.A.overrides = { 'weather.pop_when_missing': 0.2 };
    });
    const os = whySteps(o.subject, o.A, o.profile, o.cal, { hourIndex: 1 });
    expect(os[6].lines).toEqual(['Chance of rain: not given in the forecast. Assumed 20%.']);
    expect(os[12].seeds.find((x) => x.path === 'weather.pop_when_missing')).toMatchObject({ value: '0.2', overridden: true, startingValue: '0.5' });
  });

  it('a dry hour carries no chance at all', () => {
    const c = windowCase('g12-009', (a) => {
      a.ctx.forecast[12] = { hour: 12, temp_f: 62, precip_prob: null, short_forecast: 'Sunny', wind_mph: 5 };
    });
    const s = whySteps(c.subject, c.A, c.profile, c.cal, { hourIndex: 1 })[6];
    expect(s.lines).toEqual([]);
    expect(rowOf(s, 'Rain or snow')).toEqual({ label: 'Rain or snow', value: 'x1.00', note: 'The forecast says "Sunny". Dry.' });
    expect(s.rows.some((r) => r.label.startsWith('Chance of'))).toBe(false);
    expect(rowOf(s, 'Weather adjustment for this hour').value).toBe('x1.00');
  });

  it('gives the captive setting its own rows when the host is inside a venue', () => {
    const c = windowCase('g12-018', (a) => {
      a.ctx.forecast = new Array(24).fill(null);
      a.ctx.forecast[18] = { hour: 18, temp_f: 45, precip_prob: 80, short_forecast: 'Rain', wind_mph: 22 };
    });
    const s = whySteps(c.subject, c.A, c.profile, c.cal, { hourIndex: 1 })[6];
    // 02_MODEL 4.6: 45 F, 80 %, "Rain", 22 mph is 0.424320 in the open and 0.742140 inside
    expect(spec(rowOf(s, 'Weather adjustment for this hour').value)).toBe('x0.42');
    expect(spec(rowOf(s, 'Inside the venue: weather adjustment').value)).toBe('x0.74');
    expect(s.lines).toEqual(["The host's people are inside the venue, so the weather counts less for them."]);
    expect(s.rows.filter((r) => r.label.startsWith('Chance of')).length).toBe(1);
    expect(rowOf(s, 'Inside the venue: Rain').note).toBe('If it comes: x0.80.');
  });
});

describe('a window in a typical week', () => {
  const c = windowCase('g12-029'); // Friday 9:30 PM to 1 AM on a typical week, a taproom host
  const steps = whySteps(c.subject, c.A, c.profile, c.cal);

  it('says that a typical week has no weather adjustment', () => {
    expectThirteen(steps);
    expect(spec(steps[6].lines)).toEqual(['Typical week: no weather adjustment.']);
    expect(steps[6].rows).toEqual([]);
  });

  it('labels the hours of a window that crosses midnight', () => {
    expect(whyHourLabels(c.window)).toEqual(['9:30 PM', '10 PM', '11 PM', '12 AM (next day)']);
    expect(steps[9].rows[0]).toMatchObject({ label: '9:30 PM to 10 PM', note: '50% of the hour.' });
    expect(steps[9].rows[3].label).toBe('12 AM (next day) to 1 AM (next day)');
    // 5.1651 orders over the four hours
    expect(spec(rowOf(steps[9], 'Together').value)).toBe('5.2 orders');
  });
});

describe('a window at a calibrated spot', () => {
  const c = windowCase('g12-020'); // anchor A2 saved as spot B, with the seven services of 02_MODEL 4.13
  const steps = whySteps(c.subject, c.A, c.profile, c.cal);

  it('shows the truck factor and the spot factor with their services', () => {
    expectThirteen(steps);
    const s = steps[7];
    expect(s.lines).toEqual([]);
    // truck_factor 0.963784 from 6 services; spot B 0.937663 from 3
    expect(spec(rowOf(s, 'Truck factor').value)).toBe('x0.96');
    expect(rowOf(s, 'Truck factor').note).toBe('From 6 logged services. Newer ones weigh more.');
    expect(spec(rowOf(s, 'This spot').value)).toBe('x0.94');
    expect(rowOf(s, 'This spot').note).toBe('From 3 services here.');
    // 39.384 x 0.903705 = 35.59 orders (20.60 to 53.53), fair
    expect(spec(steps[10].lines[0])).toBe('Together: 21 to 54 around 36.');
    expect(spec(steps[10].lines[1])).toBe('Fair. Adjusted with your logged services.');
    expect(steps[12].seedPaths).toContain('calibration.half_life_days');
  });

  it('says when nothing is logged at this spot yet', () => {
    const other = windowCase('g12-020', (a) => {
      a.terms.spot_id = 'a-new-spot';
    });
    const s = whySteps(other.subject, other.A, other.profile, other.cal)[7];
    expect(s.lines).toEqual(['No services logged at this spot yet.']);
    expect(s.rows.map((r) => r.label)).toEqual(['Truck factor']);
  });
});

describe('a window without hours', () => {
  it('still has thirteen steps and says why they are empty', () => {
    const c = windowCase('g12-016'); // 5 PM to 5 PM
    const steps = whySteps(c.subject, c.A, c.profile, c.cal);
    expectThirteen(steps);
    for (let n = 1; n <= 10; n++) expect(steps[n - 1].lines).toContain('This window has no hours.');
    expect(steps[10].lines[0]).toBe('Together: 0 to 0 around 0.');
    expect(whyHourLabels(c.window)).toEqual([]);
    expect(defaultHourIndex(c.window)).toBe(0);
  });
});

// ---- an event ------------------------------------------------------------------------------------

describe('an event stop: 2,000 expected, 6 vendors, general, 11 AM to 3 PM (02_MODEL 4.14)', () => {
  const d = dayCase('g18-006', (a) => {
    a.plan.stops[0].event.attendance = 2000;
  });
  const stop = d.result.stops[0];
  const subject: WhySubject = { kind: 'event', title: 'Fall fair', stop, event: d.args.plan.stops[0].event, ctx: d.ctx };
  const steps = whySteps(subject, d.A, d.profile, d.cal);

  it('has the thirteen steps, in order, with the exact titles', () => {
    expectThirteen(steps);
    expect(steps.map((s) => s.title)).toEqual(TITLES);
  });

  it('prints the event rows of 3.4', () => {
    expect(stop.kind).toBe('event');
    expect(spec(steps[0].lines)).toEqual(['Expected attendance during your stop: 2,000. Counted at 60%: 1,200.']);
    expect(spec(steps[1].lines)).toEqual(['35% buy a meal (General (fair, market, sports)): 420.']);
    expect(spec(steps[2].lines)).toEqual(['Shared between 6 food vendors, counting you: 70 each.']);
    expect(spec(steps[3].lines)).toEqual(['Menu fit is not applied to events.']);
    expect(spec(steps[4].lines)).toEqual(['No host: this is an event stop.']);
    expect(spec(steps[5].rows)).toEqual([{ label: 'Orders wanted before adjustments', value: '70' }]);
    expect(steps[6].rows).toEqual([
      { label: '11 AM', value: 'x1.00' },
      { label: '12 PM', value: 'x1.00' },
      { label: '1 PM', value: 'x1.00' },
      { label: '2 PM', value: 'x1.00' },
    ]);
    expect(spec(steps[7].lines[0])).toBe('Truck factor x1.00: 70 orders wanted. Results at single spots are not used for events.');
    expect(steps[7].lines[1]).toBe('No logged services yet.');
    expect(steps[8].rows.map((r) => r.value)).toEqual(new Array(4).fill('17.5 wanted, 45.0 can be served'));
    // 17.5 in each of the four hours, 70 together
    expect(spec(steps[9].rows.map((r) => [r.label, r.value]))).toEqual([
      ['11 AM', '17.5 orders'],
      ['12 PM', '17.5 orders'],
      ['1 PM', '17.5 orders'],
      ['2 PM', '17.5 orders'],
      ['Together', '70.0 orders'],
    ]);
  });

  it('step 11 includes the event part and reads "Very rough"', () => {
    const s = steps[10];
    expect(s.rows.length).toBe(7);
    const event = s.rows.find((r) => r.label === "Attendance is the organiser's guess");
    expect(event?.value).not.toBe('0%');
    // 70.000 (25.031 to 129.674), very_rough
    expect(spec(s.lines[0])).toBe('Together: 25 to 130 around 70.');
    expect(spec(s.lines[1])).toBe('Very rough. A guess from generic assumptions. Treat it as a ranking only.');
    expect(s.more?.label).toBe('Technical detail');
  });

  it('step 12 is the money of the stop and step 13 lists the three event seeds', () => {
    expect(rowOf(steps[11], 'Sales').value).toBe('$1,050 ($375 to $1,945)');
    expect(steps[11].rows.map((r) => r.label)).toEqual(['Sales', 'Food cost', 'Packaging', 'Card fees', 'Spot fee', 'Left after food and fees']);
    const paths = steps[12].seedPaths;
    expect(spec(['events.attendance_haircut', 'events.p_buy.general', 'uncertainty.sd_event'].every((p) => paths.includes(p)))).toBe(true);
    expect(steps[12].seeds.find((x) => x.path === 'events.attendance_haircut')?.value).toBe('0.6');
    expect(steps[12].seeds.find((x) => x.path === 'events.p_buy.general')?.value).toBe('0.35');
  });

  it('uses the truck factor of the logs and says where capacity binds', () => {
    const big = dayCase('g18-007'); // a festival: demand above capacity in every hour
    const festival = big.result.stops[0];
    const s = whySteps({ kind: 'event', title: 'Festival', stop: festival, event: big.args.plan.stops[0].event, ctx: big.ctx }, big.A, big.profile, big.cal);
    expectThirteen(s);
    expect(s[8].lines).toContain('Demand is above what the truck can serve for part of the time.');
    expect(s[10].lines).toContain('The strong-day figure is limited by how fast the truck can serve.');
    const cal = clone(goldenCase('g12-020').args.cal) as CalibrationState;
    const withLogs = whySteps(subject, d.A, d.profile, cal);
    expect(withLogs[7].lines[0].startsWith('Truck factor x0.96: ')).toBe(true);
    expect(withLogs[7].lines.includes('No logged services yet.')).toBe(false);
  });

  it('a stop that is not an event says so in every step', () => {
    const w = dayCase('g18-001');
    const s = whySteps({ kind: 'event', title: 'x', stop: w.result.stops[0], event: { attendance: 1, vendors: 1, event_type: 'general' }, ctx: w.ctx }, w.A, w.profile, w.cal);
    expectThirteen(s);
    for (let n = 1; n <= 12; n++) expect(s[n - 1].lines).toEqual(['This stop is not an event.']);
  });
});

// ---- a day ---------------------------------------------------------------------------------------

describe('a day: the worked day of 02_MODEL 4.12', () => {
  const d = dayCase('g18-001');
  const subject: WhySubject = { kind: 'day', title: 'Thursday', result: d.result, stopNames: d.names, ctx: d.ctx };
  const steps = whySteps(subject, d.A, d.profile, d.cal);

  it('has the thirteen steps, in order, with the exact titles', () => {
    expectThirteen(steps);
    expect(steps.map((s) => s.title)).toEqual(TITLES);
  });

  it('gives each stop one line in steps 1 to 9', () => {
    for (let n = 1; n <= 9; n++) expect(steps[n - 1].rows.map((r) => r.label)).toEqual(['The office', 'The taproom']);
    expect(steps[0].rows.map((r) => r.value)).toEqual(['about 780 people nearby at 12 PM', 'about 74 at the host at 6 PM']);
    expect(steps[2].rows.map((r) => r.value)).toEqual(['21% of the meals nearby', "75% of the host's meals"]);
    expect(steps[3].rows.map((r) => r.value)).toEqual(['100% for lunch', '100% for dinner']);
    expect(steps[4].rows[0].value).toBe('No host at this spot.');
    expect(steps[5].rows.map((r) => r.value)).toEqual(['60.5 orders', '39.4 orders']);
    expect(steps[7].rows.map((r) => r.value)).toEqual(['No logged services yet.', 'No logged services yet.']);
    expect(steps[8].rows.map((r) => r.value)).toEqual(["Under the truck's limit", "Under the truck's limit"]);
  });

  it('step 10: the stops and the day', () => {
    expect(spec(steps[9].rows)).toEqual([
      { label: 'The office', value: '60 orders (33 to 93)' },
      { label: 'The taproom', value: '39 orders (21 to 62)' },
      { label: 'The day', value: '100 orders (54 to 155)' },
    ]);
  });

  it('step 11: lows add to lows and highs to highs, and it says so', () => {
    expect(steps[10].lines[0]).toBe("Each stop's weak-day and strong-day figures are added up, so the day's range is wide on purpose.");
    expect(spec(steps[10].lines[1])).toBe('Together: $42 to $1,012 around $482.');
    expect(steps[10].lines[2]).toBe('Rough. Not yet checked against your own sales.');
    expect(steps[10].rows).toEqual([
      { label: 'The office', value: 'Rough' },
      { label: 'The taproom', value: 'Rough' },
    ]);
  });

  it('step 12: sales, costs, take-home and what each stop adds', () => {
    const s = steps[11];
    expect(spec(rowOf(s, 'Sales').value)).toBe('$1,498 ($807 to $2,331)');
    // labour 2 x $18 x 1.10 x 11.2833 paid hours = $446.82
    expect(spec(rowOf(s, 'Labour').value)).toBe('$447');
    expect(spec(rowOf(s, 'Labour').note)).toBe('11.3 paid hours x 2 crew x $18.00 + 10%.');
    // fuel 1.100 gal + 4.600 gal at $4.195 = $23.91
    expect(spec(rowOf(s, 'Fuel').value)).toBe('$23.91');
    expect(spec(rowOf(s, 'Fuel').note)).toBe('1.100 gal driving + 4.600 gal generator at $4.195/gal (default price).');
    expect(rowOf(s, 'Tolls').value).toBe('$0.00');
    expect(rowOf(s, 'Fixed cost for the day').value).toBe('$0');
    expect(spec(rowOf(s, 'Take-home').value)).toBe('$482 ($42 to $1,012)');
    expect(spec(rowOf(s, 'Per hour of your day').value)).toBe('$43 an hour ($4 to $90)');
    expect(spec(rowOf(s, 'Per hour of your day').note)).toBe('11.3 hours worked.');
    // stop 2 adds $135.02 (-$42.64 to $352.69) and needs 25.232 orders
    expect(spec(rowOf(s, 'The taproom adds').value)).toBe('$135 (-$43 to $353)');
    expect(spec(rowOf(s, 'The taproom adds').note)).toBe('Needs 26 orders to pay for itself.');
    // stop 1 adds $318.90 ($56.71 to $630.89)
    expect(spec(rowOf(s, 'The office adds').value)).toBe('$319 ($57 to $631)');
    // the three legs: 11, 10 and 1 minutes, 4.85, 4.85 and 0.20 miles, the owner's own times
    expect(spec(s.rows.filter((r) => / to /.test(r.label) && r.note === 'Your time.').map((r) => [r.label, r.value]))).toEqual([
      ['Base to The office', '11 min, 4.9 mi'],
      ['The office to The taproom', '10 min, 4.9 mi'],
      ['The taproom to base', '1 min, 0.2 mi'],
    ]);
  });

  it('explains a Google leg and a straight-line leg, and lists the traffic seeds they rest on', () => {
    const routed = dayCase('g18-001', (a) => {
      for (const key of Object.keys(a.legs)) a.legs[key].override_minutes = null;
    });
    const s = whySteps({ kind: 'day', title: 'd', result: routed.result, stopNames: routed.names, ctx: routed.ctx }, routed.A, routed.profile, routed.cal);
    const leg = rowOf(s[11], 'Base to The office');
    expect(leg.note?.startsWith('Google drive time ')).toBe(true);
    expect(leg.note).toContain(' for the time of day');
    expect(leg.note).toContain('x1.10 for the truck');
    expect(s[12].seedPaths).toContain('traffic.dc');
    expect(s[12].seedPaths).toContain('traffic.dc_typical');
    expect(s[12].seeds.find((x) => x.path === 'traffic.dc')?.value).toBe('a table by day and hour');
    const straight = dayCase('g18-013'); // fallback legs
    const f = whySteps({ kind: 'day', title: 'd', result: straight.result, stopNames: straight.names, ctx: straight.ctx }, straight.A, straight.profile, straight.cal);
    expect(rowOf(f[11], 'Base to The office').note?.startsWith('Straight-line estimate ')).toBe(true);
    expect(f[12].seedPaths).toContain('drive_fallback.detour_factor');
    expect(f[12].seedPaths).not.toContain('traffic.dc_typical');
  });

  it('handles event and catering stops in a day', () => {
    const fair = dayCase('g18-007'); // a festival, then the taproom
    const s = whySteps({ kind: 'day', title: 'd', result: fair.result, stopNames: fair.names, ctx: fair.ctx }, fair.A, fair.profile, fair.cal);
    expectThirteen(s);
    expect(s[0].rows[0]).toEqual({ label: 'The festival', value: 'An event is estimated from its attendance, not from the people nearby.' });
    expect(s[8].rows[0].value).toBe("4 hours at the truck's limit");
    const wedding = dayCase('g18-008'); // a catering job, then the taproom
    const c = whySteps({ kind: 'day', title: 'd', result: wedding.result, stopNames: wedding.names, ctx: wedding.ctx }, wedding.A, wedding.profile, wedding.cal);
    expectThirteen(c);
    expect(c[0].rows[0]).toEqual({ label: 'The wedding', value: 'A catering job is contracted, not estimated.' });
    expect(c[10].rows[0]).toEqual({ label: 'The wedding', value: 'Fixed' });
    expect(rowOf(c[11], 'The wedding adds').note).toBeUndefined();
  });

  it('a day that was not evaluated says so in every step', () => {
    const bad = dayCase('g18-010'); // overlapping stops
    const s = whySteps({ kind: 'day', title: 'd', result: bad.result, stopNames: bad.names, ctx: bad.ctx }, bad.A, bad.profile, bad.cal);
    expectThirteen(s);
    for (let n = 1; n <= 12; n++) expect(s[n - 1].lines).toEqual(['This day has no stops to explain.']);
    expect(s[12].lines).toEqual(['No model assumption is involved: every figure here comes from your own terms.']);
  });
});

describe('worked examples', () => {
  it('this file asserts 73 values the specification prints', () => {
    expect(specCount()).toBe(73);
  });
});

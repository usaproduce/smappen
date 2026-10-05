// Formatters and parsers (docs/truck-planner/05_FRONTEND.md 3.1, 3.6 and 8.2).
//
// Every example the specification prints is asserted here through spec(...); the last test states
// how many that is.

import { describe, expect, it } from 'vitest';
import type { Estimate } from '../model';
import {
  DASH,
  estimateParts,
  fmtAbout,
  fmtCeil,
  fmtClock,
  fmtClockShort,
  fmtCoord,
  fmtCount,
  fmtCount1,
  fmtDay,
  fmtDuration,
  fmtEstimate,
  fmtEstimateSpoken,
  fmtFixed,
  fmtFuel,
  fmtHourTick,
  fmtHours,
  fmtHow,
  fmtHowLong,
  fmtMiles,
  fmtMoney,
  fmtMoneyCents,
  fmtNumber,
  fmtPercent,
  fmtPerHour,
  fmtPhone,
  fmtPlain,
  fmtRange,
  fmtTemp,
  fmtWeekday,
  fmtWindow,
  parseClock,
  parseCoords,
  parseNumber,
} from '../format';
import { spec, specCount } from './_kitFixtures';

const est = (value: number, low: number, high: number, confidence: Estimate['confidence'] = 'rough'): Estimate => ({
  value,
  low,
  high,
  confidence,
});

// The figures of the worked day of 02_MODEL 4.12 and of anchor A1.
const A1_ORDERS = est(60.4938, 33.0139, 93.1942);
const TAKE_HOME = est(482.2, 42.35, 1011.86);
const LUNCH_ONLY = est(347.18, 85.0, 659.18);
const STOP_ADDS = est(135.02, -42.64, 352.69);
const PER_HOUR = est(42.74, 42.35 / 11.2833, 1011.86 / 11.2833);

describe('money and counts (3.1)', () => {
  it('fmtMoney: whole dollars, the sign before the dollar sign, never -$0', () => {
    expect(spec(fmtMoney(1564.2))).toBe('$1,564');
    expect(spec(fmtMoney(-34.82))).toBe('-$35');
    expect(spec(fmtMoney(-0.4))).toBe('$0');
    expect(spec(fmtMoney(1234567.5))).toBe('$1,234,568');
  });

  it('fmtMoneyCents and fmtFuel', () => {
    expect(spec(fmtMoneyCents(4.54))).toBe('$4.54');
    expect(spec(fmtMoneyCents(9.541))).toBe('$9.54');
    expect(spec(fmtMoneyCents(1500))).toBe('$1,500.00');
    expect(spec(fmtFuel(4.195))).toBe('$4.195/gal');
    expect(fmtMoneyCents(-7.305)).toBe('-$7.31');
    expect(fmtMoneyCents(0.005)).toBe('$0.01');
  });

  it('fmtCount, fmtCount1, fmtCeil', () => {
    expect(spec(fmtCount(60.49))).toBe('60');
    expect(spec(fmtCount(1244215.2))).toBe('1,244,215');
    expect(spec(fmtCount(0.3))).toBe('0');
    expect(spec(fmtCount1(5.25))).toBe('5.3');
    expect(spec(fmtCount1(29.436))).toBe('29.4');
    expect(spec(fmtCeil(25.232))).toBe('26');
    expect(spec(fmtCeil(24))).toBe('24');
    expect(fmtCeil(24.0000000001)).toBe('24');
    expect(fmtCeil(24.001)).toBe('25');
    expect(fmtCeil(0)).toBe('0');
  });

  it('negative and zero money; -0 is never printed', () => {
    expect(fmtMoney(0)).toBe('$0');
    expect(fmtMoney(-0)).toBe('$0');
    expect(fmtMoney(-0.49)).toBe('$0');
    expect(fmtMoney(-0.5)).toBe('-$1');
    expect(fmtMoney(-1234.4)).toBe('-$1,234');
    expect(fmtMoneyCents(-0.004)).toBe('$0.00');
    expect(fmtCount(-0.3)).toBe('0');
    expect(fmtCount1(-0.04)).toBe('0.0');
    expect(fmtPercent(-0.001)).toBe('0%');
    expect(fmtNumber(-0.0004, 3)).toBe('0.000');
    expect(fmtCoord(-0.00001, -0.00004)).toBe('0.0000, 0.0000');
    for (const text of [fmtMoney(-0.2), fmtCount(-0.2), fmtCount1(-0.01), fmtMoneyCents(-0.001), fmtPlain(-0.0001)]) {
      expect(text.includes('-')).toBe(false);
    }
  });

  it('groups digits at 999, 1,000 and 1,000,000', () => {
    expect(fmtCount(999)).toBe('999');
    expect(fmtCount(1000)).toBe('1,000');
    expect(fmtCount(999999)).toBe('999,999');
    expect(fmtCount(1000000)).toBe('1,000,000');
    expect(fmtMoney(999.4)).toBe('$999');
    expect(fmtMoney(999.5)).toBe('$1,000');
    expect(fmtMoney(1000000)).toBe('$1,000,000');
    expect(fmtMoneyCents(1000000)).toBe('$1,000,000.00');
    expect(fmtCount1(12345.67)).toBe('12,345.7');
  });

  it('rounds half away from zero with the nudge of the model', () => {
    expect(fmtMoneyCents(2.675)).toBe('$2.68');
    expect(fmtMoneyCents(1.005)).toBe('$1.01');
    expect(fmtCount(2.5)).toBe('3');
    expect(fmtCount(-2.5)).toBe('-3');
    expect(fmtCount(65.533)).toBe('66');
    expect(fmtCount1(1234.5678)).toBe('1,234.6');
    expect(fmtMoneyCents(-17.345)).toBe('-$17.35');
  });

  it('fmtPerHour, fmtAbout, fmtPercent', () => {
    expect(spec(fmtPerHour(42.74))).toBe('$43 an hour');
    expect(spec(fmtAbout(437.11))).toBe('440');
    expect(spec(fmtAbout(1244))).toBe('1,200');
    expect(spec(fmtAbout(74.4))).toBe('74');
    expect(spec(fmtAbout(8.2))).toBe('8');
    expect(fmtAbout(99.6)).toBe('100');
    expect(fmtAbout(1249.6)).toBe('1,200');
    expect(fmtAbout(1250)).toBe('1,300');
    expect(fmtAbout(999.6)).toBe('1,000');
    expect(fmtAbout(1244215)).toBe('1,200,000');
    expect(spec(fmtPercent(0.3))).toBe('30%');
    expect(spec(fmtPercent(0.026, 1))).toBe('2.6%');
    expect(spec(fmtPercent(40 / 100))).toBe('40%');
    expect(fmtPercent(0.07)).toBe('7%');
    expect(fmtPercent(1)).toBe('100%');
    expect(fmtPercent(0.285)).toBe('29%');
  });

  it('fmtNumber and fmtPlain for plain figures', () => {
    expect(spec(fmtNumber(0.963784, 2))).toBe('0.96');
    expect(fmtNumber(1.1, 3)).toBe('1.100');
    expect(fmtNumber(4.6, 3)).toBe('4.600');
    expect(spec(fmtNumber(12345.678, 1))).toBe('12,345.7');
    expect(spec(fmtPlain(0.5))).toBe('0.5');
    expect(fmtPlain(20)).toBe('20');
    expect(spec(fmtPlain(200000))).toBe('200,000');
    expect(fmtPlain(0.026)).toBe('0.026');
    expect(spec(fmtPlain(1.2815515655446004, 4))).toBe('1.2816');
    expect(fmtPlain(100.0)).toBe('100');
    expect(spec(fmtFixed(38.96, 6))).toBe('38.960000');
    expect(fmtFixed(-77.36, 6)).toBe('-77.360000');
  });

  it('null, undefined and non-finite numbers give the em dash', () => {
    const all = [fmtMoney, fmtMoneyCents, fmtFuel, fmtCount, fmtCount1, fmtCeil, fmtPerHour, fmtAbout, fmtPercent, fmtMiles, fmtDuration, fmtHours, fmtClock, fmtClockShort, fmtHourTick, fmtHow, fmtHowLong, fmtTemp, fmtNumber, fmtPlain];
    for (const f of all) {
      expect(f(null)).toBe(DASH);
      expect(f(undefined)).toBe(DASH);
      expect(f(NaN)).toBe(DASH);
      expect(f(Infinity)).toBe(DASH);
    }
    expect(fmtWindow(null, 840)).toBe(DASH);
    expect(fmtCoord(null, 1)).toBe(DASH);
    expect(fmtDay(null)).toBe(DASH);
    expect(fmtDay('2026-02-30')).toBe(DASH);
    expect(fmtDay('8 October')).toBe(DASH);
    expect(fmtPhone(null)).toBe(DASH);
    expect(fmtRange(null, 'orders')).toBe(DASH);
    expect(fmtEstimate(undefined, 'money')).toBe(DASH);
    expect(fmtWeekday(null)).toBe(DASH);
  });
});

describe('estimates (3.1, 6.1)', () => {
  it('fmtRange uses the word "to", also for a negative low', () => {
    expect(spec(fmtRange(A1_ORDERS, 'orders'))).toBe('33 to 93');
    expect(spec(fmtRange(LUNCH_ONLY, 'money'))).toBe('$85 to $659');
    expect(spec(fmtRange(STOP_ADDS, 'money'))).toBe('-$43 to $353');
  });

  it('fmtEstimate: value, unit word, range in brackets', () => {
    expect(spec(fmtEstimate(A1_ORDERS, 'orders'))).toBe('60 orders (33 to 93)');
    expect(spec(fmtEstimate(est(1.2, 0.3, 2.9), 'orders'))).toBe('1 order (0 to 3)');
    expect(spec(fmtEstimate(TAKE_HOME, 'money'))).toBe('$482 ($42 to $1,012)');
    expect(spec(fmtEstimate(STOP_ADDS, 'money'))).toBe('$135 (-$43 to $353)');
    expect(spec(fmtEstimate(est(80, 80, 80, 'fixed'), 'orders'))).toBe('80 orders');
    expect(spec(fmtEstimate(PER_HOUR, 'money_per_hour'))).toBe('$43 an hour ($4 to $90)');
  });

  it('the other figures of the worked day and of the anchors print as the documents write them', () => {
    expect(spec(fmtEstimate(est(39.384, 20.7628, 62.198), 'orders'))).toBe('39 orders (21 to 62)');
    expect(spec(fmtEstimate(LUNCH_ONLY, 'money'))).toBe('$347 ($85 to $659)');
    expect(spec(fmtEstimate(est(561.4, 121.55, 1091.06), 'money'))).toBe('$561 ($122 to $1,091)');
    expect(spec(fmtCeil(25.232))).toBe('26');
  });

  it('fixed, zero, singular and negative low', () => {
    // fixed: no range even when low and high differ from the value on paper
    expect(fmtEstimate(est(500, 500, 500, 'fixed'), 'money')).toBe('$500');
    expect(fmtEstimate(est(744, 0, 900, 'fixed'), 'money')).toBe('$744');
    // zero: 0 orders, no range
    expect(fmtEstimate(est(0, 0, 0), 'orders')).toBe('0 orders');
    expect(fmtEstimate(est(0, 0, 0), 'money')).toBe('$0');
    // low and high that print the same carry no range
    expect(fmtEstimate(est(135, 135, 135, 'very_rough'), 'orders')).toBe('135 orders');
    expect(fmtEstimate(est(0.3, 0.2, 0.4), 'orders')).toBe('0 orders');
    expect(fmtEstimate(est(135, 122.63, 135), 'orders')).toBe('135 orders (123 to 135)');
    // singular follows the printed value
    expect(fmtEstimate(est(1, 1, 1, 'fixed'), 'orders')).toBe('1 order');
    expect(fmtEstimate(est(0.6, 0.1, 1.4), 'orders')).toBe('1 order (0 to 1)');
    expect(fmtEstimate(est(1.5, 0.6, 3.2), 'orders')).toBe('2 orders (1 to 3)');
    // a loss keeps its minus sign on every part
    expect(fmtEstimate(est(-34.82, -120.4, 55.2), 'money')).toBe('-$35 (-$120 to $55)');
    expect(fmtEstimate(est(23.15, -7.31, 60.46), 'money_per_hour')).toBe('$23 an hour (-$7 to $60)');
    expect(estimateParts(est(-34.82, -120.4, 55.2), 'money').negative).toBe(true);
    expect(estimateParts(est(-0.3, -9, 5), 'money').negative).toBe(false);
  });

  it('fmtEstimateSpoken is the sentence form', () => {
    expect(spec(fmtEstimateSpoken(A1_ORDERS, 'orders'))).toBe('60 orders, likely between 33 and 93');
    expect(spec(fmtEstimateSpoken(TAKE_HOME, 'money'))).toBe('$482, likely between $42 and $1,012');
    expect(spec(fmtEstimateSpoken(est(80, 80, 80, 'fixed'), 'orders'))).toBe('80 orders');
  });
});

describe('distance and time (3.1)', () => {
  it('fmtMiles, fmtDuration, fmtHours', () => {
    expect(spec(fmtMiles(9.7))).toBe('9.7 mi');
    expect(spec(fmtMiles(123.4))).toBe('123 mi');
    expect(spec(fmtMiles(0.02))).toBe('under 0.1 mi');
    expect(fmtMiles(0.05)).toBe('0.1 mi');
    expect(fmtMiles(99.96)).toBe('100 mi');
    expect(fmtMiles(4.8498)).toBe('4.8 mi');
    expect(spec(fmtDuration(45))).toBe('45 min');
    expect(spec(fmtDuration(65))).toBe('1 h 5 min');
    expect(spec(fmtDuration(120))).toBe('2 h');
    expect(spec(fmtDuration(677))).toBe('11 h 17 min');
    expect(fmtDuration(0)).toBe('0 min');
    expect(fmtDuration(8.7719)).toBe('9 min');
    expect(spec(fmtHours(5.8333))).toBe('5.8 hours');
    expect(spec(fmtHours(1))).toBe('1 hour');
    expect(fmtHours(9.2833)).toBe('9.3 hours');
    expect(fmtHours(2)).toBe('2 hours');
    expect(fmtHours(0.5)).toBe('0.5 hours');
  });

  it('fmtClock: 12-hour, with the neighbouring day in words', () => {
    expect(spec(fmtClock(574))).toBe('9:34 AM');
    expect(spec(fmtClock(720))).toBe('12:00 PM');
    expect(spec(fmtClock(0))).toBe('12:00 AM');
    expect(spec(fmtClock(1251))).toBe('8:51 PM');
    expect(spec(fmtClock(1470))).toBe('12:30 AM (next day)');
    expect(spec(fmtClock(-30))).toBe('11:30 PM (day before)');
    expect(fmtClock(1439)).toBe('11:59 PM');
    expect(fmtClock(1440)).toBe('12:00 AM (next day)');
    expect(spec(fmtClock(2880))).toBe('12:00 AM (2 days later)');
    expect(spec(fmtClock(-1441))).toBe('11:59 PM (2 days before)');
    expect(fmtClock(61)).toBe('1:01 AM');
  });

  it('the twelve times of the blueprint day sheet', () => {
    const minutes = [574, 619, 630, 660, 840, 860, 870, 1020, 1200, 1220, 1221, 1251];
    const clocks = ['9:34 AM', '10:19 AM', '10:30 AM', '11:00 AM', '2:00 PM', '2:20 PM', '2:30 PM', '5:00 PM', '8:00 PM', '8:20 PM', '8:21 PM', '8:51 PM'];
    for (let i = 0; i < minutes.length; i++) expect(spec(fmtClock(minutes[i]))).toBe(clocks[i]);
  });

  it('fmtClockShort, fmtWindow, fmtHourTick', () => {
    expect(spec(fmtClockShort(660))).toBe('11 AM');
    expect(spec(fmtClockShort(870))).toBe('2:30 PM');
    expect(fmtClockShort(0)).toBe('12 AM');
    expect(spec(fmtWindow(660, 840))).toBe('11 AM to 2 PM');
    expect(spec(fmtWindow(1290, 1500))).toBe('9:30 PM to 1 AM (next day)');
    expect(fmtWindow(1020, 1200)).toBe('5 PM to 8 PM');
    expect(spec(fmtHourTick(0))).toBe('12a');
    expect(spec(fmtHourTick(13))).toBe('1p');
    expect(fmtHourTick(12)).toBe('12p');
    expect(fmtHourTick(6)).toBe('6a');
    expect(fmtHourTick(18)).toBe('6p');
  });

  it('fmtHow, fmtHowLong, fmtWeekday', () => {
    expect(spec(fmtHow(84))).toBe('Thu 12 PM');
    expect(spec(fmtHowLong(84))).toBe('Thursday, 12 PM to 1 PM');
    expect(fmtHow(0)).toBe('Mon 12 AM');
    expect(fmtHow(167)).toBe('Sun 11 PM');
    expect(fmtHowLong(167)).toBe('Sunday, 11 PM to 12 AM');
    expect(fmtHow(168)).toBe('Mon 12 AM');
    expect(fmtHow(-1)).toBe('Sun 11 PM');
    expect(spec(fmtWeekday(3))).toBe('Thursday');
    expect(spec(fmtWeekday(3, 'short'))).toBe('Thu');
    expect(fmtWeekday(0)).toBe('Monday');
    expect(fmtWeekday(6, 'short')).toBe('Sun');
  });

  it('fmtDay: weekday from the model, month from the string', () => {
    expect(spec(fmtDay('2026-10-08', 'long'))).toBe('Thu, Oct 8, 2026');
    expect(spec(fmtDay('2026-10-08', 'medium'))).toBe('Thu, Oct 8');
    expect(spec(fmtDay('2026-10-08', 'short'))).toBe('Oct 8');
    expect(fmtDay('2026-10-08')).toBe('Thu, Oct 8');
    expect(fmtDay('2026-01-01', 'long')).toBe('Thu, Jan 1, 2026');
    expect(fmtDay('2027-12-31', 'long')).toBe('Fri, Dec 31, 2027');
    expect(fmtDay('2000-02-29', 'medium')).toBe('Tue, Feb 29');
  });

  it('fmtTemp, fmtPhone, fmtCoord', () => {
    expect(spec(fmtTemp(62))).toBe('62°F');
    expect(fmtTemp(-4.6)).toBe('-5°F');
    expect(spec(fmtPhone('+13017428261'))).toBe('(301) 742-8261');
    expect(fmtPhone('301-742-8261')).toBe('301-742-8261');
    expect(fmtPhone('+4420794601')).toBe('+4420794601');
    expect(fmtPhone('')).toBe('');
    expect(spec(fmtCoord(38.96, -77.36))).toBe('38.9600, -77.3600');
    expect(fmtCoord(38.96964, -77.38615)).toBe('38.9696, -77.3862');
  });
});

describe('parsers (3.1, 3.6)', () => {
  it('parseNumber strips $, commas, spaces and a trailing %', () => {
    expect(parseNumber('15')).toBe(15);
    expect(parseNumber(' $1,500.50 ')).toBe(1500.5);
    expect(parseNumber('30%')).toBe(30);
    expect(parseNumber('2.6 %')).toBe(2.6);
    expect(parseNumber('-$35')).toBe(-35);
    expect(parseNumber('.5')).toBe(0.5);
    expect(parseNumber('5.')).toBe(5);
    expect(parseNumber('200 000')).toBe(200000);
    expect(Object.is(parseNumber('-0'), 0)).toBe(true);
  });

  it('parseNumber gives null for anything that is not a plain number', () => {
    for (const text of ['', '   ', 'abc', '1.2.3', '1e3', '0x10', 'Infinity', 'NaN', '--5', '5-', '%', '$', '12 orders', '1/2']) {
      expect(parseNumber(text)).toBeNull();
    }
    expect(parseNumber(null)).toBeNull();
    expect(parseNumber(undefined)).toBeNull();
  });

  it('parseCoords reads latitude and longitude', () => {
    expect(spec(parseCoords('38.9696, -77.3861'))).toEqual({ lat: 38.9696, lng: -77.3861 });
    expect(parseCoords('38.9696 -77.3861')).toEqual({ lat: 38.9696, lng: -77.3861 });
    expect(parseCoords('  38.9696,-77.3861  ')).toEqual({ lat: 38.9696, lng: -77.3861 });
    expect(parseCoords('-90, 180')).toEqual({ lat: -90, lng: 180 });
    expect(parseCoords('90.0001, 0')).toBeNull();
    expect(parseCoords('0, -180.5')).toBeNull();
    expect(parseCoords('38.9696')).toBeNull();
    expect(parseCoords('38.9696, -77.3861, 12')).toBeNull();
    expect(parseCoords('north, west')).toBeNull();
    expect(parseCoords('')).toBeNull();
    expect(parseCoords(null)).toBeNull();
  });

  it('parseClock: the examples of 3.6', () => {
    expect(spec(parseClock('11'))).toBe(660);
    expect(spec(parseClock('2'))).toBe(840);
    expect(spec(parseClock('930'))).toBe(570);
    expect(spec(parseClock('14:15'))).toBe(855);
    expect(spec(parseClock('12a'))).toBe(0);
    expect(spec(parseClock('1am', { after: 1320, allowNextDay: true }))).toBe(1500);
    expect(spec(parseClock('2', { after: 1290, allowNextDay: true }))).toBe(1560);
  });

  it('parseClock: the rules of 3.6', () => {
    expect(spec(parseClock('noon'))).toBe(720);
    expect(spec(parseClock('midnight'))).toBe(0);
    expect(spec(parseClock('12am'))).toBe(0);
    expect(spec(parseClock('12pm'))).toBe(720);
    expect(spec(parseClock('24'))).toBe(1440);
    expect(parseClock(' 2:30 P.M. ')).toBe(870);
    expect(parseClock('NOON')).toBe(720);
    expect(parseClock('2:30pm')).toBe(870);
    expect(parseClock('230p')).toBe(870);
    expect(parseClock('0')).toBe(0);
    expect(parseClock('0:30')).toBe(30);
    expect(parseClock('13')).toBe(780);
    expect(parseClock('23:59')).toBe(1439);
    expect(parseClock('2400')).toBe(1440);
    // 1..12 without a suffix: 7..11 are AM, 12 and 1..6 are PM
    expect(parseClock('7')).toBe(420);
    expect(parseClock('10:45')).toBe(645);
    expect(parseClock('12')).toBe(720);
    expect(parseClock('1')).toBe(780);
    expect(parseClock('6')).toBe(1080);
    expect(parseClock('115')).toBe(795);
  });

  it('parseClock: a time has to come after the one before it', () => {
    // the first reading later than `after`: AM, then PM, then the next day
    expect(parseClock('2', { after: 660 })).toBe(840);
    expect(parseClock('11', { after: 660 })).toBe(1380);
    expect(parseClock('6', { after: 300 })).toBe(360);
    expect(parseClock('9', { after: 1020 })).toBe(1260);
    expect(parseClock('1', { after: 1320, allowNextDay: true })).toBe(1500);
    expect(parseClock('5', { after: 1020, allowNextDay: true })).toBe(1740);
    expect(parseClock('midnight', { after: 1290, allowNextDay: true })).toBe(1440);
    expect(parseClock('13:00', { after: 1290, allowNextDay: true })).toBe(2220);
    expect(parseClock('9pm', { after: 1290, allowNextDay: true })).toBe(2700);
    // without the next day the habit decides and the field reports the range
    expect(parseClock('1', { after: 1320 })).toBe(780);
    expect(parseClock('1am', { after: 1320 })).toBe(60);
  });

  it('parseClock rejects what is not a time', () => {
    expect(spec(parseClock('25'))).toBeNull();
    expect(spec(parseClock('12:60'))).toBeNull();
    expect(spec(parseClock('13pm'))).toBeNull();
    expect(spec(parseClock(''))).toBeNull();
    for (const text of ['0am', '24:30', '99', '1:5', '12:345', 'half past two', '2 pm est', '-3', '2.5.1 pm x']) {
      expect(parseClock(text)).toBeNull();
    }
    expect(parseClock(null)).toBeNull();
    expect(parseClock(undefined)).toBeNull();
  });
});

describe('worked examples', () => {
  it('this file asserts 107 values the specification prints', () => {
    expect(specCount()).toBe(107);
  });
});

// Truck Planner - the clock (docs/truck-planner/05_FRONTEND.md 2.7 and 8.2).
//
// The check values are those printed in the specification. Every expectation is in terms of a named
// time zone and an explicit epoch, so the result is the same on a machine in any zone.

import { describe, expect, it } from 'vitest';
import { clockSkewMinutes, nowEpochMs, regionNow, utcStamp, zonedToUtcStamp } from '../clock';

const NY = 'America/New_York';

describe('regionNow', () => {
  it('gives the check values of the specification', () => {
    expect(regionNow(NY, Date.UTC(2026, 9, 8, 3, 30))).toEqual({ date: '2026-10-07', minute: 1410 });
    expect(regionNow(NY, Date.UTC(2026, 0, 15, 17, 0))).toEqual({ date: '2026-01-15', minute: 720 });
  });

  it('follows the end of daylight time', () => {
    // 2026-11-01: 1:59 AM daylight time is followed by 1:00 AM standard time.
    expect(regionNow(NY, Date.UTC(2026, 10, 1, 5, 59))).toEqual({ date: '2026-11-01', minute: 119 });
    expect(regionNow(NY, Date.UTC(2026, 10, 1, 6, 0))).toEqual({ date: '2026-11-01', minute: 60 });
  });

  it('reads midnight as minute 0 of the new date', () => {
    expect(regionNow(NY, Date.UTC(2026, 9, 8, 4, 0))).toEqual({ date: '2026-10-08', minute: 0 });
    expect(regionNow(NY, Date.UTC(2026, 9, 8, 3, 59))).toEqual({ date: '2026-10-07', minute: 1439 });
  });

  it('uses the named zone, whatever it is', () => {
    const noonUtc = Date.UTC(2026, 6, 4, 12, 0);
    expect(regionNow('UTC', noonUtc)).toEqual({ date: '2026-07-04', minute: 720 });
    expect(regionNow('America/Los_Angeles', noonUtc)).toEqual({ date: '2026-07-04', minute: 300 });
    expect(regionNow('Pacific/Kiritimati', noonUtc)).toEqual({ date: '2026-07-05', minute: 120 });
  });
});

describe('zonedToUtcStamp', () => {
  it('gives the check values of the specification', () => {
    expect(zonedToUtcStamp(NY, '2026-10-08', 660)).toBe('20261008T150000Z');
    expect(zonedToUtcStamp(NY, '2026-12-10', 660)).toBe('20261210T160000Z');
  });

  it('moves minutes below 0 and from 1440 to the neighbouring date', () => {
    expect(zonedToUtcStamp(NY, '2026-10-08', 1500)).toBe('20261009T050000Z');
    expect(zonedToUtcStamp(NY, '2026-10-08', -30)).toBe('20261008T033000Z');
    expect(zonedToUtcStamp(NY, '2026-12-31', 1440 + 30)).toBe('20270101T053000Z');
  });

  it('takes the earlier instant of a wall time that occurs twice', () => {
    // 1:30 AM on 2026-11-01 happens at 05:30Z (daylight time) and again at 06:30Z (standard time).
    expect(zonedToUtcStamp(NY, '2026-11-01', 90)).toBe('20261101T053000Z');
    // After the repeated hour the standard offset applies.
    expect(zonedToUtcStamp(NY, '2026-11-01', 600)).toBe('20261101T150000Z');
    // East of Greenwich: 2:30 AM on 2026-10-25 in Berlin is 00:30Z (summer time) and 01:30Z.
    expect(zonedToUtcStamp('Europe/Berlin', '2026-10-25', 150)).toBe('20261025T003000Z');
  });

  it('reads a wall time that does not occur with the offset before the change', () => {
    // 2:30 AM on 2026-03-08 is skipped in New York; calendars read it as 3:30 AM daylight time.
    expect(zonedToUtcStamp(NY, '2026-03-08', 150)).toBe('20260308T073000Z');
    expect(zonedToUtcStamp(NY, '2026-03-08', 600)).toBe('20260308T140000Z');
    expect(zonedToUtcStamp('Europe/Berlin', '2026-03-29', 150)).toBe('20260329T013000Z');
  });

  it('is the inverse of regionNow for ordinary times', () => {
    for (const zone of [NY, 'America/Chicago', 'America/Phoenix', 'UTC']) {
      for (const minute of [0, 1, 574, 720, 1251, 1439]) {
        const stamp = zonedToUtcStamp(zone, '2026-10-08', minute);
        const epoch = Date.UTC(
          Number(stamp.slice(0, 4)),
          Number(stamp.slice(4, 6)) - 1,
          Number(stamp.slice(6, 8)),
          Number(stamp.slice(9, 11)),
          Number(stamp.slice(11, 13)),
        );
        expect(regionNow(zone, epoch)).toEqual({ date: '2026-10-08', minute });
      }
    }
  });
});

describe('utcStamp', () => {
  it('writes an instant as YYYYMMDDTHHMMSSZ', () => {
    expect(utcStamp(Date.UTC(2026, 9, 8, 13, 34, 0))).toBe('20261008T133400Z');
    expect(utcStamp(Date.UTC(2026, 0, 1, 0, 0, 5))).toBe('20260101T000005Z');
    expect(utcStamp(Date.UTC(2028, 1, 29, 23, 59, 59))).toBe('20280229T235959Z');
    expect(utcStamp(0)).toBe('19700101T000000Z');
  });

  it('drops the milliseconds', () => {
    expect(utcStamp(Date.UTC(2026, 9, 8, 13, 34, 0) + 999)).toBe('20261008T133400Z');
  });
});

describe('clockSkewMinutes', () => {
  // The server says it is 2026-10-08 12:00 in New York, which is 16:00Z.
  const serverInstant = Date.UTC(2026, 9, 8, 16, 0);

  it('is zero when the device agrees', () => {
    expect(clockSkewMinutes('2026-10-08', 720, NY, serverInstant)).toBe(0);
  });

  it('ignores a difference of one minute either way', () => {
    expect(clockSkewMinutes('2026-10-08', 720, NY, serverInstant + 60000)).toBe(0);
    expect(clockSkewMinutes('2026-10-08', 720, NY, serverInstant - 60000)).toBe(0);
    expect(clockSkewMinutes('2026-10-08', 720, NY, serverInstant + 59000)).toBe(0);
  });

  it('applies a difference of ten minutes', () => {
    // The device is ten minutes behind: add ten.
    expect(clockSkewMinutes('2026-10-08', 720, NY, serverInstant - 10 * 60000)).toBe(10);
    // The device is ten minutes ahead: take ten off.
    expect(clockSkewMinutes('2026-10-08', 720, NY, serverInstant + 10 * 60000)).toBe(-10);
    expect(clockSkewMinutes('2026-10-08', 720, NY, serverInstant - 2 * 60000)).toBe(2);
  });

  it('corrects a device whose date is wrong, so that it shows the server day', () => {
    // The device believes it is two days later.
    const device = serverInstant + 2 * 1440 * 60000;
    const skew = clockSkewMinutes('2026-10-08', 720, NY, device);
    expect(skew).toBe(-2880);
    expect(regionNow(NY, device + skew * 60000)).toEqual({ date: '2026-10-08', minute: 720 });
  });

  it('measures across midnight', () => {
    // Server: 00:05 on the 9th. Device: 23:55 on the 8th in the same zone.
    const device = Date.UTC(2026, 9, 9, 3, 55);
    expect(clockSkewMinutes('2026-10-09', 5, NY, device)).toBe(10);
  });
});

describe('nowEpochMs', () => {
  it('is the device epoch', () => {
    const before = Date.now();
    const now = nowEpochMs();
    expect(now).toBeGreaterThanOrEqual(before);
    expect(now).toBeLessThanOrEqual(Date.now());
  });
});

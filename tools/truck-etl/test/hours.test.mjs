import assert from 'node:assert/strict';
import { test } from 'node:test';
import { openHours, parseOpeningHours, parseOpeningHoursSpans } from '../src/hours.mjs';

// The test vectors of 03_DATA.md 5.7: [raw value, hours_mask, open hours].
const VECTORS = [
  ['24/7', 'ffffffffffffffffffffffffffffffffffffffffff', 168],
  ['04:30-21:00', 'f0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1f', 119],
  ['Mo-Fr 08:00-12:00,13:00-17:30', '00ef0300ef0300ef0300ef0300ef03000000000000', 45],
  ['Mo-Fr 10:00-18:00, Sa 10:00-17:00, Su 12:00-17:00', '00fc0300fc0300fc0300fc0300fc0300fc0100f001', 52],
  ['Mo-Th 11:00-22:00; Fr,Sa 11:00-02:00; Su 12:00-20:00', '00f83f00f83f00f83f00f83f00f8ff03f8ff03f00f', 82],
  ['Mo off; Tu-Su 16:00-22:45', '00000000007f00007f00007f00007f00007f00007f', 42],
  ['Mo-Su 11:00-21:00; PH off', '00f81f00f81f00f81f00f81f00f81f00f81f00f81f', 70],
  ['Mo-Su 09:00-18:00, Sa 08:00-15:00', '00fe0300fe0300fe0300fe0300fe0300ff0300fe03', 64],
  ['Fr 17:00+', '0000000000000000000000000000fe000000000000', 7],
];

test('opening hours: the specification vectors', () => {
  for (const [raw, mask, hours] of VECTORS) {
    assert.equal(parseOpeningHours(raw), mask, raw);
    assert.equal(openHours(mask), hours, raw);
    assert.equal(mask.length, 42);
  }
});

test('opening hours: values outside the grammar are unknown (null), never closed', () => {
  for (const raw of [
    'sunrise-sunset', '"by appointment"', 'Mo-Su 10:00-17:30; Dec 25 off', 'off', 'closed', 'Closed', 'dawn-dusk',
    'Mo-Fr 09:00-17:00 "by appointment"', 'sunrise-23:00', '24/7 closed', 'Mo-Fr 24/7', 'week 1-20 Mo 10:00-12:00',
    'Mo[1] 10:00-12:00', 'Mo 10:00-12:00 || Tu 10:00-12:00', 'Mo-Fr 10:00-12:00 open', 'unknown', 'Jan-Mar Mo 10:00-12:00',
    'Mo 25:00-26:00', 'Mo 10:60-12:00', 'Mo 10:00-49:00', 'Mo 10:00', 'Mon 10:00-12:00', 'Sat 10:00-12:00', 'Thu 10:00-12:00',
    'PH-Mo 10:00-12:00', 'Mo-PH 10:00-12:00', 'Mo 10:00-12:00;;Tu 10:00-12:00', '', '   ', ';',
  ]) {
    assert.equal(parseOpeningHours(raw), null, JSON.stringify(raw));
  }
  assert.equal(parseOpeningHours(undefined), null);
  assert.equal(parseOpeningHours(null), null);
  assert.equal(parseOpeningHours(42), null);
});

test('opening hours: preparation (case, spaces, trailing semicolons)', () => {
  const reference = parseOpeningHours('Mo-Fr 08:00-12:00,13:00-17:30');
  assert.equal(parseOpeningHours('  mo - fr   08:00 - 12:00 , 13:00 - 17:30 ;; '), reference);
  assert.equal(parseOpeningHours('MO-FR 08:00-12:00,13:00-17:30;'), reference);
});

test('opening hours: day ranges wrap around the week', () => {
  const spans = parseOpeningHoursSpans('Sa-Mo 10:00-14:00');
  assert.deepEqual(spans.map((d) => d.length), [1, 0, 0, 0, 0, 1, 1]);
  assert.deepEqual(spans[5], [[600, 840]]);
  assert.equal(openHours(parseOpeningHours('Sa-Mo 10:00-14:00')), 12);
});

test('opening hours: days alone, off, closed and holidays', () => {
  assert.equal(openHours(parseOpeningHours('Mo-Fr')), 120); // days alone: open all day
  assert.equal(openHours(parseOpeningHours('Mo-Su off')), 0);
  assert.equal(openHours(parseOpeningHours('Mo-Fr 09:00-17:00; Sa closed')), 40);
  // a sub naming only PH is validated and skipped; PH beside real days is ignored
  assert.equal(parseOpeningHours('Mo-Fr 09:00-17:00; PH off'), parseOpeningHours('Mo-Fr 09:00-17:00'));
  assert.equal(parseOpeningHours('Mo-Fr 09:00-17:00; PH 10:00-14:00'), parseOpeningHours('Mo-Fr 09:00-17:00'));
  assert.equal(parseOpeningHours('Sa,Su,PH 10:00-16:00'), parseOpeningHours('Sa,Su 10:00-16:00'));
  assert.equal(parseOpeningHours('Mo-Fr 09:00-17:00; PH 10:00-99:00'), null); // still validated
});

test('opening hours: a later rule replaces a day, a later sub of the same rule adds to it', () => {
  // second rule replaces Saturday
  assert.equal(openHours(parseOpeningHours('Mo-Su 09:00-18:00; Sa 08:00-15:00')), 6 * 9 + 7);
  // comma-separated sub of the same rule adds to Saturday: 08:00-18:00
  assert.equal(openHours(parseOpeningHours('Mo-Su 09:00-18:00, Sa 08:00-15:00')), 6 * 9 + 10);
  // a comma between two time spans stays inside one sub (hours 11 to 14 and 17 to 21: half hours count)
  assert.equal(openHours(parseOpeningHours('Tu-Su 11:30-14:30,17:00-21:30')), 6 * 9);
  // a comma between two days before any time stays inside the day list
  assert.equal(openHours(parseOpeningHours('Su-Th 10:00-24:00, Fr,Sa 10:00-01:00')), 5 * 14 + 2 * 15);
  // 24/7 followed by a rule that closes one day
  assert.equal(openHours(parseOpeningHours('24/7; Su off')), 144);
});

test('opening hours: spans past midnight, open ends and the 30-minute rule', () => {
  // Friday 22:00-02:00 runs into Saturday; Sunday 22:00-02:00 wraps into Monday
  const spans = parseOpeningHoursSpans('Fr 22:00-02:00');
  assert.deepEqual(spans[4], [[1320, 1560]]);
  const mask = Buffer.from(parseOpeningHours('Su 22:00-02:00'), 'hex');
  const bit = (h) => (mask[h >> 3] >> (h & 7)) & 1;
  assert.deepEqual([bit(6 * 24 + 22), bit(6 * 24 + 23), bit(0), bit(1), bit(2)], [1, 1, 1, 1, 0]);
  // an hour counts as open when at least 30 of its minutes are open
  assert.equal(openHours(parseOpeningHours('Mo 08:00-08:20')), 0);
  assert.equal(openHours(parseOpeningHours('Mo 08:00-08:30')), 1);
  assert.equal(openHours(parseOpeningHours('Mo 08:31-09:00')), 0);
  assert.equal(openHours(parseOpeningHours('Mo 08:30-09:29')), 1); // 30 minutes of the 08 hour, 29 of the 09 hour
  // the end may be written up to 48:00, a span may not exceed 24 hours
  assert.equal(openHours(parseOpeningHours('Mo 20:00-26:00')), 6);
  assert.equal(openHours(parseOpeningHours('Mo 00:00-24:00')), 24);
  assert.equal(parseOpeningHours('Mo 10:00-36:00'), null);
  // "H:MM-H:MM+" is read as the span itself
  assert.equal(parseOpeningHours('Mo 10:00-12:00+'), parseOpeningHours('Mo 10:00-12:00'));
  assert.equal(openHours(parseOpeningHours('17:00+')), 49);
});

test('opening hours: bit layout (bit h = dow * 24 + hour, least significant bit first)', () => {
  const mask = Buffer.from(parseOpeningHours('Mo 00:00-01:00; Tu 09:00-10:00; Su 23:00-24:00'), 'hex');
  assert.equal(mask.length, 21);
  assert.equal(mask[0], 0x01); // Monday hour 0 = bit 0
  assert.equal(mask[4], 0x02); // Tuesday hour 9 = bit 33 = byte 4, bit 1
  assert.equal(mask[20], 0x80); // Sunday hour 23 = bit 167 = byte 20, bit 7
});

test('opening hours: values longer than the column are not parsed', () => {
  const long = `Mo 10:00-12:00${'; Tu 10:00-12:00'.repeat(20)}`;
  assert.ok(long.length > 255);
  assert.equal(parseOpeningHours(long), null);
});

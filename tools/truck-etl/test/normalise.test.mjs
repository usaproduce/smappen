import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  comparableName, cutCodePoints, normaliseAddrLine, normaliseAttributes, normaliseCuisine, normaliseName, normalisePhone,
  normaliseTags, normaliseWebsite,
} from '../src/normalise.mjs';

test('phone: US numbers become +1 and ten digits, anything else is null', () => {
  const ok = {
    '+1 301-742-8261': '+13017428261',
    '(703) 555-0123': '+17035550123',
    '703.555.0123': '+17035550123',
    '1-703-555-0123': '+17035550123',
    '001 703 555 0123': '+17035550123',
    '+1-703-555-0123 ext. 12': '+17035550123',
    '703-555-0123 x204': '+17035550123',
    '703-555-0123; 703-555-9999': '+17035550123',
    '703-555-0123 / 703-555-9999': '+17035550123',
    '703-555-0123 or 703-555-9999': '+17035550123',
    '  +1 (202) 555 0199  ': '+12025550199',
  };
  for (const [raw, expected] of Object.entries(ok)) assert.equal(normalisePhone(raw), expected, raw);
  for (const raw of ['+-804-867-5421', '+44 20 7946 0958', '555-0123', '703-555-012', '1-800-FLOWERS', '123-555-0123', '703-155-0123', '', '   ', null, undefined]) {
    assert.equal(normalisePhone(raw), null, String(raw));
  }
});

test('website: http(s) with a lower-cased scheme and host, anything else is null', () => {
  assert.equal(normaliseWebsite('https://www.starbucks.com/store-locator/store/12403/'), 'https://www.starbucks.com/store-locator/store/12403/');
  assert.equal(normaliseWebsite('www.Example.com/Menu'), 'https://www.example.com/Menu');
  assert.equal(normaliseWebsite('HTTP://Example.COM:8080/a?b=C#d'), 'http://example.com:8080/a?b=C#d');
  assert.equal(normaliseWebsite(' example.org ; other.org '), 'https://example.org');
  for (const raw of ['https://www/x.com', 'http://www rnjsports.com', 'ftp://example.com/file', 'mailto:someone@example.com', 'localhost', 'http://', '', null,
    'http://a.com/, http://b.com/', `https://example.com/${'x'.repeat(240)}`]) {
    assert.equal(normaliseWebsite(raw), null, String(raw));
  }
  assert.equal(normaliseWebsite(`https://example.com/${'x'.repeat(235)}`).length, 255);
});

test('name: name tag, else brand; trimmed, collapsed, NFC, 160 code points', () => {
  assert.equal(normaliseName({ name: '  Joe\'s \t Diner \n', brand: 'B' }), 'Joe\'s Diner');
  assert.equal(normaliseName({ brand: ' Subway ' }), 'Subway');
  assert.equal(normaliseName({ name: '   ', brand: 'Subway' }), 'Subway');
  assert.equal(normaliseName({ name: '' }), null);
  assert.equal(normaliseName({}), null);
  assert.equal(normaliseName({ name: 'Café' }), 'Café'); // NFC
  assert.equal(Array.from(normaliseName({ name: 'x'.repeat(200) })).length, 160);
});

test('length limits count code points and never split a surrogate pair', () => {
  const pizza = '\u{1F355}';
  assert.equal(cutCodePoints(pizza.repeat(5), 3), pizza.repeat(3));
  assert.equal(cutCodePoints('abc', 3), 'abc');
  const name = normaliseName({ name: pizza.repeat(170) });
  assert.equal(Array.from(name).length, 160);
  assert.equal(name.length, 320);
  assert.doesNotThrow(() => JSON.parse(JSON.stringify(name)));
  assert.equal(encodeURIComponent(name).length > 0, true); // throws on a lone surrogate
});

test('comparable name for de-duplication', () => {
  assert.equal(comparableName('Café Éclair'), 'cafe eclair');
  assert.equal(comparableName('AT&T'), 'at and t');
  assert.equal(comparableName('Ben & Jerry\'s'), 'ben and jerry s');
  assert.equal(comparableName('Ben and Jerry’s'), 'ben and jerry s');
  assert.equal(comparableName('  7-Eleven #1234  '), '7 eleven 1234');
  assert.equal(comparableName('北京饭店'), ''); // no Latin letters or digits: never matches by name
  assert.equal(comparableName(null), '');
});

test('address, city, state and postcode', () => {
  assert.equal(normaliseAddrLine({ 'addr:housenumber': '44844', 'addr:street': 'Aviation Drive' }), '44844 Aviation Drive');
  assert.equal(normaliseAddrLine({ 'addr:housenumber': '6763', 'addr:street': 'Wilson Boulevard', 'addr:unit': 'R' }), '6763 Wilson Boulevard, R');
  assert.equal(normaliseAddrLine({ 'addr:street': 'Main Street' }), 'Main Street');
  assert.equal(normaliseAddrLine({ 'addr:housenumber': '12', 'addr:full': '12 Main St, Herndon' }), '12 Main St, Herndon');
  assert.equal(normaliseAddrLine({ 'addr:unit': '4' }), null);
  assert.equal(Array.from(normaliseAddrLine({ 'addr:street': 's'.repeat(300) })).length, 200);

  const a = normaliseAttributes({ 'addr:city': ' Sterling ', 'addr:state': 'va', 'addr:postcode': '20166-1234' });
  assert.deepEqual([a.city, a.state_code, a.postcode], ['Sterling', 'VA', '20166']);
  const b = normaliseAttributes({ 'addr:state': 'Virginia', 'addr:postcode': '2016' });
  assert.deepEqual([b.city, b.state_code, b.postcode], [null, null, null]);
  assert.equal(normaliseAttributes({ 'addr:postcode': '20166' }).postcode, '20166');
  assert.equal(normaliseAttributes({ 'addr:postcode': 'VA 20166' }).postcode, null);
});

test('cuisine: split, lower case, underscores, six values, 120 code points', () => {
  assert.equal(normaliseCuisine('coffee_shop'), 'coffee_shop');
  assert.equal(normaliseCuisine('Pizza; Italian ,pizza; Ice Cream;;'), 'pizza;italian;ice_cream');
  assert.equal(normaliseCuisine('a;b;c;d;e;f;g;h'), 'a;b;c;d;e;f');
  assert.equal(normaliseCuisine(''), null);
  assert.equal(normaliseCuisine(undefined), null);
  const long = Array.from({ length: 6 }, (_, i) => `${'c'.repeat(29)}${i}`).join(';'); // 6 x 30 + 5 = 185
  const cut = normaliseCuisine(long);
  assert.equal(cut.split(';').length, 3); // 3 x 30 + 2 = 92; a fourth value would give 123
  assert.ok(cut.length <= 120);
});

test('tags: the kept subset with sorted keys, or null', () => {
  assert.deepEqual(normaliseTags({ takeaway: 'yes', 'brand:wikidata': 'Q37158', name: 'x', amenity: 'cafe', food: ' ' }),
    { 'brand:wikidata': 'Q37158', takeaway: 'yes' });
  assert.deepEqual(Object.keys(normaliseTags({
    rooms: '120', email: 'a@b.c', operator: 'Op', beds: '10', capacity: '80', 'building:levels': '3', delivery: 'no', drive_through: 'yes',
    outdoor_seating: 'yes', food: 'yes', takeaway: 'only', 'brand:wikidata': 'Q1',
  })), ['beds', 'brand:wikidata', 'building:levels', 'capacity', 'delivery', 'drive_through', 'email', 'food', 'operator', 'outdoor_seating', 'rooms', 'takeaway']);
  assert.equal(normaliseTags({ name: 'x' }), null);
});

test('all attributes of the example element of 03_DATA.md 8.2', () => {
  const a = normaliseAttributes({
    amenity: 'cafe', brand: 'Starbucks', 'brand:wikidata': 'Q37158', cuisine: 'coffee_shop', name: 'Starbucks', opening_hours: '04:30-21:00',
    phone: '+1 301-742-8261', takeaway: 'yes', 'addr:housenumber': '44844', 'addr:street': 'Aviation Drive', 'addr:city': 'Sterling',
    'addr:state': 'VA', 'addr:postcode': '20166',
    website: 'https://www.starbucks.com/store-locator/store/12403/iad-terminal-d-gate-d-15-44844-aviation-dr-sterling-va-20166-us',
  });
  assert.deepEqual(a, {
    name: 'Starbucks',
    brand: 'Starbucks',
    phone: '+13017428261',
    website: 'https://www.starbucks.com/store-locator/store/12403/iad-terminal-d-gate-d-15-44844-aviation-dr-sterling-va-20166-us',
    addr_line: '44844 Aviation Drive',
    city: 'Sterling',
    state_code: 'VA',
    postcode: '20166',
    cuisine: 'coffee_shop',
    opening_hours_raw: '04:30-21:00',
    hours_mask: 'f0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1f',
    tags: { 'brand:wikidata': 'Q37158', takeaway: 'yes' },
    phoneRaw: true,
    websiteRaw: true,
  });
});

test('contact fallbacks, raw flags and opening hours that do not parse', () => {
  const a = normaliseAttributes({ 'contact:phone': '+-804-867-5421', 'contact:website': 'example.com', opening_hours: ' sunrise-sunset ' });
  assert.deepEqual([a.phone, a.phoneRaw, a.website, a.websiteRaw], [null, true, 'https://example.com', true]);
  assert.deepEqual([a.opening_hours_raw, a.hours_mask], ['sunrise-sunset', null]);
  const b = normaliseAttributes({ phone: ' ', 'contact:phone': '703-555-0123' });
  assert.deepEqual([b.phone, b.phoneRaw, b.websiteRaw, b.opening_hours_raw, b.hours_mask], ['+17035550123', true, false, null, null]);
  const long = `Mo 10:00-12:00${'; Tu 10:00-12:00'.repeat(20)}`;
  const c = normaliseAttributes({ opening_hours: long });
  assert.equal(Array.from(c.opening_hours_raw).length, 255);
  assert.equal(c.hours_mask, null); // a cut value is stored but never parsed
});

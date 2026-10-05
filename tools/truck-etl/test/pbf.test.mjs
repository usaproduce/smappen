import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { after, test } from 'node:test';
import {
  PbfError, decodePacked, decodePackedDelta, decodeVarint, forEachField, readPbf, readPbfHeader, zigzag,
} from '../src/pbf.mjs';
import { TRIGGER_KEYS, classify } from '../src/taxonomy.mjs';
import { writePbf } from '../test-support/pbf-writer.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const MINI_PBF = path.join(HERE, 'fixtures', 'mini', 'raw', 'geofabrik', 'virginia-261003.osm.pbf');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'truck-etl-pbf-'));
after(() => fs.rmSync(tmp, { recursive: true, force: true }));

let fileCounter = 0;
function pbfFile(content, options) {
  const file = path.join(tmp, `t${fileCounter++}.osm.pbf`);
  fs.writeFileSync(file, writePbf(content, options));
  return file;
}

const keepAll = { triggerKeys: new Set(['amenity', 'leisure', 'landuse', 'type', 'name']), select: (type, tags) => ({ type, tags }) };

test('varint: one byte, multi-byte and values beyond 32 bits', () => {
  assert.deepEqual(decodeVarint(Buffer.from([0x00]), 0), { value: 0, next: 1 });
  assert.deepEqual(decodeVarint(Buffer.from([0x7f]), 0), { value: 127, next: 1 });
  assert.deepEqual(decodeVarint(Buffer.from([0xac, 0x02]), 0), { value: 300, next: 2 });
  assert.deepEqual(decodeVarint(Buffer.from([0xff, 0xff, 0xff, 0xff, 0x0f]), 0), { value: 4294967295, next: 5 });
  // 2^53 - 1 = 9007199254740991, the largest exact integer
  assert.deepEqual(decodeVarint(Buffer.from([0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0x0f]), 0), { value: 9007199254740991, next: 8 });
  assert.deepEqual(decodeVarint(Buffer.from([0x05, 0xac, 0x02]), 1), { value: 300, next: 3 });
  assert.throws(() => decodeVarint(Buffer.from([0x80]), 0), PbfError);
});

test('zigzag: even gives n / 2, odd gives -(n + 1) / 2', () => {
  assert.deepEqual([0, 1, 2, 3, 4, 4294967294, 4294967295].map(zigzag), [0, -1, 1, -2, 2, 2147483647, -2147483648]);
  assert.equal(zigzag(26000000000), 13000000000);
  assert.equal(zigzag(26000000001), -13000000001);
});

test('packed arrays: plain and delta coded', () => {
  const buf = Buffer.from([0x01, 0xac, 0x02, 0x00, 0x7f]);
  assert.deepEqual(decodePacked(buf, 0, buf.length), [1, 300, 0, 127]);
  // zigzag deltas +5, -2, +300 -> 5, 3, 303
  const delta = Buffer.from([0x0a, 0x03, 0xd8, 0x04]);
  assert.deepEqual(decodePackedDelta(delta, 0, delta.length), [5, 3, 303]);
  assert.deepEqual(decodePacked(buf, 2, 2), []);
});

test('forEachField: wire types 0 and 2 are passed, 1 and 5 skipped, others refused', () => {
  // field 1 varint 150; field 2 bytes "hi"; field 3 fixed64; field 4 fixed32; field 5 varint 1
  const msg = Buffer.from([0x08, 0x96, 0x01, 0x12, 0x02, 0x68, 0x69, 0x19, 1, 2, 3, 4, 5, 6, 7, 8, 0x25, 1, 2, 3, 4, 0x28, 0x01]);
  const seen = [];
  forEachField(msg, 0, msg.length, (field, wire, a, b) => {
    seen.push(wire === 0 ? [field, wire, a] : [field, wire, msg.toString('utf8', a, b)]);
  });
  assert.deepEqual(seen, [[1, 0, 150], [2, 2, 'hi'], [5, 0, 1]]);
  assert.throws(() => forEachField(Buffer.from([0x0b]), 0, 1, () => {}), /wire type 3/); // start group
  assert.throws(() => forEachField(Buffer.from([0x12, 0x05, 0x01]), 0, 3, () => {}), /overruns/);
});

test('reader: nodes, ways and a multipolygon relation with bounding-box centres', () => {
  const file = pbfFile({
    nodes: [
      { id: 1, latE7: 389000000, lngE7: -770000000 },
      { id: 2, latE7: 389000301, lngE7: -770000000 },
      { id: 3, latE7: 389000301, lngE7: -770000400 },
      { id: 4, latE7: 389000000, lngE7: -770000400 },
      { id: 5, latE7: 389100000, lngE7: -770100000, tags: { amenity: 'cafe', name: 'Node Cafe' } },
      { id: 6, latE7: 389200000, lngE7: -770200000, tags: { highway: 'crossing' } },
      { id: 7, latE7: 389300000, lngE7: -770300000 },
      { id: 8, latE7: 389300010, lngE7: -770300021 },
      { id: 13000000000, latE7: 389400000, lngE7: -770400000, tags: { amenity: 'bar' } },
    ],
    ways: [
      { id: 10, refs: [1, 2, 3, 4, 1], tags: { leisure: 'park', name: 'Square Park' } },
      { id: 11, refs: [7, 8] },
      { id: 12, refs: [1, 2], tags: { highway: 'residential' } },
    ],
    relations: [
      { id: 20, members: [{ type: 1, ref: 11, role: 'outer' }, { type: 0, ref: 6 }, { type: 2, ref: 99 }], tags: { type: 'multipolygon', landuse: 'retail', name: 'Two Ways' } },
    ],
  }, { replicationTimestamp: 1791058850 });

  const out = readPbf(file, keepAll);
  assert.deepEqual(out.header, {
    requiredFeatures: ['OsmSchema-V0.6', 'DenseNodes'], optionalFeatures: ['Sort.Type_then_ID'], replicationTimestamp: 1791058850,
  });
  assert.deepEqual(readPbfHeader(file), out.header);
  assert.equal(out.stats.nodes, 9);
  assert.equal(out.stats.ways, 3);
  assert.equal(out.stats.relations, 1);
  assert.equal(out.stats.triggered, 4); // relation 20, way 10, nodes 5 and 13000000000
  const byKey = Object.fromEntries(out.elements.map((e) => [`${e.osmType}/${e.id}`, e]));
  assert.deepEqual(Object.keys(byKey).sort(), ['node/13000000000', 'node/5', 'relation/20', 'way/10']);

  // way 10: bbox of its four corners; centre = floor((min + max + 1) / 2) per axis
  assert.deepEqual(byKey['way/10'].box, { minLatE7: 389000000, minLngE7: -770000400, maxLatE7: 389000301, maxLngE7: -770000000 });
  assert.equal(byKey['way/10'].latE7, 389000151); // floor(778000302 / 2)
  assert.equal(byKey['way/10'].lngE7, -770000200); // floor(-1540000399 / 2)
  assert.deepEqual(byKey['way/10'].tags, { leisure: 'park', name: 'Square Park' });

  // relation 20: member way 11 (nodes 7, 8) and member node 6; the relation member is ignored
  assert.deepEqual(byKey['relation/20'].box, { minLatE7: 389200000, minLngE7: -770300021, maxLatE7: 389300010, maxLngE7: -770200000 });
  assert.equal(byKey['relation/20'].latE7, 389250005);
  assert.equal(byKey['relation/20'].lngE7, -770250010); // floor((-770300021 - 770200000 + 1) / 2)

  assert.equal(byKey['node/5'].latE7, 389100000);
  assert.equal(byKey['node/5'].box, null);
  assert.equal(byKey['node/13000000000'].lngE7, -770400000);
});

test('reader: fetch box, elements without geometry and the select callback', () => {
  const content = {
    nodes: [
      { id: 1, latE7: 389000000, lngE7: -770000000, tags: { amenity: 'cafe' } },
      { id: 2, latE7: 399000000, lngE7: -770000000, tags: { amenity: 'cafe' } },
      { id: 3, latE7: 389000000, lngE7: -770000000 },
    ],
    ways: [
      { id: 10, refs: [3], tags: { leisure: 'park', name: 'Inside' } },
      { id: 11, refs: [500, 501], tags: { leisure: 'park', name: 'Nodes missing from the extract' } },
      { id: 12, refs: [2], tags: { leisure: 'park', name: 'Outside the box' } },
    ],
  };
  const calls = [];
  const out = readPbf(pbfFile(content), {
    triggerKeys: new Set(['amenity', 'leisure']),
    select: (type, tags, id) => { calls.push(`${type}/${id}`); return id !== 1; },
    box: { south: 38, west: -78, north: 39, east: -76 },
  });
  assert.deepEqual(calls.sort(), ['node/1', 'node/2', 'way/10', 'way/11', 'way/12']);
  assert.deepEqual(out.elements.map((e) => `${e.osmType}/${e.id}`), ['way/10']);
  assert.equal(out.stats.noGeometry, 1);
  assert.equal(out.stats.outsideBox, 2); // way 12 and node 2
  assert.equal(out.stats.triggered, 5);
});

test('reader: granularity and offsets are applied', () => {
  // granularity 1000 nanodegrees, offsets in nanodegrees: e7 = round((offset + granularity * raw) / 100)
  const file = pbfFile(
    { nodes: [{ id: 1, latE7: 3890000, lngE7: 7700000, tags: { amenity: 'cafe' } }] },
    { block: { granularity: 1000, latOffset: 500, lngOffset: 250 } },
  );
  const [el] = readPbf(file, keepAll).elements;
  assert.equal(el.latE7, 38900005);
  assert.equal(el.lngE7, 77000003); // round(77000002.5)
});

test('reader: uncompressed blobs are read, other compression fields are refused', () => {
  const content = { nodes: [{ id: 1, latE7: 1, lngE7: 2, tags: { amenity: 'cafe' } }] };
  assert.equal(readPbf(pbfFile(content, { compression: 'raw' }), keepAll).elements.length, 1);
  assert.throws(() => readPbf(pbfFile(content, { compression: 'lzma' }), keepAll), /compression field 4/);
});

test('reader: unknown required features and unsorted files are refused', () => {
  const content = {
    nodes: [{ id: 1, latE7: 1, lngE7: 2 }],
    ways: [{ id: 10, refs: [1], tags: { leisure: 'park' } }],
    relations: [{ id: 20, members: [{ type: 1, ref: 10 }], tags: { type: 'multipolygon', leisure: 'park' } }],
  };
  assert.throws(() => readPbf(pbfFile(content, { requiredFeatures: ['OsmSchema-V0.6', 'HistoricalInformation'] }), keepAll),
    /required feature "HistoricalInformation"/);
  assert.throws(() => readPbf(pbfFile(content, { order: ['ways', 'nodes', 'relations'] }), keepAll), /not sorted nodes, ways, relations/);
  assert.throws(() => readPbf(pbfFile(content, { order: ['nodes', 'relations', 'ways'] }), keepAll), /not sorted nodes, ways, relations/);
  assert.equal(readPbf(pbfFile(content), keepAll).elements.length, 2);
});

test('reader: a file that is not a PBF is refused', () => {
  const file = path.join(tmp, 'junk.osm.pbf');
  fs.writeFileSync(file, Buffer.from('this is not a protocol buffer file at all, just text'));
  assert.throws(() => readPbfHeader(file), PbfError);
  assert.throws(() => readPbf(file, keepAll), PbfError);
});

test('reader: several blocks per kind, shared nodes and ways used by two relations', () => {
  const nodes = [];
  for (let i = 1; i <= 50; i++) nodes.push({ id: i, latE7: 389000000 + i * 10, lngE7: -770000000 - i * 10 });
  const ways = [
    { id: 100, refs: [1, 2, 3], tags: { leisure: 'park', name: 'A' } },
    { id: 101, refs: [3, 4, 50], tags: { leisure: 'park', name: 'B' } },
    { id: 102, refs: [10, 11] },
  ];
  const relations = [
    { id: 200, members: [{ type: 1, ref: 102 }, { type: 1, ref: 100 }], tags: { type: 'multipolygon', leisure: 'park', name: 'C' } },
    { id: 201, members: [{ type: 1, ref: 102 }], tags: { type: 'multipolygon', leisure: 'park', name: 'D' } },
  ];
  const out = readPbf(pbfFile({ nodes, ways, relations }, { blockSize: 7 }), keepAll);
  const byKey = Object.fromEntries(out.elements.map((e) => [`${e.osmType}/${e.id}`, e]));
  assert.equal(byKey['way/100'].box.maxLatE7, 389000030);
  assert.equal(byKey['way/101'].box.minLatE7, 389000030); // node 3 is shared with way 100
  assert.equal(byKey['way/101'].box.maxLatE7, 389000500);
  assert.deepEqual(byKey['relation/200'].box, { minLatE7: 389000010, minLngE7: -770000110, maxLatE7: 389000110, maxLngE7: -770000010 });
  assert.deepEqual(byKey['relation/201'].box, { minLatE7: 389000100, minLngE7: -770000110, maxLatE7: 389000110, maxLngE7: -770000100 });
});

test('reader: captureGeometry returns node lists, members and coordinates', () => {
  const file = pbfFile({
    nodes: [{ id: 1, latE7: 10, lngE7: 20 }, { id: 2, latE7: 30, lngE7: 40 }, { id: 3, latE7: 50, lngE7: 60 }],
    ways: [{ id: 10, refs: [1, 2], tags: { leisure: 'park' } }, { id: 11, refs: [2, 3] }],
    relations: [{ id: 20, members: [{ type: 1, ref: 11 }], tags: { type: 'multipolygon', leisure: 'park' } }],
  });
  const out = readPbf(file, { ...keepAll, captureGeometry: true });
  assert.deepEqual(out.geometry.wayRefs.get(10), [1, 2]);
  assert.deepEqual(out.geometry.wayRefs.get(11), [2, 3]);
  assert.deepEqual(out.geometry.relationMembers.get(20), [{ type: 1, ref: 11 }]);
  assert.deepEqual([...out.geometry.nodes.entries()].sort((a, b) => a[0] - b[0]), [[1, [10, 20]], [2, [30, 40]], [3, [50, 60]]]);
  assert.equal(readPbf(file, keepAll).geometry, undefined);
});

test('reader: the fixture cut from the Virginia extract of 2026-10-03', () => {
  const header = readPbfHeader(MINI_PBF);
  assert.deepEqual(header.requiredFeatures, ['OsmSchema-V0.6', 'DenseNodes']);
  assert.equal(header.replicationTimestamp, 1791058850); // 2026-10-03T20:20:50Z

  const dropped = {};
  const out = readPbf(MINI_PBF, {
    triggerKeys: TRIGGER_KEYS,
    select: (type, tags) => {
      const r = classify(type, tags);
      if (r.drop) { dropped[r.drop] = (dropped[r.drop] || 0) + 1; return null; }
      return r;
    },
  });
  assert.deepEqual(out.stats, { blobs: 6, nodes: 8234, ways: 851, relations: 25, triggered: 1028, noGeometry: 0, outsideBox: 0 });
  assert.deepEqual(dropped, { dropped_relation_type: 12, dropped_unnamed: 101, dropped_no_rule: 236, dropped_closed: 7 });
  assert.equal(out.elements.length, 672);
  assert.equal(out.elements.length + 12 + 101 + 236 + 7, out.stats.triggered);

  const byKey = Object.fromEntries(out.elements.map((e) => [`${e.osmType}/${e.id}`, e]));
  // values read from the full extract when the fixture was cut
  const corners = byKey['relation/3465838'];
  assert.equal(corners.tags.name, 'Seven Corners Shopping Center');
  assert.deepEqual(corners.box, { minLatE7: 388662623, minLngE7: -771540941, maxLatE7: 388709468, maxLngE7: -771485487 });
  assert.deepEqual([corners.latE7, corners.lngE7], [388686046, -771513214]);
  assert.deepEqual([byKey['way/28317055'].latE7, byKey['way/28317055'].lngE7, byKey['way/28317055'].tags.name], [388814837, -771020063, 'Maury Park']);
  const cafe = byKey['node/13184466279'];
  assert.deepEqual([cafe.latE7, cafe.lngE7, cafe.payload.type], [388841915, -771729519, 'restaurant']);
  assert.deepEqual(cafe.tags, { amenity: 'restaurant', cuisine: 'crepe', name: 'Little Falls Cafe' });
  assert.equal(byKey['node/13341065301'].tags.name, 'Chả Ốc Gia Huy'); // UTF-8 survives
});

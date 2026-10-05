// Test helper: writes a small .osm.pbf file (OsmSchema-V0.6, DenseNodes, zlib blobs).
// It is the inverse of src/pbf.mjs for the subset of the format the reader supports, and exists so that tests
// can build extracts with known content and so that test/tools/make-mini-fixture.mjs can cut one from real data.

import zlib from 'node:zlib';

function varint(n, out) {
  if (!Number.isInteger(n) || n < 0) throw new Error(`pbf-writer: cannot encode ${n} as an unsigned varint`);
  let v = n;
  while (v >= 128) {
    out.push((v % 128) | 0x80);
    v = Math.floor(v / 128);
  }
  out.push(v);
}

function zigzagEncode(n) {
  return n >= 0 ? 2 * n : -2 * n - 1;
}

function tag(field, wire, out) {
  varint(field * 8 + wire, out);
}

function bytesField(field, bytes, out) {
  tag(field, 2, out);
  varint(bytes.length, out);
  for (let i = 0; i < bytes.length; i++) out.push(bytes[i]);
}

function stringField(field, text, out) {
  bytesField(field, Buffer.from(text, 'utf8'), out);
}

function varintField(field, value, out) {
  tag(field, 0, out);
  varint(value, out);
}

function packed(values) {
  const out = [];
  for (const v of values) varint(v, out);
  return out;
}

function packedDelta(values) {
  const out = [];
  let previous = 0;
  for (const v of values) {
    varint(zigzagEncode(v - previous), out);
    previous = v;
  }
  return out;
}

class StringTable {
  constructor() {
    this.list = [''];
    this.index = new Map([['', 0]]);
  }

  id(text) {
    let i = this.index.get(text);
    if (i === undefined) {
      i = this.list.length;
      this.list.push(text);
      this.index.set(text, i);
    }
    return i;
  }

  encode() {
    const out = [];
    for (const s of this.list) stringField(1, s, out);
    return out;
  }
}

function tagIndexes(tags, table) {
  const keys = [];
  const vals = [];
  for (const [k, v] of Object.entries(tags || {})) {
    keys.push(table.id(k));
    vals.push(table.id(String(v)));
  }
  return { keys, vals };
}

function denseGroup(nodes, table) {
  const dense = [];
  bytesField(1, packedDelta(nodes.map((n) => n.id)), dense);
  bytesField(8, packedDelta(nodes.map((n) => n.latE7)), dense);
  bytesField(9, packedDelta(nodes.map((n) => n.lngE7)), dense);
  if (nodes.some((n) => n.tags && Object.keys(n.tags).length > 0)) {
    const kv = [];
    for (const n of nodes) {
      const { keys, vals } = tagIndexes(n.tags, table);
      for (let i = 0; i < keys.length; i++) kv.push(keys[i], vals[i]);
      kv.push(0);
    }
    bytesField(10, packed(kv), dense);
  }
  const group = [];
  bytesField(2, dense, group);
  return group;
}

function wayGroup(ways, table) {
  const group = [];
  for (const way of ways) {
    const msg = [];
    varintField(1, way.id, msg);
    const { keys, vals } = tagIndexes(way.tags, table);
    if (keys.length) { bytesField(2, packed(keys), msg); bytesField(3, packed(vals), msg); }
    bytesField(8, packedDelta(way.refs), msg);
    bytesField(3, msg, group);
  }
  return group;
}

function relationGroup(relations, table) {
  const group = [];
  for (const relation of relations) {
    const msg = [];
    varintField(1, relation.id, msg);
    const { keys, vals } = tagIndexes(relation.tags, table);
    if (keys.length) { bytesField(2, packed(keys), msg); bytesField(3, packed(vals), msg); }
    bytesField(8, packed(relation.members.map((m) => table.id(m.role || ''))), msg);
    bytesField(9, packedDelta(relation.members.map((m) => m.ref)), msg);
    bytesField(10, packed(relation.members.map((m) => m.type)), msg);
    bytesField(4, msg, group);
  }
  return group;
}

function primitiveBlock(buildGroup, { granularity, latOffset, lngOffset }) {
  const table = new StringTable();
  const group = buildGroup(table);
  const block = [];
  bytesField(1, table.encode(), block);
  bytesField(2, group, block);
  if (granularity !== undefined) varintField(17, granularity, block);
  if (latOffset !== undefined) varintField(19, latOffset, block);
  if (lngOffset !== undefined) varintField(20, lngOffset, block);
  return Buffer.from(block);
}

function blob(type, payload, compression) {
  const body = [];
  if (compression === 'raw') {
    bytesField(1, payload, body);
  } else if (compression === 'lzma') {
    varintField(2, payload.length, body);
    bytesField(4, payload, body); // not really lzma: the reader must refuse the field before reading it
  } else {
    varintField(2, payload.length, body);
    bytesField(3, zlib.deflateSync(payload, { level: 9 }), body);
  }
  const header = [];
  stringField(1, type, header);
  varintField(3, body.length, header);
  const length = Buffer.alloc(4);
  length.writeUInt32BE(header.length, 0);
  return Buffer.concat([length, Buffer.from(header), Buffer.from(body)]);
}

function chunks(list, size) {
  const out = [];
  for (let i = 0; i < list.length; i += size) out.push(list.slice(i, i + size));
  return out;
}

/**
 * @param {object} content
 * @param {{id: number, latE7: number, lngE7: number, tags?: Record<string,string>}[]} [content.nodes]
 * @param {{id: number, refs: number[], tags?: Record<string,string>}[]} [content.ways]
 * @param {{id: number, members: {type: 0|1|2, ref: number, role?: string}[], tags?: Record<string,string>}[]} [content.relations]
 *        member type 0 = node, 1 = way, 2 = relation
 * @param {object} [options]
 * @param {string[]} [options.requiredFeatures] default OsmSchema-V0.6 and DenseNodes
 * @param {string[]} [options.optionalFeatures] default Sort.Type_then_ID
 * @param {number} [options.replicationTimestamp] seconds since the Unix epoch
 * @param {('nodes'|'ways'|'relations')[]} [options.order] block order; default nodes, ways, relations
 * @param {number} [options.blockSize] elements per block, default 8000
 * @param {'zlib'|'raw'|'lzma'} [options.compression] default zlib
 * @param {{granularity?: number, latOffset?: number, lngOffset?: number}} [options.block] block-level overrides
 *        (node coordinates are then written as given and interpreted with them)
 * @returns {Buffer}
 */
export function writePbf({ nodes = [], ways = [], relations = [] }, options = {}) {
  const {
    requiredFeatures = ['OsmSchema-V0.6', 'DenseNodes'],
    optionalFeatures = ['Sort.Type_then_ID'],
    replicationTimestamp,
    order = ['nodes', 'ways', 'relations'],
    blockSize = 8000,
    compression = 'zlib',
    block = {},
  } = options;

  const header = [];
  for (const feature of requiredFeatures) stringField(4, feature, header);
  for (const feature of optionalFeatures) stringField(5, feature, header);
  stringField(16, 'truck-etl test writer', header);
  if (replicationTimestamp !== undefined) varintField(32, replicationTimestamp, header);
  const parts = [blob('OSMHeader', Buffer.from(header), compression === 'lzma' ? 'zlib' : compression)];

  const byId = (a, b) => a.id - b.id;
  const sorted = {
    nodes: nodes.slice().sort(byId),
    ways: ways.slice().sort(byId),
    relations: relations.slice().sort(byId),
  };
  const builders = {
    nodes: (list) => (table) => denseGroup(list, table),
    ways: (list) => (table) => wayGroup(list, table),
    relations: (list) => (table) => relationGroup(list, table),
  };
  for (const kind of order) {
    for (const part of chunks(sorted[kind], blockSize)) {
      parts.push(blob('OSMData', primitiveBlock(builders[kind](part), block), compression));
    }
  }
  return Buffer.concat(parts);
}

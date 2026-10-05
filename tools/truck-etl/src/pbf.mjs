// Zero-dependency .osm.pbf reader (03_DATA.md 2.3): Node `fs` and `zlib` only.
//
// The file is a sequence of [4-byte big-endian length][BlobHeader][Blob]. Data blobs are visited from last to
// first, one in memory at a time. A file sorted nodes, ways, relations is therefore met as relations, ways,
// nodes, so every reference is known before the element it points at is read, and one pass is enough.
// For every selected way and multipolygon relation the reader returns the bounding box of its nodes.

import fs from 'node:fs';
import zlib from 'node:zlib';

export class PbfError extends Error {}

const SUPPORTED_FEATURES = new Set(['OsmSchema-V0.6', 'DenseNodes']);
const TWO_POW_31 = 2147483648;
// Wanted node ids are also marked in a bit set indexed by the low 27 bits of the id, so that the tens of
// millions of nodes nobody asked for are rejected without a Map lookup.
const NODE_FILTER_BITS = 134217728; // 2^27 bits, 16 MiB
const NODE_FILTER_INV = 1 / NODE_FILTER_BITS;

// ---- protocol buffer primitives ------------------------------------------------------------------------

/**
 * Unsigned varint at `offset`: 7 bits per byte accumulated in a double, exact up to 2^53.
 * @returns {{value: number, next: number}}
 */
export function decodeVarint(buf, offset) {
  let p = offset;
  let x = 0;
  let m = 1;
  let c;
  do {
    if (p >= buf.length) throw new PbfError('pbf: varint runs past the end of the buffer');
    c = buf[p++];
    x += (c & 0x7f) * m;
    m *= 128;
  } while (c & 0x80);
  return { value: x, next: p };
}

/** Zigzag decode: an even n gives n / 2, an odd n gives -(n + 1) / 2. */
export function zigzag(n) {
  return n % 2 === 0 ? n / 2 : -(n + 1) / 2;
}

/** Packed unsigned varints in buf[start, end). */
export function decodePacked(buf, start, end) {
  const out = [];
  P = start;
  while (P < end) out.push(rv(buf));
  if (P !== end) throw new PbfError('pbf: packed field overruns its length');
  return out;
}

/** Packed zigzag varints with a running sum (delta coding) in buf[start, end). */
export function decodePackedDelta(buf, start, end) {
  const out = [];
  let acc = 0;
  P = start;
  while (P < end) {
    acc += zigzag(rv(buf));
    out.push(acc);
  }
  if (P !== end) throw new PbfError('pbf: packed field overruns its length');
  return out;
}

/**
 * Iterates the fields of one message in buf[start, end).
 * cb(fieldNumber, wireType, a, b): wire type 0 passes the value in `a` and the offset of its first byte in `b`;
 * wire type 2 passes the payload as [a, b). Wire types 1 and 5 are skipped. Anything else is an error.
 * The callback may itself decode (the cursor is restored after it returns).
 */
export function forEachField(buf, start, end, cb) {
  let p = start;
  while (p < end) {
    P = p;
    const key = rv(buf);
    const field = Math.floor(key / 8);
    const wire = key % 8;
    if (wire === 0) {
      const at = P;
      const value = rv(buf);
      p = P;
      cb(field, 0, value, at);
    } else if (wire === 2) {
      const len = rv(buf);
      const s = P;
      p = s + len;
      if (p > end) throw new PbfError('pbf: length-delimited field overruns its message');
      cb(field, 2, s, p);
    } else if (wire === 1) {
      p = P + 8;
    } else if (wire === 5) {
      p = P + 4;
    } else {
      throw new PbfError(`pbf: unsupported wire type ${wire}`);
    }
  }
  if (p !== end) throw new PbfError('pbf: message overruns its length');
}

// Cursor shared by the hot decoding loops below. The reader is synchronous and single-threaded.
let P = 0;

/** Reads the unsigned varint at the cursor and advances it. Bytes past the buffer read as zero and end it. */
function rv(d) {
  let c = d[P++];
  if (c < 128) return c;
  let x = c & 0x7f;
  c = d[P++];
  x |= (c & 0x7f) << 7;
  if (c < 128) return x;
  c = d[P++];
  x |= (c & 0x7f) << 14;
  if (c < 128) return x;
  c = d[P++];
  x |= (c & 0x7f) << 21;
  if (c < 128) return x;
  let m = 268435456;
  do {
    c = d[P++];
    x += (c & 0x7f) * m;
    m *= 128;
  } while (c >= 128);
  if (c === undefined) throw new PbfError('pbf: varint runs past the end of the buffer');
  return x;
}

/** Signed 64-bit varint (two's complement, up to 10 bytes) at `offset`, as a Number. Used for the block offsets. */
function decodeInt64(buf, offset) {
  let p = offset;
  let x = 0n;
  let shift = 0n;
  let c;
  do {
    c = buf[p++];
    x |= BigInt(c & 0x7f) << shift;
    shift += 7n;
  } while (c & 0x80);
  return Number(BigInt.asIntN(64, x));
}

/**
 * Decodes packed zigzag delta varints from d[start, end) into `out` and returns the count.
 * Values are accumulated as doubles (node ids exceed 2^32).
 */
function unpackDelta(d, start, end, out) {
  let n = 0;
  let acc = 0;
  P = start;
  while (P < end) {
    const x = rv(d);
    acc += x < TWO_POW_31 ? ((x >>> 1) ^ -(x & 1)) : zigzag(x);
    out[n++] = acc;
  }
  if (P !== end) throw new PbfError('pbf: packed field overruns its length');
  return n;
}

// ---- file level ----------------------------------------------------------------------------------------

/**
 * Index pass: reads only lengths and BlobHeaders.
 * @returns {{type: string, offset: number, size: number}[]} one entry per blob, in file order
 */
export function indexBlobs(fd, fileSize) {
  const blobs = [];
  const head = Buffer.alloc(4 + 256);
  let pos = 0;
  while (pos < fileSize) {
    const got = fs.readSync(fd, head, 0, Math.min(head.length, fileSize - pos), pos);
    if (got < 4) throw new PbfError('pbf: truncated file (blob header length)');
    const headerLen = head.readUInt32BE(0);
    if (headerLen === 0 || headerLen > 65536) throw new PbfError(`pbf: implausible BlobHeader length ${headerLen} at offset ${pos}`);
    let hb = head;
    let hs = 4;
    if (4 + headerLen > got) {
      hb = Buffer.alloc(headerLen);
      hs = 0;
      if (fs.readSync(fd, hb, 0, headerLen, pos + 4) !== headerLen) throw new PbfError('pbf: truncated file (blob header)');
    }
    let type = '';
    let size = -1;
    forEachField(hb, hs, hs + headerLen, (field, wire, a, b) => {
      if (field === 1 && wire === 2) type = hb.toString('utf8', a, b);
      else if (field === 3 && wire === 0) size = a;
    });
    if (size < 0) throw new PbfError(`pbf: BlobHeader without datasize at offset ${pos}`);
    const offset = pos + 4 + headerLen;
    if (offset + size > fileSize) throw new PbfError('pbf: truncated file (blob runs past the end)');
    blobs.push({ type, offset, size });
    pos = offset + size;
  }
  return blobs;
}

/** Reads and inflates one blob. */
function readBlob(fd, blob, scratch) {
  let buf = scratch.buf;
  if (buf.length < blob.size) buf = scratch.buf = Buffer.alloc(Math.max(blob.size, buf.length * 2));
  if (fs.readSync(fd, buf, 0, blob.size, blob.offset) !== blob.size) throw new PbfError('pbf: short read');
  let raw = null;
  let zs = -1;
  let ze = -1;
  forEachField(buf, 0, blob.size, (field, wire, a, b) => {
    if (field === 1 && wire === 2) raw = Buffer.from(buf.subarray(a, b));
    else if (field === 3 && wire === 2) { zs = a; ze = b; }
    else if (field >= 4 && field <= 7) throw new PbfError(`pbf: blob compression field ${field} (lzma, bzip2, lz4 or zstd) is not supported`);
  });
  if (raw) return raw;
  if (zs < 0) throw new PbfError('pbf: blob without data');
  return zlib.inflateSync(buf.subarray(zs, ze));
}

function parseHeaderBlock(d) {
  const requiredFeatures = [];
  const optionalFeatures = [];
  let replicationTimestamp = null;
  forEachField(d, 0, d.length, (field, wire, a, b) => {
    if (field === 4 && wire === 2) requiredFeatures.push(d.toString('utf8', a, b));
    else if (field === 5 && wire === 2) optionalFeatures.push(d.toString('utf8', a, b));
    else if (field === 32 && wire === 0) replicationTimestamp = a;
  });
  return { requiredFeatures, optionalFeatures, replicationTimestamp };
}

/**
 * Reads the OSMHeader blob of a file. Throws when the first blob is not an OSMHeader.
 * @returns {{requiredFeatures: string[], optionalFeatures: string[], replicationTimestamp: number|null}}
 *          the timestamp is in seconds since the Unix epoch
 */
export function readPbfHeader(filePath) {
  const fd = fs.openSync(filePath, 'r');
  try {
    const size = fs.fstatSync(fd).size;
    if (size < 8) throw new PbfError('pbf: file is too short');
    const head = Buffer.alloc(4);
    fs.readSync(fd, head, 0, 4, 0);
    const headerLen = head.readUInt32BE(0);
    if (headerLen === 0 || headerLen > 65536 || 4 + headerLen > size) {
      throw new PbfError('pbf: first blob header is implausible; not a .osm.pbf file');
    }
    const hb = Buffer.alloc(headerLen);
    if (fs.readSync(fd, hb, 0, headerLen, 4) !== headerLen) throw new PbfError('pbf: truncated file');
    let type = '';
    let blobSize = -1;
    forEachField(hb, 0, headerLen, (field, wire, a, b) => {
      if (field === 1 && wire === 2) type = hb.toString('utf8', a, b);
      else if (field === 3 && wire === 0) blobSize = a;
    });
    if (type !== 'OSMHeader') throw new PbfError(`pbf: first blob is ${JSON.stringify(type)}, expected "OSMHeader"`);
    if (blobSize < 0 || 4 + headerLen + blobSize > size) throw new PbfError('pbf: truncated header blob');
    return parseHeaderBlock(readBlob(fd, { type, offset: 4 + headerLen, size: blobSize }, { buf: Buffer.alloc(Math.max(1, blobSize)) }));
  } finally {
    fs.closeSync(fd);
  }
}

// ---- element extraction --------------------------------------------------------------------------------

/**
 * Reads the selected elements of one .osm.pbf file.
 *
 * @param {string} filePath
 * @param {object} options
 * @param {Set<string>} options.triggerKeys an element is offered to `select` only when it has one of these keys
 * @param {(osmType: 'node'|'way'|'relation', tags: Record<string,string>, id: number) => any} options.select
 *        returns a truthy payload to keep the element, a falsy value to drop it
 * @param {{south: number, west: number, north: number, east: number}} [options.box] when given, a selected
 *        element whose point lies outside the box is discarded and counted in `stats.outsideBox`
 * @param {boolean} [options.captureGeometry] also return the raw geometry of the selected elements (the node
 *        lists of ways, the members of relations, the coordinates of every node they use). Used to cut test
 *        fixtures out of an extract; the pipeline does not need it.
 * @returns {{header: object, elements: object[], stats: object, geometry?: object}} elements carry
 *        {osmType, id, tags, payload, latE7, lngE7, box}; `box` is null for nodes and
 *        {minLatE7, minLngE7, maxLatE7, maxLngE7} for ways and relations. Order: relations and ways as met
 *        on the reverse pass, then nodes as met.
 */
export function readPbf(filePath, { triggerKeys, select, box = null, captureGeometry = false }) {
  const fd = fs.openSync(filePath, 'r');
  try {
    const fileSize = fs.fstatSync(fd).size;
    const blobs = indexBlobs(fd, fileSize);
    if (blobs.length === 0 || blobs[0].type !== 'OSMHeader') throw new PbfError('pbf: first blob is not an OSMHeader');
    const scratch = { buf: Buffer.alloc(1 << 20) };
    const header = parseHeaderBlock(readBlob(fd, blobs[0], scratch));
    for (const feature of header.requiredFeatures) {
      if (!SUPPORTED_FEATURES.has(feature)) throw new PbfError(`pbf: required feature ${JSON.stringify(feature)} is not supported`);
    }
    const state = new ExtractState(triggerKeys, select, box, captureGeometry);
    for (let i = blobs.length - 1; i >= 1; i--) {
      if (blobs[i].type !== 'OSMData') continue;
      state.block(readBlob(fd, blobs[i], scratch));
    }
    const elements = state.finish();
    return {
      header,
      elements,
      ...(captureGeometry ? { geometry: state.geometry } : {}),
      stats: {
        blobs: blobs.length,
        nodes: state.nodes,
        ways: state.ways,
        relations: state.relations,
        triggered: state.triggered,
        noGeometry: state.noGeometry,
        outsideBox: state.outsideBox,
      },
    };
  } finally {
    fs.closeSync(fd);
  }
}

class ExtractState {
  constructor(triggerKeys, select, box, captureGeometry) {
    this.triggerKeys = triggerKeys;
    this.select = select;
    this.box = box;
    /** raw geometry of the selected elements, only when asked for */
    this.geometry = captureGeometry ? { wayRefs: new Map(), relationMembers: new Map(), nodes: new Map() } : null;
    this.nodes = 0;
    this.ways = 0;
    this.relations = 0;
    this.triggered = 0;
    this.noGeometry = 0;
    this.outsideBox = 0;
    this.sawWay = false;
    this.sawNode = false;
    /** kept ways and relations, each with a bounding-box accumulator */
    this.areaElements = [];
    this.nodeElements = [];
    // bounding-box accumulators, e7 integers
    this.accCap = 1024;
    this.accMinLat = new Int32Array(this.accCap);
    this.accMaxLat = new Int32Array(this.accCap);
    this.accMinLng = new Int32Array(this.accCap);
    this.accMaxLng = new Int32Array(this.accCap);
    this.accCount = new Int32Array(this.accCap);
    this.accN = 0;
    /** @type {Map<number, number|number[]>} way id -> accumulator index(es) of the relations that need it */
    this.needWay = new Map();
    /** @type {Map<number, number|number[]>} node id -> accumulator index(es) */
    this.needNode = new Map();
    this.nodeFilter = new Uint8Array(NODE_FILTER_BITS / 8);
    // per-group scratch
    this.ids = new Float64Array(8192);
    this.lats = new Float64Array(8192);
    this.lngs = new Float64Array(8192);
    this.keyScratch = [];
    // per-block string table
    this.d = null;
    this.tableRanges = [];
    this.strings = [];
    this.trigger = new Uint8Array(0);
    this.granularity = 100;
    this.latOffset = 0;
    this.lngOffset = 0;
  }

  newAccumulator() {
    if (this.accN === this.accCap) {
      const grow = (a) => { const b = new Int32Array(this.accCap * 2); b.set(a); return b; };
      this.accMinLat = grow(this.accMinLat);
      this.accMaxLat = grow(this.accMaxLat);
      this.accMinLng = grow(this.accMinLng);
      this.accMaxLng = grow(this.accMaxLng);
      this.accCount = grow(this.accCount);
      this.accCap *= 2;
    }
    const i = this.accN++;
    this.accMinLat[i] = 2147483647;
    this.accMaxLat[i] = -2147483648;
    this.accMinLng[i] = 2147483647;
    this.accMaxLng[i] = -2147483648;
    return i;
  }

  static register(map, key, acc) {
    const cur = map.get(key);
    if (cur === undefined) map.set(key, acc);
    else if (typeof cur === 'number') { if (cur !== acc) map.set(key, [cur, acc]); }
    else if (!cur.includes(acc)) cur.push(acc);
  }

  /** Registers a node whose coordinates the accumulator `acc` needs. */
  wantNode(id, acc) {
    ExtractState.register(this.needNode, id, acc);
    const low = id - Math.floor(id * NODE_FILTER_INV) * NODE_FILTER_BITS;
    this.nodeFilter[low >>> 3] |= 1 << (low & 7);
  }

  touch(acc, latE7, lngE7) {
    if (latE7 < this.accMinLat[acc]) this.accMinLat[acc] = latE7;
    if (latE7 > this.accMaxLat[acc]) this.accMaxLat[acc] = latE7;
    if (lngE7 < this.accMinLng[acc]) this.accMinLng[acc] = lngE7;
    if (lngE7 > this.accMaxLng[acc]) this.accMaxLng[acc] = lngE7;
    this.accCount[acc]++;
  }

  /** Box test on the decimal degrees the element will be written with (e7 / 1e7). */
  insideBox(latE7, lngE7) {
    const b = this.box;
    if (b === null) return true;
    const lat = latE7 / 1e7;
    const lng = lngE7 / 1e7;
    return lat >= b.south && lat <= b.north && lng >= b.west && lng <= b.east;
  }

  str(i) {
    let s = this.strings[i];
    if (s === undefined) {
      if (!(i >= 0 && i < this.strings.length)) throw new PbfError(`pbf: string index ${i} outside the string table`);
      s = this.strings[i] = this.d.toString('utf8', this.tableRanges[2 * i], this.tableRanges[2 * i + 1]);
    }
    return s;
  }

  isTrigger(i) {
    let t = this.trigger[i];
    if (t === 0) t = this.trigger[i] = this.triggerKeys.has(this.str(i)) ? 2 : 1;
    return t === 2;
  }

  e7Lat(raw) {
    return Math.round((this.latOffset + this.granularity * raw) / 100);
  }

  e7Lng(raw) {
    return Math.round((this.lngOffset + this.granularity * raw) / 100);
  }

  /** Processes one inflated PrimitiveBlock. */
  block(d) {
    const tableRanges = [];
    const groups = [];
    this.granularity = 100;
    this.latOffset = 0;
    this.lngOffset = 0;
    forEachField(d, 0, d.length, (field, wire, a, b) => {
      if (field === 1 && wire === 2) {
        forEachField(d, a, b, (f2, w2, a2, b2) => { if (f2 === 1 && w2 === 2) tableRanges.push(a2, b2); });
      } else if (field === 2 && wire === 2) groups.push(a, b);
      else if (field === 17 && wire === 0) this.granularity = a;
      else if (field === 19 && wire === 0) this.latOffset = decodeInt64(d, b);
      else if (field === 20 && wire === 0) this.lngOffset = decodeInt64(d, b);
    });
    this.d = d;
    this.tableRanges = tableRanges;
    const tableSize = tableRanges.length / 2;
    this.strings = new Array(tableSize);
    this.trigger = new Uint8Array(tableSize); // 0 unknown, 1 no, 2 yes

    for (let g = groups.length - 2; g >= 0; g -= 2) {
      const plainNodes = [];
      const dense = [];
      const ways = [];
      const relations = [];
      forEachField(d, groups[g], groups[g + 1], (field, wire, a, b) => {
        if (wire !== 2) return;
        if (field === 1) plainNodes.push(a, b);
        else if (field === 2) dense.push(a, b);
        else if (field === 3) ways.push(a, b);
        else if (field === 4) relations.push(a, b);
      });
      if (relations.length) {
        if (this.sawWay || this.sawNode) {
          throw new PbfError('pbf: file is not sorted nodes, ways, relations (a relation block follows a way or node block)');
        }
        for (let i = 0; i < relations.length; i += 2) this.relation(relations[i], relations[i + 1]);
      }
      if (ways.length) {
        if (this.sawNode) throw new PbfError('pbf: file is not sorted nodes, ways, relations (a way block follows a node block)');
        this.sawWay = true;
        for (let i = 0; i < ways.length; i += 2) this.way(ways[i], ways[i + 1]);
      }
      if (dense.length || plainNodes.length) {
        this.sawNode = true;
        for (let i = 0; i < dense.length; i += 2) this.denseNodes(dense[i], dense[i + 1]);
        for (let i = 0; i < plainNodes.length; i += 2) this.plainNode(plainNodes[i], plainNodes[i + 1]);
      }
    }
    this.d = null;
  }

  /**
   * Builds the tag object from packed key and value index ranges when one of the keys is a trigger key.
   * Returns null otherwise.
   */
  tagsOf(ks, ke, vs, ve) {
    const d = this.d;
    const keys = this.keyScratch;
    keys.length = 0;
    let hit = false;
    P = ks;
    while (P < ke) {
      const k = rv(d);
      keys.push(k);
      if (!hit && this.isTrigger(k)) hit = true;
    }
    if (!hit) return null;
    const tags = {};
    P = vs;
    for (let i = 0; i < keys.length; i++) {
      if (P >= ve) throw new PbfError('pbf: element has more keys than values');
      const v = rv(d);
      const k = this.str(keys[i]);
      if (k !== '__proto__') tags[k] = this.str(v);
    }
    return tags;
  }

  relation(start, end) {
    this.relations++;
    const d = this.d;
    let id = 0;
    let ks = -1; let ke = -1; let vs = -1; let ve = -1; let ms = -1; let me = -1; let ts = -1; let te = -1;
    P = start;
    while (P < end) {
      const key = rv(d);
      const wire = key & 7;
      const field = key >>> 3;
      if (wire === 0) { const v = rv(d); if (field === 1) id = v; }
      else if (wire === 2) {
        const len = rv(d);
        const s = P;
        P += len;
        if (field === 2) { ks = s; ke = P; }
        else if (field === 3) { vs = s; ve = P; }
        else if (field === 9) { ms = s; me = P; }
        else if (field === 10) { ts = s; te = P; }
      } else if (wire === 1) P += 8;
      else if (wire === 5) P += 4;
      else throw new PbfError(`pbf: unsupported wire type ${wire}`);
    }
    if (ks < 0) return;
    const tags = this.tagsOf(ks, ke, vs, ve);
    if (!tags) return;
    this.triggered++;
    const payload = this.select('relation', tags, id);
    if (!payload) return;
    const acc = this.newAccumulator();
    this.areaElements.push({ osmType: 'relation', id, tags, payload, acc });
    const members = ms < 0 ? [] : decodePackedDelta(d, ms, me);
    const types = ts < 0 ? [] : decodePacked(d, ts, te);
    if (this.geometry) this.geometry.relationMembers.set(id, members.map((ref, i) => ({ type: types[i], ref })));
    for (let i = 0; i < members.length; i++) {
      if (types[i] === 1) ExtractState.register(this.needWay, members[i], acc);
      else if (types[i] === 0) this.wantNode(members[i], acc);
    }
  }

  way(start, end) {
    this.ways++;
    const d = this.d;
    let id = 0;
    let ks = -1; let ke = -1; let vs = -1; let ve = -1; let rs = -1; let re = -1;
    P = start;
    while (P < end) {
      const key = rv(d);
      const wire = key & 7;
      const field = key >>> 3;
      if (wire === 0) { const v = rv(d); if (field === 1) id = v; }
      else if (wire === 2) {
        const len = rv(d);
        const s = P;
        P += len;
        if (field === 2) { ks = s; ke = P; }
        else if (field === 3) { vs = s; ve = P; }
        else if (field === 8) { rs = s; re = P; }
      } else if (wire === 1) P += 8;
      else if (wire === 5) P += 4;
      else throw new PbfError(`pbf: unsupported wire type ${wire}`);
    }
    let own = -1;
    if (ks >= 0) {
      const tags = this.tagsOf(ks, ke, vs, ve);
      if (tags) {
        this.triggered++;
        const payload = this.select('way', tags, id);
        if (payload) {
          own = this.newAccumulator();
          this.areaElements.push({ osmType: 'way', id, tags, payload, acc: own });
        }
      }
    }
    const forRelations = this.needWay.size > 0 ? this.needWay.get(id) : undefined;
    if ((own < 0 && forRelations === undefined) || rs < 0) return;
    // refs: packed zigzag varints, delta coded
    let ref = 0;
    const captured = this.geometry ? [] : null;
    if (captured) this.geometry.wayRefs.set(id, captured);
    P = rs;
    while (P < re) {
      const x = rv(d);
      ref += x < TWO_POW_31 ? ((x >>> 1) ^ -(x & 1)) : zigzag(x);
      if (captured) captured.push(ref);
      if (own >= 0) this.wantNode(ref, own);
      if (forRelations !== undefined) {
        if (typeof forRelations === 'number') this.wantNode(ref, forRelations);
        else for (const acc of forRelations) this.wantNode(ref, acc);
      }
    }
  }

  taggedNode(id, tags, latE7, lngE7) {
    this.triggered++;
    const payload = this.select('node', tags, id);
    if (!payload) return;
    if (!this.insideBox(latE7, lngE7)) { this.outsideBox++; return; }
    this.nodeElements.push({ osmType: 'node', id, tags, payload, latE7, lngE7, box: null });
  }

  plainNode(start, end) {
    this.nodes++;
    const d = this.d;
    let id = 0;
    let lat = 0;
    let lng = 0;
    let ks = -1; let ke = -1; let vs = -1; let ve = -1;
    forEachField(d, start, end, (field, wire, a, b) => {
      if (wire === 0) {
        if (field === 1) id = zigzag(a);
        else if (field === 8) lat = zigzag(a);
        else if (field === 9) lng = zigzag(a);
      } else if (wire === 2) {
        if (field === 2) { ks = a; ke = b; }
        else if (field === 3) { vs = a; ve = b; }
      }
    });
    const latE7 = this.e7Lat(lat);
    const lngE7 = this.e7Lng(lng);
    const targets = this.needNode.get(id);
    if (targets !== undefined) {
      if (this.geometry) this.geometry.nodes.set(id, [latE7, lngE7]);
      if (typeof targets === 'number') this.touch(targets, latE7, lngE7);
      else for (const acc of targets) this.touch(acc, latE7, lngE7);
    }
    if (ks >= 0) {
      const tags = this.tagsOf(ks, ke, vs, ve);
      if (tags) this.taggedNode(id, tags, latE7, lngE7);
    }
  }

  denseNodes(start, end) {
    const d = this.d;
    let is = -1; let ie = -1; let las = -1; let lae = -1; let los = -1; let loe = -1; let kvs = -1; let kve = -1;
    P = start;
    while (P < end) {
      const key = rv(d);
      const wire = key & 7;
      const field = key >>> 3;
      if (wire === 0) rv(d);
      else if (wire === 2) {
        const len = rv(d);
        const s = P;
        P += len;
        if (field === 1) { is = s; ie = P; }
        else if (field === 8) { las = s; lae = P; }
        else if (field === 9) { los = s; loe = P; }
        else if (field === 10) { kvs = s; kve = P; }
      } else if (wire === 1) P += 8;
      else if (wire === 5) P += 4;
      else throw new PbfError(`pbf: unsupported wire type ${wire}`);
    }
    if (is < 0) return;
    const cap = ie - is;
    if (this.ids.length < cap) {
      this.ids = new Float64Array(cap);
      this.lats = new Float64Array(cap);
      this.lngs = new Float64Array(cap);
    }
    const n = unpackDelta(d, is, ie, this.ids);
    if (las < 0 || los < 0) throw new PbfError('pbf: dense nodes without coordinates');
    if (unpackDelta(d, las, lae, this.lats) !== n || unpackDelta(d, los, loe, this.lngs) !== n) {
      throw new PbfError('pbf: dense node id and coordinate counts differ');
    }
    this.nodes += n;
    const { ids, lats, lngs, needNode, nodeFilter } = this;
    const simple = this.granularity === 100 && this.latOffset === 0 && this.lngOffset === 0;

    if (needNode.size > 0) {
      for (let i = 0; i < n; i++) {
        const id = ids[i];
        const low = id - Math.floor(id * NODE_FILTER_INV) * NODE_FILTER_BITS;
        if ((nodeFilter[low >>> 3] & (1 << (low & 7))) === 0) continue;
        const targets = needNode.get(id);
        if (targets === undefined) continue;
        const latE7 = simple ? lats[i] : this.e7Lat(lats[i]);
        const lngE7 = simple ? lngs[i] : this.e7Lng(lngs[i]);
        if (this.geometry) this.geometry.nodes.set(ids[i], [latE7, lngE7]);
        if (typeof targets === 'number') this.touch(targets, latE7, lngE7);
        else for (const acc of targets) this.touch(acc, latE7, lngE7);
      }
    }

    // keys_vals: key,val,...,0 per node. Absent or empty means no node of the group has tags.
    if (kvs < 0 || kve === kvs) return;
    P = kvs;
    for (let i = 0; i < n; i++) {
      if (P >= kve) throw new PbfError('pbf: dense keys_vals ends before the last node');
      if (d[P] === 0) { P++; continue; } // untagged node: a single zero byte
      const first = P;
      let hit = false;
      for (;;) {
        const k = rv(d);
        if (k === 0) break;
        if (!hit && this.isTrigger(k)) hit = true;
        rv(d);
      }
      if (!hit) continue;
      const resume = P;
      const tags = {};
      P = first;
      for (;;) {
        const k = rv(d);
        if (k === 0) break;
        const v = rv(d);
        const name = this.str(k);
        if (name !== '__proto__') tags[name] = this.str(v);
      }
      P = resume;
      this.taggedNode(ids[i], tags, simple ? lats[i] : this.e7Lat(lats[i]), simple ? lngs[i] : this.e7Lng(lngs[i]));
      P = resume;
    }
  }

  /** Resolves bounding boxes and returns the kept elements: relations and ways as met, then nodes as met. */
  finish() {
    const out = [];
    for (const el of this.areaElements) {
      const acc = el.acc;
      if (this.accCount[acc] === 0) { this.noGeometry++; continue; }
      const minLatE7 = this.accMinLat[acc];
      const maxLatE7 = this.accMaxLat[acc];
      const minLngE7 = this.accMinLng[acc];
      const maxLngE7 = this.accMaxLng[acc];
      const latE7 = Math.floor((minLatE7 + maxLatE7 + 1) / 2);
      const lngE7 = Math.floor((minLngE7 + maxLngE7 + 1) / 2);
      if (!this.insideBox(latE7, lngE7)) { this.outsideBox++; continue; }
      out.push({
        osmType: el.osmType, id: el.id, tags: el.tags, payload: el.payload, latE7, lngE7,
        box: { minLatE7, minLngE7, maxLatE7, maxLngE7 },
      });
    }
    for (const el of this.nodeElements) out.push(el);
    return out;
  }
}

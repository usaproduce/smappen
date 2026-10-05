// 2020 Census PL 94-171 geographic header reader (03_DATA.md 2.1).
// The file is vertical-bar separated, has no header row and exactly 97 fields per line. Only block rows
// (SUMLEV 750) are kept, and of those only the seven fields the pipeline uses.

export class PlError extends Error {}

export const PL_FIELD_COUNT = 97;
const IDX_SUMLEV = 2;
const IDX_GEOCODE = 9;
const IDX_AREALAND = 84;
const IDX_POP100 = 90;
const IDX_HU100 = 91;
const IDX_INTPTLAT = 92;
const IDX_INTPTLON = 93;

const BAR = 0x7c;
const LF = 0x0a;
const CR = 0x0d;

function intField(text, name, geoid) {
  if (!/^\d+$/.test(text)) throw new PlError(`pl: ${name} of block ${geoid} is not a whole number: ${JSON.stringify(text)}`);
  return Number(text);
}

function coordField(text, name, geoid) {
  if (!/^[+-]\d{1,3}\.\d+$/.test(text)) throw new PlError(`pl: ${name} of block ${geoid} is not a signed decimal: ${JSON.stringify(text)}`);
  return Number(text);
}

/**
 * Parses one line (without its line ending) into a block record, or null when it is not a block row.
 * Throws when the line does not have 97 fields.
 * @param {string} line
 */
export function parsePlLine(line) {
  const f = line.split('|');
  if (f.length !== PL_FIELD_COUNT) throw new PlError(`pl: line has ${f.length} fields, expected ${PL_FIELD_COUNT}`);
  if (f[IDX_SUMLEV] !== '750') return null;
  return blockRecord(f[IDX_GEOCODE], f[IDX_AREALAND], f[IDX_POP100], f[IDX_HU100], f[IDX_INTPTLAT], f[IDX_INTPTLON]);
}

function blockRecord(geoid, area, pop, hu, lat, lng) {
  if (!/^\d{15}$/.test(geoid)) throw new PlError(`pl: block GEOCODE is not 15 digits: ${JSON.stringify(geoid)}`);
  return {
    geoid,
    landAreaM2: intField(area, 'AREALAND', geoid),
    residents: intField(pop, 'POP100', geoid),
    housingUnits: intField(hu, 'HU100', geoid),
    lat: coordField(lat, 'INTPTLAT', geoid),
    lng: coordField(lng, 'INTPTLON', geoid),
  };
}

/**
 * Scans a whole geo header file.
 * @param {Buffer} buf uncompressed `{usps}geo2020.pl`
 * @param {(block: {geoid: string, landAreaM2: number, residents: number, housingUnits: number, lat: number, lng: number}) => void} onBlock
 * @returns {{rows: number, badRows: number, blocks: number}} `badRows` counts lines without exactly 97 fields
 */
export function scanPlGeo(buf, onBlock) {
  let rows = 0;
  let badRows = 0;
  let blocks = 0;
  const n = buf.length;
  const starts = new Int32Array(PL_FIELD_COUNT + 1);
  let p = 0;
  while (p < n) {
    let lineEnd = buf.indexOf(LF, p);
    if (lineEnd < 0) lineEnd = n;
    let end = lineEnd;
    if (end > p && buf[end - 1] === CR) end--;
    if (end > p) {
      rows++;
      let fields = 1;
      starts[0] = p;
      for (let q = p; q < end; q++) {
        if (buf[q] === BAR) {
          if (fields <= PL_FIELD_COUNT) starts[fields] = q + 1;
          fields++;
        }
      }
      if (fields !== PL_FIELD_COUNT) {
        badRows++;
      } else {
        starts[PL_FIELD_COUNT] = end + 1;
        const s = starts[IDX_SUMLEV];
        if (starts[IDX_SUMLEV + 1] - 1 - s === 3 && buf[s] === 0x37 && buf[s + 1] === 0x35 && buf[s + 2] === 0x30) {
          const field = (i) => buf.latin1Slice(starts[i], starts[i + 1] - 1);
          blocks++;
          onBlock(blockRecord(field(IDX_GEOCODE), field(IDX_AREALAND), field(IDX_POP100), field(IDX_HU100),
            field(IDX_INTPTLAT), field(IDX_INTPTLON)));
        }
      }
    }
    p = lineEnd + 1;
  }
  return { rows, badRows, blocks };
}

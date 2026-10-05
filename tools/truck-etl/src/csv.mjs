// CSV reading and writing (RFC 4180 quoting). The LODES crosswalk has quoted fields that contain commas,
// so splitting on commas is not enough (03_DATA.md 2.2).

export class CsvError extends Error {}

const QUOTE = 0x22;
const COMMA = 0x2c;
const LF = 0x0a;
const CR = 0x0d;

/**
 * Streams the records of a CSV buffer (UTF-8, LF or CRLF line ends, optional quoted fields with doubled
 * quotes). The first record is the header and is returned, not passed to the callback.
 * @param {Buffer} buf
 * @param {number[]|null} columns zero-based column indexes to extract, or null for all
 * @param {(values: string[], fieldCount: number, recordNumber: number) => void} onRecord called per data record
 *        with the extracted values (in the order of `columns`) and the record's total field count
 * @returns {{header: string[], records: number}}
 */
export function scanCsv(buf, columns, onRecord) {
  const n = buf.length;
  let p = 0;
  let header = null;
  let records = 0;
  const want = columns ? new Map(columns.map((c, i) => [c, i])) : null;

  while (p < n) {
    const values = want ? new Array(columns.length).fill('') : [];
    let field = 0;
    let recordDone = false;
    while (!recordDone) {
      let text;
      if (p < n && buf[p] === QUOTE) {
        // quoted field
        let q = p + 1;
        let hasEscape = false;
        for (;;) {
          const close = buf.indexOf(QUOTE, q);
          if (close < 0) throw new CsvError(`csv: unterminated quoted field in record ${records + 1}`);
          if (close + 1 < n && buf[close + 1] === QUOTE) { hasEscape = true; q = close + 2; continue; }
          q = close;
          break;
        }
        const wanted = !want || header === null || want.has(field);
        text = wanted ? buf.toString('utf8', p + 1, q) : '';
        if (wanted && hasEscape) text = text.replace(/""/g, '"');
        p = q + 1;
        if (p < n && buf[p] !== COMMA && buf[p] !== LF && buf[p] !== CR) {
          throw new CsvError(`csv: unexpected character after a quoted field in record ${records + 1}`);
        }
      } else {
        let q = p;
        while (q < n) {
          const c = buf[q];
          if (c === COMMA || c === LF || c === CR) break;
          q++;
        }
        const wanted = !want || header === null || want.has(field);
        text = wanted ? buf.toString('utf8', p, q) : '';
        p = q;
      }
      if (header === null || !want) values[field] = text;
      else if (want.has(field)) values[want.get(field)] = text;
      field++;
      if (p >= n) {
        recordDone = true;
      } else if (buf[p] === COMMA) {
        p++;
        if (p >= n) { // trailing comma at end of file: one more empty field
          if (header === null || !want) values[field] = '';
          field++;
          recordDone = true;
        }
      } else {
        if (buf[p] === CR) p++;
        if (p < n && buf[p] === LF) p++;
        recordDone = true;
      }
    }
    if (header === null) {
      header = values.slice(0, field);
    } else {
      records++;
      onRecord(values, field, records);
    }
  }
  if (header === null) throw new CsvError('csv: file is empty');
  return { header, records };
}

/** Quotes one CSV field when it contains a comma, a quote, CR or LF (RFC 4180). */
export function csvField(value) {
  const s = value === null || value === undefined ? '' : String(value);
  return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

/** Joins fields into one CSV line (no line ending). */
export function csvLine(values) {
  return values.map(csvField).join(',');
}

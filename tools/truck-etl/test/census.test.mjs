import assert from 'node:assert/strict';
import zlib from 'node:zlib';
import { test } from 'node:test';
import { scanCsv, csvField, csvLine, CsvError } from '../src/csv.mjs';
import {
  LodesError, checkWacHeader, checkXwalkHeader, compileSegmentGrouping, parseLodesVersion, parseSha256Sums, readWac, scanXwalk,
  sectorsToSegments,
} from '../src/lodes.mjs';
import { PL_FIELD_COUNT, PlError, parsePlLine, scanPlGeo } from '../src/pl.mjs';
import { ZipError, crc32, listZip, readZipMember } from '../src/zip.mjs';
import { writeZip } from '../test-support/zip-writer.mjs';

// Three real lines of dcgeo2020.pl (District of Columbia): the state row and two blocks.
const PL_STATE = 'PLST|DC|040|00|00|000|00|0000001|0400000US11|11|3|5|11|01702382|||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||158316124|18709762|District of Columbia|District of Columbia|A||689545|350364|+38.9042474|-077.0165167|00||';
const PL_BLOCK_1 = 'PLST|DC|750|00|00|000|00|0004034|7500000US110010001011000|110010001011000|3|5|11|01702382|001|H6|01702382|50000|C5|02390665|||||||99999|99|99999999|50000|C5|02390665|000101|1|1000|9999|9|99999|99|99999999|999|99999|99|99999999|999999|9|99999|99|99999999|47900|1|548|47894|99999|9|999|99999|Y|N||||98|||||002|||||999|||||02-005|A||99999|99999|00030||42796|951|1000|Block 1000|S||607|495|+38.9100683|-077.0528631|BK||99999';
const PL_BLOCK_2 = 'PLST|DC|750|00|00|000|00|0004036|7500000US110010001011002|110010001011002|3|5|11|01702382|001|H6|01702382|50000|C5|02390665|||||||99999|99|99999999|50000|C5|02390665|000101|1|1002|9999|9|99999|99|99999999|999|99999|99|99999999|999999|9|99999|99|99999999|47900|1|548|47894|99999|9|999|99999|Y|N||||98|||||002|||||999|||||02-005|A||99999|99999|00030||15939|4310|1002|Block 1002|S||0|0|+38.9090878|-077.0508394|BK||99999';

test('crc32 matches the standard check value', () => {
  assert.equal(crc32(Buffer.from('123456789')), 0xcbf43926);
  assert.equal(crc32(Buffer.alloc(0)), 0);
});

test('zip: a deflated member is listed, extracted and verified', () => {
  const data = Buffer.from(`${PL_STATE}\n${PL_BLOCK_1}\n`.repeat(40));
  const zip = writeZip([{ name: 'dc000012020.pl', data: Buffer.from('other member') }, { name: 'dcgeo2020.pl', data }]);
  assert.deepEqual(listZip(zip).map((e) => [e.name, e.method, e.size]), [['dc000012020.pl', 8, 12], ['dcgeo2020.pl', 8, data.length]]);
  assert.ok(listZip(zip)[1].compressedSize < data.length / 5);
  assert.deepEqual(readZipMember(zip, 'dcgeo2020.pl'), data);
});

test('zip: failures', () => {
  const data = Buffer.from('some content, some content, some content');
  assert.throws(() => readZipMember(writeZip([{ name: 'a', data }]), 'b'), /member b not found/);
  assert.throws(() => readZipMember(writeZip([{ name: 'a', data, method: 0 }]), 'a'), /method 0/);
  assert.throws(() => readZipMember(writeZip([{ name: 'a', data, corruptCrc: true }]), 'a'), /CRC-32 mismatch/);
  assert.throws(() => listZip(Buffer.from('not a zip file at all, no directory here')), ZipError);
  // a size field of 0xFFFFFFFF marks zip64
  const zip64 = writeZip([{ name: 'a', data }]);
  const central = zip64.lastIndexOf(Buffer.from([0x50, 0x4b, 0x01, 0x02]));
  zip64.writeUInt32LE(0xffffffff, central + 24);
  assert.throws(() => listZip(zip64), /zip64/);
  // truncated compressed data
  const cut = writeZip([{ name: 'a', data }]);
  const local = cut.subarray(0, 40);
  assert.throws(() => listZip(local), ZipError);
});

test('PL 94-171: real lines parse to block records', () => {
  assert.equal(PL_BLOCK_1.split('|').length, PL_FIELD_COUNT);
  assert.equal(parsePlLine(PL_STATE), null); // SUMLEV 040
  assert.deepEqual(parsePlLine(PL_BLOCK_1), {
    geoid: '110010001011000', landAreaM2: 42796, residents: 607, housingUnits: 495, lat: 38.9100683, lng: -77.0528631,
  });
  assert.deepEqual(parsePlLine(PL_BLOCK_2), {
    geoid: '110010001011002', landAreaM2: 15939, residents: 0, housingUnits: 0, lat: 38.9090878, lng: -77.0508394,
  });
  assert.equal(typeof parsePlLine(PL_BLOCK_1).geoid, 'string');
  assert.throws(() => parsePlLine('PLST|DC|750'), /3 fields, expected 97/);
});

test('PL 94-171: the scanner keeps block rows, counts rows and bad rows, accepts LF and CRLF', () => {
  for (const eol of ['\n', '\r\n']) {
    const text = [PL_STATE, PL_BLOCK_1, 'PLST|DC|750|short row', PL_BLOCK_2].join(eol) + eol;
    const blocks = [];
    const stats = scanPlGeo(Buffer.from(text, 'latin1'), (b) => blocks.push(b));
    assert.deepEqual(stats, { rows: 4, badRows: 1, blocks: 2 });
    assert.deepEqual(blocks.map((b) => b.geoid), ['110010001011000', '110010001011002']);
    assert.deepEqual(blocks[0], parsePlLine(PL_BLOCK_1));
  }
  // no final newline
  const blocks = [];
  assert.deepEqual(scanPlGeo(Buffer.from(PL_BLOCK_1, 'latin1'), (b) => blocks.push(b)), { rows: 1, badRows: 0, blocks: 1 });
  // a block row with a broken number is an error, not a silent zero
  assert.throws(() => scanPlGeo(Buffer.from(PL_BLOCK_1.replace('|607|', '|6o7|'), 'latin1'), () => {}), PlError);
});

test('csv: quoted fields with commas, doubled quotes, selected columns', () => {
  const text = 'a,b,c\n1,"x, y",3\n4,"say ""hi""",6\r\n7,,9\n';
  const rows = [];
  const all = scanCsv(Buffer.from(text), null, (values, n) => rows.push([values.slice(), n]));
  assert.deepEqual(all.header, ['a', 'b', 'c']);
  assert.equal(all.records, 3);
  assert.deepEqual(rows, [[['1', 'x, y', '3'], 3], [['4', 'say "hi"', '6'], 3], [['7', '', '9'], 3]]);

  const picked = [];
  scanCsv(Buffer.from(text), [2, 0], (values, n) => picked.push([values.slice(), n]));
  assert.deepEqual(picked, [[['3', '1'], 3], [['6', '4'], 3], [['9', '7'], 3]]);

  const noFinalNewline = [];
  scanCsv(Buffer.from('h1,h2\nv1,v2'), null, (values) => noFinalNewline.push(values.slice()));
  assert.deepEqual(noFinalNewline, [['v1', 'v2']]);
  assert.throws(() => scanCsv(Buffer.from('a,b\n1,"open'), null, () => {}), CsvError);
  assert.throws(() => scanCsv(Buffer.alloc(0), null, () => {}), /empty/);
});

test('csv: writing with RFC 4180 quoting', () => {
  assert.equal(csvField('plain'), 'plain');
  assert.equal(csvField('a,b'), '"a,b"');
  assert.equal(csvField('say "hi"'), '"say ""hi"""');
  assert.equal(csvField('two\nlines'), '"two\nlines"');
  assert.equal(csvField(null), '');
  assert.equal(csvField(12.5), '12.5');
  assert.equal(csvLine(['x', 'a,b', '', 3]), 'x,"a,b",,3');
});

const WAC_HEADER = 'w_geocode,C000,CA01,CA02,CA03,CE01,CE02,CE03,CNS01,CNS02,CNS03,CNS04,CNS05,CNS06,CNS07,CNS08,CNS09,CNS10,CNS11,CNS12,CNS13,CNS14,CNS15,CNS16,CNS17,CNS18,CNS19,CNS20,CR01,CR02,CR03,CR04,CR05,CR07,CT01,CT02,CD01,CD02,CD03,CD04,CS01,CS02,CFA01,CFA02,CFA03,CFA04,CFA05,CFS01,CFS02,CFS03,CFS04,CFS05,createdate';
// The first data row of dc_wac_S000_JT00_2023.csv.
const WAC_ROW = '110010001011000,71,21,30,20,12,14,45,0,0,0,0,0,5,0,0,1,0,30,8,6,11,0,0,0,0,10,0,43,25,0,2,0,1,59,12,5,11,18,16,36,35,0,0,0,0,0,0,0,0,0,0,20251202';

test('WAC: the real header and first row of the District of Columbia file', () => {
  const wac = readWac(Buffer.from(`${WAC_HEADER}\n${WAC_ROW}\n`));
  assert.equal(wac.rows, 1);
  assert.deepEqual(wac.geoids, ['110010001011000']);
  assert.deepEqual([...wac.c000], [71]);
  assert.deepEqual([...wac.cns], [0, 0, 0, 0, 0, 5, 0, 0, 1, 0, 30, 8, 6, 11, 0, 0, 0, 0, 10, 0]);
  assert.equal(wac.sumMismatches, 0);
});

test('WAC: header and row checks', () => {
  assert.throws(() => readWac(Buffer.from(`${WAC_HEADER.replace('w_geocode', 'h_geocode')}\n${WAC_ROW}\n`)), /unexpected header/);
  assert.throws(() => checkWacHeader(WAC_HEADER.split(',').slice(0, 52)), LodesError);
  assert.throws(() => readWac(Buffer.from(`${WAC_HEADER}\n${WAC_ROW},extra\n`)), /54 fields, expected 53/);
  assert.throws(() => readWac(Buffer.from(`${WAC_HEADER}\n${WAC_ROW.replace('110010001011000', '11001000101100')}\n`)), /bad w_geocode/);
  // sectors that do not add up to C000 are counted (gate G6), not fatal
  const off = readWac(Buffer.from(`${WAC_HEADER}\n${WAC_ROW.replace(',71,', ',72,')}\n`));
  assert.equal(off.sumMismatches, 1);
});

test('WAC: sectors group into the seven worker segments (worked example of 03_DATA.md 6.1)', () => {
  const segmentCns = {
    w_office: ['CNS09', 'CNS10', 'CNS11', 'CNS12', 'CNS13', 'CNS14'], w_health: ['CNS16'], w_edu: ['CNS15'], w_retail: ['CNS07'],
    w_industrial: ['CNS01', 'CNS02', 'CNS03', 'CNS04', 'CNS05', 'CNS06', 'CNS08'], w_hospitality: ['CNS17', 'CNS18'], w_public: ['CNS19', 'CNS20'],
  };
  const grouping = compileSegmentGrouping(segmentCns, 0.3);
  const wac = readWac(Buffer.from(`${WAC_HEADER}\n${WAC_ROW}\n`));
  const out = new Float64Array(7);
  sectorsToSegments(wac.cns, 0, grouping, out, 0);
  // office 56, health 0, education 0, retail 0, industrial 5, hospitality 0, public 10
  assert.deepEqual([...out], [56, 0, 0, 0, 5, 0, 10]);

  // construction (CNS04) enters w_industrial at its weight, summed in sector order
  const sectors = new Float64Array(20);
  sectors[0] = 1; sectors[3] = 10; sectors[4] = 2; sectors[7] = 4;
  sectorsToSegments(sectors, 0, grouping, out, 0);
  assert.equal(out[4], 1 + 0.3 * 10 + 2 + 4);
  sectorsToSegments(sectors, 0, compileSegmentGrouping(segmentCns, 1), out, 0);
  assert.equal(out[4], 17);
});

test('crosswalk: quoted labels are read by column', () => {
  const header = 'tabblk2020,st,stusps,stname,cty,ctyname,trct,trctname,bgrp,bgrpname,cbsa,cbsaname,zcta,zctaname,stplc,stplcname,ctycsub,ctycsubname,stcd119,stcd119name,stsldl,stsldlname,stsldu,stslduname,stschool,stschoolname,stsecon,stseconname,trib,tribname,tsub,tsubname,stanrc,stanrcname,mil,milname,stwib,stwibname,blklatdd,blklondd,createdate';
  // A real row of dc_xwalk.csv (Fort Lesley J McNair).
  const row = '110010064002010,11,DC,District of Columbia,11001,"District of Columbia, DC",11001006400,"64 (District of Columbia, DC)",110010064002,"2 (Tract 64, District of Columbia, DC)",47900,"Washington-Arlington-Alexandria, DC-VA-MD-WV",20024,20024,1150000,"Washington city, DC",1100150000,"Washington city (District of Columbia, DC)",1198,DC-Delegate,99999,,11006,"Ward 6, DC",1100030,"District of Columbia Public Schools, DC",9999999,,99999,,9999999,,9999999,,110431721973,Fort Lesley J McNair,11000001,01 District of Columbia WIB,38.8694709,-77.0142652,20251202';
  const seen = [];
  const out = scanXwalk(Buffer.from(`${header}\n${row}\n`), (...values) => seen.push(values));
  assert.equal(out.rows, 1);
  assert.deepEqual(seen, [['110010064002010', 'District of Columbia, DC', 'Washington city, DC', 'Fort Lesley J McNair']]);
  assert.throws(() => checkXwalkHeader(header.split(',').slice(1)), LodesError);
  assert.throws(() => scanXwalk(Buffer.from(`${header}\n110010064002010,11,DC\n`), () => {}), /3 fields, expected 41/);
});

test('LODES version file and checksum list', () => {
  const version = 'LEHD Origin-Destination Employment Statistics (LODES)\nDC (District of Columbia)\nData Vintage: 20251202_1657\nRelease Format Version 8.4\n';
  assert.deepEqual(parseLodesVersion(version), { format: '8.4', vintage: '20251202_1657' });
  assert.deepEqual(parseLodesVersion('nothing useful'), { format: null, vintage: null });
  const sums = parseSha256Sums([
    'cd9031224eac0b09e65077f0ad2046b19dc45533835f28485511e194be5c423f  dc_xwalk.csv',
    '7F460CBC51B5CE62FEC1A8059668B4EE1E1D98F7DC6E131DAF7875815C3A63DB  dc_wac_S000_JT00_2023.csv',
    'not a checksum line',
    '',
  ].join('\n'));
  assert.equal(sums.size, 2);
  assert.equal(sums.get('dc_xwalk.csv'), 'cd9031224eac0b09e65077f0ad2046b19dc45533835f28485511e194be5c423f');
  assert.equal(sums.get('dc_wac_S000_JT00_2023.csv'), '7f460cbc51b5ce62fec1a8059668b4ee1e1d98f7dc6e131daf7875815c3a63db');
});

test('gzip round trip used by the fixtures keeps the bytes', () => {
  const data = Buffer.from(`${WAC_HEADER}\n${WAC_ROW}\n`);
  assert.deepEqual(zlib.gunzipSync(zlib.gzipSync(data)), data);
});

// Minimal ZIP member reader (03_DATA.md 2.1). Node has zlib but no archive reader, and the pipeline has no
// dependency for one. Supports stored (0) listing and deflate (8) extraction; zip64 archives are refused.

import zlib from 'node:zlib';

export class ZipError extends Error {}

const SIG_EOCD = 0x06054b50;
const SIG_CENTRAL = 0x02014b50;
const SIG_LOCAL = 0x04034b50;

const CRC_TABLE = (() => {
  const table = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1);
    table[n] = c;
  }
  return table;
})();

/** CRC-32 (IEEE 802.3) of a byte buffer, as an unsigned 32-bit integer. */
export function crc32(buf) {
  let c = -1;
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}

/**
 * Lists the members of a ZIP archive held in memory.
 * @param {Buffer} buf whole archive
 * @returns {{name: string, method: number, crc32: number, compressedSize: number, size: number, localOffset: number}[]}
 */
export function listZip(buf) {
  if (buf.length < 22) throw new ZipError('zip: file is too short to hold an end-of-central-directory record');
  let eocd = -1;
  const lowest = Math.max(0, buf.length - 22 - 65535);
  for (let p = buf.length - 22; p >= lowest; p--) {
    if (buf.readUInt32LE(p) === SIG_EOCD) { eocd = p; break; }
  }
  if (eocd < 0) throw new ZipError('zip: end-of-central-directory record not found');
  const count = buf.readUInt16LE(eocd + 10);
  const cdSize = buf.readUInt32LE(eocd + 12);
  const cdOffset = buf.readUInt32LE(eocd + 16);
  if (count === 0xffff || cdSize === 0xffffffff || cdOffset === 0xffffffff) throw new ZipError('zip: zip64 archives are not supported');
  if (cdOffset + cdSize > eocd) throw new ZipError('zip: central directory lies outside the file');

  const entries = [];
  let p = cdOffset;
  for (let i = 0; i < count; i++) {
    if (p + 46 > buf.length || buf.readUInt32LE(p) !== SIG_CENTRAL) throw new ZipError(`zip: bad central header ${i}`);
    const method = buf.readUInt16LE(p + 10);
    const crc = buf.readUInt32LE(p + 16);
    const compressedSize = buf.readUInt32LE(p + 20);
    const size = buf.readUInt32LE(p + 24);
    const nameLen = buf.readUInt16LE(p + 28);
    const extraLen = buf.readUInt16LE(p + 30);
    const commentLen = buf.readUInt16LE(p + 32);
    const localOffset = buf.readUInt32LE(p + 42);
    if (compressedSize === 0xffffffff || size === 0xffffffff || localOffset === 0xffffffff) {
      throw new ZipError('zip: zip64 archives are not supported');
    }
    const name = buf.toString('utf8', p + 46, p + 46 + nameLen);
    entries.push({ name, method, crc32: crc, compressedSize, size, localOffset });
    p += 46 + nameLen + extraLen + commentLen;
  }
  return entries;
}

/**
 * Extracts one member, verifying its size and CRC-32.
 * @param {Buffer} buf whole archive
 * @param {string} memberName exact member name
 * @returns {Buffer} the uncompressed bytes
 */
export function readZipMember(buf, memberName) {
  const entry = listZip(buf).find((e) => e.name === memberName);
  if (!entry) throw new ZipError(`zip: member ${memberName} not found`);
  if (entry.method !== 8) throw new ZipError(`zip: member ${memberName} uses method ${entry.method}, only deflate (8) is supported`);
  const p = entry.localOffset;
  if (p + 30 > buf.length || buf.readUInt32LE(p) !== SIG_LOCAL) throw new ZipError(`zip: bad local header for ${memberName}`);
  const nameLen = buf.readUInt16LE(p + 26);
  const extraLen = buf.readUInt16LE(p + 28);
  const start = p + 30 + nameLen + extraLen;
  const end = start + entry.compressedSize;
  if (end > buf.length) throw new ZipError(`zip: member ${memberName} runs past the end of the file`);
  let out;
  try {
    out = zlib.inflateRawSync(buf.subarray(start, end));
  } catch (err) {
    throw new ZipError(`zip: member ${memberName} does not inflate: ${err.message}`);
  }
  if (out.length !== entry.size) throw new ZipError(`zip: member ${memberName} inflates to ${out.length} bytes, directory says ${entry.size}`);
  const crc = crc32(out);
  if (crc !== entry.crc32) {
    throw new ZipError(`zip: member ${memberName} CRC-32 mismatch (${crc.toString(16)} != ${entry.crc32.toString(16)})`);
  }
  return out;
}

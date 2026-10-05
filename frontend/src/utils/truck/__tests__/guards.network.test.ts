// Source guard: one door for network I/O (docs/truck-planner/05_FRONTEND.md rule R6 and 8.3).
//
// All truck network I/O goes through `api` from api/client.ts to /api/truck/... This test fails the
// build when truck code opens another door (fetch, XMLHttpRequest, WebSocket, EventSource,
// sendBeacon, axios), calls the shared client with a path outside /api/truck/, writes a URL to a
// host that is not on the short list of free links and credits, or imports a package that is not
// on the list of what Truck Planner is built from.

import { describe, expect, it } from 'vitest';
import { code, importsOf, lineOf, report, scan, truckFiles, type Hit } from './_closure';

const DOORS = [
  // `fetch(` as a call of its own or on window, self or globalThis; not `refetch(` or `fetchPack(`.
  { what: 'fetch(', pattern: /(?:^|[^\w$.])fetch\s*\(/ },
  { what: 'fetch(', pattern: /\b(?:window|self|globalThis)\s*\.\s*fetch\b/ },
  { what: 'XMLHttpRequest', pattern: /\bXMLHttpRequest\b/ },
  { what: 'WebSocket', pattern: /\bWebSocket\b/ },
  { what: 'EventSource', pattern: /\bEventSource\b/ },
  { what: 'sendBeacon', pattern: /\bsendBeacon\b/ },
];

/** Hosts (with a path prefix) a truck file may name: free Google Maps links, two credits, and XML namespaces. */
const ALLOWED_URLS = [
  'www.google.com/maps/',
  'www.openstreetmap.org/copyright',
  'lehd.ces.census.gov/data/',
  'www.w3.org/',
];

/** Every package truck code may import. `axios` is not on it: the shared client is the only user. */
const ALLOWED_PACKAGES = [
  'react',
  'react-dom',
  'react-router-dom',
  '@tanstack/react-query',
  'zustand',
  'zustand/middleware',
  'react-hot-toast',
  'lucide-react',
  '@react-google-maps/api',
  'h3-js',
];

const API_CALL = /\bapi\s*\.\s*(get|post|put|delete|patch|head|request)\s*\(\s*/g;

describe('guard: network I/O only through the truck API', () => {
  const files = truckFiles();
  const scripts = files.filter((file) => /\.tsx?$/.test(file));

  it('has files to check', () => {
    expect(scripts.length).toBeGreaterThan(20);
    expect(files).toContain('api/truck.ts');
  });

  it('opens no other door to the network', () => {
    const hits = scan(scripts, DOORS);
    expect(hits, '\n' + report(hits) + '\n').toEqual([]);
  });

  it('calls the shared client only with literal paths under /api/truck/', () => {
    const bad: Hit[] = [];
    const nonLiteral: Hit[] = [];
    for (const file of scripts) {
      const text = code(file);
      for (const m of text.matchAll(API_CALL)) {
        const at = (m.index ?? 0) + m[0].length;
        const line = lineOf(text, m.index ?? 0);
        const shown = text.split('\n')[line - 1].trim().slice(0, 160);
        const quote = text[at];
        if (quote === "'" || quote === '"' || quote === '`') {
          if (!text.startsWith('/api/truck/', at + 1)) {
            bad.push({ file, line, what: 'path outside /api/truck/', text: shown });
          }
        } else {
          nonLiteral.push({ file, line, what: 'path is not a literal', text: shown });
        }
      }
    }
    expect(bad, '\n' + report(bad) + '\n').toEqual([]);
    // The one path that is not a literal: fetchPack(url), which takes `region.pack.url`.
    expect(nonLiteral.map((h) => h.file), '\n' + report(nonLiteral) + '\n').toEqual(['api/truck.ts']);
    expect(nonLiteral[0].text).toContain('api.get(url');
  });

  it('fetchPack refuses a url that is not a pack path of this API', () => {
    const text = code('api/truck.ts');
    const start = text.indexOf('async fetchPack(');
    expect(start).toBeGreaterThan(-1);
    const body = text.slice(start, text.indexOf('api.get(url', start));
    expect(text).toContain("const PACK_URL_PREFIX = '/api/truck/regions/';");
    expect(body).toMatch(/if\s*\(\s*!url\.startsWith\(PACK_URL_PREFIX\)\s*\)\s*\{\s*throw\b/);
  });

  it('writes no URL to a host that is not on the list', () => {
    const bad: Hit[] = [];
    for (const file of files) {
      const text = code(file);
      for (const m of text.matchAll(/https?:\/\/([^\s'"`)<>\\]*)/g)) {
        const rest = m[1];
        if (ALLOWED_URLS.some((prefix) => rest.startsWith(prefix))) continue;
        const line = lineOf(text, m.index ?? 0);
        bad.push({ file, line, what: 'URL ' + m[0].slice(0, 80), text: text.split('\n')[line - 1].trim().slice(0, 160) });
      }
      // A protocol-relative URL would slip past the pattern above.
      for (const m of text.matchAll(/(['"`])\/\/[\w.-]+\.[a-z]{2,}/g)) {
        const line = lineOf(text, m.index ?? 0);
        bad.push({ file, line, what: 'protocol-relative URL', text: text.split('\n')[line - 1].trim().slice(0, 160) });
      }
    }
    expect(bad, '\n' + report(bad) + '\n').toEqual([]);
  });

  it('imports only the packages Truck Planner is built from', () => {
    const bad: string[] = [];
    for (const file of scripts) {
      for (const ref of importsOf(file)) {
        if (ref.bare && !ALLOWED_PACKAGES.includes(ref.specifier)) bad.push(file + ' imports ' + ref.specifier);
      }
    }
    expect(bad).toEqual([]);
  });

  it('would catch one (the guard itself works)', () => {
    // The shared client is outside truck code and is the one user of axios.
    expect(importsOf('api/client.ts').some((ref) => ref.specifier === 'axios')).toBe(true);
    expect('const r = await fetch(url);').toMatch(DOORS[0].pattern);
    expect('await query.refetch();').not.toMatch(DOORS[0].pattern);
    expect('truckApi.fetchPack(url)').not.toMatch(DOORS[0].pattern);
    expect('window.fetch(url)').toMatch(DOORS[1].pattern);
  });
});

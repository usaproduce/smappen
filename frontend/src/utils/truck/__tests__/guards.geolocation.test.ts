// Source guard: one truck is enough (docs/truck-planner/05_FRONTEND.md rule R2 and 8.3).
//
// Truck Planner never asks the browser where the device is. Places come from address search, typed
// coordinates or a map click. This test fails the build when a location or permission API is
// named anywhere in Truck Planner code or in anything that code imports.

import { describe, expect, it } from 'vitest';
import { importClosure, report, scan, truckFiles } from './_closure';

const FORBIDDEN = [
  { what: 'geolocation', pattern: /geolocation/i },
  { what: 'watchPosition', pattern: /watchPosition/ },
  { what: 'getCurrentPosition', pattern: /getCurrentPosition/ },
  { what: 'navigator.permissions', pattern: /navigator\s*\??\.\s*permissions/ },
  { what: 'permissions.query', pattern: /permissions\s*\??\.\s*query/ },
];

describe('guard: no browser location', () => {
  const files = truckFiles();
  const closure = importClosure(files);

  it('covers the truck files and their import closure', () => {
    expect(files.length).toBeGreaterThan(20);
    expect(closure.files.length).toBeGreaterThanOrEqual(files.length);
    // The shell the truck pages sit in is part of the closure.
    expect(closure.files).toContain('components/layout/AppNav.tsx');
    expect(closure.files).toContain('api/client.ts');
    expect(closure.unresolved, 'imports that resolve to no file').toEqual([]);
  });

  it('names no location or permission API', () => {
    const hits = scan(closure.files, FORBIDDEN);
    expect(hits, '\n' + report(hits) + '\n').toEqual([]);
  });

  it('would catch one (the guard itself works)', () => {
    // The map's Field notes tab is outside the truck closure and does call the location API.
    const field = 'components/advanced/FieldTab.tsx';
    expect(closure.files).not.toContain(field);
    expect(scan([field], FORBIDDEN).length).toBeGreaterThan(0);
  });
});

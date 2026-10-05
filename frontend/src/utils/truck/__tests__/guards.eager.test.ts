// Source guard: Truck Planner stays out of the main bundle (docs/truck-planner/05_FRONTEND.md 1.7 and 8.3).
//
// /truck is the app's first lazy route. The main chunk may hold exactly these truck-related things:
// TruckLayout.tsx, the /truck strings in AppNav and CommandPalette, the /truck path test and the
// inline skeleton in ProtectedRoute, and the lazy() wrappers in App.tsx. Everything else (pages,
// the UI kit, the estimator, the seeds, the map engine, h3-js, the truck api and stores) is reachable
// only through the one import() of TruckPages.
//
// A bundler puts everything a file imports statically into the same chunk, so this test walks the
// static import closure of App.tsx (dynamic imports are not followed) and fails when truck code is
// in it. The build-time twin of this test is frontend/scripts/check-truck-chunks.mjs.

import { describe, expect, it } from 'vitest';
import { code, importClosure, importsOf, isTruckFile, packageOf } from './_closure';

const APP = 'App.tsx';
const LAYOUT = 'components/truck/TruckLayout.tsx';
const BOUNDARY = 'components/truck/TruckPages.ts';

/** The five modules the eager layout may import (1.7). */
const LAYOUT_IMPORTS = ['react', 'react-router-dom', 'lucide-react', '../layout/AppNav', '../ErrorBoundary'];

/** Shell files that carry /truck strings and must import nothing from truck code. */
const SHELL = [
  'components/layout/AppNav.tsx',
  'components/common/CommandPalette.tsx',
  'components/auth/ProtectedRoute.tsx',
  'components/common/GooglePlaceAutocomplete.tsx',
];

describe('guard: the eager import graph', () => {
  const eager = importClosure([APP], { dynamic: false });

  it('walks a real graph', () => {
    expect(eager.files.length).toBeGreaterThan(50);
    expect(eager.files).toContain(LAYOUT);
    expect(eager.files).toContain('components/layout/AppNav.tsx');
    expect(eager.unresolved, 'imports that resolve to no file').toEqual([]);
  });

  it('holds no truck file other than TruckLayout.tsx', () => {
    const leaked = eager.files.filter((file) => isTruckFile(file) && file !== LAYOUT);
    const detail = leaked.map((file) => eager.chain(file).join(' -> '));
    expect(detail, 'truck code reached through static imports:\n' + detail.join('\n') + '\n').toEqual([]);
  });

  it('does not hold h3-js', () => {
    const h3 = eager.packages.filter((specifier) => packageOf(specifier) === 'h3-js');
    const detail = h3.map((specifier) => specifier + ' <- ' + eager.importersOf(specifier).join(', '));
    expect(detail).toEqual([]);
  });

  it('TruckLayout.tsx imports only the five modules of 1.7', () => {
    const refs = importsOf(LAYOUT);
    expect(refs.filter((ref) => ref.kind === 'dynamic')).toEqual([]);
    const specifiers = refs.map((ref) => ref.specifier).sort();
    expect(specifiers).toEqual([...LAYOUT_IMPORTS].sort());
  });

  it('App.tsx reaches truck code through TruckLayout and ONE import() of TruckPages', () => {
    const refs = importsOf(APP).filter((ref) => ref.file !== null && isTruckFile(ref.file));
    expect(refs.filter((ref) => ref.kind === 'static').map((ref) => ref.file)).toEqual([LAYOUT]);
    expect(refs.filter((ref) => ref.kind === 'dynamic').map((ref) => ref.file)).toEqual([BOUNDARY]);
    // One import() expression in the source, however many lazy() wrappers share it.
    expect(code(APP).match(/\bimport\s*\(/g)).toHaveLength(1);
  });

  it('the shell files import nothing from truck code', () => {
    for (const file of SHELL) {
      const truck = importsOf(file).filter((ref) => ref.file !== null && isTruckFile(ref.file));
      expect(truck.map((ref) => ref.specifier), file).toEqual([]);
      expect(importsOf(file).filter((ref) => ref.bare && packageOf(ref.specifier) === 'h3-js'), file).toEqual([]);
    }
  });

  it('TruckPages.ts is the lazy boundary: gate, pages, both stylesheets and the auth-failure hook', () => {
    const specifiers = importsOf(BOUNDARY).map((ref) => ref.specifier);
    expect(specifiers).toContain('./truck.css');
    expect(specifiers).toContain('./print.css');
    expect(specifiers).toContain('./map/authFailure');
    expect(specifiers).toContain('./TruckGate');
    const text = code(BOUNDARY);
    for (const name of [
      'TruckGate',
      'TodayPage',
      'MapPage',
      'SpotsPage',
      'SpotComparePage',
      'SpotDetailPage',
      'PlanIndexRedirect',
      'PlannerPage',
      'DaySheetPage',
      'WeekIndexRedirect',
      'WeekPage',
      'LogPage',
      'ScoutPage',
      'SettingsPage',
    ]) {
      expect(text, 'TruckPages.ts must export ' + name).toMatch(new RegExp('\\b' + name + '\\b'));
      expect(code(APP), 'App.tsx must wrap ' + name).toMatch(new RegExp('m\\.' + name + '\\b'));
    }
  });

  it('the lazy chunk does hold what the main bundle must not', () => {
    const lazy = importClosure([BOUNDARY]);
    expect(lazy.files).toContain('utils/truck/model.ts');
    expect(lazy.files).toContain('utils/truck/estimator/index.ts');
    expect(lazy.files).toContain('api/truck.ts');
    expect(lazy.files).toContain('stores/truckUiStore.ts');
    // The sentinel that check-truck-chunks.mjs looks for is rendered by the gate.
    expect(code('utils/truck/model.ts')).toContain("'tp-chunk-sentinel'");
    expect(code('components/truck/TruckGate.tsx')).toMatch(/data-tp-chunk=\{TP_CHUNK_SENTINEL\}/);
  });
});

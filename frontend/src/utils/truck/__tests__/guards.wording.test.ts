// Source guard: honest wording (docs/truck-planner/05_FRONTEND.md rule R5, 6.9 and 8.3).
//
// The app never states or implies that a spot may be used, and never dresses arithmetic up as
// something cleverer. This test fails the build when a truck file (the seed copy included):
//
//   - matches one of the banned patterns of 6.9;
//   - holds an emoji or a dingbat;
//   - says "AI", "smart", "magic" or "powered by" in a string or in JSX text;
//   - uses the word "permission" in a string outside the standing notice.
//
// It also checks that every screen that shows places carries the standing notice, and that the
// spot form credits OpenStreetMap where it lists places.

import { describe, expect, it } from 'vitest';
import { code, exists, lineOf, report, scan, truckFiles, type Hit } from './_closure';

/** 6.9, case-insensitive. `\bpermit\w*` does not match "permission". */
const BANNED = [
  { what: 'legal', pattern: /\b(il)?legal(ly|ity)?\b/i },
  { what: 'permit', pattern: /\bpermit\w*/i },
  { what: 'allowed to ...', pattern: /allowed to (park|trade|sell|vend|operate)/i },
  { what: 'approved', pattern: /\bapproved\b/i },
  { what: 'authorised', pattern: /\bauthori[sz]ed\b/i },
  { what: 'compliant', pattern: /\bcompliant\b/i },
  { what: 'lawful', pattern: /\blawful(ly)?\b/i },
  { what: 'zoned', pattern: /\bzoned\b/i },
  { what: 'ok to park', pattern: /\bok to park\b/i },
];

/** Emoji (U+1F300 to U+1FAFF) and dingbats and symbols (U+2600 to U+27BF). */
const PICTOGRAPHS = [{ what: 'emoji or dingbat', pattern: /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u }];

/** Only inside string literals and JSX text: an identifier such as `smartRound` is not copy. */
const HYPE = [
  { what: '"AI"', pattern: /\bAI\b/ },
  { what: '"smart"', pattern: /\bsmart\b/i },
  { what: '"magic"', pattern: /\bmagic/i },
  { what: '"powered by"', pattern: /powered by/i },
];

const STRING_LITERAL = /'(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*"|`(?:[^`\\]|\\[\s\S])*`/g;
const JSX_TEXT = />([^<>{}]+)</g;

/** The pieces of a file a reader of the screen can see: string literals and, in .tsx files, JSX text. */
function copyOf(file: string): { index: number; text: string }[] {
  const text = code(file);
  const out: { index: number; text: string }[] = [];
  for (const m of text.matchAll(STRING_LITERAL)) out.push({ index: m.index ?? 0, text: m[0] });
  if (file.endsWith('.tsx')) {
    for (const m of text.matchAll(JSX_TEXT)) out.push({ index: (m.index ?? 0) + 1, text: m[1] });
  }
  return out;
}

function scanCopy(files: readonly string[], patterns: readonly { what: string; pattern: RegExp }[]): Hit[] {
  const hits: Hit[] = [];
  for (const file of files) {
    if (!/\.tsx?$/.test(file)) continue;
    const text = code(file);
    for (const piece of copyOf(file)) {
      for (const { what, pattern } of patterns) {
        if (pattern.test(piece.text)) {
          hits.push({ file, line: lineOf(text, piece.index), what, text: piece.text.trim().slice(0, 160) });
        }
      }
    }
  }
  return hits;
}

describe('guard: wording', () => {
  const files = truckFiles();

  it('covers the truck files, the seed copy included', () => {
    expect(files).toContain('utils/truck/estimator/seeds.generated.ts');
    expect(files).toContain('utils/truck/wording.ts');
    expect(files.some((file) => file.endsWith('.css'))).toBe(true);
  });

  it('never says or implies that a spot may be used', () => {
    const hits = scan(files, BANNED);
    expect(hits, '\n' + report(hits) + '\n').toEqual([]);
  });

  it('has no emoji and no dingbat', () => {
    const hits = scan(files, PICTOGRAPHS);
    expect(hits, '\n' + report(hits) + '\n').toEqual([]);
  });

  it('never says AI, smart, magic or powered by', () => {
    const hits = scanCopy(files, HYPE);
    expect(hits, '\n' + report(hits) + '\n').toEqual([]);
  });

  it('uses the word permission only inside the standing notice', () => {
    // The standing notice lives in wording.ts; the PermissionNotice component prints it.
    const others = files.filter((file) => file !== 'utils/truck/wording.ts');
    const hits = scanCopy(others, [{ what: '"permission"', pattern: /\bpermission/i }]).filter(
      // Import specifiers are strings too: the component's own file name is not copy.
      (hit) => !/^['"`]\.{1,2}\//.test(hit.text),
    );
    expect(hits, '\n' + report(hits) + '\n').toEqual([]);
  });

  describe('the standing notice is on every screen that shows places', () => {
    const renders = (file: string, tag: string): boolean => exists(file) && new RegExp('<' + tag + '\\b').test(code(file));

    it('the spot card', () => {
      const card = renders('components/truck/spot/SpotCard.tsx', 'PermissionNotice');
      const analysis = renders('components/truck/spot/SpotAnalysis.tsx', 'PermissionNotice');
      expect(card || analysis, 'spot/SpotCard.tsx or spot/SpotAnalysis.tsx must render PermissionNotice').toBe(true);
    });

    for (const file of [
      'components/truck/pages/SpotsPage.tsx',
      'components/truck/pages/SpotDetailPage.tsx',
      'components/truck/pages/SpotComparePage.tsx',
      'components/truck/pages/ScoutPage.tsx',
      'components/truck/pages/PlannerPage.tsx',
      'components/truck/sheet/DaySheet.tsx',
    ]) {
      it(file.replace('components/truck/', ''), () => {
        expect(exists(file), file + ' must exist').toBe(true);
        expect(renders(file, 'PermissionNotice'), file + ' must render PermissionNotice').toBe(true);
      });
    }

    it('the spot form credits OpenStreetMap where it lists places', () => {
      const file = 'components/truck/spot/SpotForm.tsx';
      expect(exists(file), file + ' must exist').toBe(true);
      expect(code(file), file + ' must render <SourceLine kinds={[... \'osm\' ...]} />').toMatch(
        /<SourceLine\b[^>]*\bkinds=\{\[[^\]]*['"]osm['"][^\]]*\]\}/,
      );
    });
  });

  it('would catch one (the guard itself works)', () => {
    const sample = 'This spot is legal. Parking is permitted here. You are allowed to park. It is zoned for it.';
    expect(BANNED.filter(({ pattern }) => pattern.test(sample)).map((b) => b.what)).toEqual([
      'legal',
      'permit',
      'allowed to ...',
      'zoned',
    ]);
    expect('Permission to trade here and local rules are yours to check.').not.toMatch(BANNED[1].pattern);
    expect('zonedToUtcStamp(timeZone)').not.toMatch(BANNED[7].pattern);
    expect('terms.allowed').not.toMatch(BANNED[2].pattern);
    expect('\u{1F69A}').toMatch(PICTOGRAPHS[0].pattern);
    expect('✓').toMatch(PICTOGRAPHS[0].pattern);
    expect('· © ° —').not.toMatch(PICTOGRAPHS[0].pattern);
    expect('AI-ranked spots').toMatch(HYPE[0].pattern);
    expect('Aim for a main path').not.toMatch(HYPE[0].pattern);
  });
});

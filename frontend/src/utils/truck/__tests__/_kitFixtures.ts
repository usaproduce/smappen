// Test helper of the kit tests (not a test): the golden cases as inputs.
//
// The golden file (tests/fixtures/truck-planner/golden_cases.json) carries complete model inputs.
// The kit tests reuse them, so a sentence or a segment is always checked against a result the model
// itself produced, never against a hand-made object.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { makeAssumptions } from '../model';
import type { Assumptions } from '../model';

/* eslint-disable @typescript-eslint/no-explicit-any */

export interface GoldenCase {
  id: string;
  family: string;
  function: string;
  args: any;
  expected: any;
}

const GOLDEN_PATH = fileURLToPath(new URL('../../../../../tests/fixtures/truck-planner/golden_cases.json', import.meta.url));

let cases: GoldenCase[] | null = null;

export function goldenCases(): GoldenCase[] {
  if (cases === null) cases = JSON.parse(readFileSync(GOLDEN_PATH, 'utf8')).cases as GoldenCase[];
  return cases;
}

export function goldenCase(id: string): GoldenCase {
  const found = goldenCases().find((c) => c.id === id);
  if (!found) throw new Error('no golden case ' + id);
  return found;
}

export function goldenFamily(fn: string): GoldenCase[] {
  return goldenCases().filter((c) => c.function === fn);
}

/** The golden `A` argument is { overrides, region }; the seed file is implied. */
export function assume(a: any): Assumptions {
  return makeAssumptions(a.overrides, a.region);
}

/** A deep copy, so a test can change an input without touching the shared cases. */
export function clone<T>(x: T): T {
  return JSON.parse(JSON.stringify(x)) as T;
}

/** A repository file as text, with line endings normalised. */
export function repoText(relativeToRepoRoot: string): string {
  const path = fileURLToPath(new URL('../../../../../' + relativeToRepoRoot, import.meta.url));
  return readFileSync(path, 'utf8').replace(/\r\n/g, '\n');
}

/** A frontend source file as text, with line endings normalised. */
export function sourceText(relativeToSrc: string): string {
  const path = fileURLToPath(new URL('../../../' + relativeToSrc, import.meta.url));
  return readFileSync(path, 'utf8').replace(/\r\n/g, '\n');
}

// -------------------------------------------------------------------------------------------------
// Worked examples of the specification
// -------------------------------------------------------------------------------------------------

let specCounter = 0;

/**
 * Marks a value the specification prints (05_FRONTEND, 02_MODEL): the caller asserts it and the file
 * counts it, so each test file can state how many worked examples of the documents it holds.
 */
export function spec<T>(actual: T): T {
  specCounter += 1;
  return actual;
}

/** How many worked examples this test file has asserted so far. */
export function specCount(): number {
  return specCounter;
}

// Truck Planner - helper of the source guards (docs/truck-planner/05_FRONTEND.md 8.3). Not a test.
//
// The guards are Vitest tests that read source files with node:fs. This module gives them:
//
//   truckFiles()            every .ts, .tsx and .css file of Truck Planner (tests excluded)
//   importClosure(entries)  those files plus everything they import, transitively
//   stripComments(source)   the source with comments blanked, so that a comment which NAMES a
//                           forbidden thing (as this one could) does not trip a guard
//
// Every path handed out is relative to frontend/src and uses forward slashes, also on Windows.

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/** frontend/src, absolute, with forward slashes. */
export const SRC: string = slash(resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..'));

function slash(path: string): string {
  return path.replace(/\\/g, '/');
}

/** Absolute path of a file given relative to frontend/src. */
export function abs(file: string): string {
  return SRC + '/' + file;
}

export function exists(file: string): boolean {
  return existsSync(abs(file));
}

const sourceCache = new Map<string, string>();

/** The text of a file given relative to frontend/src, with line endings normalised to LF. */
export function read(file: string): string {
  let text = sourceCache.get(file);
  if (text === undefined) {
    text = readFileSync(abs(file), 'utf8').replace(/\r\n?/g, '\n');
    sourceCache.set(file, text);
  }
  return text;
}

function walk(dir: string, out: string[]): void {
  if (!existsSync(abs(dir))) return;
  for (const name of readdirSync(abs(dir)).sort()) {
    const path = dir + '/' + name;
    if (statSync(abs(path)).isDirectory()) {
      if (name === '__tests__' || name === 'node_modules') continue;
      walk(path, out);
    } else if (/\.(ts|tsx|css)$/.test(name) && !/\.test\.tsx?$/.test(name)) {
      out.push(path);
    }
  }
}

/**
 * Every source file of Truck Planner: all .ts, .tsx and .css files under components/truck/ and
 * utils/truck/ (the estimator port and the seed copy included), api/truck.ts and stores/truck*.ts.
 * Test folders are left out.
 */
export function truckFiles(): string[] {
  const out: string[] = [];
  walk('components/truck', out);
  walk('utils/truck', out);
  if (exists('api/truck.ts')) out.push('api/truck.ts');
  if (existsSync(abs('stores'))) {
    for (const name of readdirSync(abs('stores')).sort()) {
      if (/^truck.*\.tsx?$/.test(name)) out.push('stores/' + name);
    }
  }
  return out.sort();
}

/** True for a file that `truckFiles()` would list (whether or not it exists). */
export function isTruckFile(file: string): boolean {
  return (
    file.startsWith('components/truck/') ||
    file.startsWith('utils/truck/') ||
    file === 'api/truck.ts' ||
    /^stores\/truck[^/]*\.tsx?$/.test(file)
  );
}

// -------------------------------------------------------------------------------------------------
// Comments
// -------------------------------------------------------------------------------------------------

// After these a `/` starts a regular expression. `<` and `>` are left out on purpose: in a .tsx file
// they are almost always JSX (`</div>`), and a comparison followed by a regular expression is not.
const REGEX_MAY_FOLLOW = new Set(['(', ',', '=', ':', '[', '!', '&', '|', '?', '{', '}', ';', '+', '-', '*', '%', '~', '^']);
const REGEX_KEYWORDS = /(?:^|[^\w$.])(return|typeof|case|in|of|delete|void|throw|new|else|do)$/;

/**
 * The source with every comment replaced by spaces. Line breaks are kept, so line numbers and
 * columns of what remains are unchanged. String, template and regular-expression literals are left
 * exactly as they are (a `//` inside a URL is not a comment).
 *
 * It is a scanner, not a parser. Two things keep it honest on real .tsx files: a quote that is not
 * closed on its own line is not a string delimiter (it is an apostrophe in JSX text), and a `/`
 * starts a regular expression only where an expression can start.
 */
export function stripComments(source: string): string {
  const text = source.replace(/\r\n?/g, '\n');
  const n = text.length;
  let out = '';
  let i = 0;
  // Depth of `${ ... }` nesting inside template literals: each entry counts open braces.
  const templateBraces: number[] = [];
  let lastSignificant = '';

  const blank = (from: number, to: number): string => {
    let s = '';
    for (let k = from; k < to; k++) s += text[k] === '\n' ? '\n' : ' ';
    return s;
  };

  while (i < n) {
    const c = text[i];
    const next = i + 1 < n ? text[i + 1] : '';

    // Line comment
    if (c === '/' && next === '/') {
      let j = i;
      while (j < n && text[j] !== '\n') j++;
      out += blank(i, j);
      i = j;
      continue;
    }
    // Block comment
    if (c === '/' && next === '*') {
      let j = text.indexOf('*/', i + 2);
      j = j === -1 ? n : j + 2;
      out += blank(i, j);
      i = j;
      continue;
    }
    // Single- or double-quoted string: only when it closes on the same line.
    if (c === '"' || c === "'") {
      let j = i + 1;
      let closed = false;
      while (j < n && text[j] !== '\n') {
        if (text[j] === '\\') {
          j += 2;
          continue;
        }
        if (text[j] === c) {
          closed = true;
          break;
        }
        j++;
      }
      if (closed) {
        out += text.slice(i, j + 1);
        i = j + 1;
        lastSignificant = c;
        continue;
      }
      out += c;
      i++;
      continue;
    }
    // Template literal: copy text, but scan `${ ... }` as code.
    if (c === '`') {
      out += c;
      i++;
      let done = false;
      while (i < n && !done) {
        const t = text[i];
        if (t === '\\') {
          out += text.slice(i, i + 2);
          i += 2;
        } else if (t === '`') {
          out += t;
          i++;
          done = true;
        } else if (t === '$' && text[i + 1] === '{') {
          out += '${';
          i += 2;
          templateBraces.push(0);
          done = true; // back to code until the matching `}`
        } else {
          out += t;
          i++;
        }
      }
      lastSignificant = '`';
      continue;
    }
    if (templateBraces.length > 0) {
      if (c === '{') {
        templateBraces[templateBraces.length - 1]++;
      } else if (c === '}') {
        if (templateBraces[templateBraces.length - 1] === 0) {
          templateBraces.pop();
          out += c;
          i++;
          // Continue the template literal text.
          let done = false;
          while (i < n && !done) {
            const t = text[i];
            if (t === '\\') {
              out += text.slice(i, i + 2);
              i += 2;
            } else if (t === '`') {
              out += t;
              i++;
              done = true;
            } else if (t === '$' && text[i + 1] === '{') {
              out += '${';
              i += 2;
              templateBraces.push(0);
              done = true;
            } else {
              out += t;
              i++;
            }
          }
          lastSignificant = '`';
          continue;
        }
        templateBraces[templateBraces.length - 1]--;
      }
    }
    // Regular-expression literal
    if (c === '/' && (lastSignificant === '' || REGEX_MAY_FOLLOW.has(lastSignificant) || REGEX_KEYWORDS.test(out.slice(-12).trimEnd()))) {
      let j = i + 1;
      let inClass = false;
      let closed = false;
      while (j < n && text[j] !== '\n') {
        if (text[j] === '\\') {
          j += 2;
          continue;
        }
        if (text[j] === '[') inClass = true;
        else if (text[j] === ']') inClass = false;
        else if (text[j] === '/' && !inClass) {
          closed = true;
          break;
        }
        j++;
      }
      if (closed) {
        out += text.slice(i, j + 1);
        i = j + 1;
        lastSignificant = '/';
        continue;
      }
    }
    out += c;
    if (c !== ' ' && c !== '\t' && c !== '\n') lastSignificant = c;
    i++;
  }
  return out;
}

/** A stylesheet with its comments blanked. CSS has block comments only: `//` there is part of a URL. */
export function stripCssComments(source: string): string {
  const text = source.replace(/\r\n?/g, '\n');
  return text.replace(/\/\*[\s\S]*?\*\//g, (comment) => comment.replace(/[^\n]/g, ' '));
}

const codeCache = new Map<string, string>();

/** The comment-free text of a source file. */
export function code(file: string): string {
  let text = codeCache.get(file);
  if (text === undefined) {
    text = file.endsWith('.css') ? stripCssComments(read(file)) : stripComments(read(file));
    codeCache.set(file, text);
  }
  return text;
}

/** 1-based line number of an index into a text. */
export function lineOf(text: string, index: number): number {
  let line = 1;
  for (let i = 0; i < index && i < text.length; i++) {
    if (text[i] === '\n') line++;
  }
  return line;
}

// -------------------------------------------------------------------------------------------------
// Imports
// -------------------------------------------------------------------------------------------------

export interface ImportRef {
  /** The specifier as written. */
  specifier: string;
  /** `static` for `import ... from`, `export ... from` and `import '...'`; `dynamic` for `import('...')`. */
  kind: 'static' | 'dynamic';
  /** The file it resolves to (relative to frontend/src), or null for a package or a file that does not exist. */
  file: string | null;
  /** True for a bare specifier: a package such as `react` or `zustand/middleware`. */
  bare: boolean;
}

const STATIC_IMPORT = /(?:^|[\n;])\s*import\s+(?:type\s+)?(?:[\w$*\s{},]+?\s+from\s+)?(['"])([^'"\n]+)\1/g;
const STATIC_EXPORT = /(?:^|[\n;])\s*export\s+(?:type\s+)?(?:\*(?:\s+as\s+[\w$]+)?|\{[^}]*\})\s*from\s+(['"])([^'"\n]+)\1/g;
const DYNAMIC_IMPORT = /\bimport\s*\(\s*(['"`])([^'"`\n]+)\1\s*\)/g;
const CSS_IMPORT = /@import\s+(?:url\(\s*)?(['"])([^'"\n]+)\1/g;

const RESOLVE_SUFFIXES = ['', '.ts', '.tsx', '/index.ts', '/index.tsx'];

function resolveRelative(from: string, specifier: string): string | null {
  const base = slash(join(dirname(from), specifier));
  for (const suffix of RESOLVE_SUFFIXES) {
    const candidate = base + suffix;
    if (existsSync(abs(candidate)) && statSync(abs(candidate)).isFile()) return candidate;
  }
  return null;
}

const importCache = new Map<string, ImportRef[]>();

/** Every import of one file: static imports and re-exports (type-only ones included) and dynamic imports. */
export function importsOf(file: string): ImportRef[] {
  const cached = importCache.get(file);
  if (cached !== undefined) return cached;
  const refs: ImportRef[] = [];
  const text = code(file);
  const add = (specifier: string, kind: 'static' | 'dynamic') => {
    const bare = !specifier.startsWith('.') && !specifier.startsWith('/');
    refs.push({ specifier, kind, bare, file: bare ? null : resolveRelative(file, specifier) });
  };
  if (file.endsWith('.css')) {
    for (const m of text.matchAll(CSS_IMPORT)) add(m[2], 'static');
  } else {
    for (const m of text.matchAll(STATIC_IMPORT)) add(m[2], 'static');
    for (const m of text.matchAll(STATIC_EXPORT)) add(m[2], 'static');
    for (const m of text.matchAll(DYNAMIC_IMPORT)) add(m[2], 'dynamic');
  }
  importCache.set(file, refs);
  return refs;
}

export interface Closure {
  /** Every file reached, the entries included, sorted. */
  files: string[];
  /** Every bare specifier imported anywhere in the closure, sorted. */
  packages: string[];
  /** Relative specifiers that resolve to no file: `<importer> -> <specifier>`. A guard treats these as failures. */
  unresolved: string[];
  /** How a file was reached: the chain of files from an entry to it. */
  chain(file: string): string[];
  /** Which files of the closure import a package. */
  importersOf(pkg: string): string[];
}

/**
 * The import closure of some entry files: the files themselves and everything they import through
 * relative specifiers, transitively. Static imports and re-exports are always followed; dynamic
 * `import()` calls are followed unless `dynamic: false` (the eager-graph guard wants exactly what a
 * bundler puts in the same chunk). Bare specifiers are recorded as packages and not followed.
 */
export function importClosure(entries: readonly string[], options: { dynamic?: boolean } = {}): Closure {
  const followDynamic = options.dynamic !== false;
  const via = new Map<string, string | null>();
  const packages = new Map<string, Set<string>>();
  const unresolved: string[] = [];
  const queue: string[] = [];
  for (const entry of entries) {
    if (!via.has(entry)) {
      via.set(entry, null);
      queue.push(entry);
    }
  }
  while (queue.length > 0) {
    const file = queue.shift() as string;
    for (const ref of importsOf(file)) {
      if (ref.kind === 'dynamic' && !followDynamic) continue;
      if (ref.bare) {
        let set = packages.get(ref.specifier);
        if (set === undefined) {
          set = new Set<string>();
          packages.set(ref.specifier, set);
        }
        set.add(file);
        continue;
      }
      if (ref.file === null) {
        unresolved.push(file + ' -> ' + ref.specifier);
        continue;
      }
      if (!via.has(ref.file)) {
        via.set(ref.file, file);
        if (/\.(ts|tsx|css)$/.test(ref.file)) queue.push(ref.file);
      }
    }
  }
  return {
    files: [...via.keys()].sort(),
    packages: [...packages.keys()].sort(),
    unresolved,
    chain(file: string): string[] {
      const out: string[] = [];
      let at: string | null | undefined = file;
      while (at !== null && at !== undefined) {
        out.unshift(at);
        at = via.get(at);
      }
      return out;
    },
    importersOf(pkg: string): string[] {
      return [...(packages.get(pkg) ?? [])].sort();
    },
  };
}

/** The package of a bare specifier: `zustand/middleware` -> `zustand`, `@scope/name/x` -> `@scope/name`. */
export function packageOf(specifier: string): string {
  const parts = specifier.split('/');
  return specifier.startsWith('@') ? parts.slice(0, 2).join('/') : parts[0];
}

// -------------------------------------------------------------------------------------------------
// Reporting
// -------------------------------------------------------------------------------------------------

export interface Hit {
  file: string;
  line: number;
  what: string;
  text: string;
}

/** Every match of a pattern in the comment-free text of some files. */
export function scan(files: readonly string[], patterns: readonly { what: string; pattern: RegExp }[]): Hit[] {
  const hits: Hit[] = [];
  for (const file of files) {
    if (!/\.(ts|tsx|css)$/.test(file)) continue;
    const text = code(file);
    const lines = text.split('\n');
    for (const { what, pattern } of patterns) {
      const flags = pattern.flags.includes('g') ? pattern.flags : pattern.flags + 'g';
      const re = new RegExp(pattern.source, flags);
      for (const m of text.matchAll(re)) {
        const line = lineOf(text, m.index ?? 0);
        hits.push({ file, line, what, text: (lines[line - 1] ?? '').trim().slice(0, 160) });
      }
    }
  }
  return hits;
}

/** A readable list of hits for an assertion message. */
export function report(hits: readonly Hit[]): string {
  return hits.map((h) => h.file + ':' + h.line + '  ' + h.what + '  |  ' + h.text).join('\n');
}

/** A literal string as a regular expression source. */
export function literal(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
}

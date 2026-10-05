// Source rules of the pipeline: one dependency, no randomness, no wall clock in what is written, no AI service,
// requests only to the documented download hosts and only from the download and tile-fetch modules.

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const PACKAGE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function sources(...dirs) {
  const out = [];
  for (const dir of dirs) {
    for (const name of fs.readdirSync(path.join(PACKAGE, dir)).sort()) {
      if (name.endsWith('.mjs')) out.push({ file: `${dir}/${name}`, text: fs.readFileSync(path.join(PACKAGE, dir, name), 'utf8') });
    }
  }
  return out;
}

/** Source text without comments (good enough for these files: no comment markers inside string literals that matter). */
function code(text) {
  return text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:'"`\\])\/\/.*$/gm, '$1');
}

test('package: ES modules, Node 20 or newer, h3-js is the only dependency', () => {
  const pkg = JSON.parse(fs.readFileSync(path.join(PACKAGE, 'package.json'), 'utf8'));
  assert.equal(pkg.type, 'module');
  assert.equal(pkg.private, true);
  assert.deepEqual(pkg.dependencies, { 'h3-js': '4.5.0' });
  assert.equal(pkg.devDependencies, undefined);
  assert.equal(pkg.engines.node, '>=20');
  assert.ok(pkg.scripts['build:dc'] && pkg.scripts.test);
  const lock = JSON.parse(fs.readFileSync(path.join(PACKAGE, 'package-lock.json'), 'utf8'));
  assert.deepEqual(Object.keys(lock.packages).sort(), ['', 'node_modules/h3-js']);
  // imports: node built-ins, h3-js and relative modules only
  for (const { file, text } of sources('src', 'bin', 'test-support')) {
    for (const m of text.matchAll(/^\s*import\s[^'"]*['"]([^'"]+)['"]/gm)) {
      assert.ok(m[1].startsWith('node:') || m[1] === 'h3-js' || m[1].startsWith('.'), `${file} imports ${m[1]}`);
    }
    assert.ok(!/\brequire\s*\(/.test(code(text)), `${file} uses require()`);
  }
});

test('no randomness anywhere, and no wall clock outside the download bookkeeping', () => {
  for (const { file, text } of sources('src', 'bin')) {
    const c = code(text);
    assert.ok(!/Math\.random|randomUUID|randomBytes|randomInt|getRandomValues/.test(c), `${file} uses randomness`);
    const clock = /\bDate\.now\s*\(|new Date\s*\(\s*\)|performance\.now|process\.hrtime/.test(c);
    // index.json records fetched_at (never the manifest); the command line prints elapsed time; the tile helper names a folder by date
    const allowed = ['src/download.mjs', 'bin/build-region.mjs', 'bin/fetch-overpass-tiles.mjs'];
    assert.ok(!clock || allowed.includes(file), `${file} reads the clock`);
    assert.ok(!/toLocale(String|DateString|TimeString|UpperCase|LowerCase)|Intl\./.test(c), `${file} depends on the locale`);
  }
});

test('network: only the documented download hosts, only from the download and tile-fetch modules', () => {
  const hosts = new Set();
  for (const { file, text } of sources('src', 'bin')) {
    const c = code(text);
    for (const m of c.matchAll(/https?:\/\/([a-z0-9.-]+)/gi)) {
      if (m[1] === 'www.google.com') {
        assert.equal(file, 'src/review.mjs', `${file} names www.google.com`); // the review list carries a map link, nothing is requested
        continue;
      }
      hosts.add(m[1]);
    }
    const requests = /\bfetch(Impl)?\s*\(|node:https?['"]|XMLHttpRequest|node:net['"]|node:dgram['"]/.test(c);
    assert.ok(!requests || ['src/download.mjs', 'src/overpass.mjs'].includes(file), `${file} makes requests`);
    assert.ok(!/dotenv|\.env['"`]/.test(c), `${file} reads a .env file`);
    assert.ok(!/navigator\.geolocation|watchPosition|getCurrentPosition/.test(c), `${file} asks for a position`);
  }
  assert.deepEqual([...hosts].sort(), [
    'download.geofabrik.de', 'lehd.ces.census.gov', 'overpass-api.de', 'tigerweb.geo.census.gov', 'www2.census.gov',
  ]);
});

test('no AI or machine-learning service, SDK or key name', () => {
  const forbidden = /anthropic|openai|generativelanguage|aiplatform|bedrock|sagemaker|mistral|cohere|groq|together\.xyz|openrouter|perplexity|deepseek|fireworks|replicate|huggingface|voyageai|ai21|ollama|ml-sidecar|tensorflow|onnx|\bllm\b|claude|gpt-|gemini/i;
  for (const { file, text } of sources('src', 'bin', 'test-support')) {
    assert.ok(!forbidden.test(text), `${file} mentions an AI service`);
  }
});

test('files of the package use LF line endings and carry no byte order mark', () => {
  const check = (rel) => {
    const buf = fs.readFileSync(path.join(PACKAGE, rel));
    assert.ok(!buf.includes(0x0d), `${rel} holds a carriage return`);
    assert.notDeepEqual([buf[0], buf[1], buf[2]], [0xef, 0xbb, 0xbf], `${rel} starts with a byte order mark`);
    assert.equal(buf[buf.length - 1], 0x0a, `${rel} has no final newline`);
  };
  for (const rel of ['regions/dc.json', 'corrections/dc.jobs.json', 'test/fixtures/mini/mini.json', 'test/fixtures/mini/mini.jobs.json',
    'test/fixtures/mini/seeds.json', 'test/fixtures/mini/expected.json', 'test/fixtures/mini/expected-cells.tsv']) check(rel);
  const attributes = fs.readFileSync(path.join(PACKAGE, '.gitattributes'), 'utf8');
  assert.match(attributes, /^test\/fixtures\/\*\* -text$/m);
  assert.match(attributes, /^regions\/\*\.json text eol=lf$/m);
  assert.match(attributes, /^corrections\/\*\.json text eol=lf$/m);
});

test('every module the specification lists exists', () => {
  const expected = ['download', 'zip', 'pl', 'csv', 'lodes', 'pbf', 'overpass', 'counties', 'taxonomy', 'hours', 'normalise', 'dedupe', 'corrections',
    'points', 'cells', 'geo', 'seeds', 'gates', 'manifest', 'args', 'blocks', 'places', 'review', 'writers', 'region', 'vocabulary', 'pipeline'];
  const present = fs.readdirSync(path.join(PACKAGE, 'src')).filter((n) => n.endsWith('.mjs')).map((n) => n.replace(/\.mjs$/, '')).sort();
  assert.deepEqual(present, expected.slice().sort());
  for (const bin of ['build-region.mjs', 'fetch-overpass-tiles.mjs', 'adopt-raw.mjs']) assert.ok(fs.existsSync(path.join(PACKAGE, 'bin', bin)));
  for (const file of ['regions/dc.json', 'corrections/dc.jobs.json', 'README.md', 'package.json']) assert.ok(fs.existsSync(path.join(PACKAGE, file)), file);
});

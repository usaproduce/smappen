// Source guard: no AI at runtime (docs/truck-planner/05_FRONTEND.md rule R3 and 8.3).
//
// Every figure Truck Planner shows is ordinary arithmetic on public data and the owner's settings.
// This test fails the build when Truck Planner code, or anything it imports, names a host, package,
// key or first-party endpoint that reaches a language model or a machine-learning service.
// The bare word "model" is not banned: the estimator is one.

import { describe, expect, it } from 'vitest';
import { importClosure, literal, packageOf, report, scan, truckFiles } from './_closure';

const HOSTS = [
  'api.anthropic.com',
  'api.openai.com',
  'openai.azure.com',
  'generativelanguage.googleapis.com',
  'aiplatform.googleapis.com',
  'bedrock',
  'sagemaker',
  'api.mistral.ai',
  'api.cohere.',
  'api.groq.com',
  'api.together.',
  'openrouter.ai',
  'api.perplexity.ai',
  'api.x.ai',
  'api.deepseek.com',
  'api.fireworks.ai',
  'api.replicate.com',
  'huggingface.co',
  'api.voyageai.com',
  'api.ai21.com',
  ':11434',
  ':8088',
  'ml-sidecar',
];

const PACKAGES = [
  '@anthropic-ai/',
  'openai',
  '@google/generative-ai',
  'langchain',
  '@huggingface/',
  'cohere-ai',
  '@mistralai/',
  'ollama',
  '@tensorflow/',
  'onnxruntime',
];

const KEY_NAMES = ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY'];

const ENDPOINTS = [
  '/ai-score',
  '/ai-rankings',
  '/dashboard/briefing',
  '/recommendations/run',
  '/recommend',
  '/restaurants/sample',
  '/pos/',
];

// First-party classes and job types that reach a language model today, on word boundaries.
const FIRST_PARTY = [
  'AiScoringController',
  'OpsController',
  'MenuEngineeringService',
  'MenuEngineeringController',
  'SampleDataService',
  'pos.sync',
];

const TEXT_PATTERNS = [
  ...HOSTS.map((host) => ({ what: 'host ' + host, pattern: new RegExp(literal(host), 'i') })),
  ...PACKAGES.map((pkg) => ({ what: 'package ' + pkg, pattern: new RegExp(literal(pkg), 'i') })),
  ...KEY_NAMES.map((key) => ({ what: 'key ' + key, pattern: new RegExp(literal(key)) })),
  ...ENDPOINTS.map((path) => ({ what: 'endpoint ' + path, pattern: new RegExp(literal(path)) })),
  ...FIRST_PARTY.map((name) => ({ what: 'first-party ' + name, pattern: new RegExp('\\b' + literal(name) + '\\b') })),
];

describe('guard: no AI at runtime', () => {
  const files = truckFiles();
  const closure = importClosure(files);

  it('covers the truck files and their import closure', () => {
    expect(files.length).toBeGreaterThan(20);
    expect(closure.files).toContain('api/truck.ts');
    expect(closure.files).toContain('api/client.ts');
    expect(closure.unresolved, 'imports that resolve to no file').toEqual([]);
  });

  it('names no model host, SDK, key or AI endpoint', () => {
    const hits = scan(closure.files, TEXT_PATTERNS);
    expect(hits, '\n' + report(hits) + '\n').toEqual([]);
  });

  it('imports no AI or machine-learning package', () => {
    const bad = closure.packages.filter((specifier) =>
      PACKAGES.some((pkg) => {
        const name = pkg.endsWith('/') ? pkg.slice(0, -1) : pkg;
        const own = packageOf(specifier);
        return own === name || own.startsWith(name + '/') || specifier.startsWith(pkg);
      }),
    );
    const detail = bad.map((pkg) => pkg + ' <- ' + closure.importersOf(pkg).join(', '));
    expect(detail).toEqual([]);
  });

  it('never reaches the modules that call an AI endpoint', () => {
    // The carafe barrel reaches api/restaurants.ts; the dashboard asks for an AI briefing.
    for (const file of ['api/restaurants.ts', 'api/dashboard.ts', 'components/carafe/index.ts']) {
      expect(closure.files, closure.chain(file).join(' -> ')).not.toContain(file);
    }
  });

  it('would catch one (the guard itself works)', () => {
    expect(scan(['api/dashboard.ts'], TEXT_PATTERNS).length).toBeGreaterThan(0);
  });
});

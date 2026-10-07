// Truck Planner - the shell's own logic that runs without a DOM: the error helpers and query keys of
// api/truck.ts, the retry rule, and the three stores (docs/truck-planner/05_FRONTEND.md 2.1 to 2.6).

import { beforeEach, describe, expect, it, vi } from 'vitest';

// The persisted store needs a localStorage, and Node has none. vi.hoisted runs before the imports
// below, so the store finds this in-memory one when it is created.
const storage = vi.hoisted(() => {
  const items = new Map<string, string>();
  (globalThis as { localStorage?: unknown }).localStorage = {
    getItem: (key: string) => (items.has(key) ? (items.get(key) as string) : null),
    setItem: (key: string, value: string) => {
      items.set(key, String(value));
    },
    removeItem: (key: string) => {
      items.delete(key);
    },
    clear: () => items.clear(),
    key: () => null,
    get length() {
      return items.size;
    },
  };
  return { items };
});

import {
  RATE_LIMIT_SENTENCE,
  REGION_REBUILD_SENTENCE,
  apiErrorDetails,
  apiErrorMessage,
  apiErrorStatus,
  isNoTruckError,
  isRegionRebuildError,
  truckApi,
  truckKeys,
  type Plan,
  type PlanStop,
} from '../../../api/truck';
import { truckRetry } from '../../../components/truck/data/queryPolicy';
import { patchChangesVectors } from '../../../components/truck/data/mutations';
import { planFromRow } from '../../../components/truck/data/usePlans';
import { resetTruckHourStore, useTruckHourStore } from '../../../stores/truckHourStore';
import {
  draftFromPlan,
  emptyPlanDraft,
  isTemporaryStopId,
  newDraftStopId,
  resetTruckPlanDraftStore,
  useTruckPlanDraftStore,
} from '../../../stores/truckPlanDraftStore';
import { resetTruckUiStore, useTruckUiStore } from '../../../stores/truckUiStore';

/** What axios rejects with when the server answered. */
function failed(status: number, error?: unknown, details?: unknown) {
  return { response: { status, data: error === undefined ? {} : { success: false, error, details } } };
}
/** What axios rejects with when there was no answer at all. */
const offline = { message: 'Network Error' };

describe('apiErrorMessage', () => {
  it('shows the server sentence when there is one, else the fallback', () => {
    expect(apiErrorMessage(failed(409, 'You can keep at most 500 spots'), 'Could not save the spot.')).toBe('You can keep at most 500 spots');
    expect(apiErrorMessage(failed(422, 'avg_ticket must be a number between 1 and 200'), 'x')).toBe('avg_ticket must be a number between 1 and 200');
    expect(apiErrorMessage(failed(500), 'Could not save the spot.')).toBe('Could not save the spot.');
    expect(apiErrorMessage(failed(500, ''), 'Could not save the spot.')).toBe('Could not save the spot.');
    expect(apiErrorMessage(failed(500, { nested: true }), 'Could not save the spot.')).toBe('Could not save the spot.');
    expect(apiErrorMessage({ response: { status: 502, data: '<html>Bad gateway</html>' } }, 'Could not load Scout.')).toBe('Could not load Scout.');
  });

  it('is null when there was no response: the shared client has already said "Connection lost"', () => {
    expect(apiErrorMessage(offline, 'Could not save the spot.')).toBeNull();
    expect(apiErrorMessage(new Error('boom'), 'x')).toBeNull();
    expect(apiErrorMessage(null, 'x')).toBeNull();
    expect(apiErrorMessage(undefined, 'x')).toBeNull();
    expect(apiErrorMessage('a string', 'x')).toBeNull();
  });

  it('never shows the rate-limit middleware sentence', () => {
    expect(apiErrorMessage(failed(429, 'Rate limit reached for tp_drive: 240 per 3600 s'), 'x')).toBe(RATE_LIMIT_SENTENCE);
    expect(RATE_LIMIT_SENTENCE).toBe('Too many requests right now. Try again in a minute.');
    // Any other 429 sentence is shown as sent.
    expect(apiErrorMessage(failed(429, 'The Google drive-time allowance is used up for now.'), 'x')).toBe('The Google drive-time allowance is used up for now.');
    // The same words under another status are not the middleware.
    expect(apiErrorMessage(failed(400, 'Rate limit reached'), 'x')).toBe('Rate limit reached');
  });

  it('never shows what a 501 says: a route that is not built yet reads like any failed request', () => {
    expect(apiErrorMessage(failed(501, 'Not implemented yet'), 'Could not load your results here.')).toBe('Could not load your results here.');
    expect(apiErrorMessage(failed(501), 'x')).toBe('x');
    // The same words under another status are a server sentence like any other.
    expect(apiErrorMessage(failed(500, 'Not implemented yet'), 'x')).toBe('Not implemented yet');
  });

  it('never shows the region rebuild text of the server', () => {
    const e = failed(409, 'Region data was built with different model constants');
    expect(apiErrorMessage(e, 'x')).toBe(REGION_REBUILD_SENTENCE);
    expect(REGION_REBUILD_SENTENCE).toBe('Map data is being rebuilt after an update. New estimates are unavailable until it finishes.');
    expect(isRegionRebuildError(e)).toBe(true);
    expect(isRegionRebuildError(failed(409, 'Set up your truck first'))).toBe(false);
    expect(isRegionRebuildError(failed(500, 'Region data was built with different model constants'))).toBe(false);
  });
});

describe('the other error helpers', () => {
  it('apiErrorStatus is the status, or null without a response', () => {
    expect(apiErrorStatus(failed(404, 'Spot not found'))).toBe(404);
    expect(apiErrorStatus(offline)).toBeNull();
    expect(apiErrorStatus(null)).toBeNull();
  });

  it('apiErrorDetails is the details of a 422', () => {
    const details = [{ path: 'kernel.outside_option_a0', error: 'not_overridable' }];
    expect(apiErrorDetails(failed(422, 'overrides.kernel.outside_option_a0: not_overridable', details))).toEqual(details);
    expect(apiErrorDetails(failed(422, 'name is required', { field: 'name', code: 'V1' }))).toEqual({ field: 'name', code: 'V1' });
    expect(apiErrorDetails(failed(422, 'Nothing to update'))).toBeNull();
    expect(apiErrorDetails(offline)).toBeNull();
  });

  it('isNoTruckError recognises the 409 that brings the first-run step back, and nothing else', () => {
    expect(isNoTruckError(failed(409, 'Set up your truck first'))).toBe(true);
    expect(isNoTruckError(failed(409, 'A plan already exists for this date'))).toBe(false);
    expect(isNoTruckError(failed(403, 'Set up your truck first'))).toBe(false);
    expect(isNoTruckError(offline)).toBe(false);
  });
});

describe('truckRetry', () => {
  it('retries once when there was no answer or a 5xx answer', () => {
    expect(truckRetry(0, offline)).toBe(true);
    expect(truckRetry(1, offline)).toBe(false);
    expect(truckRetry(0, failed(500))).toBe(true);
    expect(truckRetry(0, failed(503, 'Contact lookup is not available on this server'))).toBe(true);
    expect(truckRetry(1, failed(500))).toBe(false);
  });

  it('never retries a 4xx answer or a 501', () => {
    for (const status of [400, 403, 404, 409, 413, 422, 429, 501]) {
      expect(truckRetry(0, failed(status, 'x')), String(status)).toBe(false);
    }
  });
});

describe('truckKeys', () => {
  it('has the shapes of 2.2', () => {
    expect(truckKeys.all).toEqual(['truck']);
    expect(truckKeys.bootstrap()).toEqual(['truck', 'bootstrap']);
    expect(truckKeys.pack('/api/truck/regions/dc/pack/v1')).toEqual(['truck', 'pack', '/api/truck/regions/dc/pack/v1']);
    expect(truckKeys.simulate('dc-1', '38.960000', '-77.360000', '-', 'normal')).toEqual(['truck', 'simulate', 'dc-1', '38.960000', '-77.360000', '-', 'normal']);
    expect(truckKeys.spots(false)).toEqual(['truck', 'spots', 'list', false]);
    expect(truckKeys.spot('a')).toEqual(['truck', 'spots', 'one', 'a']);
    expect(truckKeys.dayContext('2026-10-08', 2)).toEqual(['truck', 'day-context', '2026-10-08', 2]);
    expect(truckKeys.driveTimes('k')).toEqual(['truck', 'drive-times', 'k']);
    expect(truckKeys.plans('2026-10-05', '2026-10-11', true)).toEqual(['truck', 'plans', 'list', '2026-10-05', '2026-10-11', true]);
    expect(truckKeys.plan('p')).toEqual(['truck', 'plans', 'one', 'p']);
    expect(truckKeys.suggestDay('2026-10-08', '{}')).toEqual(['truck', 'suggest', 'day', '2026-10-08', '{}']);
    expect(truckKeys.suggestWeek('2026-10-05', '{}')).toEqual(['truck', 'suggest', 'week', '2026-10-05', '{}']);
    expect(truckKeys.services('', '', '')).toEqual(['truck', 'services', '', '', '']);
    expect(truckKeys.accuracy('', '')).toEqual(['truck', 'accuracy', '', '']);
    expect(truckKeys.scout('declined,hidden')).toEqual(['truck', 'scout', 'declined,hidden']);
    expect(truckKeys.sources()).toEqual(['truck', 'sources']);
  });

  it('every key starts with the prefix that invalidates all of Truck Planner', () => {
    const keys = [truckKeys.bootstrap(), truckKeys.spots(true), truckKeys.plan('p'), truckKeys.scout(''), truckKeys.sources()];
    for (const key of keys) expect(key[0]).toBe(truckKeys.all[0]);
  });
});

describe('truckApi.fetchPack', () => {
  it('refuses a url that is not a pack path of this API, before any request', async () => {
    await expect(truckApi.fetchPack('https://example.com/pack')).rejects.toThrow('not a pack url');
    await expect(truckApi.fetchPack('/api/restaurants/1')).rejects.toThrow('not a pack url');
    await expect(truckApi.fetchPack('/api/truck/export')).rejects.toThrow('not a pack url');
    await expect(truckApi.fetchPack('')).rejects.toThrow('not a pack url');
  });
});

describe('truckHourStore', () => {
  beforeEach(() => resetTruckHourStore());

  it('starts on Thursday 12 PM, a typical week, not playing', () => {
    const s = useTruckHourStore.getState();
    expect(s.how).toBe(84);
    expect(s.playing).toBe(false);
    expect(s.date).toBeNull();
  });

  it('setHow wraps modulo 168', () => {
    const { setHow } = useTruckHourStore.getState();
    setHow(168);
    expect(useTruckHourStore.getState().how).toBe(0);
    setHow(-1);
    expect(useTruckHourStore.getState().how).toBe(167);
    setHow(170.9);
    expect(useTruckHourStore.getState().how).toBe(2);
    setHow(Number.NaN);
    expect(useTruckHourStore.getState().how).toBe(2);
  });

  it('step wraps from Sunday 11 PM to Monday 12 AM and back', () => {
    const { setHow, step } = useTruckHourStore.getState();
    setHow(167);
    step(1);
    expect(useTruckHourStore.getState().how).toBe(0);
    step(-1);
    expect(useTruckHourStore.getState().how).toBe(167);
    step(24);
    expect(useTruckHourStore.getState().how).toBe(23);
  });

  it('notifies nobody when the hour is unchanged', () => {
    let calls = 0;
    const off = useTruckHourStore.subscribe(() => {
      calls += 1;
    });
    const { setHow, setPlaying, setDate } = useTruckHourStore.getState();
    setHow(84);
    setHow(84 + 168);
    setPlaying(false);
    setDate(null);
    expect(calls).toBe(0);
    setHow(85);
    setPlaying(true);
    expect(calls).toBe(2);
    off();
  });

  it('the subscriber sees the previous state, as the map layer needs', () => {
    const seen: [number, number][] = [];
    const off = useTruckHourStore.subscribe((s, prev) => {
      if (s.how !== prev.how) seen.push([prev.how, s.how]);
    });
    useTruckHourStore.getState().step(1);
    useTruckHourStore.getState().step(1);
    off();
    expect(seen).toEqual([[84, 85], [85, 86]]);
  });

  it('setDate moves to that date\'s day of the week and keeps the hour', () => {
    const { setHow, setDate } = useTruckHourStore.getState();
    setHow(3 * 24 + 17); // Thursday 5 PM
    setDate('2026-10-10'); // a Saturday
    expect(useTruckHourStore.getState().how).toBe(5 * 24 + 17);
    expect(useTruckHourStore.getState().date).toBe('2026-10-10');
    setDate('2026-10-05'); // a Monday
    expect(useTruckHourStore.getState().how).toBe(17);
    setDate(null);
    expect(useTruckHourStore.getState().date).toBeNull();
    expect(useTruckHourStore.getState().how).toBe(17);
  });
});

describe('truckUiStore', () => {
  beforeEach(() => resetTruckUiStore());

  it('has the defaults of 2.4', () => {
    const s = useTruckUiStore.getState();
    expect(s.mapLayer).toBe('opportunity');
    expect(s.mapCamera).toBeNull();
    expect(s.lastHow).toBeNull();
    expect(s.playSpeedMs).toBe(600);
    expect(s.showSpotPins).toBe(true);
    expect(s.showScoutDots).toBe(false);
    expect(s.windowHours).toBe(3);
    expect(s.compareIds).toEqual([]);
    expect(s.mapsAuthFailed).toBe(false);
  });

  it('patch changes fields and keeps at most four spots to compare', () => {
    useTruckUiStore.getState().patch({ mapLayer: 'people', windowHours: 4, mapsAuthFailed: true });
    expect(useTruckUiStore.getState().mapLayer).toBe('people');
    expect(useTruckUiStore.getState().windowHours).toBe(4);
    expect(useTruckUiStore.getState().mapsAuthFailed).toBe(true);
    useTruckUiStore.getState().patch({ compareIds: ['a', 'b', 'c', 'd', 'e'] });
    expect(useTruckUiStore.getState().compareIds).toEqual(['a', 'b', 'c', 'd']);
  });

  it('persists under smappen-truck-ui: the allow-list of 2.4 and never the auth failure', () => {
    useTruckUiStore.getState().patch({ mapLayer: 'competition', lastHow: 100, mapsAuthFailed: true });
    const written = JSON.parse(storage.items.get('smappen-truck-ui') as string) as { state: Record<string, unknown> };
    expect(Object.keys(written.state).sort()).toEqual(
      ['compareIds', 'lastHow', 'mapCamera', 'mapLayer', 'playSpeedMs', 'showScoutDots', 'showSpotPins', 'windowHours'],
    );
    expect(written.state.mapLayer).toBe('competition');
    expect(written.state.lastHow).toBe(100);
  });

  it('reads well-formed values back from storage', async () => {
    const kept = {
      mapLayer: 'people', mapCamera: { lat: 38.9, lng: -77.03, zoom: 12.5 }, lastHow: 100, playSpeedMs: 300,
      showSpotPins: false, showScoutDots: true, windowHours: 2, compareIds: ['a', 'b'],
    };
    storage.items.set('smappen-truck-ui', JSON.stringify({ state: kept, version: 1 }));
    await useTruckUiStore.persist.rehydrate();
    expect(useTruckUiStore.getState()).toMatchObject(kept);
  });

  it('drops what is not well-formed, so an old or edited key cannot break the map', async () => {
    const bad = {
      mapLayer: 'heat', mapCamera: { lat: 138.9, lng: -77.03, zoom: 12 }, lastHow: 168, playSpeedMs: 50,
      showSpotPins: 'yes', windowHours: 9, compareIds: ['a', 2, 'c', 'd', 'e', 'f'], mapsAuthFailed: true,
    };
    storage.items.set('smappen-truck-ui', JSON.stringify({ state: bad, version: 1 }));
    await useTruckUiStore.persist.rehydrate();
    expect(useTruckUiStore.getState()).toMatchObject({
      mapLayer: 'opportunity', mapCamera: null, lastHow: null, playSpeedMs: 600, showSpotPins: true, windowHours: 3,
      compareIds: ['a', 'c', 'd', 'e'], mapsAuthFailed: false,
    });
    storage.items.set('smappen-truck-ui', 'not json');
    await useTruckUiStore.persist.rehydrate();
    expect(useTruckUiStore.getState().mapLayer).toBe('opportunity');
    expect(typeof useTruckUiStore.getState().patch).toBe('function');
  });
});

function stop(id: string, spotId: string, open: number, close: number): PlanStop {
  return {
    id, kind: 'spot', spot_id: spotId, label: '', point: null, address: '', open_minute: open, close_minute: close,
    gap_before_unpaid: false, setup_minutes: null, teardown_minutes: null, fee_flat: 0, fee_pct: 0, fee_min: 0, event: null, catering: null,
  };
}

const savedPlan: Plan = {
  id: 'plan-1', date: '2026-10-08', name: '', treat_as: 'sat', notes: null, status: 'planned',
  stops: [stop('s-1', 'spot-a', 660, 840), stop('s-2', 'spot-b', 1020, 1200)],
  result: null, context: null, result_state: 'none', evaluated_at: null, maps_route_url: null,
  created_at: '2026-10-04 23:50:12', updated_at: '2026-10-04 23:50:12',
};

describe('truckPlanDraftStore', () => {
  beforeEach(() => resetTruckPlanDraftStore());
  const drafts = () => useTruckPlanDraftStore.getState().drafts;

  it('load starts an empty day, or shows the saved plan unchanged', () => {
    useTruckPlanDraftStore.getState().load('2026-10-09', null);
    expect(drafts()['2026-10-09']).toEqual(emptyPlanDraft('2026-10-09'));
    useTruckPlanDraftStore.getState().load('2026-10-08', savedPlan);
    const draft = drafts()['2026-10-08'];
    expect(draft.planId).toBe('plan-1');
    expect(draft.treat_as).toBe('sat');
    expect(draft.notes).toBe('');
    expect(draft.dirty).toBe(false);
    expect(draft.stops.map((s) => s.id)).toEqual(['s-1', 's-2']);
    expect(draft).toEqual(draftFromPlan(savedPlan));
  });

  it('load changes nothing when the saved plan is what the draft already shows', () => {
    useTruckPlanDraftStore.getState().load('2026-10-08', savedPlan);
    const before = drafts();
    useTruckPlanDraftStore.getState().load('2026-10-08', { ...savedPlan, stops: savedPlan.stops.map((s) => ({ ...s })) });
    expect(drafts()).toBe(before);
  });

  it('patch sets dirty, and load is ignored while a dirty draft exists', () => {
    const store = useTruckPlanDraftStore.getState();
    store.load('2026-10-08', savedPlan);
    store.patch('2026-10-08', (d) => ({ ...d, notes: 'bring the big grill' }));
    expect(drafts()['2026-10-08'].dirty).toBe(true);
    store.load('2026-10-08', { ...savedPlan, notes: 'from another tab' });
    expect(drafts()['2026-10-08'].notes).toBe('bring the big grill');
    store.load('2026-10-08', null);
    expect(drafts()['2026-10-08'].notes).toBe('bring the big grill');
  });

  it('patch on a date without a draft starts from an empty day, and keeps the date', () => {
    useTruckPlanDraftStore.getState().patch('2026-10-12', (d) => ({ ...d, date: 'ignored', notes: 'x' }));
    expect(drafts()['2026-10-12']).toMatchObject({ planId: null, date: '2026-10-12', notes: 'x', dirty: true, stops: [] });
  });

  it('markSaved replaces the draft with the saved plan: real stop ids, not dirty', () => {
    const store = useTruckPlanDraftStore.getState();
    store.load('2026-10-08', null);
    store.patch('2026-10-08', (d) => ({ ...d, stops: [{ ...draftFromPlan(savedPlan).stops[0], id: newDraftStopId() }] }));
    expect(isTemporaryStopId(drafts()['2026-10-08'].stops[0].id)).toBe(true);
    store.markSaved('2026-10-08', savedPlan);
    expect(drafts()['2026-10-08']).toEqual(draftFromPlan(savedPlan));
  });

  it('discard drops one date and leaves the others', () => {
    const store = useTruckPlanDraftStore.getState();
    store.load('2026-10-08', savedPlan);
    store.load('2026-10-09', null);
    store.discard('2026-10-08');
    expect(Object.keys(drafts())).toEqual(['2026-10-09']);
    const before = drafts();
    store.discard('2026-01-01');
    expect(drafts()).toBe(before);
  });

  it('temporary stop ids come from a counter, are never "base" and never look like a server id', () => {
    const a = newDraftStopId();
    const b = newDraftStopId();
    expect(a).toMatch(/^n[0-9]+$/);
    expect(b).toMatch(/^n[0-9]+$/);
    expect(Number(b.slice(1))).toBe(Number(a.slice(1)) + 1);
    expect(isTemporaryStopId(a)).toBe(true);
    expect(isTemporaryStopId('base')).toBe(false);
    expect(isTemporaryStopId('6f1c0000-0000-4000-8000-000000000001')).toBe(false);
    expect(isTemporaryStopId('n')).toBe(false);
    expect(isTemporaryStopId('new-1')).toBe(false);
  });
});

describe('planFromRow', () => {
  it('turns a list row into a Plan whose snapshot is null, whatever its result_state', () => {
    const row = { ...savedPlan, result_state: 'fresh' as const, stop_count: 2, summary: null };
    const { result: _r, context: _c, ...withoutSnapshot } = row;
    const plan = planFromRow(withoutSnapshot);
    expect(plan.result).toBeNull();
    expect(plan.context).toBeNull();
    expect(plan.result_state).toBe('fresh');
    expect(plan.stops).toBe(savedPlan.stops);
    expect(Object.keys(plan).sort()).toEqual(Object.keys(savedPlan).sort());
  });
});

describe('patchChangesVectors', () => {
  const spot = {
    id: 'spot-a', name: 'A', point: { lat: 38.96, lng: -77.36 }, address: '', county_fips: '51059', notes: null,
    terms: {
      spot_id: 'spot-a', visibility: 'normal' as const, fee_flat: 0, fee_pct: 0, fee_min: 0, allowed: null,
      host: { segment: 'v_nightlife' as const, size: 120, size_source: 'owner' as const, only_food: true, point_id: 'pw1', place_type: 'taproom' },
    },
    host_details: { place_type: 'taproom', name: 'Example Brewing', contact: null, phone: null, website: null, place_key: 'w1', google_place_id: null },
    vectors: null, vectors_state: 'none' as const, logs: { count: 0, last_date: null }, maps_url: '', archived: false, created_at: '', updated_at: '',
  };
  const sameHost = { place_key: 'w1', segment: 'v_nightlife' as const, size: 120, only_food: true };

  it('is false for the fields that leave the stored vectors valid', () => {
    expect(patchChangesVectors(spot, { name: 'B', address: 'x', notes: 'y' })).toBe(false);
    expect(patchChangesVectors(spot, { terms: { visibility: 'prominent', fee_flat: 75, fee_pct: 0.1, fee_min: 75 } })).toBe(false);
    expect(patchChangesVectors(spot, { host_details: { phone: '+13017428261' } })).toBe(false);
    expect(patchChangesVectors(spot, { terms: { host: sameHost } })).toBe(false);
    expect(patchChangesVectors(spot, { terms: { host: { ...sameHost, only_food: false } } })).toBe(false);
    // The size of a visitor host changes the estimate, not the vectors.
    expect(patchChangesVectors(spot, { terms: { host: { ...sameHost, size: 400 } } })).toBe(false);
    expect(patchChangesVectors(spot, { point: { lat: 38.96, lng: -77.36 } })).toBe(false);
  });

  it('is true for the point, the host link, the host segment and the size of a worker or resident host', () => {
    expect(patchChangesVectors(spot, { point: { lat: 38.961, lng: -77.36 } })).toBe(true);
    expect(patchChangesVectors(spot, { terms: { host: null } })).toBe(true);
    expect(patchChangesVectors(spot, { terms: { host: { ...sameHost, place_key: 'w2' } } })).toBe(true);
    expect(patchChangesVectors(spot, { terms: { host: { ...sameHost, segment: 'v_events' } } })).toBe(true);
    const office = { ...spot, host_details: null, terms: { ...spot.terms, host: { ...spot.terms.host, segment: 'w_office' as const, size: 600, point_id: null, place_type: null } } };
    expect(patchChangesVectors(office, { terms: { host: { segment: 'w_office', size: 600, only_food: false } } })).toBe(false);
    expect(patchChangesVectors(office, { terms: { host: { segment: 'w_office', size: 1000 } } })).toBe(true);
    const bare = { ...spot, host_details: null, terms: { ...spot.terms, host: null } };
    expect(patchChangesVectors(bare, { terms: { host: null } })).toBe(false);
    expect(patchChangesVectors(bare, { terms: { host: { segment: 'res', size: 500 } } })).toBe(true);
  });
});

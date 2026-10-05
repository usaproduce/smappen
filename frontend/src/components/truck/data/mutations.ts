// Truck Planner - every write (docs/truck-planner/05_FRONTEND.md 2.3).
//
// One hook per row of the table of 2.3. Every mutation rolls back what it changed optimistically and
// shows one sentence when it fails: the server's when it sent one, else the fallback of its row.
// `apiErrorMessage` returns null when there was no response at all, because the shared client has
// already toasted "Connection lost" for that. Success toasts are one or two words.

import { createElement } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQueryClient, type QueryClient, type QueryKey } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import {
  apiErrorMessage,
  apiErrorStatus,
  truckApi,
  truckKeys,
  type AssumptionsInfo,
  type ContactLookupAnswer,
  type DeletedCounts,
  type DriveLeg,
  type DriveOverrideRecord,
  type Lead,
  type LeadPatch,
  type LeadSpotAnswer,
  type LeadSpotBody,
  type OverrideChanges,
  type Plan,
  type PlanBody,
  type ProfilePatch,
  type SaveProfileAnswer,
  type ScoutAnswer,
  type ServiceBody,
  type ServiceDeleteAnswer,
  type ServiceLog,
  type ServiceWriteAnswer,
  type Spot,
  type SpotBody,
  type SpotPatch,
  type TruckProfileX,
} from '../../../api/truck';
import { roundHalfAway } from '../../../utils/truck/model';
import type { CalibrationState, DayResult, LatLng, OverrideMap } from '../../../utils/truck/model';
import { hostKey, withinTolerance } from '../../../utils/truck/assemble';
import { resetTruckHourStore } from '../../../stores/truckHourStore';
import { resetTruckPlanDraftStore, useTruckPlanDraftStore } from '../../../stores/truckPlanDraftStore';
import { resetTruckUiStore, useTruckUiStore } from '../../../stores/truckUiStore';
import type { BootstrapData } from './useBootstrap';
import type { DriveTimesData } from './useDriveTimes';

// Query key prefixes used for invalidation.
const SUGGEST = ['truck', 'suggest'] as const;
const SCOUT = ['truck', 'scout'] as const;
const PLANS = ['truck', 'plans'] as const;
const PLAN_LISTS = ['truck', 'plans', 'list'] as const;
const SPOTS = ['truck', 'spots'] as const;
const SPOT_LISTS = ['truck', 'spots', 'list'] as const;
const SERVICES = ['truck', 'services'] as const;
const ACCURACY = ['truck', 'accuracy'] as const;
const DAY_CONTEXT = ['truck', 'day-context'] as const;
const DRIVE_TIMES = ['truck', 'drive-times'] as const;

/** The server's sentence when there is one, else the fallback; nothing when the client already toasted. */
function toastFailure(e: unknown, fallback: string): void {
  const message = apiErrorMessage(e, fallback);
  if (message) toast.error(message);
}

function patchBootstrap(qc: QueryClient, change: (data: BootstrapData) => BootstrapData): void {
  qc.setQueryData<BootstrapData>(truckKeys.bootstrap(), (data) => (data === undefined ? data : change(data)));
}

type Snapshot = [QueryKey, unknown][];

/** What every cached query under a key prefix holds right now, for a rollback. */
function snapshot(qc: QueryClient, prefix: QueryKey): Snapshot {
  return qc.getQueriesData({ queryKey: prefix });
}

function restore(qc: QueryClient, saved: Snapshot | undefined): void {
  if (saved === undefined) return;
  for (const [key, data] of saved) qc.setQueryData(key, data);
}

// -------------------------------------------------------------------------------------------------
// Profile and assumptions
// -------------------------------------------------------------------------------------------------

function mergeProfile(profile: TruckProfileX, patch: ProfilePatch): TruckProfileX {
  const { base, daypart_fit, timezone: _timezone, ...flat } = patch;
  const next: TruckProfileX = { ...profile, ...flat };
  if (base !== undefined) {
    const { state: _state, ...point } = base;
    next.base = { ...profile.base, ...point };
  }
  if (daypart_fit !== undefined) next.daypart_fit = { ...profile.daypart_fit, ...daypart_fit };
  return next;
}

/**
 * Route 3: change the truck profile, or create the truck on the first call. Send only the keys that
 * changed. The answer carries `warnings` (`timezone_assumed`, `base_outside_region`) for the caller.
 */
export function useSaveProfile() {
  const qc = useQueryClient();
  return useMutation<SaveProfileAnswer, unknown, ProfilePatch, { previous: BootstrapData | undefined }>({
    mutationFn: (patch) => truckApi.saveProfile(patch),
    onMutate: async (patch) => {
      await qc.cancelQueries({ queryKey: truckKeys.bootstrap() });
      const previous = qc.getQueryData<BootstrapData>(truckKeys.bootstrap());
      // Optimistic only when a truck exists: the first save creates it and needs the server's defaults.
      if (previous?.truck) {
        const truck = previous.truck;
        patchBootstrap(qc, (data) => ({ ...data, truck: { ...truck, profile: mergeProfile(truck.profile, patch) } }));
      }
      return { previous };
    },
    onError: (e, _patch, context) => {
      if (context?.previous !== undefined) qc.setQueryData(truckKeys.bootstrap(), context.previous);
      toastFailure(e, 'Could not save your settings.');
    },
    onSuccess: async (answer, patch, context) => {
      const created = !context?.previous?.truck;
      if (!created) {
        patchBootstrap(qc, (data) => ({
          ...data,
          has_truck: true,
          truck: answer.truck,
          region: answer.region,
          fuel: answer.fuel,
        }));
      }
      void qc.invalidateQueries({ queryKey: SUGGEST });
      void qc.invalidateQueries({ queryKey: SCOUT });
      if (created || patch.base !== undefined || patch.fuel_type !== undefined || patch.fuel_price_override !== undefined) {
        void qc.invalidateQueries({ queryKey: DAY_CONTEXT });
      }
      if (created || patch.base !== undefined || patch.avoid_tolls !== undefined || patch.avoid_highways !== undefined) {
        void qc.invalidateQueries({ queryKey: DRIVE_TIMES });
      }
      toast.success('Saved');
      // A new truck, a moved base or another region changes more than the three fields above (the
      // region of the assumptions, the time zone, today, the counts): read the whole answer again.
      // Awaited for a new truck, so the page behind the first-run step renders with its context.
      if (created) {
        await qc.invalidateQueries({ queryKey: truckKeys.bootstrap() });
      } else if (patch.base !== undefined || patch.region_id !== undefined || patch.timezone !== undefined) {
        void qc.invalidateQueries({ queryKey: truckKeys.bootstrap() });
      }
    },
  });
}

function mergeOverrides(current: OverrideMap, changes: OverrideChanges): OverrideMap {
  const next: OverrideMap = { ...current };
  for (const path of Object.keys(changes)) {
    const value = changes[path];
    if (value === null) delete next[path];
    else next[path] = value;
  }
  return next;
}

function writeAssumptions(qc: QueryClient, assumptions: AssumptionsInfo): void {
  patchBootstrap(qc, (data) => ({ ...data, assumptions }));
  // Overrides are owner-scope seeds: no vectors change, but every server-computed figure does.
  void qc.invalidateQueries({ queryKey: SUGGEST });
  void qc.invalidateQueries({ queryKey: SCOUT });
  void qc.invalidateQueries({ queryKey: PLANS });
}

/**
 * Route 5: `{ <seed path>: value | null }`, merged on the server (null removes a path). A 422 carries
 * `details: [{ path, error }]` (read it with `apiErrorDetails`) and its sentence is shown as sent.
 */
export function useSaveOverrides() {
  const qc = useQueryClient();
  return useMutation<AssumptionsInfo, unknown, OverrideChanges, { previous: BootstrapData | undefined }>({
    mutationFn: (changes) => truckApi.saveOverrides(changes),
    onMutate: async (changes) => {
      await qc.cancelQueries({ queryKey: truckKeys.bootstrap() });
      const previous = qc.getQueryData<BootstrapData>(truckKeys.bootstrap());
      patchBootstrap(qc, (data) => ({
        ...data,
        assumptions: { ...data.assumptions, overrides: mergeOverrides(data.assumptions.overrides, changes) },
      }));
      return { previous };
    },
    onError: (e, _changes, context) => {
      if (context?.previous !== undefined) qc.setQueryData(truckKeys.bootstrap(), context.previous);
      toastFailure(e, 'Could not save the assumptions.');
    },
    onSuccess: (assumptions) => {
      writeAssumptions(qc, assumptions);
      toast.success('Saved');
    },
  });
}

/** Route 6: remove the overrides of the given paths, or every override when called without paths. */
export function useResetOverrides() {
  const qc = useQueryClient();
  return useMutation<AssumptionsInfo, unknown, string[] | undefined, { previous: BootstrapData | undefined }>({
    mutationFn: (paths) => truckApi.resetOverrides(paths),
    onMutate: async (paths) => {
      await qc.cancelQueries({ queryKey: truckKeys.bootstrap() });
      const previous = qc.getQueryData<BootstrapData>(truckKeys.bootstrap());
      patchBootstrap(qc, (data) => {
        let overrides: OverrideMap = {};
        if (paths !== undefined) {
          overrides = { ...data.assumptions.overrides };
          for (const path of paths) delete overrides[path];
        }
        return { ...data, assumptions: { ...data.assumptions, overrides } };
      });
      return { previous };
    },
    onError: (e, _paths, context) => {
      if (context?.previous !== undefined) qc.setQueryData(truckKeys.bootstrap(), context.previous);
      toastFailure(e, 'Could not save the assumptions.');
    },
    onSuccess: (assumptions) => {
      writeAssumptions(qc, assumptions);
      toast.success('Saved');
    },
  });
}

// -------------------------------------------------------------------------------------------------
// Spots
// -------------------------------------------------------------------------------------------------

/** Route 11. Not optimistic: the id and the vectors come from the server. */
export function useCreateSpot() {
  const qc = useQueryClient();
  return useMutation<Spot, unknown, SpotBody>({
    mutationFn: (body) => truckApi.createSpot(body),
    onError: (e) => toastFailure(e, 'Could not save the spot.'),
    onSuccess: (spot) => {
      qc.setQueryData(truckKeys.spot(spot.id), spot);
      void qc.invalidateQueries({ queryKey: SPOT_LISTS });
      void qc.invalidateQueries({ queryKey: truckKeys.bootstrap() }); // counts
      toast.success('Spot saved');
    },
  });
}

/**
 * True when a spot patch makes the server compute new vectors: a moved point, another host link,
 * another host segment, or another size of a worker or resident host. Such an edit is not applied
 * optimistically: the stored vectors would no longer match the terms.
 */
export function patchChangesVectors(spot: Spot, patch: SpotPatch): boolean {
  if (patch.point !== undefined && (patch.point.lat !== spot.point.lat || patch.point.lng !== spot.point.lng)) {
    return true;
  }
  if (patch.terms === undefined || patch.terms.host === undefined) return false;
  const saved = spot.terms.host;
  const savedKey = hostKey(
    saved === null && !spot.host_details?.place_key
      ? null
      : { place_key: spot.host_details?.place_key ?? null, segment: saved?.segment ?? null, size: saved?.size ?? null },
  );
  const next = patch.terms.host;
  const nextKey = hostKey(
    next === null ? null : { place_key: next.place_key ?? null, segment: next.segment ?? null, size: next.size ?? null },
  );
  return savedKey !== nextKey;
}

/** A spot with the fields of a patch that do not change vectors applied (the optimistic copy). */
function applySpotPatch(spot: Spot, patch: SpotPatch): Spot {
  const next: Spot = { ...spot };
  if (patch.name !== undefined) next.name = patch.name;
  if (patch.address !== undefined) next.address = patch.address;
  if (patch.notes !== undefined) next.notes = patch.notes;
  if (patch.host_details !== undefined) {
    next.host_details = {
      place_type: spot.host_details?.place_type ?? null,
      name: spot.host_details?.name ?? null,
      contact: spot.host_details?.contact ?? null,
      phone: spot.host_details?.phone ?? null,
      website: spot.host_details?.website ?? null,
      place_key: spot.host_details?.place_key ?? null,
      google_place_id: spot.host_details?.google_place_id ?? null,
      ...patch.host_details,
    };
  }
  if (patch.terms !== undefined) {
    const t = patch.terms;
    const terms = { ...spot.terms };
    if (t.visibility !== undefined) terms.visibility = t.visibility;
    if (t.fee_flat !== undefined) terms.fee_flat = t.fee_flat;
    if (t.fee_pct !== undefined) terms.fee_pct = t.fee_pct;
    if (t.fee_min !== undefined) terms.fee_min = t.fee_min;
    if (t.allowed !== undefined) terms.allowed = t.allowed;
    if (t.host !== undefined && t.host !== null && spot.terms.host !== null) {
      // Same link, same segment (checked by patchChangesVectors): only the food flag and the size
      // of a visitor host can differ.
      const host = { ...spot.terms.host };
      if (t.host.only_food !== undefined) host.only_food = t.host.only_food;
      if (t.host.size !== undefined) {
        host.size = t.host.size;
        host.size_source = t.host.size_source ?? 'owner';
      }
      terms.host = host;
    }
    next.terms = terms;
  }
  return next;
}

/**
 * Route 14. Optimistic for the fields that do not change vectors (name, address, notes, host
 * details, visibility, fees, allowed hours, the only-food flag, the size of a visitor host). An
 * edit that changes vectors waits for the server's answer.
 */
export function useUpdateSpot() {
  const qc = useQueryClient();
  return useMutation<Spot, unknown, { id: string; patch: SpotPatch }, { saved: Snapshot | undefined }>({
    mutationFn: ({ id, patch }) => truckApi.updateSpot(id, patch),
    onMutate: async ({ id, patch }) => {
      const current =
        qc.getQueryData<Spot>(truckKeys.spot(id)) ??
        qc
          .getQueriesData<Spot[]>({ queryKey: SPOT_LISTS })
          .flatMap(([, list]) => list ?? [])
          .find((s) => s.id === id);
      if (current === undefined || patchChangesVectors(current, patch)) return { saved: undefined };
      await qc.cancelQueries({ queryKey: SPOTS });
      const saved = snapshot(qc, SPOTS);
      const optimistic = applySpotPatch(current, patch);
      qc.setQueryData(truckKeys.spot(id), optimistic);
      qc.setQueriesData<Spot[]>({ queryKey: SPOT_LISTS }, (list) =>
        list === undefined ? list : list.map((s) => (s.id === id ? optimistic : s)),
      );
      return { saved };
    },
    onError: (e, _variables, context) => {
      restore(qc, context?.saved);
      toastFailure(e, 'Could not save the spot.');
    },
    onSuccess: (spot) => {
      qc.setQueryData(truckKeys.spot(spot.id), spot);
      void qc.invalidateQueries({ queryKey: SPOT_LISTS });
      void qc.invalidateQueries({ queryKey: SUGGEST });
      toast.success('Saved');
    },
  });
}

/** Route 15: archive a spot. The caller asks first; planned days and logged services keep working. */
export function useArchiveSpot() {
  const qc = useQueryClient();
  return useMutation<{ id: string; archived: true }, unknown, string>({
    mutationFn: (id) => truckApi.archiveSpot(id),
    onError: (e) => toastFailure(e, 'Could not delete the spot.'),
    onSuccess: (_answer, id) => {
      const compareIds = useTruckUiStore.getState().compareIds;
      if (compareIds.includes(id)) {
        useTruckUiStore.getState().patch({ compareIds: compareIds.filter((other) => other !== id) });
      }
      void qc.invalidateQueries({ queryKey: SPOTS });
      void qc.invalidateQueries({ queryKey: SUGGEST });
      void qc.invalidateQueries({ queryKey: truckKeys.bootstrap() }); // counts
      toast.success('Spot deleted');
    },
  });
}

const MAX_REFRESH_ROUNDS = 10;
let refreshRunning = false;

/**
 * Route 12: recompute the spots whose stored vectors are not fresh, up to 50 a round, for at most
 * ten rounds. Silent: no toast either way, a stale spot simply keeps its tag. One run at a time for
 * the whole page, however many components ask.
 */
export function useRefreshStaleSpots() {
  const qc = useQueryClient();
  return useMutation<{ refreshed: number; remaining: number }, unknown, void>({
    mutationFn: async () => {
      if (refreshRunning) return { refreshed: 0, remaining: 0 };
      refreshRunning = true;
      try {
        let refreshed = 0;
        let remaining = 0;
        for (let round = 0; round < MAX_REFRESH_ROUNDS; round++) {
          const answer = await truckApi.refreshStaleSpots();
          refreshed += answer.refreshed;
          remaining = answer.remaining;
          // No progress means the rest cannot be computed right now: asking again would not help.
          if (answer.remaining <= 0 || answer.refreshed <= 0) break;
        }
        return { refreshed, remaining };
      } finally {
        refreshRunning = false;
      }
    },
    onSuccess: (answer) => {
      if (answer.refreshed > 0) void qc.invalidateQueries({ queryKey: SPOTS });
    },
  });
}

// -------------------------------------------------------------------------------------------------
// Plans
// -------------------------------------------------------------------------------------------------

/** The server's sentence when a plan of that date was saved elsewhere (another tab). */
const PLAN_EXISTS_ERROR = 'A plan already exists for this date';

export interface SavePlanVariables {
  date: string;
  /** Id of the saved plan of this date, or null for the first save. */
  planId: string | null;
  /** `date`, `treat_as`, `notes`, `status` and the stops in order; unsaved stops without an `id`. */
  body: PlanBody;
  /** What the planner shows for this draft. Development builds compare it with the server's snapshot. */
  localResult?: DayResult | null;
}

function isPlanExistsError(e: unknown): boolean {
  return apiErrorStatus(e) === 409 && apiErrorMessage(e, '') === PLAN_EXISTS_ERROR;
}

/** Development only: the browser's take-home figures against the server's snapshot of the saved day. */
function checkPlanDrift(local: DayResult | null | undefined, plan: Plan): void {
  if (!import.meta.env.DEV || !local || plan.result === null) return;
  const server = plan.result;
  const pairs: [string, number, number][] = [
    ['totals.take_home.value', local.totals.take_home.value, server.totals.take_home.value],
    ['totals.take_home.low', local.totals.take_home.low, server.totals.take_home.low],
    ['totals.take_home.high', local.totals.take_home.high, server.totals.take_home.high],
  ];
  const stops = Math.min(local.stops.length, server.stops.length);
  for (let i = 0; i < stops; i++) {
    const a = local.stops[i].adds.take_home;
    const b = server.stops[i].adds.take_home;
    pairs.push(['stops[' + i + '].adds.take_home.value', a.value, b.value]);
    pairs.push(['stops[' + i + '].adds.take_home.low', a.low, b.low]);
    pairs.push(['stops[' + i + '].adds.take_home.high', a.high, b.high]);
  }
  const off = pairs.filter(([, a, b]) => !withinTolerance(a, b));
  if (off.length > 0 || local.stops.length !== server.stops.length) {
    console.warn('[truck] estimator drift', 'saved day ' + plan.date, off);
  }
}

/**
 * Put a saved plan into (or, with null, take a deleted plan out of) every cached plan list that was
 * read with its stops. The lists are refetched right after, but a page that builds its draft from
 * the list must not see the old row in between: it would bring a deleted day back, or replace a
 * day that was just saved with what it was before.
 */
function writePlanToLists(qc: QueryClient, id: string, plan: Plan | null): void {
  for (const [key, list] of qc.getQueriesData<Plan[]>({ queryKey: PLAN_LISTS })) {
    // ['truck', 'plans', 'list', from, to, stops]: lists read without stops hold other rows.
    if (list === undefined || key[5] !== true) continue;
    const from = typeof key[3] === 'string' ? key[3] : '';
    const to = typeof key[4] === 'string' ? key[4] : '';
    const next = list.filter((row) => row.id !== id && (plan === null || row.date !== plan.date));
    if (plan !== null && plan.date >= from && plan.date <= to) {
      // The list copy never carries the server's snapshot (see planFromRow in usePlans.ts).
      next.push({ ...plan, result: null, context: null });
      next.sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0));
    }
    qc.setQueryData<Plan[]>(key, next);
  }
}

/**
 * Routes 23 and 26: save a day. The first save of a date creates the plan; if a plan of that date
 * was created elsewhere in the meantime, the save is repeated as an update of that plan. The
 * returned plan replaces the draft, which gives new stops their real ids.
 */
export function useSavePlan() {
  const qc = useQueryClient();
  return useMutation<Plan, unknown, SavePlanVariables, { key: QueryKey | null; previous: Plan | undefined }>({
    mutationFn: async ({ date, planId, body }) => {
      if (planId !== null) return truckApi.updatePlan(planId, body);
      try {
        return await truckApi.createPlan(body);
      } catch (e) {
        if (!isPlanExistsError(e)) throw e;
        const rows = await truckApi.listPlans(date, date, { stops: true });
        const existing = rows.find((row) => row.date === date);
        if (existing === undefined) throw e;
        return truckApi.updatePlan(existing.id, body);
      }
    },
    onMutate: async ({ planId, body }) => {
      if (planId === null) return { key: null, previous: undefined };
      const key = truckKeys.plan(planId);
      await qc.cancelQueries({ queryKey: key });
      const previous = qc.getQueryData<Plan>(key);
      if (previous !== undefined) {
        // The server's snapshot belongs to the stops it was computed for: it is dropped until the
        // answer brings the new one.
        qc.setQueryData<Plan>(key, {
          ...previous,
          date: body.date,
          treat_as: body.treat_as ?? null,
          notes: body.notes ?? null,
          status: body.status ?? previous.status,
          result: null,
          context: null,
          result_state: 'none',
        });
      }
      return { key, previous };
    },
    onError: (e, _variables, context) => {
      if (context && context.key !== null && context.previous !== undefined) {
        qc.setQueryData(context.key, context.previous);
      }
      toastFailure(e, 'Could not save the day.');
    },
    onSuccess: (plan, { date, localResult }) => {
      useTruckPlanDraftStore.getState().markSaved(date, plan);
      qc.setQueryData(truckKeys.plan(plan.id), plan);
      writePlanToLists(qc, plan.id, plan);
      void qc.invalidateQueries({ queryKey: PLAN_LISTS });
      checkPlanDrift(localResult, plan);
      toast.success('Day saved');
    },
  });
}

/** Route 27: clear a day. The caller asks first. Logged services keep their numbers. */
export function useDeletePlan() {
  const qc = useQueryClient();
  return useMutation<{ id: string; deleted: true }, unknown, { id: string; date: string }>({
    mutationFn: ({ id }) => truckApi.deletePlan(id),
    onError: (e) => toastFailure(e, 'Could not clear the day.'),
    onSuccess: (_answer, { id, date }) => {
      qc.removeQueries({ queryKey: truckKeys.plan(id) });
      writePlanToLists(qc, id, null);
      useTruckPlanDraftStore.getState().discard(date);
      void qc.invalidateQueries({ queryKey: PLAN_LISTS });
      toast.success('Day cleared');
    },
  });
}

// -------------------------------------------------------------------------------------------------
// Drive-time corrections
// -------------------------------------------------------------------------------------------------

/** The server's key of a coordinate for drive legs and corrections: four decimals, half away from zero. */
function legKey(deg: number): number {
  return roundHalfAway(deg * 10000.0, 0);
}

function samePlace(a: LatLng, b: LatLng): boolean {
  return legKey(a.lat) === legKey(b.lat) && legKey(a.lng) === legKey(b.lng);
}

/** A leg without its correction: Google's toll estimate again when there is one. */
function withoutOverride(leg: DriveLeg): DriveLeg {
  const googleToll = leg.toll_state === 'estimate' && leg.google_toll !== null ? leg.google_toll : null;
  return {
    ...leg,
    override: null,
    toll_source: googleToll !== null ? 'google' : 'none',
    leg_input: { ...leg.leg_input, override_minutes: null, toll: googleToll ?? 0.0 },
  };
}

function withOverride(leg: DriveLeg, id: string, minutes: number | null, toll: number | null, note: string): DriveLeg {
  const base = withoutOverride(leg);
  return {
    ...base,
    override: { id, minutes, toll, note },
    toll_source: toll !== null ? 'owner' : base.toll_source,
    leg_input: {
      ...base.leg_input,
      override_minutes: minutes,
      toll: toll !== null ? toll : base.leg_input.toll,
    },
  };
}

/** Apply a change to one directed pair of places in every cached drive-times result. */
function patchLegs(qc: QueryClient, match: (data: DriveTimesData, leg: DriveLeg) => boolean, change: (leg: DriveLeg) => DriveLeg): void {
  qc.setQueriesData<DriveTimesData>({ queryKey: DRIVE_TIMES }, (data) => {
    if (data === undefined) return data;
    let touched = false;
    const legs = data.legs.map((leg) => {
      if (!match(data, leg)) return leg;
      touched = true;
      return change(leg);
    });
    return touched ? { ...data, legs } : data;
  });
}

function pointOf(data: DriveTimesData, id: string): LatLng | null {
  for (const p of data.points) {
    if (p.id === id) return p;
  }
  return null;
}

export interface LegOverrideVariables {
  from: LatLng;
  to: LatLng;
  /** The owner's minutes for this drive at every hour, or null to keep the estimate. */
  minutes: number | null;
  /** The owner's toll, or null to use Google's estimate. */
  toll: number | null;
}

/**
 * Route 20: the owner's own time and toll for one directed pair of places. Optimistic: the pair is
 * patched in every cached drive-times result, so every day that uses this drive re-evaluates at once.
 */
export function useSaveLegOverride() {
  const qc = useQueryClient();
  return useMutation<DriveOverrideRecord, unknown, LegOverrideVariables, { saved: Snapshot }>({
    mutationFn: ({ from, to, minutes, toll }) => truckApi.saveLegOverride({ from, to, minutes, toll }),
    onMutate: async ({ from, to, minutes, toll }) => {
      await qc.cancelQueries({ queryKey: DRIVE_TIMES });
      const saved = snapshot(qc, DRIVE_TIMES);
      patchLegs(
        qc,
        (data, leg) => {
          const a = pointOf(data, leg.from_id);
          const b = pointOf(data, leg.to_id);
          return a !== null && b !== null && samePlace(a, from) && samePlace(b, to);
        },
        (leg) => withOverride(leg, leg.override?.id ?? '', minutes, toll, leg.override?.note ?? ''),
      );
      return { saved };
    },
    onError: (e, _variables, context) => {
      restore(qc, context?.saved);
      toastFailure(e, 'Could not save the drive time.');
    },
    onSuccess: (record, { from, to }) => {
      // The same pair again, now with the correction's real id (needed to remove it later).
      patchLegs(
        qc,
        (data, leg) => {
          const a = pointOf(data, leg.from_id);
          const b = pointOf(data, leg.to_id);
          return a !== null && b !== null && samePlace(a, from) && samePlace(b, to);
        },
        (leg) => withOverride(leg, record.id, record.minutes, record.toll, record.note),
      );
      void qc.invalidateQueries({ queryKey: SUGGEST });
    },
  });
}

/** Route 21: remove a correction ("Use the estimate"). Optimistic in the same way. */
export function useDeleteLegOverride() {
  const qc = useQueryClient();
  return useMutation<{ id: string; deleted: true }, unknown, string, { saved: Snapshot }>({
    mutationFn: (id) => truckApi.deleteLegOverride(id),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: DRIVE_TIMES });
      const saved = snapshot(qc, DRIVE_TIMES);
      patchLegs(qc, (_data, leg) => leg.override !== null && leg.override.id === id, withoutOverride);
      return { saved };
    },
    onError: (e, _id, context) => {
      restore(qc, context?.saved);
      toastFailure(e, 'Could not save the drive time.');
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: SUGGEST });
    },
  });
}

// -------------------------------------------------------------------------------------------------
// Logged services
// -------------------------------------------------------------------------------------------------

/** Every logged service changes the calibration, and with it every estimate on every page. */
function afterServiceWrite(qc: QueryClient, calibration: CalibrationState): void {
  patchBootstrap(qc, (data) => ({ ...data, calibration }));
  void qc.invalidateQueries({ queryKey: SERVICES });
  void qc.invalidateQueries({ queryKey: ACCURACY });
  void qc.invalidateQueries({ queryKey: SUGGEST });
  void qc.invalidateQueries({ queryKey: SCOUT });
  void qc.invalidateQueries({ queryKey: PLANS });
  void qc.invalidateQueries({ queryKey: truckKeys.bootstrap() }); // counts; the spot list's log counts
  void qc.invalidateQueries({ queryKey: SPOT_LISTS });
}

let temporaryServices = 0;

/** A cached list is keyed `['truck', 'services', from, to, spotId]`, with `''` for an open end or any spot. */
function listHolds(key: QueryKey, service: ServiceLog): boolean {
  const from = typeof key[2] === 'string' ? key[2] : '';
  const to = typeof key[3] === 'string' ? key[3] : '';
  const spotId = typeof key[4] === 'string' ? key[4] : '';
  if (from !== '' && service.date < from) return false;
  if (to !== '' && service.date > to) return false;
  return spotId === '' || spotId === service.spot_id;
}

export interface SaveServiceVariables {
  /** Id of the service to change. Leave out to log a new one. */
  id?: string;
  body: ServiceBody;
}

/**
 * Routes 32 and 34: log a service, or change one. A new service appears at the top of the cached
 * lists at once, under a temporary id and without a stored estimate, until the server answers.
 */
export function useSaveService() {
  const qc = useQueryClient();
  return useMutation<ServiceWriteAnswer, unknown, SaveServiceVariables, { saved: Snapshot | undefined }>({
    mutationFn: ({ id, body }) => (id === undefined ? truckApi.createService(body) : truckApi.updateService(id, body)),
    onMutate: async ({ id, body }) => {
      if (id !== undefined) return { saved: undefined };
      await qc.cancelQueries({ queryKey: SERVICES });
      const saved = snapshot(qc, SERVICES);
      temporaryServices += 1;
      const placeholder: ServiceLog = {
        id: 'new-' + temporaryServices,
        kind: body.kind ?? 'spot',
        spot_id: body.spot_id ?? null,
        plan_id: null,
        plan_stop_id: body.plan_stop_id ?? null,
        date: body.date,
        open_minute: body.open_minute,
        close_minute: body.close_minute,
        actual: body.actual,
        sales: body.sales ?? null,
        sold_out: body.sold_out ?? false,
        notes: body.notes ?? null,
        source: 'manual',
        external_key: null,
        treat_as: body.treat_as ?? null,
        prediction: null,
        created_at: '',
        updated_at: '',
      };
      for (const [key, list] of qc.getQueriesData<ServiceLog[]>({ queryKey: SERVICES })) {
        if (list !== undefined && listHolds(key, placeholder)) qc.setQueryData<ServiceLog[]>(key, [placeholder, ...list]);
      }
      return { saved };
    },
    onError: (e, _variables, context) => {
      restore(qc, context?.saved);
      toastFailure(e, 'Could not save the service.');
    },
    onSuccess: (answer) => {
      afterServiceWrite(qc, answer.calibration);
      toast.success('Logged');
    },
  });
}

/** Route 35: delete a logged service. The caller asks first; estimates stop using it. */
export function useDeleteService() {
  const qc = useQueryClient();
  return useMutation<ServiceDeleteAnswer, unknown, string>({
    mutationFn: (id) => truckApi.deleteService(id),
    onError: (e) => toastFailure(e, 'Could not delete the service.'),
    onSuccess: (answer) => {
      afterServiceWrite(qc, answer.calibration);
      toast.success('Deleted');
    },
  });
}

// -------------------------------------------------------------------------------------------------
// Scout leads
// -------------------------------------------------------------------------------------------------

/** Write a lead into the candidate that carries it, in every cached Scout result. */
function writeLead(qc: QueryClient, placeKey: string, change: (lead: Lead) => Lead): void {
  qc.setQueriesData<ScoutAnswer>({ queryKey: SCOUT }, (answer) => {
    if (answer === undefined) return answer;
    let touched = false;
    const candidates = answer.candidates.map((candidate) => {
      if (candidate.place.place_key !== placeKey) return candidate;
      touched = true;
      return { ...candidate, lead: change(candidate.lead) };
    });
    return touched ? { ...answer, candidates } : answer;
  });
}

/** Route 39: a lead's status or notes. Optimistic; a failed save puts the old values back. */
export function useSaveLead() {
  const qc = useQueryClient();
  return useMutation<Lead, unknown, { placeKey: string; patch: LeadPatch }, { saved: Snapshot }>({
    mutationFn: ({ placeKey, patch }) => truckApi.saveLead(placeKey, patch),
    onMutate: async ({ placeKey, patch }) => {
      await qc.cancelQueries({ queryKey: SCOUT });
      const saved = snapshot(qc, SCOUT);
      writeLead(qc, placeKey, (lead) => ({
        ...lead,
        status: patch.status ?? lead.status,
        notes: patch.notes !== undefined ? patch.notes : lead.notes,
      }));
      return { saved };
    },
    onError: (e, _variables, context) => {
      restore(qc, context?.saved);
      toastFailure(e, 'Could not update the lead.');
    },
    onSuccess: (lead, { placeKey }) => {
      writeLead(qc, placeKey, () => lead);
    },
  });
}

/** Route 40: look a place up on Google. Only ever sent from its own button; never optimistic. */
export function useLookupContact() {
  const qc = useQueryClient();
  return useMutation<ContactLookupAnswer, unknown, { placeKey: string; force?: boolean }>({
    mutationFn: ({ placeKey, force }) => truckApi.lookupContact(placeKey, force === true),
    onError: (e) => toastFailure(e, 'The lookup did not work. Try again later.'),
    onSuccess: (answer, { placeKey }) => {
      writeLead(qc, placeKey, () => answer.lead);
    },
  });
}

/** Route 41: save a scouted place as a spot. The toast links to the new spot. */
export function useSaveLeadAsSpot() {
  const qc = useQueryClient();
  return useMutation<LeadSpotAnswer, unknown, { placeKey: string; body: LeadSpotBody }>({
    mutationFn: ({ placeKey, body }) => truckApi.saveLeadAsSpot(placeKey, body),
    onError: (e) => toastFailure(e, 'Could not save the spot.'),
    onSuccess: (answer, { placeKey }) => {
      writeLead(qc, placeKey, () => answer.lead);
      qc.setQueryData(truckKeys.spot(answer.spot.id), answer.spot);
      void qc.invalidateQueries({ queryKey: SPOT_LISTS });
      void qc.invalidateQueries({ queryKey: truckKeys.bootstrap() }); // counts
      toast.success((t) =>
        createElement(
          'span',
          null,
          'Spot saved ',
          createElement(
            Link,
            {
              to: '/truck/spots/' + encodeURIComponent(answer.spot.id),
              onClick: () => toast.dismiss(t.id),
              className: 'font-bold underline',
            },
            'Open spot',
          ),
        ),
      );
    },
  });
}

// -------------------------------------------------------------------------------------------------
// Everything
// -------------------------------------------------------------------------------------------------

/**
 * Route 43: delete the truck and everything entered for it. The caller asks for the typed phrase
 * first. Afterwards nothing cached is valid any more: the three stores go back to their defaults,
 * the bootstrap answer is read again, which brings the first-run step back, and every other truck
 * query is dropped. A 403 (not the owner or an admin) is not toasted: the card that holds the button
 * says it in its own words.
 */
export function useDeleteAllData() {
  const qc = useQueryClient();
  return useMutation<DeletedCounts, unknown, void>({
    mutationFn: () => truckApi.deleteAllData(),
    onError: (e) => {
      if (apiErrorStatus(e) === 403) return;
      toastFailure(e, 'Could not delete the data.');
    },
    onSuccess: async () => {
      resetTruckUiStore();
      resetTruckHourStore();
      resetTruckPlanDraftStore();
      toast.success('Deleted');
      // Order matters. Resetting the bootstrap query empties it at once, so the gate takes the
      // pages off the screen before anything else happens; only then are their queries dropped.
      // Dropped while a page is still mounted, a query would be created again by its observer and
      // ask the server for data of a truck that no longer exists.
      await qc.resetQueries({ queryKey: truckKeys.bootstrap() });
      qc.removeQueries({ queryKey: truckKeys.all, predicate: (query) => query.queryKey[1] !== 'bootstrap' });
    },
  });
}

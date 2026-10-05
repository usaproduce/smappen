import { useCallback, useMemo, useRef } from 'react';
import type { DriveLeg, DrivePoint, HostHint, HostInput, Located, OutletRow, Spot } from '../../../api/truck';
import {
  SEEDS,
  bestWindows,
  dayPlan,
  modFloor,
  typicalContext,
  vectorsMatch,
  weekStrip,
  windowOrders,
} from '../../../utils/truck/model';
import type {
  BestWindow,
  DayResult,
  Host,
  LatLng,
  LocationVectors,
  SpotTerms,
  StopInput,
  Visibility,
  WindowResult,
} from '../../../utils/truck/model';
import { coord6, linkedHostKey, sortedJson, spotVectors, typicalWithFuel } from '../../../utils/truck/assemble';
import { nextDateWithDow } from '../../../utils/truck/time';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { regionRebuilding, useTruck } from './TruckContext';
import { useDriveTimes } from './useDriveTimes';
import { useNow } from './useNow';
import { useSimulate, type SimulateRequest } from './useSimulate';

export interface SpotEstimateInput {
  /** A clicked point, or the point of a spot being created or moved. Default: the saved spot's point. */
  point?: LatLng | null;
  /** A saved spot. Its stored vectors are used for as long as the edit does not change them. */
  spot?: Spot | null;
  /** The terms to estimate with: the spot's own, or the draft being edited. */
  terms: SpotTerms;
  /** The place the host is linked to (`HostHint.place_key`). Default: the saved spot's link. */
  hostPlaceKey?: string | null;
  /**
   * A form is editing these terms: ask for all three visibilities, so that changing the visibility
   * afterwards needs no request. Default false (one visibility, the one of `terms`).
   */
  editing?: boolean;
  /**
   * Also load the food outlets and the possible hosts around a saved spot that is evaluated from
   * its stored vectors (one `simulate` request, used for nothing else). Default false.
   */
  withPlaces?: boolean;
  /** Window length in hours for `best`. Default: the owner's choice on the spot card (`windowHours`). */
  windowHours?: number;
}

export interface SpotEstimate {
  /**
   * `pending`: waiting for vectors, nothing to show yet. `ready`: numbers on screen. `error`: the
   * request failed and there is nothing to show. `outside`: the point is outside the loaded area
   * and no source point was in reach (`in_region` false with `points_used` 0); only a described
   * host counts. `rebuilding`: new vectors are needed while the region data is being rebuilt, so no
   * request is sent. `none`: a saved spot without stored vectors ("No estimate yet").
   */
  status: 'pending' | 'ready' | 'error' | 'outside' | 'rebuilding' | 'none';
  /**
   * The numbers on screen belong to the last terms and vectors that matched, not to the current
   * input (new vectors are on their way, or cannot be had right now). Render estimates with
   * `RangeValue dim`.
   */
  dim: boolean;
  /** The terms the numbers were computed with: the input's, with the server's resolved host. */
  terms: SpotTerms | null;
  vectors: LocationVectors | null;
  located: Located | null;
  /**
   * The host after the server's link rule and defaults (segment, size, size source, only food,
   * point id, place type), when vectors came from `simulate`. A form adopts it as its draft host.
   */
  resolvedHost: Host | null;
  /** Food outlets within walking distance, nearest first; null when not loaded (see `withPlaces`). */
  outlets: OutletRow[] | null;
  outletsTotal: number | null;
  /** Places within 250 m that could be the host, nearest first; null when not loaded. */
  hostsNearby: HostHint[] | null;
  /** Expected orders for each hour of a typical week (168 values, capped at the truck's capacity). */
  week: number[] | null;
  /** The top three windows of the typical week that do not overlap. */
  best: BestWindow[];
  /** The one-hour window at an hour of the typical week. */
  hour(how: number): WindowResult | null;
  /**
   * A window on a day of the typical week (0 = Monday); `close` may pass midnight. Null also for a
   * window the model refuses (anything but `0 <= open <= close <= 2880`).
   */
  windowOn(dow: number, open: number, close: number): WindowResult | null;
  /**
   * The whole day when this is the only stop, on a typical week with the current fuel price. Null
   * while the drive legs of a saved spot are still on their way.
   */
  oneStopDay(dow: number, open: number, close: number): DayResult | null;
  /** The legs behind `oneStopDay`: Google's for a saved spot, none for a clicked point (straight line). */
  driveLegs: DriveLeg[];
  error: unknown;
  refetch(): void;
}

const ALL_VISIBILITIES: Visibility[] = ['hidden', 'normal', 'prominent'];
const PLACES_VISIBILITY: Visibility[] = ['normal'];
const NO_POINTS: DrivePoint[] = [];
const NO_WINDOWS: BestWindow[] = [];

interface Matched {
  subject: string;
  terms: SpotTerms;
  vectors: LocationVectors;
  point: LatLng;
}

/**
 * The host to estimate with: the server's resolution (it carries the point id the vectors were
 * computed with) plus what the draft may change without new vectors: the only-food flag, and the
 * size of a visitor host.
 */
function adoptHost(draft: Host | null, resolved: Host | null): Host | null {
  if (resolved === null) return null;
  if (draft === null) return resolved;
  const visitors = SEEDS.segments[resolved.segment].group === 'visitors';
  if (visitors && draft.size > 0) {
    return { ...resolved, only_food: draft.only_food, size: draft.size, size_source: draft.size_source };
  }
  return { ...resolved, only_food: draft.only_food };
}

function samePoint(a: LatLng, b: LatLng): boolean {
  return coord6(a.lat) === coord6(b.lat) && coord6(a.lng) === coord6(b.lng);
}

/**
 * Everything the spot card, the spot pages and the spot form show about one place
 * (docs/truck-planner/05_FRONTEND.md 2.5). All of it is computed in the browser from exact vectors:
 *
 * - A saved spot is evaluated from its stored vectors and sends nothing while its point, host link,
 *   host segment and, for a worker or resident host, host size are unchanged. A visibility change
 *   is therefore instant.
 * - A clicked point, and an edit that changes one of those four, asks `simulate`. So does a saved
 *   spot whose stored vectors do not match its own saved terms (its host was saved while the
 *   vectors could not be recomputed): it must not wait on screen for a refresh that may not come.
 * - The estimator is never called with terms and vectors that do not match (`vectorsMatch`). While
 *   they do not, the last matching estimate stays available with `dim` set.
 * - A clicked point never spends a drive-time request: its one-stop day uses the straight-line
 *   estimate. A saved spot asks for the two legs base to spot and back.
 */
export function useSpotEstimate(input: SpotEstimateInput): SpotEstimate {
  const { A, profile, cal, region, fuel } = useTruck();
  const today = useNow().date;
  const storedHours = useTruckUiStore((s) => s.windowHours);
  const hours = input.windowHours ?? storedHours;

  const spot = input.spot ?? null;
  const point = input.point ?? (spot !== null ? spot.point : null);
  const rebuilding = regionRebuilding(region);

  // Callers build `terms` inline: keep one object for as long as its content is the same.
  const termsKey = sortedJson(input.terms);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const terms = useMemo(() => input.terms, [termsKey]);

  const savedPlaceKey = spot?.host_details?.place_key ?? null;
  const placeKey = input.hostPlaceKey === undefined ? savedPlaceKey : input.hostPlaceKey;

  // Stored vectors serve while nothing that enters them has changed.
  const unchanged =
    spot !== null &&
    point !== null &&
    samePoint(point, spot.point) &&
    linkedHostKey(placeKey, terms.host) === linkedHostKey(savedPlaceKey, spot.terms.host);
  const stored = unchanged && spot !== null ? spotVectors(spot, terms.visibility) : null;
  // The terms to pair with them: the draft's, with the saved host (it carries the point id the
  // vectors were computed with). Stored vectors that do not match even those are not used: the
  // server is asked instead, like for an edit.
  const storedTerms = useMemo(
    (): SpotTerms | null => (unchanged && spot !== null ? { ...terms, host: adoptHost(terms.host, spot.terms.host) } : null),
    [unchanged, spot, terms],
  );
  const useStored = stored !== null && storedTerms !== null && vectorsMatch(A, storedTerms, stored);
  const noVectors = unchanged && stored === null;
  const needsSimulate = point !== null && !useStored && !noVectors;

  // The host as a request describes it. A host without a size yet cannot be asked for.
  const hostRequest = useMemo((): HostInput | null => {
    const host = terms.host;
    if (host === null) return placeKey ? { place_key: placeKey } : null;
    if (!(host.size > 0)) return placeKey ? { place_key: placeKey, segment: host.segment, only_food: host.only_food } : null;
    return {
      ...(placeKey ? { place_key: placeKey } : {}),
      segment: host.segment,
      size: host.size,
      size_source: host.size_source,
      only_food: host.only_food,
    };
  }, [terms, placeKey]);

  const pointLat = point === null ? null : point.lat;
  const pointLng = point === null ? null : point.lng;
  const editing = input.editing === true;
  const visibility = terms.visibility;
  const spotId = spot === null ? null : spot.id;

  const request = useMemo((): SimulateRequest | null => {
    if (pointLat === null || pointLng === null || !needsSimulate) return null;
    return {
      point: { lat: pointLat, lng: pointLng },
      host: hostRequest,
      visibilities: editing ? ALL_VISIBILITIES : [visibility],
      spotId,
    };
  }, [pointLat, pointLng, needsSimulate, hostRequest, editing, visibility, spotId]);
  const simulate = useSimulate(request);

  const placesRequest = useMemo((): SimulateRequest | null => {
    if (!useStored || input.withPlaces !== true || pointLat === null || pointLng === null) return null;
    return { point: { lat: pointLat, lng: pointLng }, host: hostRequest, visibilities: PLACES_VISIBILITY, spotId };
  }, [useStored, input.withPlaces, pointLat, pointLng, hostRequest, spotId]);
  const places = useSimulate(placesRequest);

  // What matches right now: terms with the host the vectors were computed for, and those vectors.
  // An answer kept as placeholder belongs to the previous host of this point: its outlets are still
  // right (they depend on the point alone), its vectors and its host are not. While the region data
  // is being rebuilt no answer counts as current, not even one still in the cache: what stays on
  // screen then is the last matching estimate, dimmed.
  const anyAnswer = request === null ? undefined : simulate.data;
  const answer = rebuilding || simulate.isPlaceholderData ? undefined : anyAnswer;
  const subject = (spotId ?? '') + '@' + (pointLat === null || pointLng === null ? '' : coord6(pointLat) + ',' + coord6(pointLng));
  const matched = useMemo((): Matched | null => {
    if (pointLat === null || pointLng === null) return null;
    const here = { lat: pointLat, lng: pointLng };
    if (useStored && storedTerms !== null && stored !== null) {
      return { subject, terms: storedTerms, vectors: stored, point: here };
    }
    if (answer === undefined) return null;
    const candidateTerms: SpotTerms = { ...terms, host: adoptHost(terms.host, answer.host) };
    const vectors: LocationVectors | undefined = answer.vectors[terms.visibility];
    if (vectors === undefined || !vectorsMatch(A, candidateTerms, vectors)) return null;
    return { subject, terms: candidateTerms, vectors, point: here };
  }, [A, terms, useStored, storedTerms, stored, answer, subject, pointLat, pointLng]);

  // Keep the last matching estimate of THIS place; another place never inherits numbers.
  const last = useRef<Matched | null>(null);
  if (matched !== null) last.current = matched;
  else if (last.current !== null && last.current.subject !== subject) last.current = null;
  const shown = matched ?? last.current;
  const dim = matched === null && shown !== null;

  // A saved spot asks for its two drive legs; a clicked point never spends a drive-time request.
  const drivePoints = useMemo((): DrivePoint[] => {
    if (!unchanged || spot === null) return NO_POINTS;
    return [
      { id: 'base', lat: profile.base.lat, lng: profile.base.lng },
      { id: spot.id, lat: spot.point.lat, lng: spot.point.lng },
    ];
  }, [unchanged, spot, profile.base.lat, profile.base.lng]);
  const drivePairs = useMemo(
    (): [string, string][] | undefined =>
      drivePoints.length === 2 ? [['base', drivePoints[1].id], [drivePoints[1].id, 'base']] : undefined,
    [drivePoints],
  );
  const drive = useDriveTimes(drivePoints, drivePairs);

  const typical = useMemo(() => [0, 1, 2, 3, 4, 5, 6].map((dow) => typicalContext(A, dow)), [A]);
  const typicalFuel = useMemo(() => [0, 1, 2, 3, 4, 5, 6].map((dow) => typicalWithFuel(A, dow, fuel)), [A, fuel]);

  const week = useMemo(
    () => (shown === null ? null : weekStrip(A, profile, shown.terms, shown.vectors, cal)),
    [A, profile, cal, shown],
  );
  const best = useMemo(() => (week === null ? NO_WINDOWS : bestWindows(week, hours, 3, true)), [week, hours]);

  const windowOn = useCallback(
    (dow: number, open: number, close: number): WindowResult | null => {
      if (shown === null) return null;
      // The model raises `invalid_window` for these; a half-typed time must not take the page down.
      if (!(open >= 0 && open <= close && close <= 2880)) return null;
      const d = modFloor(dow, 7);
      return windowOrders(A, profile, shown.terms, shown.vectors, cal, typical[d], typical[(d + 1) % 7], open, close);
    },
    [A, profile, cal, shown, typical],
  );

  const hour = useCallback(
    (how: number): WindowResult | null => {
      const h = modFloor(how, 168);
      const clock = h % 24;
      return windowOn((h - clock) / 24, clock * 60, clock * 60 + 60);
    },
    [windowOn],
  );

  const driveReady = drive.status !== 'pending';
  const legInputs = drive.legInputs;
  const oneStopDay = useCallback(
    (dow: number, open: number, close: number): DayResult | null => {
      if (shown === null || !driveReady) return null;
      // A window that is merely wrong goes to the model, which answers with its own warning.
      if (!Number.isFinite(open) || !Number.isFinite(close)) return null;
      const d = modFloor(dow, 7);
      const stop: StopInput = {
        id: spotId ?? 'point',
        kind: 'spot',
        spot_id: shown.terms.spot_id,
        point: shown.point,
        open_minute: open,
        close_minute: close,
        gap_before_unpaid: false,
        setup_minutes: null,
        teardown_minutes: null,
        terms: shown.terms,
        vectors: shown.vectors,
        event: null,
        catering: null,
      };
      // The date is only echoed by the model; the contexts are those of a typical week.
      const plan = { date: nextDateWithDow(today, d), stops: [stop] };
      return dayPlan(A, profile, plan, typicalFuel[d], typicalFuel[(d + 1) % 7], legInputs, cal);
    },
    [A, profile, cal, shown, driveReady, spotId, today, typicalFuel, legInputs],
  );

  const placesAnswer = useStored ? places.data : anyAnswer;
  const located = useMemo((): Located | null => {
    if (placesAnswer !== undefined) return placesAnswer.located;
    if (shown === null) return null;
    return {
      in_region: shown.vectors.in_region,
      region_id: shown.vectors.region_id,
      county_fips: spot !== null ? spot.county_fips : null,
      state: null,
    };
  }, [placesAnswer, shown, spot]);

  let status: SpotEstimate['status'];
  if (noVectors) status = 'none';
  else if (request !== null && rebuilding) status = 'rebuilding';
  else if (shown === null) status = request !== null && simulate.isError ? 'error' : 'pending';
  else if (!shown.vectors.in_region && shown.vectors.points_used === 0) status = 'outside';
  else status = 'ready';

  const refetchSimulate = simulate.refetch;
  const refetchPlaces = places.refetch;
  const refetchDrive = drive.refetch;
  const refetch = useCallback(() => {
    if (request !== null) void refetchSimulate();
    if (placesRequest !== null) void refetchPlaces();
    refetchDrive();
  }, [request, placesRequest, refetchSimulate, refetchPlaces, refetchDrive]);

  return {
    status,
    dim,
    terms: shown === null ? null : shown.terms,
    vectors: shown === null ? null : shown.vectors,
    located,
    resolvedHost: answer === undefined ? null : answer.host,
    outlets: placesAnswer === undefined ? null : placesAnswer.outlets,
    outletsTotal: placesAnswer === undefined ? null : placesAnswer.outlets_total,
    hostsNearby: placesAnswer === undefined ? null : placesAnswer.hosts_nearby,
    week,
    best,
    hour,
    windowOn,
    oneStopDay,
    driveLegs: drive.legs,
    error: request !== null ? simulate.error : null,
    refetch,
  };
}

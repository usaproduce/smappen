import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { MapPinned, Route } from 'lucide-react';
import toast from 'react-hot-toast';
import { apiErrorMessage, type Spot } from '../../../api/truck';
import { useTruckPlanDraftStore, type DraftStop, type PlanDraft } from '../../../stores/truckPlanDraftStore';
import { indexSpots, spotVectors, trafficIsNeutral } from '../../../utils/truck/assemble';
import { fmtDay, fmtWindow } from '../../../utils/truck/format';
import { dayRoute } from '../../../utils/truck/links';
import { addDays, parseDate } from '../../../utils/truck/model';
import type { LatLng, Suggestion } from '../../../utils/truck/model';
import {
  SAVE_STATE_TEXT,
  addRequest,
  addStop,
  addedMessage,
  calendarStops,
  defaultSpotWindow,
  draftChanged,
  freeWindow,
  insertIndex,
  isStopNotice,
  legViews,
  markWaitsUnpaid,
  moveStop,
  moveStopTo,
  movedMessage,
  newCateringStop,
  newEventStop,
  newSpotStop,
  patchStop,
  planBody,
  removeStop,
  removedMessage,
  replaceStops,
  routePoints,
  saveBlocker,
  saveStateOf,
  setTreatAs,
  spotOfStop,
  stopIndex,
  stopLimitText,
  stopNames,
  stopPoint,
  stopProblem,
  stopWarningCodes,
  weatherHours,
  type LegView,
  type StopWindow,
} from '../../../utils/truck/planDraft';
import { warningRows } from '../../../utils/truck/warnings';
import ErrorBoundary from '../../ErrorBoundary';
import {
  regionRebuilding,
  useDayContexts,
  useDeletePlan,
  useNow,
  usePlanEvaluation,
  usePlanForDate,
  useSavePlan,
  useSpots,
  useTruck,
} from '../data';
import AddStopMenu from '../planner/AddStopMenu';
import BottomBar from '../planner/BottomBar';
import DaySummary from '../planner/DaySummary';
import LegEditor from '../planner/LegEditor';
import PlannerActions, { SaveDayRow, type SaveControl } from '../planner/PlannerActions';
import PlannerHeader from '../planner/PlannerHeader';
import StopList, { type StopListItem } from '../planner/StopList';
import SuggestDayPanel from '../planner/SuggestDayPanel';
import UnpaidGapCard from '../planner/UnpaidGapCard';
import {
  EmptyState,
  Modal,
  PermissionNotice,
  QueryError,
  SkeletonCard,
  SkeletonRows,
  Timeline,
  WarningList,
  WhyDrawer,
  type WhySubject,
} from '../ui';

/** True for a civil date the model accepts: `YYYY-MM-DD`, 1970-01-01 to 2199-12-31. */
function isDate(text: string | undefined): text is string {
  if (text === undefined) return false;
  try {
    parseDate(text);
    return true;
  } catch {
    return false;
  }
}

/**
 * The planner (docs/truck-planner/05_FRONTEND.md 4.5): one day, its stops in the owner's order,
 * the drives between them, the timeline with its leave-by times, and what the day clears.
 *
 * The date in the URL is checked before anything else: anything that is not a date goes to today,
 * in the truck's time zone. So does the last date of the model's calendar, because a day is always
 * evaluated with the context of the day after it.
 */
export default function PlannerPage() {
  const { date } = useParams();
  const today = useNow().date;
  if (!isDate(date) || !isDate(addDays(date, 1))) return <Navigate to={'/truck/plan/' + today} replace />;
  // Keyed by the date: another day starts with its own open dialogs and its own prefill.
  return <PlannerDay key={date} date={date} today={today} />;
}

const NO_STOPS: DraftStop[] = [];
const CARD = 'bg-white rounded-xl border p-4 sm:p-5';
const DAY_FAILED = 'Could not load this day.';
const SPOTS_FAILED = 'Could not load your spots.';

function withoutParams(current: URLSearchParams, names: readonly string[]): URLSearchParams {
  const next = new URLSearchParams(current);
  for (const name of names) next.delete(name);
  return next;
}

function PlannerDay({ date, today }: { date: string; today: string }) {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const { A, profile, cal, region, limits } = useTruck();

  // ---- What the day is built from ---------------------------------------------------------------
  const spotsQuery = useSpots({ archived: true });
  const spots = spotsQuery.data;
  const spotsById = useMemo(() => indexSpots(spots ?? []), [spots]);
  const activeSpots = useMemo(() => (spots ?? []).filter((spot) => !spot.archived), [spots]);

  const planFor = usePlanForDate(date);
  const draft = useTruckPlanDraftStore((s) => s.drafts[date]);
  const load = useTruckPlanDraftStore((s) => s.load);
  const patch = useTruckPlanDraftStore((s) => s.patch);
  const markSaved = useTruckPlanDraftStore((s) => s.markSaved);
  const discard = useTruckPlanDraftStore((s) => s.discard);
  const anyDirty = useTruckPlanDraftStore((s) => {
    for (const key of Object.keys(s.drafts)) {
      if (s.drafts[key].dirty) return true;
    }
    return false;
  });

  // The saved day (or an empty one) becomes the draft; the store ignores it while the owner has
  // unsaved edits for this date.
  useEffect(() => {
    if (planFor.status === 'ready') load(date, planFor.plan);
  }, [date, planFor.status, planFor.plan, load]);

  const stops = draft === undefined ? NO_STOPS : draft.stops;
  const treatAs = draft === undefined ? null : draft.treat_as;
  const evaluation = usePlanEvaluation(date, stops, treatAs);
  const treatByDate = useMemo(() => ({ [date]: treatAs }), [date, treatAs]);
  const days = useDayContexts(date, 2, treatByDate);
  const ctx = days.contexts[date] ?? null;
  const ctxNext = days.contexts[addDays(date, 1)] ?? null;

  const result = evaluation.result;
  // A day with a stop that closes before it opens, or that opens before the one before it closes,
  // is not evaluated: the model answers with those problems and nothing else.
  const evaluated = result !== null && stops.length > 0 && result.stops.length === stops.length;
  const pending = evaluation.status === 'pending';
  const dim = evaluation.updating;
  const neutral = useMemo(() => trafficIsNeutral(A), [A]);
  const names = useMemo(() => stopNames(stops, spotsById), [stops, spotsById]);
  const legs = useMemo(
    () => (result !== null && evaluated ? legViews(result.timeline, evaluation.legs, neutral) : []),
    [result, evaluated, evaluation.legs, neutral],
  );
  const rows = useMemo(() => (result === null ? [] : warningRows(result, names, ctx)), [result, names, ctx]);

  // ---- Saving -----------------------------------------------------------------------------------
  const known = planFor.status === 'ready';
  const savedPlan = known ? planFor.plan : null;
  const changed = draft === undefined ? false : known ? draftChanged(draft, savedPlan) : draft.dirty;
  const planId = known ? (savedPlan === null ? null : savedPlan.id) : draft === undefined ? null : draft.planId;
  // A day that was only suggested (status "draft") becomes a planned day when it is saved.
  const needsSave = changed || (savedPlan !== null && savedPlan.status === 'draft');
  const blocker = draft === undefined ? null : saveBlocker(draft, result);
  const state = saveStateOf(savedPlan, changed);

  // Edits the owner took back by hand leave nothing to save: the draft is the saved day again, so
  // a later change made elsewhere is no longer held off.
  useEffect(() => {
    if (draft === undefined || !draft.dirty || !known || changed) return;
    if (savedPlan !== null) markSaved(date, savedPlan);
    else {
      discard(date);
      load(date, null);
    }
  }, [draft, known, changed, savedPlan, date, markSaved, discard, load]);

  // Closing the tab with unsaved edits asks first.
  useEffect(() => {
    if (!anyDirty) return undefined;
    const warn = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [anyDirty]);

  const { mutateAsync: savePlanAsync, isPending: saving } = useSavePlan();
  const { mutate: deletePlan, isPending: clearing } = useDeletePlan();
  const localResult = evaluated ? result : null;

  const saveDay = useCallback(async (): Promise<boolean> => {
    const current = useTruckPlanDraftStore.getState().drafts[date];
    if (current === undefined) return false;
    try {
      await savePlanAsync({ date, planId, body: planBody(current), localResult });
      return true;
    } catch {
      return false; // the hook has shown the server's sentence
    }
  }, [date, planId, localResult, savePlanAsync]);

  const saveControl: SaveControl = {
    enabled: needsSave && blocker === null && !saving,
    saving,
    hint: blocker,
    onSave: () => {
      void saveDay();
    },
  };

  const clearDay = () => {
    if (savedPlan !== null) deletePlan({ id: savedPlan.id, date });
    else {
      discard(date);
      load(date, null);
    }
  };

  // ---- Edits ------------------------------------------------------------------------------------
  const [announcement, setAnnouncement] = useState('');
  const [revealId, setRevealId] = useState<string | null>(null);
  const [addOpen, setAddOpen] = useState(false);
  const [editingKey, setEditingKey] = useState<string | null>(null);
  const [why, setWhy] = useState<WhySubject | null>(null);
  const [suggested, setSuggested] = useState<Suggestion | null>(null);
  const focusAdd = useRef(false);

  const edit = useCallback((change: (d: PlanDraft) => PlanDraft) => patch(date, change), [patch, date]);
  const stopLimit = limits.max_stops_per_plan;
  const full = stops.length >= stopLimit;

  /** Add a stop where its window falls in the day; the stops already there keep their order. */
  const place = (stop: DraftStop, name: string) => {
    const current = useTruckPlanDraftStore.getState().drafts[date];
    const present = current === undefined ? NO_STOPS : current.stops;
    if (present.length >= stopLimit) {
      toast.error(stopLimitText(stopLimit));
      return;
    }
    const at = insertIndex(present, { open: stop.open_minute, close: stop.close_minute });
    edit((d) => addStop(d, stop, at));
    setAnnouncement(addedMessage(name, at + 1, present.length + 1));
    setRevealId(stop.id);
  };

  const addSpot = (spot: Spot, window: StopWindow | null) => {
    const current = useTruckPlanDraftStore.getState().drafts[date];
    const present = current === undefined ? NO_STOPS : current.stops;
    place(newSpotStop(spot.id, window !== null ? window : defaultSpotWindow(A, profile, cal, ctx, spot, present)), spot.name);
  };

  const removeOne = (stopId: string) => {
    if (draft === undefined) return;
    const index = stopIndex(draft, stopId);
    if (index < 0) return;
    const removed = draft.stops[index];
    const name = names[index];
    edit((d) => removeStop(d, stopId));
    setAnnouncement(removedMessage(name));
    focusAdd.current = true;
    toast(
      (t) => (
        <span>
          Removed {name}.{' '}
          <button
            type="button"
            className="font-bold underline underline-offset-2"
            onClick={() => {
              toast.dismiss(t.id);
              edit((d) => (stopIndex(d, removed.id) >= 0 || d.stops.length >= stopLimit ? d : addStop(d, removed, index)));
            }}
          >
            Undo
          </button>
        </span>
      ),
      { duration: 6000 },
    );
  };

  // The button that removed a stop is gone with its card: focus moves on to "Add stop".
  useEffect(() => {
    if (!focusAdd.current) return;
    focusAdd.current = false;
    const button = document.querySelector<HTMLElement>('[data-tp-add-stop]');
    if (button !== null) button.focus({ preventScroll: true });
  }, [stops]);

  const moveBy = (stopId: string, delta: -1 | 1) => {
    if (draft === undefined) return;
    const from = stopIndex(draft, stopId);
    const to = from + delta;
    if (from < 0 || to < 0 || to >= draft.stops.length) return;
    edit((d) => moveStop(d, stopId, delta));
    setAnnouncement(movedMessage(names[from], to + 1, draft.stops.length));
  };

  const moveTo = (stopId: string, toIndex: number) => {
    if (draft === undefined) return;
    const from = stopIndex(draft, stopId);
    if (from < 0 || toIndex === from) return;
    edit((d) => moveStopTo(d, stopId, toIndex));
    setAnnouncement(movedMessage(names[from], toIndex + 1, draft.stops.length));
  };

  // ---- Deep links: ?add=<spotId>&open=&close= adds that spot once; ?suggest=1 opens the panel ----
  const request = addRequest(params);
  const requestKey =
    request === null ? null : request.spotId + '|' + (request.window === null ? '' : String(request.window.open) + '-' + String(request.window.close));
  const handledRequest = useRef<string | null>(null);
  useEffect(() => {
    if (request === null || requestKey === null) {
      handledRequest.current = null;
      return;
    }
    // The day and the spots first; without a window in the link, the date's context too.
    if (draft === undefined || spots === undefined) return;
    if (request.window === null && days.status === 'pending') return;
    if (handledRequest.current === requestKey) return;
    handledRequest.current = requestKey;
    const spot = spotsById.get(request.spotId);
    if (spot === undefined || spot.archived) toast.error('That spot is not in your list.');
    else addSpot(spot, request.window);
    setParams((current) => withoutParams(current, ['add', 'open', 'close']), { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [requestKey, draft === undefined, spots === undefined, days.status]);

  const suggestOpen = params.get('suggest') === '1';
  const closeSuggest = () => setParams((current) => withoutParams(current, ['suggest']), { replace: true });
  const takeSuggestion = (suggestion: Suggestion) => {
    edit((d) => replaceStops(d, suggestion.stops));
    setSuggested(null);
    closeSuggest();
  };

  // ---- "Why this number" ------------------------------------------------------------------------
  const whyDay = () => {
    if (result === null || !evaluated || ctx === null) return;
    setWhy({ kind: 'day', title: 'Take-home on ' + fmtDay(date, 'medium'), result, stopNames: names, ctx });
  };

  const whyStop = (index: number): (() => void) | undefined => {
    if (result === null || !evaluated || ctx === null) return undefined;
    const stop = stops[index];
    const dayStop = result.stops[index];
    const timed = result.timeline.stops[index];
    const title = names[index] + ', ' + fmtWindow(timed.effective_open, stop.close_minute) + ' on ' + fmtDay(date, 'medium');
    if (stop.kind === 'spot') {
      const spot = spotOfStop(stop, spotsById);
      const vectors = spot === null ? null : spotVectors(spot);
      const window = dayStop.window;
      if (spot === null || vectors === null || window === null) return undefined;
      return () => setWhy({ kind: 'window', title, window, vectors, terms: { ...spot.terms, spot_id: spot.id }, ctx, ctxNext, money: dayStop.money });
    }
    if (stop.kind === 'event') {
      const event = stop.event;
      if (event === null) return undefined;
      return () => setWhy({ kind: 'event', title, stop: dayStop, event, ctx, ctxNext });
    }
    return undefined; // a catering job is contracted, not estimated: the day's breakdown lists it
  };

  // ---- Drives -----------------------------------------------------------------------------------
  const base: LatLng = { lat: profile.base.lat, lng: profile.base.lng };
  const pointOf = (id: string): LatLng | null => {
    if (id === 'base') return base;
    for (const stop of stops) {
      if (stop.id === id) return stopPoint(stop, spotsById);
    }
    return null;
  };
  const legEnds = (leg: LegView) => {
    const origin = pointOf(leg.fromId);
    const destination = pointOf(leg.toId);
    return origin === null || destination === null ? null : { origin, destination };
  };
  const editing = editingKey === null ? null : legs.find((leg) => leg.key === editingKey) ?? null;
  const editingEnds = editing === null ? null : legEnds(editing);

  // ---- What is on the page ----------------------------------------------------------------------
  const hours = weatherHours(stops);
  const route = routePoints(stops, spotsById);
  const inDay = useMemo(() => {
    const ids = new Set<string>();
    for (const stop of stops) {
      if (stop.spot_id !== null) ids.add(stop.spot_id);
    }
    return ids;
  }, [stops]);

  const header = (
    <PlannerHeader
      date={date}
      onDate={(next) => navigate('/truck/plan/' + next)}
      context={ctx}
      forecast={days.forecast}
      fromHour={hours.fromHour}
      toHour={hours.toHour}
      treatAs={treatAs}
      onTreatAs={(value) => edit((d) => setTreatAs(d, value))}
      saveState={draft === undefined ? 'none' : state}
      past={date < today}
      driveStrip={evaluation.notes.driveFallback}
      forecastFailed={days.status === 'degraded'}
      onRetryForecast={days.refetch}
    />
  );

  const addMenu = (hideButton: boolean) => (
    <AddStopMenu
      open={addOpen}
      onOpen={() => setAddOpen(true)}
      onClose={() => setAddOpen(false)}
      spots={activeSpots}
      inDay={inDay}
      limitText={full ? stopLimitText(stopLimit) : null}
      hideButton={hideButton}
      onAddSpot={(spot) => {
        setAddOpen(false);
        addSpot(spot, null);
      }}
      onAddEvent={() => {
        setAddOpen(false);
        place(newEventStop(A, freeWindow(stops)), 'an event');
      }}
      onAddCatering={() => {
        setAddOpen(false);
        place(newCateringStop(freeWindow(stops)), 'a catering job');
      }}
    />
  );

  const actions = (
    <PlannerActions
      date={date}
      changed={changed}
      saved={savedPlan !== null}
      hasContent={stops.length > 0 || treatAs !== null}
      save={saveDay}
      saveHint={blocker}
      saving={saving}
      routeHref={!changed && savedPlan !== null ? savedPlan.maps_route_url : null}
      route={route.length > 0 ? dayRoute(base, route) : null}
      calendar={{ result: localResult, stops: draft === undefined ? [] : calendarStops(stops, spotsById) }}
      onClear={clearDay}
      clearing={clearing}
    />
  );

  let body: ReactNode;
  let bar = false;

  if (draft === undefined || spots === undefined) {
    const dayFailed = draft === undefined && planFor.status === 'error';
    const spotsFailed = spots === undefined && spotsQuery.isError;
    body = dayFailed ? (
      <QueryError message={apiErrorMessage(planFor.error, DAY_FAILED) ?? DAY_FAILED} onRetry={planFor.refetch} />
    ) : spotsFailed ? (
      <QueryError
        message={apiErrorMessage(spotsQuery.error, SPOTS_FAILED) ?? SPOTS_FAILED}
        onRetry={() => {
          void spotsQuery.refetch();
        }}
      />
    ) : (
      <div className="grid gap-4 lg:grid-cols-12" aria-busy="true">
        <SkeletonRows rows={2} rowHeight={220} className="lg:col-span-7" />
        <SkeletonCard height={360} className="lg:col-span-5" />
      </div>
    );
    body = (
      <>
        {body}
        <PermissionNotice variant="line" />
      </>
    );
  } else if (stops.length === 0) {
    // Nothing to add from: the planner works from saved spots.
    const noSpots = activeSpots.length === 0;
    bar = needsSave;
    body = (
      <>
        {noSpots ? (
          <EmptyState icon={MapPinned} title="Save a spot first" body="The planner works from your saved spots." action={{ label: 'Open the map', to: '/truck/map' }} />
        ) : (
          <EmptyState
            icon={Route}
            title={'Nothing planned for ' + fmtDay(date, 'medium')}
            body="Add a stop to see drive times, costs and take-home."
            // "Save day" is the one primary action while a change waits to be saved.
            action={needsSave ? undefined : { label: 'Add a stop', onClick: () => setAddOpen(true) }}
            secondary={needsSave ? { label: 'Add a stop', onClick: () => setAddOpen(true) } : undefined}
          />
        )}
        {addMenu(true)}
        {needsSave ? (
          <div className="hidden md:block md:max-w-md">
            <SaveDayRow save={saveControl} />
          </div>
        ) : null}
        {savedPlan !== null || needsSave ? <div className="md:max-w-md">{actions}</div> : null}
        <PermissionNotice variant="line" />
      </>
    );
  } else {
    bar = true;
    const items: StopListItem[] = stops.map((stop, index) => ({
      stop,
      name: names[index],
      spot: spotOfStop(stop, spotsById),
      point: stopPoint(stop, spotsById),
      problem: stopProblem(stop, spotsById),
      evaluated: result !== null && evaluated ? result.stops[index] : null,
      timed: result !== null && evaluated ? result.timeline.stops[index] : null,
      warningCodes: result === null ? [] : stopWarningCodes(result, index),
      notices: rows.filter((row) => isStopNotice(row, index)),
      onWhy: whyStop(index),
    }));

    const summary =
      result !== null && evaluated && ctx !== null ? (
        <ErrorBoundary scope="Day summary" inline>
          <DaySummary result={result} profile={profile} context={ctx} dim={dim} onWhy={whyDay} />
        </ErrorBoundary>
      ) : evaluation.status === 'error' ? (
        <QueryError message={apiErrorMessage(evaluation.error, SPOTS_FAILED) ?? SPOTS_FAILED} onRetry={evaluation.refetch} />
      ) : pending ? (
        <div aria-busy="true">
          <SkeletonCard height={320} />
        </div>
      ) : (
        <section className={CARD} style={{ borderColor: 'var(--line-soft)' }}>
          <h2 className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
            No figures for this day yet
          </h2>
          <p className="mt-1 text-sm font-semibold" style={{ color: 'var(--body)' }}>
            {evaluation.status === 'unavailable'
              ? 'One of the stops cannot be worked out yet. Its card says why.'
              : 'Take-home, costs and the timeline show once the problems below are fixed.'}
          </p>
        </section>
      );

    body = (
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-start">
        {/* One column below 1024 px: the wrappers dissolve and the blocks take the order of the
            width (phone: stops, timeline, costs, checks, actions; tablet: costs first). */}
        <div className="contents lg:col-span-7 lg:block lg:min-w-0 lg:space-y-4">
          <div className="order-1 min-w-0 space-y-3 md:order-2">
            <StopList
              items={items}
              legs={legs}
              legEnds={legEnds}
              spots={activeSpots}
              pending={pending}
              dim={dim}
              rebuilding={regionRebuilding(region)}
              setupDefault={profile.setup_minutes}
              teardownDefault={profile.teardown_minutes}
              announcement={announcement}
              revealId={revealId}
              onPatch={(stopId, change) => edit((d) => patchStop(d, stopId, change))}
              onMove={moveBy}
              onMoveTo={moveTo}
              onRemove={removeOne}
              onEditLeg={(leg) => setEditingKey(leg.key)}
            />
            {addMenu(false)}
          </div>
          <section className={CARD + ' order-2 min-w-0 md:order-3'} style={{ borderColor: 'var(--line-soft)' }}>
            <h2 className="mb-2 text-base font-extrabold" style={{ color: 'var(--ink)' }}>
              The day, start to finish
            </h2>
            {result !== null && evaluated ? (
              <div className={dim ? 'tp-dim' : undefined}>
                <Timeline timeline={result.timeline} stopNames={names} />
              </div>
            ) : pending ? (
              <div aria-busy="true">
                <SkeletonRows rows={3} rowHeight={28} />
              </div>
            ) : (
              <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
                The times of the day show once it can be worked out.
              </p>
            )}
          </section>
        </div>

        {/* From 1024 px: one column that stays under the sub-navigation and scrolls on its own when
            it is taller than the window, with "Save day" kept in view at its foot. */}
        <div className="contents lg:sticky lg:top-[110px] lg:col-span-5 lg:flex lg:max-h-[calc(100dvh_-_126px)] lg:min-w-0 lg:flex-col">
          <div className="contents lg:block lg:min-h-0 lg:flex-1 lg:space-y-4 lg:overflow-y-auto lg:[scrollbar-width:thin]">
            <div className="order-3 min-w-0 md:order-1">{summary}</div>
            {result !== null && evaluated && result.unpaid_gap_alternative !== null ? (
              <div className="order-4 min-w-0">
                <UnpaidGapCard
                  alternative={result.unpaid_gap_alternative}
                  dim={dim}
                  onMark={() => edit((d) => markWaitsUnpaid(d, result.timeline))}
                />
              </div>
            ) : null}
            {result !== null && ctx !== null && result.warnings.length > 0 ? (
              <section className={CARD + ' order-5 min-w-0'} style={{ borderColor: 'var(--line-soft)' }}>
                <h2 className="mb-2 text-base font-extrabold" style={{ color: 'var(--ink)' }}>
                  Things to check
                </h2>
                <WarningList result={result} stopNames={names} context={ctx} />
              </section>
            ) : null}
            <div className="order-6 min-w-0 space-y-3">
              {actions}
              <PermissionNotice variant="line" />
            </div>
          </div>
          {/* Last on the page in every one-column layout that shows it, as it is last in the document. */}
          <div className="order-7 hidden md:block lg:flex-none lg:pt-3">
            <SaveDayRow save={saveControl} />
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className={'space-y-4' + (bar ? ' pb-24 md:pb-0' : '')}>
      {header}
      {body}

      {bar ? (
        <BottomBar
          takeHome={result !== null && evaluated ? result.totals.take_home : null}
          dim={dim}
          text={blocker !== null ? blocker : state === 'none' ? null : SAVE_STATE_TEXT[state]}
          save={saveControl}
        />
      ) : null}

      {editing !== null && editingEnds !== null ? (
        <LegEditor
          key={editing.key}
          open
          onClose={() => setEditingKey(null)}
          fromName={editing.fromStop === null ? 'base' : names[editing.fromStop]}
          toName={editing.toStop === null ? 'base' : names[editing.toStop]}
          from={editingEnds.origin}
          to={editingEnds.destination}
          sent={editing.sent}
          trafficNeutral={neutral}
        />
      ) : null}

      <WhyDrawer open={why !== null} onClose={() => setWhy(null)} subject={why} />

      <SuggestDayPanel
        date={date}
        treatAs={treatAs}
        open={suggestOpen}
        onClose={closeSuggest}
        onUse={(suggestion) => {
          // A day that already has stops is replaced only after the owner says so.
          if (stops.length > 0) setSuggested(suggestion);
          else takeSuggestion(suggestion);
        }}
      />
      <Modal
        open={suggested !== null}
        onClose={() => setSuggested(null)}
        title="Replace the stops of this day?"
        size="sm"
        footer={
          <>
            <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={() => setSuggested(null)}>
              Keep my stops
            </button>
            <button
              type="button"
              className="btn btn-primary h-11 md:h-9 px-3 text-sm"
              onClick={() => {
                if (suggested !== null) takeSuggestion(suggested);
              }}
            >
              Use this plan
            </button>
          </>
        }
      >
        <p>The suggested stops take the place of the ones in this day. Nothing is saved until you save the day.</p>
      </Modal>
    </div>
  );
}

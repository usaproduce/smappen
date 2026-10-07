import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { CalendarDays, Info, MapPinned, Route, TriangleAlert } from 'lucide-react';
import { apiErrorMessage, type PlanStop } from '../../../api/truck';
import { useTruckPlanDraftStore } from '../../../stores/truckPlanDraftStore';
import { indexSpots } from '../../../utils/truck/assemble';
import { fmtDay, fmtWindow } from '../../../utils/truck/format';
import { serviceName, spotNamesOf } from '../../../utils/truck/logForm';
import { unloggedStops } from '../../../utils/truck/logView';
import { addDays } from '../../../utils/truck/model';
import {
  LOG_TEXT,
  PLAN_TEXT,
  chipHours,
  isEvaluated,
  isPlanned,
  latestService,
  mayStartTonight,
  mayStillRun,
  planOn,
  plannedCountText,
  plannedDates,
  weatherEffect,
} from '../../../utils/truck/nextAction';
import { draftChanged, stopNames, weatherHours } from '../../../utils/truck/planDraft';
import { mondayOf, weekDates } from '../../../utils/truck/time';
import { STRIPS } from '../../../utils/truck/wording';
import { useDayContexts, useNow, usePlanEvaluation, usePlans, useServices, useSpots, useTruck } from '../data';
import FuelLine from '../today/FuelLine';
import NextAction, { type TodayTimeline } from '../today/NextAction';
import TodayPlanCard from '../today/TodayPlanCard';
import ToLogCard from '../today/ToLogCard';
import WeatherAtStops, { type WeatherStop } from '../today/WeatherAtStops';
import { EmptyState, HolidayChip, QueryError, SkeletonCard, WeatherChip, WhyDrawer, type WhySubject } from '../ui';

const NO_STOPS: PlanStop[] = [];
const CARD = 'bg-white rounded-xl border p-4 sm:p-5';
/** Unlogged stops are looked for this many days back, and at most this many are listed. */
const DAYS_BACK = 7;
const UNLOGGED_LIMIT = 5;

/** A strip under the header: something behind the numbers is missing. */
function Strip({ children }: { children: ReactNode }) {
  return (
    <div
      role="status"
      className="flex items-start gap-2 rounded-xl border px-3 py-2.5 text-sm font-semibold"
      style={{ background: 'var(--fresh-aging-bg)', borderColor: 'var(--line-soft)', color: 'var(--ink)' }}
    >
      <TriangleAlert size={15} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--fresh-aging)' }} />
      <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1">{children}</div>
    </div>
  );
}

/**
 * Today (docs/truck-planner/05_FRONTEND.md 4.1), the landing page of Truck Planner. It is about
 * operations and has no map: the next thing to do, today's plan with its leave-by times and what
 * it is expected to clear, what is left to log, the weather over the stops, the week at a glance
 * and the fuel price in use.
 *
 * "Today" and "now" are the truck's, never the device's (`useNow`). Every figure is the saved
 * plan as the browser's estimator evaluates it, the same evaluation the planner shows. Each block
 * loads and fails on its own, so one failed request never blanks the page.
 */
export default function TodayPage() {
  const { counts } = useTruck();
  const now = useNow();
  const today = now.date;
  const minute = now.minute;
  const from = useMemo(() => addDays(today, -DAYS_BACK), [today]);
  const to = useMemo(() => addDays(today, 6), [today]);
  const tomorrowDate = useMemo(() => addDays(today, 1), [today]);
  const yesterdayDate = useMemo(() => addDays(today, -1), [today]);

  // ---- What the page is built from ---------------------------------------------------------------
  const plansQuery = usePlans(from, to);
  const plans = plansQuery.data;
  const spotsQuery = useSpots({ archived: true });
  const spots = spotsQuery.data;
  const servicesQuery = useServices({ from, to: today });
  const services = servicesQuery.data;
  const spotsById = useMemo(() => indexSpots(spots ?? []), [spots]);
  const spotNames = useMemo(() => spotNamesOf(spots ?? []), [spots]);

  // ---- Today's plan and its evaluation -------------------------------------------------------------
  const saved = planOn(plans, today);
  const plan = isPlanned(saved) ? saved : null;
  const stops = plan === null ? NO_STOPS : plan.stops;
  const treatAs = saved !== null && saved.status !== 'cancelled' ? saved.treat_as : null;
  const evaluation = usePlanEvaluation(today, stops, treatAs);
  const treatByDate = useMemo(() => ({ [today]: treatAs }), [today, treatAs]);
  const days = useDayContexts(today, 2, treatByDate);
  const ctx = days.contexts[today] ?? null;
  const ctxNext = days.contexts[tomorrowDate] ?? null;
  const result = evaluation.result;
  const evaluated = isEvaluated(result, stops.length);
  const names = useMemo(() => stopNames(stops, spotsById), [stops, spotsById]);
  const todayTimeline = useMemo<TodayTimeline | null>(
    () => (isEvaluated(result, stops.length) ? { timeline: result.timeline, stopNames: names } : null),
    [result, stops.length, names],
  );

  // The planner keeps its unsaved edits in memory: when it holds some for today, the page says so.
  const draft = useTruckPlanDraftStore((s) => s.drafts[today]);
  const unsaved = plans !== undefined && draft !== undefined && draft.dirty && draftChanged(draft, saved);

  // A day is not cut off at midnight: yesterday's late service, and an early start tonight.
  const yesterdayPlan = planOn(plans, yesterdayDate);
  const tomorrowPlan = planOn(plans, tomorrowDate);
  const yesterday = isPlanned(yesterdayPlan) && mayStillRun(yesterdayPlan.stops, minute) ? yesterdayPlan : null;
  const tomorrow = isPlanned(tomorrowPlan) && mayStartTonight(tomorrowPlan.stops) ? tomorrowPlan : null;

  // ---- "Log what happened" -----------------------------------------------------------------------
  const unlogged = useMemo(
    () =>
      plans === undefined || services === undefined || spots === undefined
        ? undefined
        : unloggedStops(plans, services, { date: today, minute }, { spotNames, since: from, limit: UNLOGGED_LIMIT }),
    [plans, services, spots, spotNames, today, minute, from],
  );
  const last = latestService(services);
  const latest = last === null || spots === undefined ? null : { service: last, name: serviceName(last, spotNames) };
  let logFailed: string | null = null;
  if (plans === undefined && plansQuery.isError) logFailed = apiErrorMessage(plansQuery.error, LOG_TEXT.plansFailed) ?? LOG_TEXT.plansFailed;
  else if (services === undefined && servicesQuery.isError) logFailed = apiErrorMessage(servicesQuery.error, LOG_TEXT.servicesFailed) ?? LOG_TEXT.servicesFailed;
  else if (spots === undefined && spotsQuery.isError) logFailed = apiErrorMessage(spotsQuery.error, LOG_TEXT.spotsFailed) ?? LOG_TEXT.spotsFailed;
  const retryLog = () => {
    if (plansQuery.isError) void plansQuery.refetch();
    if (servicesQuery.isError) void servicesQuery.refetch();
    if (spotsQuery.isError) void spotsQuery.refetch();
  };

  // ---- Weather over the stops ----------------------------------------------------------------------
  const weatherStops: WeatherStop[] = stops.map((stop, index) => {
    // A stop that opens after midnight is served on the next civil date: its hours are that date's.
    const nextDay = stop.open_minute >= 1440;
    const shift = nextDay ? 1440 : 0;
    const hours = weatherHours([{ open_minute: stop.open_minute - shift, close_minute: stop.close_minute - shift }]);
    return {
      key: stop.id,
      name: names[index],
      window: fmtWindow(stop.open_minute, stop.close_minute),
      fromHour: hours.fromHour,
      toHour: hours.toHour,
      nextDay,
      passed: stop.close_minute <= minute,
      effect: evaluated ? weatherEffect(result.stops[index]).text : null,
    };
  });
  const headerHours = chipHours(stops);

  // ---- This week ---------------------------------------------------------------------------------
  const monday = mondayOf(today);
  const plannedThisWeek = plans === undefined ? null : plannedDates(plans, weekDates(monday)).length;

  // ---- "Why this number" -------------------------------------------------------------------------
  const [why, setWhy] = useState<WhySubject | null>(null);
  const whyDay = () => {
    if (!evaluated || ctx === null) return;
    setWhy({ kind: 'day', title: 'Take-home on ' + fmtDay(today, 'medium'), result, stopNames: names, ctx });
  };

  // ---- The left column: what to do, and the plan ---------------------------------------------------
  const next = (
    <NextAction
      date={today}
      minute={minute}
      today={todayTimeline}
      pending={plan !== null && evaluation.status === 'pending'}
      yesterday={yesterday}
      tomorrow={tomorrow}
      spotsById={spotsById}
    />
  );

  let main: ReactNode;
  if (plans === undefined) {
    main = plansQuery.isError ? (
      <QueryError
        message={apiErrorMessage(plansQuery.error, PLAN_TEXT.failed) ?? PLAN_TEXT.failed}
        onRetry={() => {
          void plansQuery.refetch();
        }}
      />
    ) : (
      <div className="space-y-4" aria-busy="true">
        <SkeletonCard height={132} />
        <SkeletonCard height={280} />
      </div>
    );
  } else if (plan !== null) {
    main = (
      <>
        {next}
        <TodayPlanCard date={today} plan={plan} names={names} evaluation={evaluation} context={ctx} minute={minute} unsaved={unsaved} onWhy={whyDay} />
      </>
    );
  } else {
    main = (
      <>
        {next}
        {counts.spots === 0 ? (
          <EmptyState
            icon={MapPinned}
            title="Start with the map"
            body="Open the map, click where you might park, and save the spots worth a closer look."
            action={{ label: 'Open the map', to: '/truck/map' }}
          />
        ) : (
          <EmptyState
            icon={Route}
            title="Nothing planned for today"
            body="Pick a saved spot and a time window to see drive times, costs and take-home."
            action={{ label: 'Plan today', to: '/truck/plan/' + today }}
          />
        )}
        {unsaved ? (
          <p className="flex items-start gap-1.5 text-sm font-semibold" style={{ color: 'var(--ink)' }}>
            <Info size={15} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
            <span>{PLAN_TEXT.unsavedOnly}</span>
          </p>
        ) : null}
      </>
    );
  }

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
          <CalendarDays size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Today
        </h1>
        <p className="text-sm font-bold tabular-nums" style={{ color: 'var(--body)' }} data-tp-today={today}>
          {fmtDay(today, 'medium')}
        </p>
        {ctx !== null ? <HolidayChip context={ctx} /> : null}
        {ctx !== null && days.status === 'ready' ? (
          <WeatherChip forecast={ctx.forecast} fromHour={headerHours.fromHour} toHour={headerHours.toHour} info={days.forecast} />
        ) : null}
      </header>

      {days.status === 'degraded' ? (
        <Strip>
          <span>{STRIPS.forecastFailed}</span>
          <button type="button" className="btn btn-secondary min-h-[44px] md:min-h-[32px] px-3 py-0 text-sm" onClick={days.refetch}>
            {STRIPS.tryAgain}
          </button>
        </Strip>
      ) : null}
      {plan !== null && evaluation.notes.driveFallback ? (
        <Strip>
          <span>{STRIPS.driveTimesUnavailable}</span>
        </Strip>
      ) : null}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-start">
        <div className="min-w-0 space-y-4 lg:col-span-8">{main}</div>

        <div className="min-w-0 space-y-4 lg:col-span-4">
          <ToLogCard today={today} unlogged={unlogged} failed={logFailed} onRetry={retryLog} latest={latest} everLogged={counts.services > 0} />

          {days.status === 'degraded' ? null : (
            <WeatherAtStops
              today={today}
              stops={weatherStops}
              forecast={ctx === null ? undefined : ctx.forecast}
              forecastNext={ctxNext === null ? null : ctxNext.forecast}
              info={days.forecast}
              fromHour={headerHours.fromHour}
              toHour={headerHours.toHour}
            />
          )}

          <section aria-labelledby="tp-today-week" className={CARD} style={{ borderColor: 'var(--line-soft)' }}>
            <div className="flex items-center justify-between gap-3">
              <h2 id="tp-today-week" className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
                This week
              </h2>
              <Link
                to={'/truck/week/' + monday}
                className="-my-2 inline-flex min-h-[44px] md:min-h-0 items-center text-sm font-bold underline underline-offset-2"
                style={{ color: 'var(--ink)' }}
              >
                Open week
              </Link>
            </div>
            {plannedThisWeek !== null ? (
              <p className="mt-1 text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                {plannedCountText(plannedThisWeek)}
              </p>
            ) : plansQuery.isError ? (
              <p className="mt-1 text-sm font-semibold" style={{ color: 'var(--body)' }}>
                {LOG_TEXT.plansFailed}
              </p>
            ) : (
              <div className="mt-2" aria-busy="true">
                <div aria-hidden className="skeleton" style={{ height: 18, width: '60%', borderRadius: 4 }} />
              </div>
            )}
          </section>

          <FuelLine fuel={days.fuel} />
        </div>
      </div>

      <WhyDrawer open={why !== null} onClose={() => setWhy(null)} subject={why} />
    </div>
  );
}

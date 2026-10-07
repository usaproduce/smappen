import { useCallback, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router-dom';
import { CalendarRange, ChevronLeft, ChevronRight, MapPinned, TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '../../../api/truck';
import { indexSpots } from '../../../utils/truck/assemble';
import { fmtDay } from '../../../utils/truck/format';
import { addDays, dayOfWeek, parseDate } from '../../../utils/truck/model';
import type { TreatAs } from '../../../utils/truck/model';
import { WEEK_TEXT, isPlanned, loggedOrders, planOn, weekSum, weekTitle } from '../../../utils/truck/nextAction';
import { stopNames } from '../../../utils/truck/planDraft';
import { mondayOf, weekDates } from '../../../utils/truck/time';
import { STRIPS, WEATHER } from '../../../utils/truck/wording';
import { useDayContexts, useNow, usePlans, useServices, useSpots, useTruck } from '../data';
import BestWeekPanel from '../week/BestWeekPanel';
import DayCard, { type DayEvaluation } from '../week/DayCard';
import WeekTotals, { type WeekPart } from '../week/WeekTotals';
import { EmptyState, QueryError, SkeletonRows, WhyDrawer, type WhySubject } from '../ui';

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
 * Week (docs/truck-planner/05_FRONTEND.md 4.6): seven days with their holiday and weather, the
 * planned days with what each is expected to clear, and the week's total.
 *
 * The week in the URL is checked before anything else. Weeks start on Monday: another day of the
 * week goes to the Monday of its week, and anything that is not a date goes to this week. So does
 * the last week of the model's calendar: a Sunday is evaluated with the context of the Monday
 * after it, which that week does not have.
 */
export default function WeekPage() {
  const { weekStart } = useParams();
  if (!isDate(weekStart)) return <Navigate to="/truck/week" replace />;
  if (dayOfWeek(weekStart) !== 0) return <Navigate to={'/truck/week/' + mondayOf(weekStart)} replace />;
  if (!isDate(addDays(weekStart, 7))) return <Navigate to="/truck/week" replace />;
  // Keyed by the week: another week starts with its own results and its own open dialogs.
  return <Week key={weekStart} weekStart={weekStart} />;
}

const NAV_BUTTON = 'btn btn-secondary h-11 md:h-9 px-3 text-sm disabled:opacity-40 disabled:cursor-not-allowed';

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

function Week({ weekStart }: { weekStart: string }) {
  const navigate = useNavigate();
  const { counts } = useTruck();
  const today = useNow().date;
  const dates = useMemo(() => weekDates(weekStart), [weekStart]);
  const weekEnd = dates[6];

  // ---- What the week is built from ---------------------------------------------------------------
  const plansQuery = usePlans(weekStart, weekEnd);
  const plans = plansQuery.data;
  const spotsQuery = useSpots({ archived: true });
  const spots = spotsQuery.data;
  const spotsById = useMemo(() => indexSpots(spots ?? []), [spots]);
  const servicesQuery = useServices({ from: weekStart, to: weekEnd });
  const services = servicesQuery.data;

  // Each planned day carries its own "Treat this day as": the chips show the day as it is evaluated.
  const treatByDate = useMemo(() => {
    const out: Record<string, TreatAs | null> = {};
    for (const plan of plans ?? []) {
      if (plan.status !== 'cancelled' && plan.treat_as !== null) out[plan.date] = plan.treat_as;
    }
    return out;
  }, [plans]);
  const days = useDayContexts(weekStart, 8, treatByDate);
  const forecastFailed = days.status === 'degraded';

  // ---- The days as their cards evaluate them -------------------------------------------------------
  const [evaluations, setEvaluations] = useState<Record<string, DayEvaluation>>({});
  const onEvaluation = useCallback((date: string, next: DayEvaluation | null) => {
    setEvaluations((current) => {
      const before = current[date];
      if (next === null) {
        if (before === undefined) return current;
        const rest = { ...current };
        delete rest[date];
        return rest;
      }
      if (before !== undefined && before.status === next.status && before.result === next.result && before.driveFallback === next.driveFallback) {
        return current;
      }
      return { ...current, [date]: next };
    });
  }, []);

  const [why, setWhy] = useState<WhySubject | null>(null);

  const planned = dates.map((date) => isPlanned(planOn(plans, date)));
  const plannedList = dates.filter((_, index) => planned[index]);
  const sum = weekSum(
    dates.map((date, index) => {
      const evaluation = planned[index] ? evaluations[date] : undefined;
      return { planned: planned[index], result: evaluation === undefined ? null : evaluation.result };
    }),
  );
  const pending = dates.some((date, index) => planned[index] && (evaluations[date] === undefined || evaluations[date].status === 'pending'));
  const driveFallback = dates.some((date, index) => planned[index] && evaluations[date] !== undefined && evaluations[date].driveFallback);

  const parts: WeekPart[] = [];
  for (let i = 0; i < dates.length; i++) {
    const date = dates[i];
    const evaluation = planned[i] ? evaluations[date] : undefined;
    const context = days.contexts[date];
    const plan = planOn(plans, date);
    if (evaluation === undefined || evaluation.result === null || plan === null) continue;
    const result = evaluation.result;
    parts.push({
      date,
      takeHome: result.totals.take_home,
      onWhy: () => {
        if (context === undefined) return;
        setWhy({ kind: 'day', title: 'Take-home on ' + fmtDay(date, 'medium'), result, stopNames: stopNames(plan.stops, spotsById), ctx: context });
      },
    });
  }

  // ---- Moving between weeks ------------------------------------------------------------------------
  const thisWeek = mondayOf(today);
  const previous = addDays(weekStart, -7);
  const next = addDays(weekStart, 7);
  const canGoBack = isDate(previous);
  const canGoOn = isDate(addDays(next, 7));
  const go = (week: string) => navigate('/truck/week/' + week);

  // A week further out than the forecast reaches says so once, under the days.
  const beyondForecast = !forecastFailed && dates.some((date) => date >= today && days.contexts[date] !== undefined && days.contexts[date].forecast === null);

  let body: ReactNode;
  if (plans === undefined) {
    body = plansQuery.isError ? (
      <QueryError
        message={apiErrorMessage(plansQuery.error, WEEK_TEXT.plansFailed) ?? WEEK_TEXT.plansFailed}
        onRetry={() => {
          void plansQuery.refetch();
        }}
      />
    ) : (
      <div aria-busy="true" className="space-y-3">
        <SkeletonRows rows={1} rowHeight={96} />
        <SkeletonRows rows={4} rowHeight={88} />
      </div>
    );
  } else if (counts.spots === 0 && plannedList.length === 0) {
    body = (
      <EmptyState
        icon={MapPinned}
        title="Start with the map"
        body="Open the map, click where you might park, and save the spots worth a closer look."
        action={{ label: 'Open the map', to: '/truck/map' }}
      />
    );
  } else {
    body = (
      <>
        <WeekTotals sum={sum} pending={pending} parts={parts} />
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4 lg:gap-4">
          {dates.map((date) => (
            <DayCard
              key={date}
              date={date}
              today={today}
              plan={planOn(plans, date)}
              context={days.contexts[date] ?? null}
              forecast={days.forecast}
              forecastFailed={forecastFailed}
              logged={loggedOrders(services, date)}
              spotsById={spotsById}
              onEvaluation={onEvaluation}
              onWhy={setWhy}
            />
          ))}
          {/* The eighth cell: the best-week panel of the suggestions package. Empty until it has something to show. */}
          <div className="min-w-0 empty:hidden">
            <BestWeekPanel
              weekStart={weekStart}
              plannedDates={plannedList}
              onApplied={() => {
                void plansQuery.refetch();
              }}
            />
          </div>
        </div>
        {beyondForecast ? (
          <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
            {WEATHER.noneHelp}
          </p>
        ) : null}
      </>
    );
  }

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
          <CalendarRange size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> {weekTitle(weekStart)}
        </h1>
        <div className="flex flex-wrap items-center gap-2">
          <button type="button" className={NAV_BUTTON} disabled={!canGoBack} onClick={() => go(previous)}>
            <ChevronLeft size={16} aria-hidden /> {WEEK_TEXT.previous}
          </button>
          <button type="button" className={NAV_BUTTON} disabled={weekStart === thisWeek} aria-pressed={weekStart === thisWeek} onClick={() => go(thisWeek)}>
            {WEEK_TEXT.thisWeek}
          </button>
          <button type="button" className={NAV_BUTTON} disabled={!canGoOn} onClick={() => go(next)}>
            {WEEK_TEXT.next} <ChevronRight size={16} aria-hidden />
          </button>
        </div>
      </header>

      {forecastFailed ? (
        <Strip>
          <span>{STRIPS.forecastFailed}</span>
          <button type="button" className="btn btn-secondary min-h-[44px] md:min-h-[32px] px-3 py-0 text-sm" onClick={days.refetch}>
            {STRIPS.tryAgain}
          </button>
        </Strip>
      ) : null}
      {driveFallback ? (
        <Strip>
          <span>{STRIPS.driveTimesUnavailable}</span>
        </Strip>
      ) : null}

      {body}

      <WhyDrawer open={why !== null} onClose={() => setWhy(null)} subject={why} />
    </div>
  );
}

import { useEffect, useMemo } from 'react';
import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Ban, FilePen, NotebookPen, TriangleAlert } from 'lucide-react';
import { apiErrorMessage, type Plan, type Spot } from '../../../api/truck';
import { fmtDay, fmtWeekday } from '../../../utils/truck/format';
import { dayOfWeek } from '../../../utils/truck/model';
import type { DayContext, DayResult } from '../../../utils/truck/model';
import { LOG_TEXT, WEEK_TEXT, chipHours, isEvaluated, isPlanned, loggedLine, stopLine, thingsToCheck } from '../../../utils/truck/nextAction';
import { stopNames } from '../../../utils/truck/planDraft';
import { WEATHER } from '../../../utils/truck/wording';
import { usePlanEvaluation, type ForecastState } from '../data';
import { HolidayChip, QueryError, RangeValue, WeatherChip, type WhySubject } from '../ui';

/** A planned day as the week adds it up. */
export interface DayEvaluation {
  /** `none`: the day cannot be worked out as it stands (a stop without figures, a problem in its times). */
  status: 'pending' | 'ready' | 'none' | 'error';
  /** The day as the model evaluated it; null unless `ready`. */
  result: DayResult | null;
  /** A drive of the day is a straight-line estimate, or Google drive times are not available. */
  driveFallback: boolean;
}

export interface DayCardProps {
  date: string;
  /** Today's civil date in the truck's time zone. */
  today: string;
  /** The plan row of the date; null when the date has none. */
  plan: Plan | null;
  /** The context of the date with its plan's "Treat this day as"; null while the week's contexts load. */
  context: DayContext | null;
  /** State and age of the forecast behind the weather chip. */
  forecast: ForecastState;
  /** The forecast could not be loaded: the page says so once, and the chip is left out. */
  forecastFailed: boolean;
  /** Orders logged on the date; null when none were. */
  logged: number | null;
  /** The saved spots by id, deleted ones included. */
  spotsById: ReadonlyMap<string, Spot>;
  /** Reports the day's evaluation whenever it changes, and null when the day leaves the page. */
  onEvaluation: (date: string, evaluation: DayEvaluation | null) => void;
  /** Opens "Why this number" for the day's take-home. */
  onWhy: (subject: WhySubject) => void;
}

const CARD = 'bg-white rounded-xl border p-3 sm:p-4 min-w-0';
const TEXT_LINK = 'inline-flex min-h-[44px] md:min-h-0 items-center text-sm font-bold underline underline-offset-2';

interface HeadProps {
  date: string;
  today: string;
  context: DayContext | null;
  forecast: ForecastState;
  forecastFailed: boolean;
  hours: { fromHour: number; toHour: number };
  tags?: ReactNode;
}

/** The date, its tags, and what kind of day and what weather the model takes it for. */
function Head({ date, today, context, forecast, forecastFailed, hours, tags }: HeadProps) {
  // A date behind us has no forecast to show, and "not yet" would be the wrong thing to say about it.
  const weather =
    context === null || forecastFailed || date < today ? null : context.forecast === null ? (
      <span className="tp-chip">{WEATHER.none}</span>
    ) : (
      <WeatherChip forecast={context.forecast} fromHour={hours.fromHour} toHour={hours.toHour} info={forecast} />
    );
  return (
    <>
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <h2 className="text-base font-extrabold leading-tight" style={{ color: 'var(--ink)' }}>
          {fmtWeekday(dayOfWeek(date), 'long')}{' '}
          <span className="whitespace-nowrap font-bold tabular-nums" style={{ color: 'var(--body)' }}>
            {fmtDay(date, 'short')}
          </span>
        </h2>
        {date === today ? (
          <span className="tp-chip" style={{ background: 'var(--nav-active-bg)', color: 'var(--nav-active-fg)' }} data-tp-today-tag="">
            {WEEK_TEXT.today}
          </span>
        ) : null}
        {tags}
      </div>
      {context !== null && (weather !== null || context.holiday !== null || context.treat_as !== null) ? (
        <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1.5">
          <HolidayChip context={context} />
          {weather}
        </div>
      ) : null}
    </>
  );
}

function Logged({ orders }: { orders: number | null }) {
  if (orders === null) return null;
  return (
    <p className="mt-1.5 flex items-center gap-1.5 text-[13px] font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
      <NotebookPen size={13} aria-hidden className="flex-none" style={{ color: 'var(--slate)' }} />
      {loggedLine(orders)}
    </p>
  );
}

function dayLabel(date: string): string {
  return fmtWeekday(dayOfWeek(date), 'long') + ', ' + fmtDay(date, 'short');
}

/** A day with a plan: its stops, what it is expected to clear, and what is left to check. */
function PlannedCard({ date, today, plan, context, forecast, forecastFailed, logged, spotsById, onEvaluation, onWhy }: DayCardProps & { plan: Plan }) {
  const stops = plan.stops;
  const evaluation = usePlanEvaluation(date, stops, plan.treat_as);
  const result = evaluation.result;
  const figures = isEvaluated(result, stops.length) ? result : null;
  const status: DayEvaluation['status'] =
    evaluation.status === 'error' ? 'error' : evaluation.status === 'pending' ? 'pending' : figures !== null ? 'ready' : 'none';
  const driveFallback = evaluation.notes.driveFallback;
  const names = useMemo(() => stopNames(stops, spotsById), [stops, spotsById]);

  useEffect(() => {
    onEvaluation(date, { status, result: figures, driveFallback });
  }, [date, status, figures, driveFallback, onEvaluation]);
  useEffect(() => () => onEvaluation(date, null), [date, onEvaluation]);

  const check = result === null ? null : thingsToCheck(result.warnings.length);
  const why =
    figures !== null && context !== null
      ? () => onWhy({ kind: 'day', title: 'Take-home on ' + fmtDay(date, 'medium'), result: figures, stopNames: names, ctx: context })
      : undefined;

  let figure: ReactNode;
  if (status === 'error') {
    figure = <QueryError message={apiErrorMessage(evaluation.error, LOG_TEXT.spotsFailed) ?? LOG_TEXT.spotsFailed} onRetry={evaluation.refetch} />;
  } else if (status === 'pending') {
    figure = (
      <div aria-busy="true">
        <div aria-hidden className="skeleton" style={{ height: 64, minWidth: 96, borderRadius: 8 }} />
      </div>
    );
  } else if (figures === null) {
    figure = (
      <p className="flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
        <TriangleAlert size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--fresh-aging)' }} />
        <span>{WEEK_TEXT.noFigures}</span>
      </p>
    );
  } else {
    // A compact figure in the phone's row, a larger one in the card of a wider screen.
    figure = (
      <>
        <div className="md:hidden">
          <RangeValue estimate={figures.totals.take_home} unit="money" size="sm" onWhy={why} dim={evaluation.updating} />
        </div>
        <div className="hidden md:block">
          <RangeValue estimate={figures.totals.take_home} unit="money" size="md" onWhy={why} dim={evaluation.updating} />
        </div>
      </>
    );
  }
  const wide = status === 'error' || (status === 'none' && figures === null);

  return (
    <article className={CARD} style={{ borderColor: 'var(--line-soft)' }} aria-label={dayLabel(date)} data-tp-day={date} data-tp-day-state={status}>
      <div className={wide ? '' : 'flex items-start justify-between gap-3 md:block'}>
        <div className="min-w-0 flex-1">
          <Head
            date={date}
            today={today}
            context={context}
            forecast={forecast}
            forecastFailed={forecastFailed}
            hours={chipHours(stops)}
            tags={
              plan.status === 'draft' ? (
                <span className="tp-chip" title="Suggested, not confirmed yet. Save the day in the planner to confirm it.">
                  <FilePen size={12} strokeWidth={2.6} aria-hidden />
                  {WEEK_TEXT.draft}
                </span>
              ) : null
            }
          />
          <ul className="mt-2 space-y-0.5">
            {stops.map((stop, index) => (
              <li key={stop.id} className="break-words text-sm font-semibold" style={{ color: 'var(--ink)' }}>
                {stopLine(names[index], stop)}
              </li>
            ))}
          </ul>
        </div>
        <div className={wide ? 'mt-2' : 'flex-none max-w-[46%] md:mt-3 md:max-w-none'}>{figure}</div>
      </div>

      <div className="mt-1 flex flex-wrap items-center gap-x-4 md:mt-2">
        {check !== null ? (
          <span className="inline-flex items-center gap-1.5 text-[13px] font-bold" style={{ color: 'var(--ink)' }}>
            <TriangleAlert size={13} aria-hidden className="flex-none" style={{ color: 'var(--fresh-aging)' }} />
            {check}
          </span>
        ) : null}
        <Link to={'/truck/plan/' + date} className={TEXT_LINK} style={{ color: 'var(--ink)' }} aria-label={WEEK_TEXT.open + ' ' + dayLabel(date)}>
          {WEEK_TEXT.open}
        </Link>
      </div>
      <Logged orders={logged} />
    </article>
  );
}

/** A day without a plan. */
function OpenCard({ date, today, plan, context, forecast, forecastFailed, logged }: DayCardProps) {
  const cancelled = plan !== null && plan.status === 'cancelled';
  return (
    <article className={CARD} style={{ borderColor: 'var(--line-soft)' }} aria-label={dayLabel(date)} data-tp-day={date} data-tp-day-state="open">
      <div className="flex items-start justify-between gap-3 md:block">
        <div className="min-w-0 flex-1">
          <Head
            date={date}
            today={today}
            context={context}
            forecast={forecast}
            forecastFailed={forecastFailed}
            hours={chipHours([])}
            tags={
              cancelled ? (
                <span className="tp-chip">
                  <Ban size={12} strokeWidth={2.6} aria-hidden />
                  {WEEK_TEXT.cancelled}
                </span>
              ) : null
            }
          />
          <p className="mt-2 text-sm font-semibold" style={{ color: 'var(--body)' }}>
            {WEEK_TEXT.nothingPlanned}
          </p>
        </div>
        {date >= today ? (
          <div className="flex-none md:mt-3">
            <Link to={'/truck/plan/' + date} className="btn btn-secondary h-11 md:h-9 px-3 text-sm" aria-label={WEEK_TEXT.planThisDay + ': ' + dayLabel(date)}>
              {WEEK_TEXT.planThisDay}
            </Link>
          </div>
        ) : null}
      </div>
      <Logged orders={logged} />
    </article>
  );
}

/**
 * One day of the week (docs/truck-planner/05_FRONTEND.md 4.6): the date, the holiday and the
 * weather the model takes it for, and either the day's stops with what they are expected to clear
 * or the way to plan it. On a phone it is a compact row with the figure at the right.
 *
 * A planned day is evaluated here, in the browser, by the same hook the planner uses; the card
 * hands the result to the page, which adds the week up. A day that cannot be evaluated says so in
 * its own card and leaves the other days alone.
 */
export default function DayCard(props: DayCardProps) {
  const plan = props.plan;
  return isPlanned(plan) ? <PlannedCard {...props} plan={plan} /> : <OpenCard {...props} />;
}

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import type { Plan, Spot } from '../../../api/truck';
import { fmtDay, fmtWindow } from '../../../utils/truck/format';
import type { Timeline } from '../../../utils/truck/model';
import {
  NEXT_CAPTION,
  NEXT_LOG_BUTTON,
  NEXT_NOTE,
  isEvaluated,
  nextNoteFor,
  nextUp,
  type ServiceDay,
} from '../../../utils/truck/nextAction';
import { stopNames } from '../../../utils/truck/planDraft';
import { usePlanEvaluation } from '../data';
import { SkeletonCard } from '../ui';

/** Today's day as the "Next" sentence reads it. */
export interface TodayTimeline {
  timeline: Timeline;
  /** The name of stop i of the plan. */
  stopNames: readonly string[];
}

export interface NextActionProps {
  /** Today's civil date and the minute of the day, both in the truck's time zone. */
  date: string;
  minute: number;
  /** Today's day as the model evaluated it; null with nothing planned or nothing worked out. */
  today: TodayTimeline | null;
  /** Today has a plan and its figures are still on their way. */
  pending: boolean;
  /** Yesterday's plan while it may still be running (a service past midnight); else null. */
  yesterday: Plan | null;
  /** Tomorrow's plan when it may begin before midnight tonight; else null. */
  tomorrow: Plan | null;
  /** The saved spots by id, deleted ones included: the names of the stops. */
  spotsById: ReadonlyMap<string, Spot>;
}

/**
 * A neighbouring day worked out for its timeline alone. It renders nothing: it reports the day to
 * the card, and takes it back when it leaves the page.
 */
function NeighbourDay({
  plan,
  offset,
  spotsById,
  onDay,
}: {
  plan: Plan;
  offset: -1 | 1;
  spotsById: ReadonlyMap<string, Spot>;
  onDay: (offset: number, day: ServiceDay | null) => void;
}) {
  const evaluation = usePlanEvaluation(plan.date, plan.stops, plan.treat_as);
  const result = evaluation.result;
  const timeline = isEvaluated(result, plan.stops.length) ? result.timeline : null;
  const names = useMemo(() => stopNames(plan.stops, spotsById), [plan.stops, spotsById]);
  const date = plan.date;
  useEffect(() => {
    onDay(offset, timeline === null ? null : { offset, date, timeline, stopNames: names });
    return () => onDay(offset, null);
  }, [offset, date, timeline, names, onDay]);
  return null;
}

/**
 * "Next" (docs/truck-planner/05_FRONTEND.md 4.1): the one thing to do now, as a sentence, from the
 * first event of the plan's timeline that is later than the clock. It is the first thing on the
 * landing page.
 *
 * A day is not cut off at midnight. While a service that began yesterday is still running, its next
 * step is the sentence; so is the prep, tonight, for a stop that opens in the small hours of
 * tomorrow. The sentence comes from the plan and the clock alone: the app does not know where the
 * truck is, and the line under the sentence says so.
 */
export default function NextAction({ date, minute, today, pending, yesterday, tomorrow, spotsById }: NextActionProps) {
  const [neighbours, setNeighbours] = useState<Record<number, ServiceDay | null>>({});
  const onDay = useCallback((offset: number, day: ServiceDay | null) => {
    setNeighbours((current) => (current[offset] === day || (current[offset] === undefined && day === null) ? current : { ...current, [offset]: day }));
  }, []);

  const before = yesterday !== null ? neighbours[-1] ?? null : null;
  const after = tomorrow !== null ? neighbours[1] ?? null : null;
  const up = useMemo(() => {
    const days: ServiceDay[] = [];
    if (before !== null) days.push(before);
    if (today !== null) days.push({ offset: 0, date, timeline: today.timeline, stopNames: today.stopNames });
    if (after !== null) days.push(after);
    return nextUp(days, minute);
  }, [before, after, today, date, minute]);

  const watchers = (
    <>
      {yesterday !== null ? <NeighbourDay key={yesterday.id} plan={yesterday} offset={-1} spotsById={spotsById} onDay={onDay} /> : null}
      {tomorrow !== null ? <NeighbourDay key={tomorrow.id} plan={tomorrow} offset={1} spotsById={spotsById} onDay={onDay} /> : null}
    </>
  );

  if (up === null) {
    return (
      <>
        {watchers}
        {pending ? (
          <div aria-busy="true">
            <SkeletonCard height={132} />
          </div>
        ) : null}
      </>
    );
  }

  const { day, action } = up;
  const finished = action.kind === 'finished';
  const stop = action.stopIndex === null ? null : day.timeline.stops[action.stopIndex] ?? null;
  const stopName = action.stopIndex === null ? '' : day.stopNames[action.stopIndex] ?? '';
  const otherDay = day.offset !== 0;

  return (
    <section aria-labelledby="tp-next-caption" className="bg-white rounded-xl border p-4 sm:p-5" style={{ borderColor: 'var(--line-soft)' }} data-tp-next={action.kind}>
      {watchers}
      <h2 id="tp-next-caption" className="text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
        {NEXT_CAPTION}
      </h2>
      <p aria-live="polite" className="mt-1 break-words text-3xl font-extrabold leading-tight tabular-nums" style={{ color: 'var(--ink)' }}>
        {action.text}
      </p>
      {stop !== null ? (
        <p className="mt-1.5 text-sm font-bold" style={{ color: 'var(--body)' }}>
          <span className="break-words">{stopName}</span>
          {' · '}
          <span className="whitespace-nowrap tabular-nums">{fmtWindow(stop.open, stop.close)}</span>
        </p>
      ) : null}
      {finished ? (
        <div className="mt-3">
          <Link to="/truck/log?new=1" className="btn btn-primary h-11 md:h-9 px-4 text-sm">
            {NEXT_LOG_BUTTON}
          </Link>
        </div>
      ) : null}
      <p className="mt-2.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
        {otherDay ? nextNoteFor(day.date) : NEXT_NOTE}
      </p>
      {otherDay ? (
        <Link
          to={'/truck/plan/' + day.date}
          className="inline-flex min-h-[44px] md:min-h-0 md:mt-1 items-center text-sm font-bold underline underline-offset-2"
          style={{ color: 'var(--ink)' }}
        >
          Open the plan for {fmtDay(day.date, 'medium')}
        </Link>
      ) : null}
    </section>
  );
}

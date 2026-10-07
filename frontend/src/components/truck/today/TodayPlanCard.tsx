import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { FilePen, Info, TriangleAlert, Truck } from 'lucide-react';
import { apiErrorMessage, type Plan } from '../../../api/truck';
import { trafficIsNeutral } from '../../../utils/truck/assemble';
import { fmtWindow } from '../../../utils/truck/format';
import type { DayContext } from '../../../utils/truck/model';
import { LOG_TEXT, PLAN_TEXT, arrivalLine, dayIsDone, dayLengthLine, isEvaluated, thingsToCheck } from '../../../utils/truck/nextAction';
import { isStopNotice, legViews, type LegView } from '../../../utils/truck/planDraft';
import { warningRows } from '../../../utils/truck/warnings';
import { OPEN_IN_MAPS } from '../../../utils/truck/wording';
import { useTruck, type PlanEvaluation } from '../data';
import { OpenInMaps, QueryError, RangeValue, SkeletonRows } from '../ui';

export interface TodayPlanCardProps {
  /** Today's civil date in the truck's time zone. */
  date: string;
  /** Today's saved plan: it has at least one stop. */
  plan: Plan;
  /** The name of stop i of the plan. */
  names: readonly string[];
  /** The day as the browser evaluates it. */
  evaluation: PlanEvaluation;
  /** The context of today with the plan's "Treat this day as"; null while it loads. */
  context: DayContext | null;
  /** The minute of the day, in the truck's time zone. */
  minute: number;
  /** The planner holds changes to this day that are not saved. */
  unsaved: boolean;
  /** Opens "Why this number" for the day's take-home. */
  onWhy: () => void;
}

const BUTTON = 'btn btn-secondary h-11 md:h-9 px-3 text-sm';

/** One drive of the day in two lines: when to leave, then how long and how far. */
function DriveLine({ leg, dim }: { leg: LegView; dim: boolean }) {
  return (
    <div className={'flex items-start gap-2' + (dim ? ' tp-dim' : '')} data-tp-drive={leg.position}>
      <Truck size={15} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
      <div className="min-w-0">
        <p className="text-sm font-extrabold tabular-nums" style={{ color: 'var(--ink)' }}>
          {leg.times}
        </p>
        <p className="flex flex-wrap items-center gap-x-1.5 text-xs font-semibold tabular-nums" style={{ color: 'var(--body)' }}>
          <span>{leg.title}</span>
          {leg.straight ? (
            <span className="inline-flex items-center gap-1">
              <TriangleAlert size={12} aria-hidden className="flex-none" style={{ color: 'var(--fresh-aging)' }} />
              {leg.source}
            </span>
          ) : null}
        </p>
      </div>
    </div>
  );
}

/**
 * "Today's plan" (docs/truck-planner/05_FRONTEND.md 4.1): the day in the order it happens, with
 * the times to leave by, each stop's window and expected orders, and what the day is expected to
 * clear with its range. Every time and figure is the model's for the saved plan; the drive lines
 * are the planner's own sentences, so the two pages cannot read differently.
 */
export default function TodayPlanCard({ date, plan, names, evaluation, context, minute, unsaved, onWhy }: TodayPlanCardProps) {
  const { A } = useTruck();
  const stops = plan.stops;
  const result = evaluation.result;
  const evaluated = isEvaluated(result, stops.length);
  const dim = evaluation.updating;
  const neutral = useMemo(() => trafficIsNeutral(A), [A]);
  const sent = evaluation.legs;
  const legs = useMemo(() => (isEvaluated(result, stops.length) ? legViews(result.timeline, sent, neutral) : []), [result, stops.length, sent, neutral]);
  const rows = useMemo(() => (result === null ? [] : warningRows(result, names, context)), [result, names, context]);
  const planner = '/truck/plan/' + date;
  const done = evaluated && dayIsDone(result.timeline, minute);
  const check = result === null ? null : thingsToCheck(result.warnings.length);
  const pending = evaluation.status === 'pending';
  const failed = evaluation.status === 'error';

  return (
    <section aria-labelledby="tp-today-plan" className="bg-white rounded-xl border p-4 sm:p-5" style={{ borderColor: 'var(--line-soft)' }}>
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <h2 id="tp-today-plan" className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
          {PLAN_TEXT.title}
        </h2>
        {plan.status === 'draft' ? (
          <span className="tp-chip" title="Suggested, not confirmed yet. Save the day in the planner to confirm it.">
            <FilePen size={12} strokeWidth={2.6} aria-hidden />
            {PLAN_TEXT.draftTag}
          </span>
        ) : null}
      </div>
      {unsaved ? (
        <p className="mt-1.5 flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
          <Info size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
          <span>{PLAN_TEXT.unsaved}</span>
        </p>
      ) : null}

      {failed ? (
        <div className="mt-3">
          <QueryError message={apiErrorMessage(evaluation.error, LOG_TEXT.spotsFailed) ?? LOG_TEXT.spotsFailed} onRetry={evaluation.refetch} />
        </div>
      ) : pending ? (
        <div className="mt-3" aria-busy="true">
          <SkeletonRows rows={stops.length > 3 ? 3 : stops.length} rowHeight={64} />
        </div>
      ) : (
        <ol className="mt-3 space-y-3">
          {stops.map((stop, index) => {
            const leg = evaluated ? legs[index] : undefined;
            const timed = evaluated ? result.timeline.stops[index] : undefined;
            const dayStop = evaluated ? result.stops[index] : undefined;
            const notices = rows.filter((row) => isStopNotice(row, index));
            return (
              <li key={stop.id} className="space-y-2" data-tp-today-stop={index + 1}>
                {leg !== undefined ? <DriveLine leg={leg} dim={dim} /> : null}
                <div className="rounded-lg px-3 py-2.5" style={{ background: 'var(--bg-panel)' }}>
                  <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1.5">
                    <div className="flex min-w-0 items-start gap-2.5">
                      <span
                        className="mt-px inline-flex h-6 w-6 flex-none items-center justify-center rounded-full bg-white text-xs font-extrabold tabular-nums"
                        style={{ color: 'var(--ink)' }}
                      >
                        <span className="sr-only">Stop </span>
                        {index + 1}
                      </span>
                      <div className="min-w-0">
                        <p className="break-words text-base font-extrabold leading-snug" style={{ color: 'var(--ink)' }}>
                          {names[index]}
                        </p>
                        <p className="text-sm font-bold tabular-nums" style={{ color: 'var(--body)' }}>
                          {fmtWindow(stop.open_minute, stop.close_minute)}
                          {timed !== undefined ? <span className="font-semibold"> · {arrivalLine(timed)}</span> : null}
                        </p>
                      </div>
                    </div>
                    {dayStop !== undefined ? (
                      <div className="pl-[34px] sm:pl-0">
                        <RangeValue estimate={dayStop.orders} unit="orders" layout="inline" size="sm" dim={dim} />
                      </div>
                    ) : null}
                  </div>
                  {notices.map((row) => (
                    <p key={String(row.index) + row.code} className="mt-1.5 flex items-start gap-1.5 pl-[34px] text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
                      <TriangleAlert
                        size={14}
                        aria-hidden
                        className="mt-0.5 flex-none"
                        style={{ color: row.level === 'error' ? 'var(--money-negative)' : 'var(--fresh-aging)' }}
                      />
                      <span>
                        <span className="font-extrabold">{row.prefix}</span> {row.text}
                      </span>
                    </p>
                  ))}
                </div>
              </li>
            );
          })}
        </ol>
      )}

      {evaluated && legs.length > stops.length ? (
        <div className="mt-3">
          <DriveLine leg={legs[legs.length - 1]} dim={dim} />
        </div>
      ) : null}

      {!failed && !pending && !evaluated ? (
        <p className="mt-3 flex items-start gap-1.5 text-sm font-semibold" style={{ color: 'var(--ink)' }}>
          <TriangleAlert size={15} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--fresh-aging)' }} />
          <span>{PLAN_TEXT.noFigures}</span>
        </p>
      ) : null}

      {evaluated ? (
        <div className="mt-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-3 border-t pt-4" style={{ borderColor: 'var(--line-soft)' }}>
          <RangeValue estimate={result.totals.take_home} unit="money" size="lg" label="TAKE-HOME" onWhy={onWhy} dim={dim} />
          <div className={'text-sm' + (dim ? ' tp-dim' : '')}>
            <p className="font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
              {dayLengthLine(result.timeline)}
            </p>
            {check !== null ? (
              <Link
                to={planner}
                className="inline-flex min-h-[44px] md:min-h-0 items-center gap-1.5 font-bold underline underline-offset-2"
                style={{ color: 'var(--ink)' }}
              >
                <TriangleAlert size={14} aria-hidden className="flex-none" style={{ color: 'var(--fresh-aging)' }} />
                {check}
              </Link>
            ) : null}
          </div>
        </div>
      ) : null}

      <div className="mt-4 flex flex-wrap items-center gap-2">
        {plan.maps_route_url !== null ? (
          <OpenInMaps href={plan.maps_route_url} label={OPEN_IN_MAPS.route} variant={done ? 'button' : 'primary'} />
        ) : null}
        <Link to={planner} className={BUTTON}>
          {PLAN_TEXT.editPlan}
        </Link>
        <Link to={planner + '/sheet'} className={BUTTON}>
          {PLAN_TEXT.daySheet}
        </Link>
      </div>
    </section>
  );
}

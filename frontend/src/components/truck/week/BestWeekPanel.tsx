import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, TriangleAlert } from 'lucide-react';
import toast from 'react-hot-toast';
import { apiErrorMessage, apiErrorStatus, truckApi, truckKeys, type PlanBody } from '../../../api/truck';
import { indexSpots } from '../../../utils/truck/assemble';
import {
  BEST_WEEK,
  PLAN_EXISTS_ERROR,
  bestWeekCaption,
  draftExistsLine,
  draftFailedLine,
  draftsAddedLine,
  draftsDatesLine,
  draftsQuestion,
  weekDayRows,
  weekDraftBodies,
  weekLimits,
} from '../../../utils/truck/scoutView';
import { useNow, useSpots, useSuggestWeek, useTruck } from '../data';
import { Modal, QueryError, RangeValue, SkeletonRows } from '../ui';

export interface BestWeekPanelProps {
  /** The Monday the week starts on. */
  weekStart: string;
  /** Dates of the week that already have a plan: a suggestion never replaces one. */
  plannedDates: string[];
  /** Draft plans were created for empty days: the week reads its plans again. */
  onApplied: () => void;
}

/** What "Use for the empty days" did, date by date. */
interface Applied {
  added: string[];
  /** One sentence per date that got no draft: it has a plan by now, or the server refused it. */
  problems: string[];
}

/**
 * The best-week panel (docs/truck-planner/05_FRONTEND.md 4.6): "Suggest a week" asks the server for
 * the week it would drive from the saved spots (route 30) and shows it as returned: a suggestion or
 * a day off for each day, and the week's take-home as a range with its label.
 *
 * "Use for the empty days" adds the suggested days as drafts, after a question, and only where the
 * date has no plan: each draft is created (route 23), never written over a plan, and a date that got
 * a plan in the meantime is left as it is. A draft becomes a planned day when the owner opens it in
 * the planner and saves it.
 */
export default function BestWeekPanel({ weekStart, plannedDates, onApplied }: BestWeekPanelProps) {
  const qc = useQueryClient();
  const { A, counts } = useTruck();
  const today = useNow().date;
  const [askedFor, setAskedFor] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [applied, setApplied] = useState<{ weekStart: string; outcome: Applied } | null>(null);

  const body = useMemo(() => ({ week_start: weekStart }), [weekStart]);
  const query = useSuggestWeek(body, askedFor === weekStart);
  const spots = useSpots({ archived: true }).data;
  const spotsById = useMemo(() => indexSpots(spots ?? []), [spots]);
  const data = query.data;
  const outcome = applied !== null && applied.weekStart === weekStart ? applied.outcome : null;

  // The dates this panel gave a draft count as planned before the week has read its plans again.
  const planned = useMemo(() => (outcome === null ? plannedDates : plannedDates.concat(outcome.added)), [plannedDates, outcome]);
  const rows = useMemo(() => (data === undefined ? [] : weekDayRows(data.week, planned, today, spotsById)), [data, planned, today, spotsById]);
  const bodies = useMemo(() => (data === undefined ? [] : weekDraftBodies(data.week, planned, today)), [data, planned, today]);
  const limits = weekLimits(A);
  const anySuggestion = rows.some((row) => row.takeHome !== null);

  const apply = useMutation<Applied, unknown, PlanBody[]>({
    mutationFn: async (drafts) => {
      const result: Applied = { added: [], problems: [] };
      // One after the other, and only ever a create: a date that has a plan answers 409 and keeps it.
      for (const draft of drafts) {
        try {
          await truckApi.createPlan(draft);
          result.added.push(draft.date);
        } catch (e) {
          const sentence = apiErrorMessage(e, '');
          const exists = apiErrorStatus(e) === 409 && sentence === PLAN_EXISTS_ERROR;
          result.problems.push(exists ? draftExistsLine(draft.date) : draftFailedLine(draft.date, sentence));
        }
      }
      return result;
    },
    onSuccess: (result) => {
      setApplied({ weekStart, outcome: outcome === null ? result : { added: outcome.added.concat(result.added), problems: result.problems } });
      setConfirming(false);
      if (result.added.length > 0) {
        void qc.invalidateQueries({ queryKey: ['truck', 'plans', 'list'] });
        void qc.invalidateQueries({ queryKey: truckKeys.bootstrap() }); // counts
        toast.success('Drafts added');
        onApplied();
      }
    },
  });

  const ask = () => {
    if (askedFor === weekStart) void query.refetch();
    else setAskedFor(weekStart);
  };

  const working = query.isFetching;

  return (
    <section
      aria-labelledby="tp-best-week-title"
      className="rounded-xl border bg-white p-4 sm:p-5"
      style={{ borderColor: 'var(--line-soft)' }}
    >
      <h2 id="tp-best-week-title" className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
        {BEST_WEEK.title}
      </h2>

      {data === undefined ? (
        <>
          <p className="mt-1 text-sm font-semibold" style={{ color: 'var(--body)' }}>
            {counts.spots > 0 ? BEST_WEEK.text : BEST_WEEK.noSpots}
          </p>
          {counts.spots > 0 ? (
            <button
              type="button"
              className="btn btn-secondary mt-3 h-11 md:h-9 px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
              disabled={working}
              onClick={ask}
            >
              {working ? <span className="spinner" aria-hidden /> : null}
              {working ? BEST_WEEK.asking : BEST_WEEK.ask}
            </button>
          ) : (
            <Link to="/truck/map" className="btn btn-secondary mt-3 h-11 md:h-9 px-3 text-sm">
              Open the map
            </Link>
          )}
          {working ? (
            <div className="mt-3" aria-busy="true">
              <SkeletonRows rows={3} rowHeight={56} />
            </div>
          ) : null}
          {query.isError && !working ? (
            <div className="mt-3">
              <QueryError message={apiErrorMessage(query.error, BEST_WEEK.failed) ?? BEST_WEEK.failed} onRetry={ask} />
            </div>
          ) : null}
        </>
      ) : !anySuggestion ? (
        <>
          <p className="mt-1 text-sm font-bold" style={{ color: 'var(--ink)' }}>
            {data.spots_considered === 0 ? BEST_WEEK.noSpots : BEST_WEEK.noneTitle}
          </p>
          {data.spots_considered === 0 ? null : (
            <p className="mt-0.5 text-sm font-semibold" style={{ color: 'var(--body)' }}>
              {BEST_WEEK.noneBody}
            </p>
          )}
        </>
      ) : (
        <div className={working ? 'tp-dim' : undefined}>
          <div className="mt-2">
            <RangeValue estimate={data.week.total_take_home} unit="money" size="lg" label={BEST_WEEK.total} />
          </div>
          <p className="mt-2 text-xs font-semibold" style={{ color: 'var(--body)' }}>
            {bestWeekCaption(limits.maxDays, limits.maxVisits)} {BEST_WEEK.totalNote}
          </p>
          {data.fallback_pairs > 0 ? (
            <p className="mt-2 flex items-start gap-1.5 text-xs font-semibold" style={{ color: 'var(--ink)' }}>
              <TriangleAlert size={13} aria-hidden className="mt-px flex-none" style={{ color: 'var(--fresh-aging)' }} />
              <span>{BEST_WEEK.fallback}</span>
            </p>
          ) : null}

          <ol className="tp-stat-list mt-3">
            {rows.map((row) => (
              <li key={row.date} className="py-2.5">
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-extrabold" style={{ color: 'var(--ink)' }}>
                  {row.label}
                  {row.takeHome === null ? <span className="tp-chip tp-chip-sm">{BEST_WEEK.dayOff}</span> : null}
                  {outcome !== null && outcome.added.indexOf(row.date) >= 0 ? (
                    <span className="tp-chip tp-chip-sm">
                      <Check size={12} strokeWidth={3} aria-hidden /> Draft added
                    </span>
                  ) : null}
                </p>
                {row.stops.length > 0 ? (
                  <ul className="mt-0.5 space-y-0.5">
                    {row.stops.map((stop, index) => (
                      <li key={stop.spotId + String(index)} className="text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
                        <span className="font-bold" style={{ color: 'var(--ink)' }}>
                          {stop.name}
                        </span>
                        , <span className="tabular-nums">{stop.window}</span>
                      </li>
                    ))}
                  </ul>
                ) : null}
                {row.takeHome !== null ? (
                  <div className="mt-1">
                    <RangeValue estimate={row.takeHome} unit="money" layout="inline" size="sm" />
                  </div>
                ) : null}
                {row.note !== null && row.note !== BEST_WEEK.dayOff ? (
                  <p className="mt-0.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
                    {row.note}
                  </p>
                ) : null}
              </li>
            ))}
          </ol>

          {outcome !== null ? (
            <div role="status" className="mt-3 space-y-1">
              {outcome.added.length > 0 ? (
                <p className="flex items-start gap-1.5 text-[13px] font-bold" style={{ color: 'var(--ink)' }}>
                  <Check size={14} strokeWidth={3} aria-hidden className="mt-0.5 flex-none" />
                  <span>{draftsAddedLine(outcome.added.length)}</span>
                </p>
              ) : null}
              {outcome.problems.map((line) => (
                <p key={line} className="flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
                  <TriangleAlert size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--fresh-aging)' }} />
                  <span>{line}</span>
                </p>
              ))}
            </div>
          ) : null}

          <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
            <button
              type="button"
              className="btn btn-secondary h-11 md:h-9 px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
              disabled={bodies.length === 0 || apply.isPending || working}
              onClick={() => setConfirming(true)}
            >
              {BEST_WEEK.use}
            </button>
            <button
              type="button"
              className="inline-flex min-h-[44px] md:min-h-[28px] items-center text-[13px] font-bold underline underline-offset-2 disabled:opacity-60"
              style={{ color: 'var(--body)' }}
              disabled={working}
              onClick={ask}
            >
              {working ? BEST_WEEK.asking : BEST_WEEK.again}
            </button>
          </div>
          {bodies.length === 0 ? (
            <p className="mt-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
              {BEST_WEEK.nothingToAdd}
            </p>
          ) : null}
        </div>
      )}

      <Modal
        open={confirming}
        onClose={() => setConfirming(false)}
        title={draftsQuestion(bodies.length)}
        size="sm"
        dismissible={!apply.isPending}
        footer={
          <>
            <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" disabled={apply.isPending} onClick={() => setConfirming(false)}>
              {BEST_WEEK.cancel}
            </button>
            <button type="button" className="btn btn-primary h-11 md:h-9 px-3 text-sm" disabled={apply.isPending || bodies.length === 0} onClick={() => apply.mutate(bodies)}>
              {apply.isPending ? BEST_WEEK.adding : BEST_WEEK.confirm}
            </button>
          </>
        }
      >
        <p>{draftsDatesLine(bodies.map((draft) => draft.date))}</p>
        <p className="mt-2">{BEST_WEEK.confirmBody}</p>
      </Modal>
    </section>
  );
}

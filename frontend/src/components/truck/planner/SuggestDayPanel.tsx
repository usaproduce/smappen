import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { TriangleAlert } from 'lucide-react';
import { apiErrorMessage } from '../../../api/truck';
import { indexSpots } from '../../../utils/truck/assemble';
import { fmtDay } from '../../../utils/truck/format';
import type { DayContext, Suggestion } from '../../../utils/truck/model';
import { SUGGEST, dayLengthLine, suggestionAsStops, suggestionStops, suggestionTitle } from '../../../utils/truck/scoutView';
import { TREAT_AS_LABELS } from '../../../utils/truck/wording';
import { useDayContexts, usePlanEvaluation, useSpots, useSuggestDay, useTruck } from '../data';
import { QueryError, RangeValue, Sheet, SkeletonRows, WhyDrawer, type WhySubject } from '../ui';

export interface SuggestDayPanelProps {
  date: string;
  /** The planner's "Treat this day as" for this date. */
  treatAs: DayContext['treat_as'];
  open: boolean;
  onClose: () => void;
  /** "Use this plan": the planner replaces the draft's stops with the suggestion's. */
  onUse: (s: Suggestion) => void;
}

/**
 * "Suggest a day" (docs/truck-planner/05_FRONTEND.md 4.5 rule 6): up to three days for the date,
 * built by the server from the saved spots, the owner's costs and the date's forecast (route 29).
 * Each is shown as returned: its stops, its take-home and its orders as ranges with their labels,
 * and the length of the day.
 *
 * A suggestion is not a booking and saves nothing. "Use this plan" hands it to the planner, which
 * asks before it replaces stops and keeps the day a draft until the owner saves it.
 *
 * The panel brings its own way in: where the planner mounts it, it shows a row with the button
 * "Suggest a day", which opens the panel through the address (`?suggest=1`).
 */
export default function SuggestDayPanel({ date, treatAs, open, onClose, onUse }: SuggestDayPanelProps) {
  const [, setParams] = useSearchParams();
  const { counts } = useTruck();

  const openPanel = () => {
    setParams(
      (current) => {
        const next = new URLSearchParams(current);
        next.set('suggest', '1');
        return next;
      },
      { replace: true },
    );
  };

  return (
    <>
      {/* Suggestions are built from saved spots: without one there is nothing to offer. */}
      {counts.spots > 0 ? (
        <section
          aria-label={SUGGEST.open}
          className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border bg-white p-4 sm:p-5"
          style={{ borderColor: 'var(--line-soft)' }}
        >
          <div className="min-w-0 flex-1 basis-64">
            <h2 className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
              {SUGGEST.open}
            </h2>
            <p className="mt-0.5 text-sm font-semibold" style={{ color: 'var(--body)' }}>
              {SUGGEST.openText}
            </p>
          </div>
          <button type="button" className="btn btn-secondary h-11 md:h-9 flex-none px-3 text-sm" data-tp-suggest-day="" onClick={openPanel}>
            {SUGGEST.open}
          </button>
        </section>
      ) : null}

      <Sheet open={open} onClose={onClose} title={SUGGEST.title} width={560}>
        {open ? <Suggestions date={date} treatAs={treatAs} onUse={onUse} /> : null}
      </Sheet>
    </>
  );
}

function Suggestions({ date, treatAs, onUse }: Pick<SuggestDayPanelProps, 'date' | 'treatAs' | 'onUse'>) {
  const body = useMemo(() => ({ date, treat_as: treatAs }), [date, treatAs]);
  const query = useSuggestDay(body, true);
  const spotsQuery = useSpots({ archived: true });
  const spots = spotsQuery.data;
  const spotsById = useMemo(() => indexSpots(spots ?? []), [spots]);
  const [whyFor, setWhyFor] = useState<number | null>(null);
  const data = query.data;

  return (
    <div className="space-y-4">
      <div>
        <p className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
          {fmtDay(date, 'long')}
        </p>
        {treatAs !== null ? (
          <p className="text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
            Treat this day as: {TREAT_AS_LABELS[treatAs]}
          </p>
        ) : null}
        <p className="mt-1 text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
          {SUGGEST.caption}
        </p>
      </div>

      {data === undefined ? (
        query.isError ? (
          <QueryError
            message={apiErrorMessage(query.error, SUGGEST.failed) ?? SUGGEST.failed}
            onRetry={() => {
              void query.refetch();
            }}
          />
        ) : (
          <div aria-busy="true">
            <SkeletonRows rows={3} rowHeight={168} />
          </div>
        )
      ) : data.suggestions.length === 0 ? (
        <div className="rounded-xl border bg-white p-4" style={{ borderColor: 'var(--line-soft)' }}>
          <p className="text-sm font-extrabold" style={{ color: 'var(--ink)' }}>
            {data.spots_considered === 0 ? SUGGEST.noSpotsTitle : SUGGEST.noneTitle}
          </p>
          <p className="mt-1 text-sm font-semibold" style={{ color: 'var(--body)' }}>
            {data.spots_considered === 0 ? SUGGEST.noSpotsBody : SUGGEST.noneBody}
          </p>
          {data.spots_considered === 0 ? (
            <Link to="/truck/map" className="btn btn-secondary mt-3 h-11 md:h-9 px-3 text-sm">
              {SUGGEST.noSpotsAction}
            </Link>
          ) : null}
        </div>
      ) : (
        <>
          {data.fallback_pairs > 0 ? (
            <p className="flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
              <TriangleAlert size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--fresh-aging)' }} />
              <span>{SUGGEST.fallback}</span>
            </p>
          ) : null}
          <ol className="space-y-3">
            {data.suggestions.map((suggestion) => {
              const lines = suggestionStops(suggestion, spotsById);
              const titleId = 'tp-suggest-' + String(suggestion.position);
              return (
                <li key={suggestion.position}>
                  <article aria-labelledby={titleId} className="rounded-xl border bg-white p-4" style={{ borderColor: 'var(--line-soft)' }}>
                    <h3 id={titleId} className="text-sm font-extrabold" style={{ color: 'var(--ink)' }}>
                      {suggestionTitle(suggestion.position)}
                    </h3>
                    <ol className="mt-1.5 space-y-1">
                      {lines.map((line, index) => (
                        <li key={line.spotId + String(index)} className="flex flex-wrap items-baseline gap-x-2 text-sm">
                          <span className="font-bold" style={{ color: 'var(--ink)' }}>
                            {line.name}
                          </span>
                          <span className="font-semibold tabular-nums" style={{ color: 'var(--body)' }}>
                            {line.window}
                          </span>
                        </li>
                      ))}
                    </ol>
                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                      <RangeValue
                        estimate={suggestion.take_home}
                        unit="money"
                        size="md"
                        label={SUGGEST.takeHome}
                        onWhy={() => setWhyFor(suggestion.position)}
                      />
                      <RangeValue estimate={suggestion.orders} unit="orders" size="sm" label={SUGGEST.orders} />
                    </div>
                    <p className="mt-2 text-[13px] font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                      {dayLengthLine(suggestion.day_minutes)}
                    </p>
                    {whyFor === suggestion.position ? (
                      <SuggestionWhy
                        date={date}
                        treatAs={treatAs}
                        suggestion={suggestion}
                        stopNames={lines.map((line) => line.name)}
                        onClose={() => setWhyFor(null)}
                      />
                    ) : null}
                    <button type="button" className="btn btn-secondary mt-3 h-11 md:h-9 w-full px-3 text-sm sm:w-auto" onClick={() => onUse(suggestion)}>
                      {SUGGEST.use}
                    </button>
                  </article>
                </li>
              );
            })}
          </ol>
        </>
      )}
    </div>
  );
}

interface SuggestionWhyProps {
  date: string;
  treatAs: DayContext['treat_as'];
  suggestion: Suggestion;
  stopNames: string[];
  onClose: () => void;
}

/**
 * "Why this number" for a suggested day: the suggestion's stops are evaluated here, in the browser,
 * as the planner would evaluate them once the plan is used, and the day's breakdown opens on that.
 */
function SuggestionWhy({ date, treatAs, suggestion, stopNames, onClose }: SuggestionWhyProps) {
  const stops = useMemo(() => suggestionAsStops(suggestion), [suggestion]);
  const evaluation = usePlanEvaluation(date, stops, treatAs);
  const treatByDate = useMemo(() => ({ [date]: treatAs }), [date, treatAs]);
  const days = useDayContexts(date, 2, treatByDate);
  const ctx = days.contexts[date] ?? null;
  const result = evaluation.status === 'ready' && !evaluation.updating ? evaluation.result : null;
  const position = suggestion.position;

  const subject = useMemo((): WhySubject | null => {
    if (result === null || ctx === null || result.stops.length !== stops.length) return null;
    return { kind: 'day', title: suggestionTitle(position) + ' for ' + fmtDay(date, 'medium'), result, stopNames, ctx };
    // The names follow the stops: they are the same list for the same suggestion.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [result, ctx, stops.length, position, date]);

  const failed = subject === null && (evaluation.status === 'error' || evaluation.status === 'unavailable' || (result !== null && ctx !== null));

  return (
    <>
      {subject === null ? (
        failed ? (
          <p role="alert" className="mt-2 flex items-start gap-1.5 text-xs font-semibold" style={{ color: 'var(--ink)' }}>
            <TriangleAlert size={13} aria-hidden className="mt-px flex-none" style={{ color: 'var(--money-negative)' }} />
            <span>{SUGGEST.whyFailed}</span>
          </p>
        ) : (
          <p role="status" className="mt-2 flex items-center gap-1.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
            <span className="spinner" aria-hidden /> {SUGGEST.whyWorking}
          </p>
        )
      ) : null}
      <WhyDrawer open={subject !== null} onClose={onClose} subject={subject} />
    </>
  );
}

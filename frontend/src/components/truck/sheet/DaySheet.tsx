import { useId, useMemo } from 'react';
import { Route } from 'lucide-react';
import { apiErrorMessage, type Plan, type PlanStop, type Spot } from '../../../api/truck';
import { useTruckPlanDraftStore } from '../../../stores/truckPlanDraftStore';
import { indexSpots, trafficIsNeutral } from '../../../utils/truck/assemble';
import { fmtClock, fmtCoord, fmtDay, fmtDuration, fmtMiles, fmtWindow, type EstimateUnit } from '../../../utils/truck/format';
import { estimateText } from '../../../utils/truck/ics';
import { MODEL_VERSION, addDays } from '../../../utils/truck/model';
import type { DayContext, DayResult, DayStop, DayTotals, Timeline } from '../../../utils/truck/model';
import { PLAN_TEXT, isEvaluated } from '../../../utils/truck/nextAction';
import {
  calendarStops,
  draftChanged,
  legViews,
  spotOfStop,
  stopAddress,
  stopNames,
  stopPoint,
  weatherHours,
  type CalendarStop,
  type LegView,
  type SpotIndex,
} from '../../../utils/truck/planDraft';
import { phoneLink } from '../../../utils/truck/scoutView';
import { feeText } from '../../../utils/truck/spotSummary';
import { timelineRows } from '../../../utils/truck/timelineView';
import { MONEY_LINE_LABELS, sheetDriveLine } from '../../../utils/truck/wording';
import {
  useDayContexts,
  useNow,
  usePlanEvaluation,
  usePlanForDate,
  useSpots,
  useTruck,
  type DayContexts,
  type PlanEvaluation,
  type PlanForDate,
} from '../data';
import {
  EmptyState,
  HolidayChip,
  OpenInMaps,
  PermissionNotice,
  QueryError,
  SkeletonCard,
  SkeletonRows,
  SourceLine,
  WarningList,
  WeatherChip,
} from '../ui';

export interface DaySheetProps {
  date: string;
}

const NO_STOPS: PlanStop[] = [];
const DAY_FAILED = 'Could not load this day.';
const SPOTS_FAILED = 'Could not load your spots.';

/** The saved day of a date as the sheet and its toolbar read it. */
export interface SavedDay {
  /** The request for the date's plan: `plan` is only known once it is `ready`. */
  planFor: PlanForDate;
  /** The saved plan of the date, or null when the date has none. */
  plan: Plan | null;
  stops: readonly PlanStop[];
  /** The name of stop i of the plan. */
  names: string[];
  /** The saved spots by id, deleted ones included. */
  spotsById: SpotIndex;
  /** The saved day as the browser evaluates it, like the planner. */
  evaluation: PlanEvaluation;
  /** The evaluated day; null until the model has evaluated every stop. */
  result: DayResult | null;
  days: DayContexts;
  /** The context of the date with the plan's "Treat this day as"; null while it loads. */
  context: DayContext | null;
  /** The context of the next date, for a stop that opens after midnight. */
  contextNext: DayContext | null;
  /** The stops as the calendar file takes them. */
  calendar: CalendarStop[];
  /** The planner holds changes to this day that are not saved: the sheet shows the saved day. */
  unsaved: boolean;
}

/**
 * The saved plan of a date (`usePlanForDate`), evaluated in the browser exactly as the planner
 * evaluates its draft: the same contexts, spots, drive legs and calibration through `dayPlan`.
 */
export function useSavedDay(date: string): SavedDay {
  const planFor = usePlanForDate(date);
  const plan = planFor.plan;
  const stops = plan === null ? NO_STOPS : plan.stops;
  const treatAs = plan === null ? null : plan.treat_as;

  const spots = useSpots({ archived: true }).data;
  const spotsById = useMemo(() => indexSpots(spots ?? []), [spots]);

  const evaluation = usePlanEvaluation(date, stops, treatAs);
  const treatByDate = useMemo(() => ({ [date]: treatAs }), [date, treatAs]);
  const days = useDayContexts(date, 2, treatByDate);
  const evaluated = evaluation.result;

  const names = useMemo(() => stopNames(stops, spotsById), [stops, spotsById]);
  const calendar = useMemo(() => calendarStops(stops, spotsById), [stops, spotsById]);

  const draft = useTruckPlanDraftStore((s) => s.drafts[date]);
  const unsaved = planFor.status === 'ready' && plan !== null && draft !== undefined && draft.dirty && draftChanged(draft, plan);

  return {
    planFor,
    plan,
    stops,
    names,
    spotsById,
    evaluation,
    result: isEvaluated(evaluated, stops.length) ? evaluated : null,
    days,
    context: days.contexts[date] ?? null,
    contextNext: days.contexts[addDays(date, 1)] ?? null,
    calendar,
    unsaved,
  };
}

// -------------------------------------------------------------------------------------------------
// What the sheet prints, worked out from the model's own results
// -------------------------------------------------------------------------------------------------

interface TimeRow {
  key: string;
  /** "9:34 AM"; empty for a drive, which starts at the row above it. */
  time: string;
  what: string;
  where: string;
  drive: boolean;
}

/**
 * The rows of "Times": one per event of the day with the timeline's own labels, and after each
 * departure the drive that follows, "Drive 11 min, 4.9 mi (Your time)". Minutes, miles and where a
 * drive time comes from are the model's and the planner's; nothing is worked out here.
 */
function timeRows(timeline: Timeline, names: readonly string[], legs: readonly LegView[]): TimeRow[] {
  const out: TimeRow[] = [];
  let nextLeg = 0;
  timelineRows(timeline, names).forEach((row, index) => {
    out.push({
      key: 'event-' + String(index),
      time: fmtClock(row.minute),
      what: row.label,
      where: row.stopIndex === null ? 'Base' : names[row.stopIndex],
      drive: false,
    });
    if (row.kind !== 'leave_base' && row.kind !== 'leave') return;
    const leg = nextLeg < legs.length ? legs[nextLeg] : null;
    nextLeg += 1;
    if (leg === null) return;
    out.push({
      key: 'drive-' + String(index),
      time: '',
      what: 'Drive ' + fmtDuration(leg.minutes) + ', ' + fmtMiles(leg.miles) + ' (' + leg.source + ')',
      where: leg.toStop === null ? 'To base' : 'To ' + names[leg.toStop],
      drive: true,
    });
  });
  return out;
}

/** The fee of a stop as a sentence: "Fee: $50 flat plus 10% of sales, $75 minimum." or "No fee." */
function feeSentence(terms: { fee_flat: number; fee_pct: number; fee_min: number }): string {
  const charged = terms.fee_flat > 0 || terms.fee_pct > 0 || terms.fee_min > 0;
  return charged ? 'Fee: ' + feeText(terms) + '.' : 'No fee.';
}

type NumberKey = 'orders' | 'sales' | 'contribution' | 'labour' | 'fuel' | 'tolls' | 'fixed_cost' | 'take_home' | 'take_home_per_hour';

/** The nine lines of "The day in numbers", in the order the sheet prints them. */
const NUMBERS: readonly { key: NumberKey; label: string; unit: EstimateUnit }[] = [
  { key: 'orders', label: MONEY_LINE_LABELS.orders, unit: 'orders' },
  { key: 'sales', label: MONEY_LINE_LABELS.sales, unit: 'money' },
  { key: 'contribution', label: MONEY_LINE_LABELS.contribution, unit: 'money' },
  { key: 'labour', label: MONEY_LINE_LABELS.labour, unit: 'money' },
  { key: 'fuel', label: MONEY_LINE_LABELS.fuel, unit: 'money' },
  { key: 'tolls', label: MONEY_LINE_LABELS.tolls, unit: 'money' },
  { key: 'fixed_cost', label: 'Fixed cost', unit: 'money' },
  { key: 'take_home', label: MONEY_LINE_LABELS.take_home, unit: 'money' },
  { key: 'take_home_per_hour', label: 'Take-home per hour', unit: 'money_per_hour' },
];

// -------------------------------------------------------------------------------------------------
// Pieces
// -------------------------------------------------------------------------------------------------

const SHEET = 'tp-sheet bg-white rounded-xl border p-4 sm:p-6 space-y-6';
const SECTION_TITLE = 'tp-sheet-h text-base font-extrabold';
const COLUMN_HEAD = 'py-1.5 text-left text-[11px] font-bold uppercase tracking-wider';
const LINE = 'text-sm font-semibold';

/** One stop in the title block: its hours, for the weather chip. */
interface WeatherLine {
  key: string;
  /** "Herndon office park, 11 AM to 2 PM". */
  text: string;
  fromHour: number;
  toHour: number;
  /** The stop opens after midnight: its hours are those of the next civil date. */
  nextDay: boolean;
  /** The stop has closed (by the truck's clock): a missing forecast is then not one still to come. */
  passed: boolean;
}

function weatherLines(stops: readonly PlanStop[], names: readonly string[], date: string, now: { date: string; minute: number }): WeatherLine[] {
  // Minutes from the sheet's midnight to now: negative before the date, past 1440 after it.
  const tomorrow = addDays(date, 1);
  const sinceMidnight =
    now.date === date ? now.minute : now.date === tomorrow ? 1440 + now.minute : now.date < date ? Number.NEGATIVE_INFINITY : Number.POSITIVE_INFINITY;
  return stops.map((stop, index) => {
    const nextDay = stop.open_minute >= 1440;
    const shift = nextDay ? 1440 : 0;
    const hours = weatherHours([{ open_minute: stop.open_minute - shift, close_minute: stop.close_minute - shift }]);
    return {
      key: stop.id,
      text: names[index] + ', ' + fmtWindow(stop.open_minute, stop.close_minute),
      fromHour: hours.fromHour,
      toHour: hours.toHour,
      nextDay,
      passed: stop.close_minute <= sinceMidnight,
    };
  });
}

/** The page's heading: "Day sheet" and the date. On paper it is the day's title. */
function SheetHeading({ date }: { date: string }) {
  return (
    <h1 className="tp-sheet-title text-2xl font-extrabold" style={{ color: 'var(--ink)' }}>
      <span className="block">Day sheet</span>
      <span className="block text-lg tabular-nums">{fmtDay(date, 'long')}</span>
    </h1>
  );
}

/** Section 1: "Day sheet", the date, the truck's name, the holiday if any, one weather line per stop. */
function SheetTitle({ date, day }: { date: string; day: SavedDay }) {
  const { profile } = useTruck();
  const now = useNow();
  const context = day.context;
  const next = day.contextNext;
  const lines = weatherLines(day.stops, day.names, date, now);
  return (
    <div className="space-y-2">
      <SheetHeading date={date} />
      <p className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
        {profile.name}
      </p>
      {context !== null ? <HolidayChip context={context} /> : null}
      {context !== null && lines.length > 0 ? (
        <ul className="space-y-1.5" aria-label="Weather over each stop">
          {lines.map((line) => (
            <li key={line.key} className={'flex flex-wrap items-center gap-x-2 gap-y-1 ' + LINE} style={{ color: 'var(--ink)' }}>
              <span className="min-w-0 break-words">{line.text}</span>
              <WeatherChip
                forecast={line.nextDay ? (next === null ? null : next.forecast) : context.forecast}
                fromHour={line.fromHour}
                toHour={line.toHour}
                info={day.days.forecast}
                passed={line.passed}
              />
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

/** Section 2: "Times", a table of the day's events and drives. */
function Times({ rows }: { rows: readonly TimeRow[] }) {
  const id = useId();
  return (
    <section aria-labelledby={id}>
      <h2 id={id} className={SECTION_TITLE} style={{ color: 'var(--ink)' }}>
        Times
      </h2>
      <table className="tp-sheet-table mt-2 w-full border-collapse text-sm">
        <thead>
          <tr>
            <th scope="col" className={COLUMN_HEAD + ' w-[5.5rem] pr-3'} style={{ color: 'var(--slate)' }}>
              Time
            </th>
            <th scope="col" className={COLUMN_HEAD + ' pr-3'} style={{ color: 'var(--slate)' }}>
              What
            </th>
            <th scope="col" className={COLUMN_HEAD} style={{ color: 'var(--slate)' }}>
              Where
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.key} className="border-t" style={{ borderColor: 'var(--line-soft)' }}>
              <td className="tp-sheet-time py-1.5 pr-3 align-top font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                {row.time}
              </td>
              <td className={'py-1.5 pr-3 align-top ' + (row.drive ? 'font-semibold' : 'font-bold')} style={{ color: row.drive ? 'var(--body)' : 'var(--ink)' }}>
                {row.what}
              </td>
              <td className="py-1.5 align-top font-semibold break-words" style={{ color: 'var(--body)' }}>
                {row.where}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

/**
 * Section 3: one block per stop. Name, address, the host's name, contact and phone, the window,
 * the estimate with its range and label as text, the fee as a sentence and the owner's notes. The
 * link to Google Maps is for the screen and does not print.
 */
function StopBlock({ stop, name, spot, spotsById, evaluated }: { stop: PlanStop; name: string; spot: Spot | null; spotsById: SpotIndex; evaluated: DayStop }) {
  const id = useId();
  const point = stopPoint(stop, spotsById);
  const address = stopAddress(stop, spotsById).trim();
  const host = spot === null ? null : spot.host_details;
  const hostName = host === null || host.name === null ? '' : host.name.trim();
  const contact = host === null || host.contact === null ? '' : host.contact.trim();
  const phone = host === null ? null : phoneLink(host.phone);
  const notes = spot === null || spot.notes === null ? '' : spot.notes.trim();
  // A spot's fee is part of its terms; an event carries its own. A catering job is contracted and pays none.
  const fee = stop.kind === 'spot' ? (spot === null ? null : feeSentence(spot.terms)) : stop.kind === 'event' ? feeSentence(stop) : null;
  return (
    <section className="tp-sheet-stop space-y-1" aria-labelledby={id}>
      <h2 id={id} className={SECTION_TITLE + ' break-words'} style={{ color: 'var(--ink)' }}>
        {name}
      </h2>
      {address !== '' || point !== null ? (
        <p className={LINE + ' break-words'} style={{ color: 'var(--body)' }}>
          {address !== '' ? address : point !== null ? fmtCoord(point.lat, point.lng) : ''}
        </p>
      ) : null}
      {hostName !== '' ? (
        <p className={LINE + ' break-words'} style={{ color: 'var(--body)' }}>
          Host: {hostName}
        </p>
      ) : null}
      {contact !== '' || phone !== null ? (
        <p className={LINE + ' break-words'} style={{ color: 'var(--body)' }}>
          Contact: {contact}
          {contact !== '' && phone !== null ? ', ' : ''}
          {phone === null ? null : phone.href === null ? (
            phone.text
          ) : (
            <a href={phone.href} className="font-bold underline underline-offset-2" style={{ color: 'var(--ink)' }}>
              {phone.text}
            </a>
          )}
        </p>
      ) : null}
      <p className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
        {fmtWindow(stop.open_minute, stop.close_minute)}
      </p>
      <p className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
        Estimate: {estimateText(evaluated.orders, 'orders')}
      </p>
      {fee !== null ? (
        <p className={LINE} style={{ color: 'var(--body)' }}>
          {fee}
        </p>
      ) : null}
      {notes !== '' ? (
        <p className={LINE + ' whitespace-pre-line break-words'} style={{ color: 'var(--body)' }}>
          Notes: {notes}
        </p>
      ) : null}
      {spot !== null || point !== null ? (
        <div className="tp-no-print">{spot !== null ? <OpenInMaps href={spot.maps_url} /> : point !== null ? <OpenInMaps point={point} /> : null}</div>
      ) : null}
    </section>
  );
}

/**
 * Section 4: "The day in numbers". Every line is the estimate as text with its range and its label
 * in words, so a black-and-white page says as much as the screen; the day's own costs are fixed
 * amounts and print as one figure that says so.
 */
function Numbers({ totals }: { totals: DayTotals }) {
  const id = useId();
  return (
    <section aria-labelledby={id}>
      <h2 id={id} className={SECTION_TITLE} style={{ color: 'var(--ink)' }}>
        The day in numbers
      </h2>
      <table className="tp-sheet-table mt-2 w-full border-collapse text-sm">
        <tbody>
          {NUMBERS.map((line, index) => (
            <tr key={line.key} className={index > 0 ? 'border-t' : undefined} style={{ borderColor: 'var(--line-soft)' }}>
              <th scope="row" className="py-1.5 pr-3 text-left align-top font-semibold" style={{ color: 'var(--body)' }}>
                {line.label}
              </th>
              <td className={'py-1.5 text-right align-top tabular-nums ' + (line.key === 'take_home' ? 'font-extrabold' : 'font-bold')} style={{ color: 'var(--ink)' }}>
                {estimateText(totals[line.key], line.unit)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

/** Section 5: "Things to check", the model's warnings in its own order. Nothing when there are none. */
function Checks({ result, names, context }: { result: DayResult; names: string[]; context: DayContext }) {
  const id = useId();
  if (result.warnings.length === 0) return null;
  return (
    <section aria-labelledby={id}>
      <h2 id={id} className={SECTION_TITLE + ' mb-2'} style={{ color: 'var(--ink)' }}>
        Things to check
      </h2>
      <WarningList result={result} stopNames={names} context={context} />
    </section>
  );
}

// -------------------------------------------------------------------------------------------------

/**
 * The day sheet (docs/truck-planner/05_FRONTEND.md 4.10): the date's saved plan as a page to print
 * and to read on a phone at the truck. The times, the stops, the day in numbers and the things to
 * check are the model's for the saved day, evaluated in the browser like the planner. An estimate is
 * always printed as text with its range and its label.
 *
 * The standing notice is on the sheet in every state (a source guard checks that it stays).
 */
export default function DaySheet({ date }: DaySheetProps) {
  const day = useSavedDay(date);
  const { A } = useTruck();
  const now = useNow();
  const neutral = useMemo(() => trafficIsNeutral(A), [A]);
  const { planFor, plan, stops, names, spotsById, evaluation, result, context } = day;
  const sent = evaluation.legs;
  const legs = useMemo(() => (result === null ? [] : legViews(result.timeline, sent, neutral)), [result, sent, neutral]);
  const planner = '/truck/plan/' + date;

  if (planFor.status === 'error') {
    return (
      <div className="space-y-3">
        <SheetHeading date={date} />
        <QueryError message={apiErrorMessage(planFor.error, DAY_FAILED) ?? DAY_FAILED} onRetry={planFor.refetch} />
        <PermissionNotice variant="line" />
      </div>
    );
  }

  if (planFor.status === 'pending') {
    return (
      <div className="space-y-3" aria-busy="true">
        <SheetHeading date={date} />
        <SkeletonCard height={120} />
        <SkeletonRows rows={3} rowHeight={96} />
        <PermissionNotice variant="line" />
      </div>
    );
  }

  if (plan === null) {
    return (
      <div className="space-y-3">
        <SheetHeading date={date} />
        <EmptyState icon={Route} title="Nothing saved for this day" body="Save the day in the planner first." action={{ label: 'Open the planner', to: planner }} />
        <PermissionNotice variant="line" />
      </div>
    );
  }

  const frame = { borderColor: 'var(--line-soft)' };

  // A saved day without a stop: there is nothing to time, to estimate or to print.
  if (stops.length === 0) {
    return (
      <article className={SHEET} style={frame}>
        <SheetTitle date={date} day={day} />
        <p className={LINE} style={{ color: 'var(--ink)' }}>
          Nothing is planned for this day.
        </p>
        <PermissionNotice variant="line" />
      </article>
    );
  }

  if (result === null || context === null) {
    const failed = evaluation.status === 'error';
    const waiting = !failed && (evaluation.status === 'pending' || context === null);
    const partial = evaluation.result;
    return (
      <article className={SHEET} style={frame} aria-busy={waiting ? true : undefined}>
        <SheetTitle date={date} day={day} />
        {failed ? (
          <QueryError message={apiErrorMessage(evaluation.error, SPOTS_FAILED) ?? SPOTS_FAILED} onRetry={evaluation.refetch} />
        ) : waiting ? (
          <SkeletonRows rows={3} rowHeight={96} />
        ) : (
          <>
            {/* A stop cannot be evaluated, or the times of the day do not work out: the planner says why. */}
            <p className={LINE} style={{ color: 'var(--ink)' }}>
              {PLAN_TEXT.noFigures}
            </p>
            <ul className="tp-stat-list">
              {stops.map((stop, index) => (
                <li key={stop.id} className="flex flex-wrap items-baseline justify-between gap-x-4 py-2 text-sm">
                  <span className="min-w-0 break-words font-bold" style={{ color: 'var(--ink)' }}>
                    {names[index]}
                  </span>
                  <span className="font-bold tabular-nums" style={{ color: 'var(--body)' }}>
                    {fmtWindow(stop.open_minute, stop.close_minute)}
                  </span>
                </li>
              ))}
            </ul>
            {partial !== null && context !== null ? <Checks result={partial} names={names} context={context} /> : null}
          </>
        )}
        <PermissionNotice variant="line" />
      </article>
    );
  }

  const straight = result.timeline.legs.some((leg) => leg.source === 'fallback');
  // OpenStreetMap is credited when a stop's host is a place taken from its map data.
  const fromMapData = stops.some((stop) => {
    const spot = spotOfStop(stop, spotsById);
    return spot !== null && spot.host_details !== null && spot.host_details.place_key !== null && spot.host_details.place_key !== '';
  });

  return (
    <article className={SHEET} style={frame}>
      <SheetTitle date={date} day={day} />

      <Times rows={timeRows(result.timeline, names, legs)} />

      {stops.map((stop, index) => (
        <StopBlock key={stop.id} stop={stop} name={names[index]} spot={spotOfStop(stop, spotsById)} spotsById={spotsById} evaluated={result.stops[index]} />
      ))}

      <Numbers totals={result.totals} />

      <Checks result={result} names={names} context={context} />

      <div className="tp-sheet-foot space-y-1.5 border-t pt-3" style={frame}>
        <PermissionNotice variant="line" />
        {fromMapData ? <SourceLine kinds={['osm_sentence']} /> : null}
        <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {sheetDriveLine(straight, neutral)}
        </p>
        <p className="text-xs font-semibold tabular-nums" style={{ color: 'var(--body)' }}>
          Estimates from model {MODEL_VERSION}. Printed {fmtDay(now.date, 'long')} {fmtClock(now.minute)}.
        </p>
      </div>
    </article>
  );
}

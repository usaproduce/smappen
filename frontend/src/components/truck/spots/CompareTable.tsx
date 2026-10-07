import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ArrowUp, Clock, RefreshCw, TriangleAlert } from 'lucide-react';
import type { DriveLeg, Spot } from '../../../api/truck';
import { MAP_DOMAIN } from '../../../utils/truck/model';
import type { DayResult, StopMoney, WindowResult } from '../../../utils/truck/model';
import { fmtCeil, fmtCount, fmtDuration, fmtHowLong, fmtMiles, fmtWeekday, fmtWindow } from '../../../utils/truck/format';
import { choiceHow, feeText, hostText, type SpotEstimateState, type WindowChoice } from '../../../utils/truck/spotSummary';
import { SPOT_STATE_TAGS, VISIBILITY_TEXT, driveFallbackReason, driveSourceLabel } from '../../../utils/truck/wording';
import { RangeValue, WeekStrip } from '../ui';

/** Everything one column of the comparison shows. The page computes it; this component only lays it out. */
export interface CompareColumn {
  spot: Spot;
  state: SpotEstimateState;
  /** "Updating" or "Out of date" when the stored vectors are not current, else null. */
  staleWord: string | null;
  /** The window this column is compared on; null when there is none. */
  choice: WindowChoice | null;
  window: WindowResult | null;
  money: StopMoney | null;
  /** The whole day when this window is its only stop; null while it cannot be given. */
  day: DayResult | null;
  /** The drive legs are still on their way: the day follows in a moment. */
  dayPending: boolean;
  /** The leg base to spot as the server sent it; null when it sent none. */
  sentLeg: DriveLeg | null;
  /** Expected orders per hour of the typical week, 168 values. */
  week: number[] | null;
  /** Logged services at this spot that the model uses. */
  services: number;
  /** This column has the highest expected take-home of the comparison. */
  highest: boolean;
}

export interface CompareTableProps {
  columns: CompareColumn[];
  /** The time-of-day traffic table changes nothing: the drive label drops its traffic part. */
  trafficNeutral: boolean;
  /** The region data is being rebuilt: the stale tag reads "Out of date". */
  rebuilding: boolean;
}

const NO_BEST_WINDOW = 'No hour of the week reaches one order here.';

function Plain({ children }: { children: ReactNode }) {
  return (
    <span className="text-sm font-semibold" style={{ color: 'var(--ink)' }}>
      {children}
    </span>
  );
}

function Quiet({ children }: { children: ReactNode }) {
  return (
    <span className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
      {children}
    </span>
  );
}

function Pending() {
  return <span aria-busy="true" className="skeleton block" style={{ height: 22, width: 120, borderRadius: 6 }} />;
}

/** What a figure cell prints when its column has no estimate. */
function noFigure(c: CompareColumn): ReactNode {
  if (c.state === 'none') return <Quiet>{SPOT_STATE_TAGS.none}</Quiet>;
  return <Quiet>—</Quiet>;
}

function windowCell(c: CompareColumn): ReactNode {
  if (c.choice !== null) {
    return (
      <Plain>
        {fmtWeekday(c.choice.dow, 'short')} {fmtWindow(c.choice.open, c.choice.close)}
      </Plain>
    );
  }
  if (c.state === 'ready') return <Quiet>{NO_BEST_WINDOW}</Quiet>;
  return noFigure(c);
}

function takeHomeCell(c: CompareColumn): ReactNode {
  if (c.choice === null || c.window === null) return noFigure(c);
  if (c.dayPending) return <Pending />;
  if (c.day === null || c.day.stops.length === 0) return <Quiet>—</Quiet>;
  return (
    <div className="space-y-1.5">
      <RangeValue estimate={c.day.totals.take_home} unit="money" size="md" />
      {c.highest ? (
        <span className="tp-chip">
          <ArrowUp size={12} strokeWidth={2.75} aria-hidden />
          Highest expected
        </span>
      ) : null}
    </div>
  );
}

function breakEvenCell(c: CompareColumn): ReactNode {
  if (c.choice === null || c.window === null) return noFigure(c);
  if (c.dayPending) return <Pending />;
  if (c.day === null || c.day.stops.length === 0) return <Quiet>—</Quiet>;
  const orders = c.day.stops[0].adds.break_even_orders;
  if (orders === null) return <Plain>This spot cannot break even at your ticket and costs.</Plain>;
  return (
    <span className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
      {fmtCeil(orders)} orders
    </span>
  );
}

function driveCell(c: CompareColumn, trafficNeutral: boolean): ReactNode {
  if (c.choice === null || c.window === null) return noFigure(c);
  if (c.dayPending) return <Pending />;
  const leg = c.day !== null && c.day.timeline.legs.length > 0 ? c.day.timeline.legs[0] : null;
  if (leg === null) return <Quiet>—</Quiet>;
  const label = driveSourceLabel({
    legSource: leg.source,
    driveSource: c.sentLeg === null ? null : c.sentLeg.source,
    departMinute: leg.depart_minute,
    trafficNeutral,
  });
  const reason = leg.source === 'fallback' ? driveFallbackReason(c.sentLeg === null ? null : c.sentLeg.fallback_reason) : null;
  return (
    <div>
      <span className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
        {fmtDuration(leg.minutes)}, {fmtMiles(leg.miles)}
      </span>
      <span className="mt-0.5 flex items-start gap-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
        {leg.source === 'fallback' ? <TriangleAlert size={13} aria-hidden className="mt-px flex-none" style={{ color: 'var(--fresh-aging)' }} /> : null}
        <span>
          {label}
          {reason !== null ? '. ' + reason : ''}
        </span>
      </span>
    </div>
  );
}

/** The busiest hour of a week strip in words. No figure: a number here would be an estimate without its range. */
function weekSentence(week: readonly number[]): string {
  let peak = 0;
  for (let how = 1; how < week.length; how++) if (week[how] > week[peak]) peak = how;
  if (!(week[peak] > 0)) return 'No orders in any hour of a typical week.';
  return 'Busiest hour of a typical week: ' + fmtHowLong(peak) + '.';
}

function weekCell(c: CompareColumn): ReactNode {
  if (c.week === null) return noFigure(c);
  const sentence = weekSentence(c.week);
  const marked =
    c.choice === null
      ? undefined
      : [{ ...choiceHow(c.choice), label: fmtWeekday(c.choice.dow, 'long') + ' ' + fmtWindow(c.choice.open, c.choice.close) }];
  return (
    <div className="min-w-0">
      <p className="mb-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
        {sentence}
      </p>
      <WeekStrip values={c.week} yMax={MAP_DOMAIN.opportunity} windows={marked} compact ariaSummary={c.spot.name + '. ' + sentence} />
    </div>
  );
}

interface Row {
  label: string;
  cell: (c: CompareColumn) => ReactNode;
}

function rowsOf(trafficNeutral: boolean): Row[] {
  return [
    { label: 'Window', cell: windowCell },
    { label: 'Orders', cell: (c) => (c.window !== null ? <RangeValue estimate={c.window.orders} unit="orders" size="md" /> : noFigure(c)) },
    { label: 'Left after food and fees', cell: (c) => (c.money !== null ? <RangeValue estimate={c.money.contribution} unit="money" size="sm" /> : noFigure(c)) },
    { label: 'Take-home for a one-stop day', cell: takeHomeCell },
    { label: 'Break-even', cell: breakEvenCell },
    { label: 'Drive from base', cell: (c) => driveCell(c, trafficNeutral) },
    { label: 'Fee', cell: (c) => <Plain>{feeText(c.spot.terms)}</Plain> },
    { label: 'Host', cell: (c) => <Plain>{hostText(c.spot)}</Plain> },
    { label: 'Only food here', cell: (c) => <Plain>{c.spot.terms.host === null ? '—' : c.spot.terms.host.only_food ? 'Yes' : 'No'}</Plain> },
    { label: 'Visibility', cell: (c) => <Plain>{VISIBILITY_TEXT[c.spot.terms.visibility].label}</Plain> },
    { label: 'Services the model uses', cell: (c) => <span className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>{fmtCount(c.services)}</span> },
    { label: 'Week', cell: weekCell },
  ];
}

function ColumnHead({ column, rebuilding }: { column: CompareColumn; rebuilding: boolean }) {
  const Icon = rebuilding ? Clock : RefreshCw;
  return (
    <div className="min-w-0">
      <Link
        to={'/truck/spots/' + encodeURIComponent(column.spot.id)}
        className="text-sm font-extrabold underline underline-offset-2 normal-case tracking-normal"
        style={{ color: 'var(--ink)' }}
      >
        {column.spot.name}
      </Link>
      {column.staleWord !== null ? (
        <span className="mt-1 block">
          <span className="tp-chip tp-chip-sm normal-case tracking-normal">
            <Icon size={11} strokeWidth={2.75} aria-hidden />
            {column.staleWord}
          </span>
        </span>
      ) : null}
    </div>
  );
}

/**
 * Spots side by side (docs/truck-planner/05_FRONTEND.md 4.4): one column per spot, one row per
 * fact. From 640 px it is a table with a header per row; on a phone each spot is a 260 px card in
 * a row that scrolls sideways and snaps, and every cell repeats its row label as a caption.
 */
export default function CompareTable({ columns, trafficNeutral, rebuilding }: CompareTableProps) {
  const rows = rowsOf(trafficNeutral);
  return (
    <>
      <div className="tp-scroll-x hidden rounded-xl border bg-white sm:block" style={{ borderColor: 'var(--line-soft)' }}>
        <table className="w-full text-sm" style={{ minWidth: 190 + 230 * columns.length }}>
          <caption className="sr-only">Spots side by side</caption>
          <thead style={{ background: 'var(--bg-panel)' }}>
            <tr>
              <th scope="col" className="px-3 py-2 text-left" style={{ minWidth: 190 }}>
                <span className="sr-only">Fact</span>
              </th>
              {columns.map((column) => (
                <th key={column.spot.id} scope="col" className="px-3 py-2 text-left align-top" style={{ minWidth: 230 }}>
                  <ColumnHead column={column} rebuilding={rebuilding} />
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.label} className="border-t" style={{ borderColor: 'var(--line-soft)' }}>
                <th scope="row" className="p-3 text-left align-top text-[13px] font-bold" style={{ color: 'var(--body)', minWidth: 190 }}>
                  {row.label}
                </th>
                {columns.map((column) => (
                  <td key={column.spot.id} className="p-3 align-top">
                    {row.cell(column)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="tp-scroll-x flex snap-x snap-mandatory gap-3 pb-2 sm:hidden" role="list" aria-label="Spots side by side">
        {columns.map((column) => (
          <article
            key={column.spot.id}
            role="listitem"
            className="flex-none snap-start rounded-xl border bg-white p-3"
            style={{ width: 260, borderColor: 'var(--line-soft)' }}
          >
            <ColumnHead column={column} rebuilding={rebuilding} />
            <dl className="mt-2 space-y-3">
              {rows.map((row) => (
                <div key={row.label}>
                  <dt className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
                    {row.label}
                  </dt>
                  <dd className="mt-0.5 min-w-0">{row.cell(column)}</dd>
                </div>
              ))}
            </dl>
          </article>
        ))}
      </div>
    </>
  );
}

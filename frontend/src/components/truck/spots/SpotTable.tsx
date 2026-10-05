import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ChevronRight, Clock, RefreshCw } from 'lucide-react';
import type { Spot } from '../../../api/truck';
import { fmtWeekday, fmtWindow } from '../../../utils/truck/format';
import { feeText, hostLine, type SpotSummary } from '../../../utils/truck/spotSummary';
import { SPOT_STATE_TAGS } from '../../../utils/truck/wording';
import { DataTable, RangeValue, type Column } from '../ui';

export interface SpotTableRow {
  spot: Spot;
  summary: SpotSummary;
}

export interface SpotTableProps {
  /** The rows in the order the page chose. */
  rows: SpotTableRow[];
  /** The spots ticked for comparison. */
  ticked: readonly string[];
  /** How many spots can be ticked at once. */
  tickLimit: number;
  onTick: (id: string, on: boolean) => void;
  /** A row of the table was clicked. */
  onOpen: (id: string) => void;
  /** The address of a spot's page, for the links of the cards. */
  hrefOf: (id: string) => string;
  /** The region data is being rebuilt: stale spots read "Out of date", not "Updating". */
  rebuilding: boolean;
  /** Shown when no row is left (a search without a match). */
  empty: ReactNode;
}

const NO_BEST_WINDOW = 'No hour of the week reaches one order here.';

/** The word that says the numbers of a spot are not current, or null when they are. */
function staleWord(row: SpotTableRow, rebuilding: boolean): string | null {
  if (row.spot.vectors_state === 'none') return null; // its cells say "No estimate yet"
  if (row.spot.vectors_state === 'stale' || row.summary.state === 'mismatch') {
    return rebuilding ? SPOT_STATE_TAGS.rebuilding : SPOT_STATE_TAGS.stale;
  }
  return null;
}

function StateTag({ word, rebuilding }: { word: string; rebuilding: boolean }) {
  const Icon = rebuilding ? Clock : RefreshCw;
  return (
    <span className="tp-chip tp-chip-sm">
      <Icon size={11} strokeWidth={2.75} aria-hidden />
      {word}
    </span>
  );
}

function windowText(row: SpotTableRow): string {
  const best = row.summary.best;
  if (best !== null) return fmtWeekday(best.dow, 'short') + ' ' + fmtWindow(best.open, best.close);
  if (row.summary.state === 'ready') return NO_BEST_WINDOW;
  return '—';
}

/** What an estimate cell prints when there is no estimate to show. */
function noEstimate(row: SpotTableRow): string {
  return row.summary.state === 'none' ? SPOT_STATE_TAGS.none : '—';
}

/**
 * The table of saved spots (docs/truck-planner/05_FRONTEND.md 4.4): one row per spot with the best
 * window of the typical week, its orders and what it leaves after food and fees. Each estimate is a
 * RangeValue: value, range and confidence label together.
 *
 * The six columns need about 900 px. Where the list has less room (a phone, a tablet held upright)
 * the same facts are cards, two to a row from 620 px, so nothing has to be scrolled sideways.
 */
export default function SpotTable({ rows, ticked, tickLimit, onTick, onOpen, hrefOf, rebuilding, empty }: SpotTableProps) {
  const tickBox = (row: SpotTableRow) => {
    const on = ticked.includes(row.spot.id);
    return (
      <label className="inline-flex h-11 w-11 md:h-6 md:w-6 cursor-pointer items-center justify-center" title={'Compare ' + row.spot.name}>
        <input
          type="checkbox"
          className="h-4 w-4 cursor-pointer"
          style={{ accentColor: 'var(--brand)' }}
          checked={on}
          disabled={!on && ticked.length >= tickLimit}
          onChange={(e) => onTick(row.spot.id, e.target.checked)}
          aria-label={'Compare ' + row.spot.name}
        />
      </label>
    );
  };

  const nameBlock = (row: SpotTableRow, link = false) => {
    const word = staleWord(row, rebuilding);
    const host = hostLine(row.spot);
    return (
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          {link ? (
            <Link to={hrefOf(row.spot.id)} className="font-bold underline underline-offset-2" style={{ color: 'var(--ink)' }}>
              {row.spot.name}
            </Link>
          ) : (
            <span className="font-bold" style={{ color: 'var(--ink)' }}>
              {row.spot.name}
            </span>
          )}
          {word !== null ? <StateTag word={word} rebuilding={rebuilding} /> : null}
        </div>
        {host !== null ? (
          <div className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
            {host}
          </div>
        ) : null}
        {row.spot.address !== '' ? (
          <div className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
            {row.spot.address}
          </div>
        ) : null}
      </div>
    );
  };

  const ordersCell = (row: SpotTableRow) =>
    row.summary.orders !== null ? (
      <RangeValue estimate={row.summary.orders} unit="orders" size="sm" layout="inline" />
    ) : (
      <span style={{ color: 'var(--body)' }}>{noEstimate(row)}</span>
    );

  const leftCell = (row: SpotTableRow) =>
    row.summary.contribution !== null ? (
      <RangeValue estimate={row.summary.contribution} unit="money" size="sm" layout="inline" />
    ) : (
      <span style={{ color: 'var(--body)' }}>{noEstimate(row)}</span>
    );

  const columns: Column<SpotTableRow>[] = [
    { key: 'tick', header: 'Compare', width: '4.5rem', render: tickBox },
    { key: 'spot', header: 'Spot', width: '26%', render: nameBlock },
    {
      key: 'window',
      header: 'Best window (typical week)',
      render: (row) => <span className={row.summary.best !== null ? 'whitespace-nowrap' : ''}>{windowText(row)}</span>,
    },
    { key: 'orders', header: 'Orders', render: ordersCell },
    { key: 'left', header: 'Left after food and fees', render: leftCell },
    { key: 'fee', header: 'Fee', render: (row) => feeText(row.spot.terms) },
  ];

  const card = (row: SpotTableRow) => (
    <div className="flex h-full items-start gap-1 rounded-xl border bg-white p-3" style={{ borderColor: 'var(--line-soft)' }}>
      <div className="-ml-2 -mt-2 flex-none md:ml-0 md:mr-1.5 md:mt-0">{tickBox(row)}</div>
      <div className="min-w-0 flex-1 space-y-2">
        {nameBlock(row, true)}
        <dl className="space-y-1.5 text-sm">
          <CardFact label="Best window (typical week)">{windowText(row)}</CardFact>
          <CardFact label="Orders">{ordersCell(row)}</CardFact>
          <CardFact label="Left after food and fees">{leftCell(row)}</CardFact>
          <CardFact label="Fee">{feeText(row.spot.terms)}</CardFact>
        </dl>
      </div>
      <Link
        to={hrefOf(row.spot.id)}
        className="-mr-2 -mt-2 inline-flex h-11 w-11 flex-none items-center justify-center rounded-lg"
        style={{ color: 'var(--body)' }}
        aria-label={'Open ' + row.spot.name}
      >
        <ChevronRight size={18} aria-hidden />
      </Link>
    </div>
  );

  return (
    <div className="@container">
      {/* The kit's table scrolls sideways in its own box; `relative` keeps its hidden header text inside that box. */}
      <div className="hidden @[900px]:block [&_.tp-scroll-x]:relative">
        <DataTable caption="Saved spots" columns={columns} rows={rows} rowKey={(row) => row.spot.id} onRowClick={(row) => onOpen(row.spot.id)} empty={empty} />
      </div>
      <div className="@[900px]:hidden">
        {rows.length === 0 ? (
          <div className="rounded-xl border bg-white p-6 text-center text-sm font-semibold" style={{ borderColor: 'var(--line-soft)', color: 'var(--body)' }}>
            {empty}
          </div>
        ) : (
          <ul className="grid gap-2 @[620px]:grid-cols-2" aria-label="Saved spots">
            {rows.map((row) => (
              <li key={row.spot.id}>{card(row)}</li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

function CardFact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
        {label}
      </dt>
      <dd className="font-semibold" style={{ color: 'var(--ink)' }}>
        {children}
      </dd>
    </div>
  );
}

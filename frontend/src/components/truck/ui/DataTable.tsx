import { useMemo } from 'react';
import type { KeyboardEvent, MouseEvent, ReactNode } from 'react';
import { ArrowDown, ArrowUp, ChevronRight, ChevronsUpDown } from 'lucide-react';
import { TABLE } from '../../../utils/truck/wording';
import { sortRows } from './kit';

export interface Column<T> {
  key: string;
  header: string;
  /** right for numbers: they are set in tabular figures at weight 700. */
  align?: 'left' | 'right';
  /** A CSS width for the column, for example "8rem". */
  width?: string;
  render: (row: T) => ReactNode;
  /** Makes the column sortable: numbers sort numerically, strings byte-wise. */
  sortValue?: (row: T) => number | string;
}

export interface DataTableProps<T> {
  /** What the table lists, for screen readers. */
  caption: string;
  columns: Column<T>[];
  rows: T[];
  rowKey: (row: T) => string;
  /** The column the rows are ordered by; the caller keeps it and turns it in onSort. */
  sort?: { key: string; dir: 'asc' | 'desc' };
  onSort?: (key: string) => void;
  onRowClick?: (row: T) => void;
  /** Under 640 px the table is replaced by a list of these cards; without it the table scrolls sideways. */
  mobileCard?: (row: T) => ReactNode;
  /** Shown in place of the rows when there are none. */
  empty?: ReactNode;
  /** 8 px row padding instead of 12 px. */
  dense?: boolean;
}

const INTERACTIVE = 'a, button, input, select, textarea, label, summary, [role="button"], [role="switch"], [role="tab"]';

/** True when a click landed on a control inside the row, which then keeps the click for itself. */
function fromControl(target: EventTarget | null, row: Element): boolean {
  if (!(target instanceof Element)) return false;
  const control = target.closest(INTERACTIVE);
  return control !== null && control !== row && row.contains(control);
}

/**
 * A real table (docs/truck-planner/05_FRONTEND.md 3.9). Sortable headers are buttons with
 * aria-sort; ties are broken by the row key, so the order never depends on the browser. Clickable
 * rows are reachable by keyboard and carry a chevron.
 */
export default function DataTable<T>(props: DataTableProps<T>) {
  const { caption, columns, rows, rowKey, sort, onSort, onRowClick, mobileCard, empty, dense = false } = props;

  const ordered = useMemo(() => {
    if (sort === undefined) return rows;
    const column = columns.find((c) => c.key === sort.key);
    if (column === undefined || column.sortValue === undefined) return rows;
    return sortRows(rows, column.sortValue, sort.dir, rowKey);
  }, [rows, columns, sort, rowKey]);

  const pad = dense ? 'px-3 py-2' : 'p-3';
  const clickable = onRowClick !== undefined;

  const onRowKey = (e: KeyboardEvent<HTMLElement>, row: T) => {
    if (e.key !== 'Enter' || e.target !== e.currentTarget || onRowClick === undefined) return;
    e.preventDefault();
    onRowClick(row);
  };
  const onRowMouse = (e: MouseEvent<HTMLElement>, row: T) => {
    if (onRowClick === undefined || fromControl(e.target, e.currentTarget)) return;
    onRowClick(row);
  };

  const table = (
    <div className={'tp-scroll-x bg-white border rounded-xl' + (mobileCard !== undefined ? ' hidden sm:block' : '')} style={{ borderColor: 'var(--line-soft)' }}>
      <table className="w-full text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead style={{ background: 'var(--bg-panel)' }}>
          <tr>
            {columns.map((c) => {
              const sortable = c.sortValue !== undefined && onSort !== undefined;
              const active = sort !== undefined && sort.key === c.key;
              const ariaSort = !sortable ? undefined : active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none';
              const Arrow = !active ? ChevronsUpDown : sort.dir === 'asc' ? ArrowUp : ArrowDown;
              return (
                <th
                  key={c.key}
                  scope="col"
                  aria-sort={ariaSort}
                  className={(dense ? 'px-3 py-1.5' : 'px-3 py-2') + ' text-[11px] font-bold uppercase tracking-wider whitespace-nowrap ' + (c.align === 'right' ? 'text-right' : 'text-left')}
                  style={{ color: 'var(--slate)', width: c.width }}
                >
                  {sortable ? (
                    <button
                      type="button"
                      onClick={() => onSort(c.key)}
                      className={'inline-flex items-center gap-1 uppercase tracking-wider font-bold' + (c.align === 'right' ? ' flex-row-reverse' : '')}
                      style={{ color: active ? 'var(--ink)' : 'var(--slate)' }}
                    >
                      {c.header}
                      <Arrow size={12} strokeWidth={2.6} aria-hidden />
                    </button>
                  ) : (
                    c.header
                  )}
                </th>
              );
            })}
            {clickable ? (
              <th scope="col" className="w-8 px-1">
                <span className="sr-only">{TABLE.open}</span>
              </th>
            ) : null}
          </tr>
        </thead>
        <tbody>
          {ordered.length === 0 ? (
            <tr>
              <td colSpan={columns.length + (clickable ? 1 : 0)} className="p-6 text-center text-sm font-semibold" style={{ color: 'var(--body)' }}>
                {empty !== undefined ? empty : TABLE.empty}
              </td>
            </tr>
          ) : (
            ordered.map((row) => (
              <tr
                key={rowKey(row)}
                className={'border-t' + (clickable ? ' tp-row-click' : '')}
                style={{ borderColor: 'var(--line-soft)' }}
                tabIndex={clickable ? 0 : undefined}
                onClick={clickable ? (e) => onRowMouse(e, row) : undefined}
                onKeyDown={clickable ? (e) => onRowKey(e, row) : undefined}
              >
                {columns.map((c) => (
                  <td
                    key={c.key}
                    className={pad + ' align-top ' + (c.align === 'right' ? 'text-right tabular-nums font-bold' : 'text-left font-semibold')}
                    style={{ color: 'var(--ink)' }}
                  >
                    {c.render(row)}
                  </td>
                ))}
                {clickable ? (
                  <td className="w-8 px-1 align-middle" style={{ color: 'var(--body)' }}>
                    <ChevronRight size={16} aria-hidden />
                  </td>
                ) : null}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );

  if (mobileCard === undefined) return table;

  return (
    <>
      {table}
      <div className="sm:hidden">
        <p className="sr-only">{caption}</p>
        {ordered.length === 0 ? (
          <div className="rounded-xl border bg-white p-6 text-center text-sm font-semibold" style={{ borderColor: 'var(--line-soft)', color: 'var(--body)' }}>
            {empty !== undefined ? empty : TABLE.empty}
          </div>
        ) : (
          <ul className="space-y-2">
            {ordered.map((row) => (
              <li key={rowKey(row)}>
                <div
                  className={'flex items-stretch gap-2 rounded-xl border bg-white p-3' + (clickable ? ' tp-row-click' : '')}
                  style={{ borderColor: 'var(--line-soft)', color: 'var(--ink)' }}
                  role={clickable ? 'button' : undefined}
                  tabIndex={clickable ? 0 : undefined}
                  onClick={clickable ? (e) => onRowMouse(e, row) : undefined}
                  onKeyDown={clickable ? (e) => onRowKey(e, row) : undefined}
                >
                  <div className="min-w-0 flex-1">{mobileCard(row)}</div>
                  {clickable ? (
                    <span className="flex flex-none items-center" style={{ color: 'var(--body)' }}>
                      <ChevronRight size={18} aria-hidden />
                    </span>
                  ) : null}
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </>
  );
}

import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Ellipsis, Pencil, Trash2 } from 'lucide-react';
import { apiErrorMessage, type ServiceLog, type Spot } from '../../../api/truck';
import { DASH, fmtCount, fmtMoneyCents, fmtWindow } from '../../../utils/truck/format';
import {
  HISTORY_ID,
  SERVICE_LIMITS,
  defaultHistoryFilter,
  historyDate,
  historyRange,
  isUnsavedService,
  serviceName,
  serviceResult,
  serviceWhen,
  storedEstimate,
  type HistoryFilter,
  type HistoryRange,
} from '../../../utils/truck/logForm';
import { useServices } from '../data';
import { DataTable, Field, QueryError, RangeValue, SkeletonRows, nextSort, sortRows, type Column } from '../ui';

export interface HistoryTableProps {
  /** Spot, first date and last date; kept by the page so that a look at the other tab does not lose it. */
  filter: HistoryFilter;
  onFilter: (next: HistoryFilter) => void;
  /** Today in the truck's time zone. */
  today: string;
  /** Every spot of the truck, deleted ones included; undefined while they are on their way. */
  spots: readonly Spot[] | undefined;
  /** The spots could not be loaded: the rows would name no spot. */
  spotsFailed: boolean;
  onRetrySpots: () => void;
  /** Names of the spots by id. */
  names: Readonly<Record<string, string>>;
  onEdit: (service: ServiceLog) => void;
  onDelete: (service: ServiceLog) => void;
}

const LOAD_FAILED = 'Could not load your logged services.';
const SPOTS_FAILED = 'Could not load your spots.';
const CAPTION = 'Logged services';
const SAVING = 'Saving...';

type Sort = { key: string; dir: 'asc' | 'desc' };

/**
 * The history of the Log (docs/truck-planner/05_FRONTEND.md 4.7): every logged service of a date
 * range, newest first, against the estimate that was kept with it. That estimate is the stored
 * one, shown with its range and its label; nothing here is worked out again. A service can be
 * edited or deleted from its row.
 *
 * The seven columns and the row menu need about 1,040 px. Where the list has less room (a phone,
 * a tablet, a small laptop) the same facts are shown as cards, two to a row from 620 px: the
 * choice follows the width the list itself has, so the table is never scrolled sideways.
 */
export default function HistoryTable({ filter, onFilter, today, spots, spotsFailed, onRetrySpots, names, onEdit, onDelete }: HistoryTableProps) {
  const ids = 'tp-log-history-' + useId().split(':').join('') + '-';
  const [sort, setSort] = useState<Sort>({ key: 'date', dir: 'desc' });

  // A filter the server would refuse is not sent: its field says why and the list stays as it was.
  const checked = useMemo(() => historyRange(filter, today), [filter, today]);
  const lastGood = useRef<HistoryRange>({ args: {}, isDefault: true });
  if (checked.ok) lastGood.current = checked.range;
  const range = lastGood.current;
  const errors = checked.ok ? {} : checked.errors;

  const query = useServices(range.args);
  const rows = query.data;
  const start = useMemo(() => defaultHistoryFilter(today), [today]);
  const filtered = !range.isDefault;

  const menuOf = (service: ServiceLog): ReactNode => (
    <RowMenu
      label={'Actions for ' + historyDate(service.date, today) + ', ' + serviceName(service, names)}
      disabled={isUnsavedService(service)}
      onEdit={() => onEdit(service)}
      onDelete={() => onDelete(service)}
    />
  );

  const estimateOf = (service: ServiceLog): ReactNode => {
    const estimate = storedEstimate(service);
    if (estimate === null) return <span style={{ color: 'var(--body)' }}>{DASH}</span>;
    return <RangeValue estimate={estimate} unit="orders" layout="inline" size="sm" />;
  };

  const resultOf = (service: ServiceLog): string => (isUnsavedService(service) ? SAVING : serviceResult(service));

  const extraOf = (service: ServiceLog): ReactNode => {
    const notes = service.notes !== null && service.notes.trim() !== '' ? service.notes.trim() : null;
    if (service.sales === null && notes === null) return null;
    return (
      <span className="mt-0.5 block text-xs font-semibold" style={{ color: 'var(--body)' }}>
        {service.sales !== null ? <span className="tabular-nums">Sales {fmtMoneyCents(service.sales)}</span> : null}
        {service.sales !== null && notes !== null ? ' · ' : null}
        {notes !== null ? <span className="break-words">{notes}</span> : null}
      </span>
    );
  };

  const columns: Column<ServiceLog>[] = [
    {
      key: 'date',
      header: 'Date',
      sortValue: serviceWhen,
      render: (s) => <span className="whitespace-nowrap">{historyDate(s.date, today)}</span>,
    },
    {
      key: 'spot',
      header: 'Spot',
      sortValue: (s) => serviceName(s, names),
      render: (s) => (
        <span className="block min-w-[9rem]">
          <span className="break-words">{serviceName(s, names)}</span>
          {extraOf(s)}
        </span>
      ),
    },
    {
      key: 'hours',
      header: 'Hours',
      // Hours that end on the service date stay on one line; "(next day)" may wrap.
      render: (s) => <span className={'tabular-nums' + (s.close_minute <= 1440 ? ' whitespace-nowrap' : '')}>{fmtWindow(s.open_minute, s.close_minute)}</span>,
    },
    { key: 'orders', header: 'Orders', align: 'right', sortValue: (s) => s.actual, render: (s) => fmtCount(s.actual) },
    { key: 'estimate', header: 'Estimate', render: estimateOf },
    { key: 'result', header: 'Result', render: (s) => <span className="block min-w-[9.5rem]">{resultOf(s)}</span> },
    { key: 'sold-out', header: 'Sold out', render: (s) => (s.sold_out ? 'Yes' : <span style={{ color: 'var(--body)' }}>No</span>) },
    { key: 'actions', header: 'Actions', align: 'right', width: '4.5rem', render: menuOf },
  ];

  const ordered = useMemo(() => {
    if (rows === undefined) return [];
    const column = sort.key === 'spot' ? (s: ServiceLog) => serviceName(s, names) : sort.key === 'orders' ? (s: ServiceLog) => s.actual : serviceWhen;
    return sortRows(rows, column, sort.dir, (s) => s.id);
  }, [rows, sort, names]);

  const empty = filtered
    ? 'No services in this range.'
    : 'No services in the last ' + fmtCount(SERVICE_LIMITS.defaultDaysBack) + ' days. Pick an earlier first date to see older ones.';

  return (
    <section id={HISTORY_ID} tabIndex={-1} className="@container min-w-0 space-y-3 focus:outline-none" aria-labelledby={ids + 'title'}>
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h2 id={ids + 'title'} className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
          History
        </h2>
        {rows !== undefined && spots !== undefined ? (
          <span className="text-sm font-bold tabular-nums" style={{ color: 'var(--body)' }}>
            {fmtCount(rows.length)} {rows.length === 1 ? 'service' : 'services'}
            {filtered ? '' : ', last ' + fmtCount(SERVICE_LIMITS.defaultDaysBack) + ' days'}
          </span>
        ) : null}
      </div>

      <div className="grid gap-3 grid-cols-2 @[620px]:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] @[620px]:items-start">
        <Field id={ids + 'spot'} label="Spot" className="col-span-2 @[620px]:col-span-1">
          {(control) => (
            <select
              {...control}
              className="select h-11 md:h-9 text-sm font-semibold"
              style={{ paddingTop: 0, paddingBottom: 0 }}
              value={filter.spotId}
              onChange={(e) => onFilter({ ...filter, spotId: e.target.value })}
            >
              <option value="">All spots</option>
              {(spots ?? []).map((spot) => (
                <option key={spot.id} value={spot.id}>
                  {spot.name}
                  {spot.archived ? ' (deleted)' : ''}
                </option>
              ))}
            </select>
          )}
        </Field>
        <Field id={ids + 'from'} label="From" error={errors.from}>
          {(control) => (
            <input
              {...control}
              type="date"
              className={'input h-11 md:h-9 text-sm font-semibold tabular-nums' + (errors.from !== undefined ? ' tp-invalid' : '')}
              style={{ paddingTop: 0, paddingBottom: 0 }}
              value={filter.from}
              max={today}
              onChange={(e) => onFilter({ ...filter, from: e.target.value })}
            />
          )}
        </Field>
        <Field id={ids + 'to'} label="To" error={errors.to}>
          {(control) => (
            <input
              {...control}
              type="date"
              className={'input h-11 md:h-9 text-sm font-semibold tabular-nums' + (errors.to !== undefined ? ' tp-invalid' : '')}
              style={{ paddingTop: 0, paddingBottom: 0 }}
              value={filter.to}
              max={today}
              onChange={(e) => onFilter({ ...filter, to: e.target.value })}
            />
          )}
        </Field>
        {filter.spotId !== start.spotId || filter.from !== start.from || filter.to !== start.to ? (
          <button
            type="button"
            className="btn btn-secondary col-span-2 h-11 md:h-9 px-3 text-sm @[620px]:col-span-1 @[620px]:mt-[21px]"
            onClick={() => onFilter(start)}
          >
            Last {fmtCount(SERVICE_LIMITS.defaultDaysBack)} days
          </button>
        ) : null}
      </div>

      {spots === undefined && spotsFailed ? (
        <QueryError message={SPOTS_FAILED} onRetry={onRetrySpots} />
      ) : rows === undefined || spots === undefined ? (
        rows === undefined && query.isError ? (
          <QueryError
            message={apiErrorMessage(query.error, LOAD_FAILED) ?? LOAD_FAILED}
            onRetry={() => {
              void query.refetch();
            }}
          />
        ) : (
          <div aria-busy="true">
            <SkeletonRows rows={4} rowHeight={52} />
          </div>
        )
      ) : (
        <>
          <div className="hidden @[1040px]:block [&_.tp-scroll-x]:relative">
            <DataTable
              caption={CAPTION}
              columns={columns}
              rows={rows}
              rowKey={(s) => s.id}
              sort={sort}
              onSort={(key) => setSort((current) => nextSort(current, key))}
              empty={empty}
              dense
            />
          </div>
          <div className="@[1040px]:hidden">
            {ordered.length === 0 ? (
              <p className="rounded-xl border bg-white p-6 text-center text-sm font-semibold" style={{ borderColor: 'var(--line-soft)', color: 'var(--body)' }}>
                {empty}
              </p>
            ) : (
              <ul className="grid gap-2 @[620px]:grid-cols-2" aria-label={CAPTION}>
                {ordered.map((s) => (
                  <li key={s.id} className="min-w-0 rounded-xl border bg-white p-3" style={{ borderColor: 'var(--line-soft)' }}>
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <div className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                          {historyDate(s.date, today)} · {fmtWindow(s.open_minute, s.close_minute)}
                        </div>
                        <div className="break-words text-sm font-semibold" style={{ color: 'var(--ink)' }}>
                          {serviceName(s, names)}
                        </div>
                        {extraOf(s)}
                      </div>
                      <div className="-mr-1 -mt-1 flex-none">{menuOf(s)}</div>
                    </div>
                    <dl className="mt-2 grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-3 gap-y-1 text-sm">
                      <Fact label="Orders">
                        <span className="font-bold tabular-nums">{fmtCount(s.actual)}</span>
                      </Fact>
                      <Fact label="Estimate">{estimateOf(s)}</Fact>
                      <Fact label="Result">{resultOf(s)}</Fact>
                      <Fact label="Sold out">{s.sold_out ? 'Yes' : 'No'}</Fact>
                    </dl>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </>
      )}
    </section>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
        {label}
      </dt>
      <dd className="min-w-0 font-semibold" style={{ color: 'var(--ink)' }}>
        {children}
      </dd>
    </>
  );
}

const MENU_WIDTH = 176;
/** The room the menu needs under its button: its height with the 44 px entries of a phone. */
const MENU_HEIGHT = 100;
const EDGE = 8;

/** Where the menu is drawn: under its button from `top`, or above it up to `bottom` (from the window's lower edge). */
type MenuPlace = { left: number; top: number; bottom?: undefined } | { left: number; bottom: number; top?: undefined };

/**
 * The menu of one row: "Edit" and "Delete". It is drawn into the document body and placed from its
 * button, so the box the table scrolls in cannot cut it off; it follows the button when the page
 * scrolls. Up and Down move between the two entries, Escape closes and gives the focus back.
 */
function RowMenu({ label, disabled, onEdit, onDelete }: { label: string; disabled: boolean; onEdit: () => void; onDelete: () => void }) {
  const [open, setOpen] = useState(false);
  const [place, setPlace] = useState<MenuPlace | null>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const menuId = useId();

  useLayoutEffect(() => {
    if (!open) {
      setPlace(null);
      return undefined;
    }
    const update = () => {
      const el = trigger.current;
      if (el === null) return;
      const box = el.getBoundingClientRect();
      let left = box.right - MENU_WIDTH;
      if (left + MENU_WIDTH > window.innerWidth - EDGE) left = window.innerWidth - EDGE - MENU_WIDTH;
      if (left < EDGE) left = EDGE;
      const below = box.bottom + 4;
      // The height a fixed box is laid out in (the window without a scrollbar).
      const view = document.documentElement.clientHeight > 0 ? document.documentElement.clientHeight : window.innerHeight;
      // Without room under the button the menu opens above it, held by its lower edge: it then sits
      // 4 px over the button whatever its own height is (its entries are shorter on a desktop).
      const above = below + MENU_HEIGHT > view && box.top > MENU_HEIGHT + 4;
      setPlace(above ? { left, bottom: view - box.top + 4 } : { left, top: below });
    };
    update();
    window.addEventListener('scroll', update, true);
    window.addEventListener('resize', update);
    return () => {
      window.removeEventListener('scroll', update, true);
      window.removeEventListener('resize', update);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return undefined;
    const onPointer = (e: PointerEvent) => {
      if (!(e.target instanceof Node)) return;
      if (trigger.current !== null && trigger.current.contains(e.target)) return;
      if (menu.current !== null && menu.current.contains(e.target)) return;
      setOpen(false);
    };
    document.addEventListener('pointerdown', onPointer);
    return () => document.removeEventListener('pointerdown', onPointer);
  }, [open]);

  // The first entry takes the focus once the menu is on the page.
  useEffect(() => {
    if (!open || place === null || menu.current === null) return;
    if (menu.current.contains(document.activeElement)) return;
    const firstItem = menu.current.querySelector<HTMLButtonElement>('[role="menuitem"]');
    if (firstItem !== null) firstItem.focus();
  }, [open, place]);

  const close = (restore: boolean) => {
    setOpen(false);
    if (restore && trigger.current !== null) trigger.current.focus();
  };

  const onMenuKey = (e: KeyboardEvent<HTMLDivElement>) => {
    const items = menu.current === null ? [] : Array.from(menu.current.querySelectorAll<HTMLButtonElement>('[role="menuitem"]'));
    const at = items.findIndex((item) => item === document.activeElement);
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      close(true);
    } else if (e.key === 'Tab') {
      close(true);
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (items.length === 0) return;
      const step = e.key === 'ArrowDown' ? 1 : -1;
      const next = at === -1 ? 0 : (at + step + items.length) % items.length;
      items[next].focus();
    } else if (e.key === 'Home' || e.key === 'End') {
      e.preventDefault();
      if (items.length > 0) items[e.key === 'Home' ? 0 : items.length - 1].focus();
    }
  };

  const pick = (action: () => void) => {
    close(true);
    action();
  };

  const itemClass = 'flex min-h-[44px] md:min-h-[36px] w-full items-center gap-2 rounded-md px-2.5 text-left text-sm font-bold hover:bg-slate-50';

  return (
    <>
      <button
        ref={trigger}
        type="button"
        className="btn btn-secondary h-11 w-11 md:h-9 md:w-9 disabled:opacity-40 disabled:cursor-not-allowed"
        style={{ padding: 0 }}
        aria-label={label}
        title="Edit or delete"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        disabled={disabled}
        onClick={() => setOpen(!open)}
      >
        <Ellipsis size={18} aria-hidden />
      </button>
      {open && place !== null
        ? createPortal(
            <div
              ref={menu}
              id={menuId}
              role="menu"
              aria-label={label}
              className="rounded-lg border bg-white p-1 shadow-float"
              style={{ position: 'fixed', left: place.left, top: place.top, bottom: place.bottom, width: MENU_WIDTH, zIndex: 40, borderColor: 'var(--line-soft)' }}
              onKeyDown={onMenuKey}
            >
              <button type="button" role="menuitem" className={itemClass} style={{ color: 'var(--ink)' }} onClick={() => pick(onEdit)}>
                <Pencil size={15} aria-hidden /> Edit
              </button>
              <button type="button" role="menuitem" className={itemClass} style={{ color: 'var(--money-negative)' }} onClick={() => pick(onDelete)}>
                <Trash2 size={15} aria-hidden /> Delete
              </button>
            </div>,
            document.body,
          )
        : null}
    </>
  );
}

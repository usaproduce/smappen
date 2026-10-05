import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import type { WindowResult } from '../../../../utils/truck/model';
import { fmtWeekday, fmtWindow } from '../../../../utils/truck/format';
import type { WindowSlot } from '../../../../utils/truck/hourControl';
import { nextDateWithDow } from '../../../../utils/truck/time';
import { RangeValue, Tabs } from '../../ui';

export interface BestWindowsProps {
  /** The best windows of the typical week that do not overlap, best first (at most three). */
  slots: readonly WindowSlot[];
  /** A window on a day of the typical week, from `useSpotEstimate`. */
  windowOn: (dow: number, open: number, close: number) => WindowResult | null;
  /** Window length in hours. */
  hours: 2 | 3 | 4;
  onHours: (hours: 2 | 3 | 4) => void;
  /** Index of the window the money section is for. */
  selected: number;
  onSelect: (index: number) => void;
  /** A saved spot gets "Plan this" on every row; a clicked point has nothing to plan yet. */
  spotId: string | null;
  /** Today in the truck's time zone, for the date "Plan this" opens. */
  today: string;
  dim: boolean;
}

const LENGTHS: { id: string; label: string; hours: 2 | 3 | 4 }[] = [
  { id: '2', label: '2 hours', hours: 2 },
  { id: '3', label: '3 hours', hours: 3 },
  { id: '4', label: '4 hours', hours: 4 },
];

/** "Tuesday 11 AM to 2 PM". */
export function windowLabel(slot: WindowSlot): string {
  return fmtWeekday(slot.dow, 'long') + ' ' + fmtWindow(slot.open, slot.close);
}

/**
 * "Best windows" of the spot card (docs/truck-planner/05_FRONTEND.md 4.3, section B): the top
 * windows of a typical week for the chosen length, each with its orders as a range. Choosing a row
 * moves the hour bar to its first hour and makes it the window the money section adds up.
 */
export default function BestWindows({ slots, windowOn, hours, onHours, selected, onSelect, spotId, today, dim }: BestWindowsProps) {
  const rows = useMemo(
    () => slots.map((slot) => ({ slot, result: windowOn(slot.dow, slot.open, slot.close) })),
    [slots, windowOn],
  );

  return (
    <div className="space-y-2.5">
      <Tabs
        tabs={LENGTHS}
        value={String(hours)}
        variant="segmented"
        ariaLabel="Window length"
        onChange={(id) => {
          for (const length of LENGTHS) if (length.id === id) onHours(length.hours);
        }}
      />
      {rows.length === 0 ? (
        <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
          No hour of the week reaches one order here.
        </p>
      ) : (
        <ol className="space-y-1.5">
          {rows.map(({ slot, result }, index) => {
            const chosen = index === selected;
            const label = windowLabel(slot);
            return (
              <li
                key={slot.how}
                className="rounded-lg border px-2.5 py-1.5"
                style={{
                  borderColor: chosen ? 'var(--brand)' : 'var(--line-soft)',
                  background: chosen ? 'var(--nav-active-bg)' : undefined,
                }}
              >
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    aria-pressed={chosen}
                    title="Use this window for the money figures"
                    onClick={() => onSelect(index)}
                    className="flex min-h-11 md:min-h-8 min-w-0 flex-1 items-center gap-2 rounded-md text-left"
                  >
                    <span
                      aria-hidden="true"
                      className="inline-flex h-5 w-5 flex-none items-center justify-center rounded text-[11px] font-extrabold tabular-nums"
                      style={{ background: 'var(--ink)', color: 'var(--bg)' }}
                    >
                      {index + 1}
                    </span>
                    <span className="sr-only">Window {index + 1}:</span>
                    <span className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
                      {label}
                    </span>
                  </button>
                  {spotId !== null ? (
                    <Link
                      to={
                        '/truck/plan/' + nextDateWithDow(today, slot.dow) +
                        '?add=' + encodeURIComponent(spotId) + '&open=' + String(slot.open) + '&close=' + String(slot.close)
                      }
                      aria-label={'Plan this: ' + label}
                      className="inline-flex min-h-11 md:min-h-8 flex-none items-center text-xs font-bold underline underline-offset-2"
                      style={{ color: 'var(--ink)' }}
                    >
                      Plan this
                    </Link>
                  ) : null}
                </div>
                {result !== null ? (
                  <div className="pb-0.5 pl-7">
                    <RangeValue estimate={result.orders} unit="orders" size="sm" layout="inline" dim={dim} />
                  </div>
                ) : null}
              </li>
            );
          })}
        </ol>
      )}
    </div>
  );
}

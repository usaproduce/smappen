import { useState } from 'react';
import { Info } from 'lucide-react';
import { fmtDay } from '../../../utils/truck/format';
import type { Estimate } from '../../../utils/truck/model';
import { WEEK_TEXT, plannedCountText, weekMissingLine, type WeekSum } from '../../../utils/truck/nextAction';
import { STANDING } from '../../../utils/truck/wording';
import { Modal, RangeValue } from '../ui';

/** One planned day inside the week's total. */
export interface WeekPart {
  date: string;
  /** The day's take-home, as the model evaluated the day. */
  takeHome: Estimate;
  /** Opens that day's own "Why this number". */
  onWhy: () => void;
}

export interface WeekTotalsProps {
  /** The week's total: the model's sum of the planned days that have a figure. */
  sum: WeekSum;
  /** A planned day is still being worked out: the total is not final yet. */
  pending: boolean;
  /** The days in the total, in date order. */
  parts: readonly WeekPart[];
}

/**
 * The totals strip of the week (docs/truck-planner/05_FRONTEND.md 4.6): what the planned days are
 * expected to clear together, and how many days are planned. The total is the model's `estSum` of
 * the day results, so each day's low and high are added up and the range is wide on purpose; the
 * strip says so. "Why this number" lists the days the total is made of, each with its own
 * breakdown one step away.
 */
export default function WeekTotals({ sum, pending, parts }: WeekTotalsProps) {
  const [open, setOpen] = useState(false);
  const missing = pending ? null : weekMissingLine(sum);
  const total = sum.total;

  return (
    <section
      aria-label="The week in one figure"
      className="bg-white rounded-xl border p-4 sm:p-5"
      style={{ borderColor: 'var(--line-soft)' }}
      data-tp-week-total={total === null ? '' : 'shown'}
    >
      <div className="flex flex-wrap items-start justify-between gap-x-8 gap-y-3">
        <div className="min-w-0">
          {total !== null ? (
            <RangeValue estimate={total} unit="money" size="lg" label={WEEK_TEXT.totalLabel} onWhy={() => setOpen(true)} dim={pending} />
          ) : pending ? (
            <div aria-busy="true">
              <div aria-hidden className="skeleton" style={{ height: 76, width: 220, borderRadius: 8 }} />
            </div>
          ) : (
            <>
              <div className="text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
                {WEEK_TEXT.totalLabel}
              </div>
              <p className="mt-0.5 text-sm font-semibold" style={{ color: 'var(--body)' }}>
                {WEEK_TEXT.nothing}
              </p>
            </>
          )}
        </div>
        <div className="min-w-0 max-w-md">
          <p className="text-base font-extrabold tabular-nums" style={{ color: 'var(--ink)' }} data-tp-week-planned={sum.planned}>
            {plannedCountText(sum.planned)}
          </p>
          {total !== null ? (
            <p className="mt-0.5 text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
              {WEEK_TEXT.helper}
            </p>
          ) : null}
          {missing !== null ? (
            <p className="mt-1 flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
              <Info size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
              <span>{missing}</span>
            </p>
          ) : null}
        </div>
      </div>

      <Modal open={open && total !== null} onClose={() => setOpen(false)} title={WEEK_TEXT.whyTitle} size="md">
        <p>{WEEK_TEXT.whyLead}</p>
        <ul className="tp-stat-list mt-2">
          {parts.map((part) => (
            <li key={part.date} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2">
              <span className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                {fmtDay(part.date, 'medium')}
              </span>
              <RangeValue estimate={part.takeHome} unit="money" layout="inline" size="sm" onWhy={part.onWhy} />
            </li>
          ))}
          {total !== null ? (
            <li className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2">
              <span className="text-sm font-extrabold" style={{ color: 'var(--ink)' }}>
                The week
              </span>
              <RangeValue estimate={total} unit="money" layout="inline" size="sm" />
            </li>
          ) : null}
        </ul>
        <p className="mt-2">{WEEK_TEXT.helper}</p>
        <p className="mt-2">{STANDING.underBreakdown}</p>
      </Modal>
    </section>
  );
}

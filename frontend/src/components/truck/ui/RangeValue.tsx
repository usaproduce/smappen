import { CircleHelp } from 'lucide-react';
import type { Estimate } from '../../../utils/truck/model';
import { estimateParts } from '../../../utils/truck/format';
import { WHY } from '../../../utils/truck/wording';
import ConfidenceChip from './ConfidenceChip';
import { estimateAriaLabel } from './kit';

export interface RangeValueProps {
  /** The only way to show an estimate: value, low, high and confidence travel together. */
  estimate: Estimate;
  unit: 'orders' | 'money' | 'money_per_hour';
  /** The value at 14 / 18 / 24 / 30 px; default "md". */
  size?: 'sm' | 'md' | 'lg' | 'xl';
  /** stack: value, range line, chip. inline: "60 orders (33 to 93)" and the chip on one line. */
  layout?: 'stack' | 'inline';
  /** Uppercase caption: above the value in the stack, before it on the same line inline. */
  label?: string;
  /** Adds the "Why this number" button. */
  onWhy?: () => void;
  /** One line under the range, for example "Limited by how fast the truck can serve." */
  note?: string;
  /** The inputs are refreshing: 55 % opacity and aria-busy. */
  dim?: boolean;
}

const VALUE_SIZE: Record<NonNullable<RangeValueProps['size']>, string> = {
  sm: 'text-sm',
  md: 'text-lg',
  lg: 'text-2xl',
  xl: 'text-3xl',
};

/**
 * An estimate on screen (docs/truck-planner/05_FRONTEND.md 3.2, rule R4). It takes an Estimate and
 * nothing less, so a bare number cannot be shown as an estimate by accident: the value, the range
 * written with "to" and the confidence chip always come together, and no prop hides the chip.
 *
 * Value, low and high are each rounded by the formatter of the unit. A fixed amount shows the value
 * and the chip "Fixed" without a range. A negative money value keeps its minus sign and takes the
 * colour of a loss; nothing is ever coloured green.
 */
export default function RangeValue({ estimate, unit, size = 'md', layout = 'stack', label, onWhy, note, dim = false }: RangeValueProps) {
  const p = estimateParts(estimate, unit);
  const valueColor = p.negative ? 'var(--money-negative)' : 'var(--ink)';
  const chip = <ConfidenceChip confidence={estimate.confidence} size={size === 'sm' ? 'sm' : 'md'} hint />;
  const why =
    onWhy === undefined ? null : (
      <button
        type="button"
        onClick={(e) => {
          e.stopPropagation();
          onWhy();
        }}
        className="inline-flex items-center gap-1 rounded-lg px-1.5 text-xs font-bold underline underline-offset-2 min-h-[44px] md:min-h-[24px]"
        style={{ color: 'var(--body)' }}
      >
        <CircleHelp size={14} aria-hidden />
        {WHY.button}
      </button>
    );
  const group = {
    role: 'group' as const,
    'aria-label': estimateAriaLabel(estimate, unit, label),
    'aria-busy': dim ? (true as const) : undefined,
  };

  if (layout === 'inline') {
    return (
      <span {...group} className={'inline-flex flex-wrap items-baseline gap-x-1.5 gap-y-1 align-baseline' + (dim ? ' tp-dim' : '')}>
        {label !== undefined && label !== '' ? (
          <span aria-hidden className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
            {label}
          </span>
        ) : null}
        <span aria-hidden className="inline-flex items-baseline gap-x-1 whitespace-nowrap">
          <span className={VALUE_SIZE[size] + ' font-extrabold tabular-nums leading-tight'} style={{ color: valueColor }}>
            {p.value}
          </span>
          {p.unitWord !== '' ? (
            <span className="text-sm font-bold" style={{ color: 'var(--body)' }}>
              {p.unitWord}
            </span>
          ) : null}
          {p.hasRange ? (
            <span className="text-[13px] font-semibold tabular-nums" style={{ color: 'var(--body)' }}>
              ({p.low} to {p.high})
            </span>
          ) : null}
        </span>
        <span className="inline-flex items-center gap-1 self-center">
          {chip}
          {why}
        </span>
        {note !== undefined && note !== '' ? (
          <span className="basis-full text-xs font-semibold" style={{ color: 'var(--body)' }}>
            {note}
          </span>
        ) : null}
      </span>
    );
  }

  return (
    <div {...group} className={'min-w-0' + (dim ? ' tp-dim' : '')}>
      {label !== undefined && label !== '' ? (
        <div aria-hidden className={(size === 'sm' ? 'text-[10px]' : 'text-[11px]') + ' font-bold uppercase tracking-wider'} style={{ color: 'var(--slate)' }}>
          {label}
        </div>
      ) : null}
      <div aria-hidden className="flex flex-wrap items-baseline gap-x-1.5">
        <span className={VALUE_SIZE[size] + ' font-extrabold tabular-nums leading-tight'} style={{ color: valueColor }}>
          {p.value}
        </span>
        {p.unitWord !== '' ? (
          <span className="text-sm font-bold" style={{ color: 'var(--body)' }}>
            {p.unitWord}
          </span>
        ) : null}
      </div>
      {p.hasRange ? (
        <div aria-hidden className="text-[13px] font-semibold tabular-nums" style={{ color: 'var(--body)' }}>
          {p.low} to {p.high}
        </div>
      ) : null}
      {note !== undefined && note !== '' ? (
        <div className="text-xs font-semibold mt-0.5" style={{ color: 'var(--body)' }}>
          {note}
        </div>
      ) : null}
      <div className="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1">
        {chip}
        {why}
      </div>
    </div>
  );
}

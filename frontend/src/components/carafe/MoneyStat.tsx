import { type ReactNode } from 'react';
import AnimatedNumber from '../common/AnimatedNumber';
import FreshnessChip, { type FreshnessState } from './FreshnessChip';

/**
 * Canonical Carafe "headline dollar number" tile. Every paid feature
 * terminates in one of these — never a chart, never a raw toLocaleString
 * string. Wraps AnimatedNumber so the figure eases in on mount, and
 * supports an optional FreshnessChip when a timestamp is passed.
 *
 * Shape (top to bottom): label row + optional chip, dollar figure, footer.
 * Tones tint only the figure so label/footer stay readable.
 */

type Props = {
  label: string;
  value: number | null | undefined;
  precision?: 'dollars' | 'cents';
  timestamp?: string | number | Date;
  freshnessLabel?: string;
  freshnessState?: FreshnessState;
  freshnessText?: string;
  tone?: 'positive' | 'negative' | 'neutral';
  size?: 'sm' | 'md' | 'lg' | 'xl';
  footer?: ReactNode;
  durationMs?: number;
  className?: string;
  icon?: ReactNode;
};

const SIZE_TABLE = {
  sm: { figure: 'text-xl',   labelGap: 4, footerGap: 6 },
  md: { figure: 'text-2xl',  labelGap: 6, footerGap: 6 },
  lg: { figure: 'text-3xl',  labelGap: 6, footerGap: 8 },
  xl: { figure: 'text-4xl',  labelGap: 8, footerGap: 10 },
} as const;

export default function MoneyStat({
  label,
  value,
  precision = 'dollars',
  timestamp,
  freshnessLabel,
  freshnessState,
  freshnessText,
  tone = 'neutral',
  size = 'lg',
  footer,
  // Roll-up duration — tuned to the Carafe ≤250ms motion budget. Long
  // enough that the figure visibly counts up rather than snapping; short
  // enough that an operator polling every 60s doesn't see a sluggish
  // counter. AnimatedNumber respects prefers-reduced-motion internally.
  durationMs = 240,
  className,
  icon,
}: Props) {
  const figureColor =
    tone === 'positive' ? 'var(--money-positive)'
    : tone === 'negative' ? 'var(--money-negative)'
    : 'var(--money-neutral)';

  const sz = SIZE_TABLE[size];

  return (
    <div
      className={'flex flex-col ' + (className ?? '')}
      style={{ gap: sz.labelGap }}
      data-tone={tone}
    >
      {(label || timestamp != null || freshnessState) && (
        <div className="flex items-center justify-between gap-2 min-w-0">
          <div
            className="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider min-w-0"
            style={{ color: 'var(--slate)' }}
          >
            {icon}
            {label && <span className="truncate">{label}</span>}
          </div>
          {timestamp != null ? (
            <FreshnessChip timestamp={timestamp} label={freshnessLabel} />
          ) : freshnessState ? (
            <FreshnessChip state={freshnessState} text={freshnessText ?? freshnessState} />
          ) : null}
        </div>
      )}

      <div
        className={'font-extrabold leading-none tabular-nums ' + sz.figure}
        style={{ color: figureColor }}
      >
        {value == null ? (
          <span style={{ color: 'var(--muted)' }}>—</span>
        ) : (
          <AnimatedNumber
            value={value}
            durationMs={durationMs}
            format={(n) => formatMoney(n, precision)}
          />
        )}
      </div>

      {footer ? (
        <div style={{ marginTop: sz.footerGap, color: 'var(--body)' }} className="text-xs">
          {footer}
        </div>
      ) : null}
    </div>
  );
}

function formatMoney(n: number, precision: 'dollars' | 'cents'): string {
  if (precision === 'cents') {
    return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  return '$' + Math.round(n).toLocaleString();
}

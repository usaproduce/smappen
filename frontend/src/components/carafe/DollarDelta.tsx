import { ArrowUpRight, ArrowDownRight, Minus } from 'lucide-react';

/**
 * Signed dollar change. Color is taken from the value's sign (callers
 * can't drift color from number). `goodWhen` flips polarity for metrics
 * where "down" is good (cost, food-cost %, etc.).
 */

type Props = {
  value: number;
  goodWhen?: 'up' | 'down';
  precision?: 'dollars' | 'cents';
  comparison?: string;
  size?: 'sm' | 'md' | 'lg';
  hideIcon?: boolean;
  className?: string;
};

export default function DollarDelta({
  value,
  goodWhen = 'up',
  precision = 'dollars',
  comparison,
  size = 'md',
  hideIcon = false,
  className,
}: Props) {
  const sign = value === 0 ? 0 : value > 0 ? 1 : -1;
  const goodSign = goodWhen === 'up' ? 1 : -1;
  const tone: 'positive' | 'negative' | 'neutral' =
    sign === 0 ? 'neutral'
    : sign === goodSign ? 'positive'
    : 'negative';

  const color =
    tone === 'positive' ? 'var(--money-positive)'
    : tone === 'negative' ? 'var(--money-negative)'
    : 'var(--money-neutral)';

  const sizing = {
    sm: { font: 12, icon: 12, gap: 3 },
    md: { font: 14, icon: 14, gap: 4 },
    lg: { font: 18, icon: 18, gap: 5 },
  }[size];

  const Icon = sign > 0 ? ArrowUpRight : sign < 0 ? ArrowDownRight : Minus;
  const formatted = formatMoney(Math.abs(value), precision);
  const signChar = sign > 0 ? '+' : sign < 0 ? '−' : '';
  const aria = value === 0
    ? `no change${comparison ? ` ${comparison}` : ''}`
    : `${signChar === '+' ? 'up' : 'down'} ${formatted}${comparison ? ` ${comparison}` : ''}`;

  return (
    <span
      className={'inline-flex items-baseline font-semibold ' + (className ?? '')}
      style={{ color, gap: sizing.gap, fontSize: sizing.font }}
      data-tone={tone}
      aria-label={aria}
    >
      {!hideIcon && (
        <Icon size={sizing.icon} strokeWidth={2.5} style={{ alignSelf: 'center', flexShrink: 0 }} />
      )}
      <span>
        {signChar}
        {formatted}
      </span>
      {comparison && (
        <span
          className="font-medium"
          style={{ color: 'var(--slate)', fontSize: Math.max(11, sizing.font - 2) }}
        >
          {comparison}
        </span>
      )}
    </span>
  );
}

function formatMoney(abs: number, precision: 'dollars' | 'cents'): string {
  if (precision === 'cents') {
    return '$' + abs.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  return '$' + Math.round(abs).toLocaleString();
}

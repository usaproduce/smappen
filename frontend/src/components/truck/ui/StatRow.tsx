import type { ReactNode } from 'react';

export interface StatRowProps {
  label: string;
  value: ReactNode;
  /** A second line under the value, 12 px. */
  sub?: ReactNode;
  /** Weight 800 instead of 700: the row the others lead to. */
  strong?: boolean;
  /** The value is a loss or a cost that went wrong: it takes the colour of a loss. The caller writes the minus sign or the word. */
  negative?: boolean;
}

/**
 * One fact with its figure (docs/truck-planner/05_FRONTEND.md 3.11): cost lines, drive lines,
 * settings summaries. Rows sit in a StatList (a <dl>). Never for an estimate: that is RangeValue.
 */
export default function StatRow({ label, value, sub, strong = false, negative = false }: StatRowProps) {
  return (
    <div className="flex items-baseline justify-between gap-4 py-2">
      <dt className="min-w-0 text-sm font-semibold" style={{ color: 'var(--body)' }}>
        {label}
      </dt>
      <dd className="min-w-0 text-right">
        <span className={'text-sm tabular-nums ' + (strong ? 'font-extrabold' : 'font-bold')} style={{ color: negative ? 'var(--money-negative)' : 'var(--ink)' }}>
          {value}
        </span>
        {sub !== undefined && sub !== null && sub !== '' ? (
          <span className="block text-xs font-semibold" style={{ color: 'var(--body)' }}>
            {sub}
          </span>
        ) : null}
      </dd>
    </div>
  );
}

/** The list StatRows sit in; rows are separated by a hairline. */
export function StatList({ children, className }: { children: ReactNode; className?: string }) {
  return <dl className={'tp-stat-list' + (className !== undefined ? ' ' + className : '')}>{children}</dl>;
}

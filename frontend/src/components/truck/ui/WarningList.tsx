import { Info, TriangleAlert } from 'lucide-react';
import type { DayContext, DayResult } from '../../../utils/truck/model';
import { warningRows } from '../../../utils/truck/warnings';

export interface WarningListProps {
  result: DayResult;
  /** The name of stop i of the plan. */
  stopNames: string[];
  /** The context of the day: the holiday warning takes its name from it. */
  context: DayContext;
}

const ICON_COLOR = {
  error: 'var(--money-negative)',
  warn: 'var(--fresh-aging)',
  info: 'var(--slate)',
} as const;

/**
 * The model's warnings for a day, in the order the model gives them, with the sentences of 6.5
 * (docs/truck-planner/05_FRONTEND.md 3.13). Each line starts with a visible word for its level
 * ("Problem:", "Check:", "Note:") next to its icon, so the level never rests on colour. Renders
 * nothing when the day has no warnings.
 */
export default function WarningList({ result, stopNames, context }: WarningListProps) {
  const rows = warningRows(result, stopNames, context);
  if (rows.length === 0) return null;
  return (
    <ul className="space-y-2">
      {rows.map((row) => {
        const Icon = row.level === 'info' ? Info : TriangleAlert;
        return (
          <li key={String(row.index) + row.code} className="flex items-start gap-2 text-sm font-semibold" style={{ color: 'var(--ink)' }}>
            <Icon size={16} aria-hidden className="mt-0.5 flex-none" style={{ color: ICON_COLOR[row.level] }} />
            <span>
              <span className="font-extrabold">{row.prefix}</span> {row.text}
            </span>
          </li>
        );
      })}
    </ul>
  );
}

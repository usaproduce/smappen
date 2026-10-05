import { PermissionNotice } from '../ui';

export interface DaySheetProps {
  date: string;
}

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.10 and 9.2). The day sheet package replaces the body:
 * the date's saved plan as a printable sheet (times, one block per stop, the day in numbers, things
 * to check), evaluated in the browser like the planner.
 *
 * The standing notice stays in this component whatever replaces the rest (a source guard checks it).
 */
export default function DaySheet(_props: DaySheetProps) {
  return (
    <div className="space-y-3">
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        Day sheet not built yet.
      </p>
      <PermissionNotice variant="line" />
    </div>
  );
}

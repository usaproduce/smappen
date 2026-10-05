import { NotebookPen } from 'lucide-react';

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.7 and 9.2). The Log and Accuracy package replaces the
 * body: "Log a service", the stops not logged yet, the history, and how the estimates are doing.
 */
export default function LogPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <NotebookPen size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Log
      </h1>
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        Not built yet.
      </p>
    </div>
  );
}

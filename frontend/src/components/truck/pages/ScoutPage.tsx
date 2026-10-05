import { Compass } from 'lucide-react';
import { PermissionNotice } from '../ui';

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.8 and 9.2). The Scout package replaces the body: places
 * within reach of the base that could host a truck, in the server's rank order, each with a rough
 * range, its lead status and "Save as spot".
 *
 * The standing notice stays on this page whatever replaces the rest (a source guard checks it).
 */
export default function ScoutPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <Compass size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Scout
      </h1>
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        Not built yet.
      </p>
      <PermissionNotice variant="line" />
    </div>
  );
}

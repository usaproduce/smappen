import { Store } from 'lucide-react';
import { PermissionNotice } from '../ui';

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.4 and 9.2). The Spots and Settings package replaces the
 * body: two to four spots side by side (`?ids=a,b,c`), on each spot's best window or on one window
 * for all (`?win=`).
 *
 * The standing notice stays on this page whatever replaces the rest (a source guard checks it).
 */
export default function SpotComparePage() {
  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <Store size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Compare spots
      </h1>
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        Not built yet.
      </p>
      <PermissionNotice variant="line" />
    </div>
  );
}

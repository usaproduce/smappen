import { Store } from 'lucide-react';
import { PermissionNotice } from '../ui';

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.4 and 9.2). The Spots and Settings package replaces the
 * body: one spot (`:spotId`) with its terms, the drive from the base, its logged results and the
 * spot analysis. An unknown id shows "This spot no longer exists." with a link back to the list.
 *
 * The standing notice stays on this page whatever replaces the rest (a source guard checks it).
 */
export default function SpotDetailPage() {
  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <Store size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Spot
      </h1>
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        Not built yet.
      </p>
      <PermissionNotice variant="line" />
    </div>
  );
}

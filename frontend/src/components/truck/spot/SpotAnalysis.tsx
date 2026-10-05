import type { HostHint, Spot } from '../../../api/truck';
import type { SpotTerms } from '../../../utils/truck/model';
import { PermissionNotice } from '../ui';

export interface SpotAnalysisProps {
  /** A clicked point, or a saved spot. */
  subject: { kind: 'point'; lat: number; lng: number } | { kind: 'spot'; spot: Spot };
  /** Estimate with these terms instead of the subject's own (a form previewing its draft). */
  termsOverride?: SpotTerms;
  /** `card`: inside the map's sheet. `page`: embedded in the spot detail page. */
  layout: 'card' | 'page';
  /** "Save as spot" was pressed; the places that could be the host come along for the form. */
  onSaveAsSpot?: (hosts: HostHint[]) => void;
}

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.3 and 9.2). The map page package replaces the body: the
 * sections "This hour", "Best windows", "Week at a glance", "Who is here", "Competition" and
 * "Money", all computed by `useSpotEstimate` from exact vectors.
 *
 * The standing notice stays in this component whatever replaces the rest (a source guard checks it).
 */
export default function SpotAnalysis(_props: SpotAnalysisProps) {
  return (
    <div className="space-y-3">
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        Spot analysis not built yet.
      </p>
      <PermissionNotice variant="line" />
    </div>
  );
}

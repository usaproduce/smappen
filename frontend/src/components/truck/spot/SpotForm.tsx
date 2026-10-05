import type { HostHint, Spot, SpotBody } from '../../../api/truck';
import { SourceLine } from '../ui';

export interface SpotFormProps {
  mode: 'create' | 'edit';
  /** What the form starts from: the point of a map click, or the saved spot's fields. */
  initial: Partial<SpotBody>;
  /** The spot being edited (`mode: 'edit'`). */
  spotId?: string;
  /** Places within reach of the point that could be the host, nearest first. */
  nearbyHosts?: HostHint[];
  /** `modal`: inside a Modal for a new spot. `inline`: on the spot detail page. */
  presentation: 'modal' | 'inline';
  onSaved: (spot: Spot) => void;
  onCancel: () => void;
}

/**
 * STUB (docs/truck-planner/05_FRONTEND.md 4.4 and 9.2). The Spots and Settings package replaces the
 * body: place, host, visibility, fee and days and hours, with "Save spot" and "Cancel".
 *
 * The OpenStreetMap credit stays in this component whatever replaces the rest: the form lists
 * places from that data (a source guard checks it).
 */
export default function SpotForm(_props: SpotFormProps) {
  return (
    <div className="space-y-3">
      <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
        Spot form not built yet.
      </p>
      <SourceLine kinds={['osm']} />
    </div>
  );
}

import { Link } from 'react-router-dom';
import type { HostHint, Spot } from '../../../api/truck';
import type { LatLng } from '../../../utils/truck/model';
import { pointParam } from '../../../utils/truck/hourControl';
import ErrorBoundary from '../../ErrorBoundary';
import { QueryError, Sheet, SkeletonCard, SkeletonRows } from '../ui';
import SpotAnalysis from './SpotAnalysis';

/** What the card is about. */
export type SpotCardSubject =
  | { kind: 'point'; lat: number; lng: number }
  | { kind: 'spot'; spot: Spot }
  /** A saved spot was asked for and the spot list is still on its way. */
  | { kind: 'loading' }
  /** The spot list could not be loaded. */
  | { kind: 'failed'; onRetry: () => void }
  /** The spot asked for is not in the list (it was deleted, or the link is wrong). */
  | { kind: 'missing' };

export interface SpotCardProps {
  /** Null closes the card. */
  subject: SpotCardSubject | null;
  /** `right`: a 420 px panel beside the map. `bottom`: a sheet under it (a phone, a tablet held upright). */
  side: 'right' | 'bottom';
  onClose: () => void;
  /** "Save as spot" was pressed for a point; the places that could be its host come along. */
  onSaveAsSpot: (point: LatLng, hosts: HostHint[]) => void;
}

function titleOf(subject: SpotCardSubject | null): string {
  if (subject === null) return '';
  if (subject.kind === 'spot') return subject.spot.name;
  if (subject.kind === 'point') return 'This point';
  return 'Saved spot';
}

/**
 * The spot card of the map (docs/truck-planner/05_FRONTEND.md 4.2 and 4.3): `SpotAnalysis` in a
 * sheet that is not modal, so the map and the hour bar beside it stay in use. The card opens at
 * once, with a skeleton until the estimate is there. Escape (with focus in the card) and the close
 * button close it.
 */
export default function SpotCard({ subject, side, onClose, onSaveAsSpot }: SpotCardProps) {
  // One place, one state: another point or spot starts from scratch (chosen window, open lists, a crash).
  let key = 'none';
  let body = null;
  if (subject !== null) {
    key = subject.kind;
    if (subject.kind === 'point') {
      const point = { lat: subject.lat, lng: subject.lng };
      key = 'pt:' + pointParam(point);
      body = <SpotAnalysis key={key} subject={subject} layout="card" onSaveAsSpot={(hosts) => onSaveAsSpot(point, hosts)} />;
    } else if (subject.kind === 'spot') {
      key = 'spot:' + subject.spot.id;
      body = <SpotAnalysis key={key} subject={subject} layout="card" />;
    } else if (subject.kind === 'loading') {
      body = (
        <div aria-busy="true" className="space-y-4">
          <SkeletonCard height={92} />
          <SkeletonRows rows={3} rowHeight={54} />
        </div>
      );
    } else if (subject.kind === 'failed') {
      body = <QueryError message="Could not load your spots." onRetry={subject.onRetry} />;
    } else {
      body = (
        <div className="space-y-2">
          <p className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
            This spot no longer exists.
          </p>
          <Link to="/truck/spots" className="inline-flex min-h-11 md:min-h-0 items-center text-sm font-bold underline underline-offset-2" style={{ color: 'var(--ink)' }}>
            All spots
          </Link>
        </div>
      );
    }
  }

  return (
    <Sheet open={subject !== null} onClose={onClose} title={titleOf(subject)} side={side} width={420} modal={false}>
      <ErrorBoundary key={key} scope="Spot card" inline>
        {body}
      </ErrorBoundary>
    </Sheet>
  );
}

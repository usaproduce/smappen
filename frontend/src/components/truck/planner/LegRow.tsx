import { Pencil, TriangleAlert, Truck } from 'lucide-react';
import type { MapsPoint } from '../../../utils/truck/links';
import type { LegView } from '../../../utils/truck/planDraft';
import { OPEN_IN_MAPS } from '../../../utils/truck/wording';
import { OpenInMaps } from '../ui';

export interface LegRowProps {
  leg: LegView;
  /** The two ends of the drive, for the link to Google Maps; null when one of them has no place. */
  ends: { origin: MapsPoint; destination: MapsPoint } | null;
  /** The drive legs of the day are still on their way: the figures may still change. */
  dim: boolean;
  /** Opens the editor for the owner's own time and toll; left out where there is nothing to correct. */
  onEdit?: () => void;
}

/**
 * One drive of the day, between two stop cards (docs/truck-planner/05_FRONTEND.md 4.5): how long
 * and how far, where that time comes from (a Google time, a straight-line estimate with its
 * reason, or the owner's own correction), the toll, and when to leave. Minutes and clock times are
 * the model's; nothing is worked out here.
 */
export default function LegRow({ leg, ends, dim, onEdit }: LegRowProps) {
  return (
    <div className="rounded-lg px-3 py-2" style={{ background: 'var(--bg-panel)' }} data-tp-leg={leg.position}>
      <div className="flex items-start gap-2">
        <Truck size={16} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
        <div className={'min-w-0 flex-1' + (dim ? ' tp-dim' : '')} aria-busy={dim ? true : undefined}>
          <p className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
            {leg.title}
          </p>
          <p className="flex items-start gap-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
            {leg.straight ? (
              <TriangleAlert size={13} aria-hidden className="mt-px flex-none" style={{ color: 'var(--fresh-aging)' }} />
            ) : null}
            <span>
              {leg.source}
              {leg.reason !== null ? '. ' + leg.reason : ''}
            </span>
          </p>
          {leg.toll !== null ? (
            <p className="text-xs font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
              {leg.toll}
            </p>
          ) : null}
          <p className="mt-0.5 text-[13px] font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
            {leg.times}
          </p>
        </div>
        {/* In the corner of the row, so that it and the link below never fight for one line on a phone. */}
        {onEdit !== undefined ? (
          <button
            type="button"
            onClick={onEdit}
            className="-my-1.5 -mr-1 inline-flex min-h-[44px] md:min-h-[32px] flex-none items-center gap-1.5 px-1 text-sm font-bold underline underline-offset-2"
            style={{ color: 'var(--ink)' }}
          >
            <Pencil size={14} aria-hidden className="flex-none" />
            Edit
            <span className="sr-only"> this drive</span>
          </button>
        ) : null}
      </div>
      {ends !== null ? (
        <div className="pl-6">
          <OpenInMaps route={ends} label={OPEN_IN_MAPS.leg} />
        </div>
      ) : null}
    </div>
  );
}

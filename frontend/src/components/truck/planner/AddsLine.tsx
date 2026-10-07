import { TriangleAlert } from 'lucide-react';
import type { StopAdds, StopKind } from '../../../utils/truck/model';
import { addsView } from '../../../utils/truck/planDraft';
import { RangeValue } from '../ui';

export interface AddsLineProps {
  /** The whole day with this stop minus the whole day without it (the model's `adds`). */
  adds: StopAdds;
  kind: StopKind;
  /** Codes of the model's warnings about this stop: they decide whether a loss is said in words. */
  warningCodes: readonly string[];
  /** The drive legs of the day are still on their way. */
  dim: boolean;
}

/**
 * "What this stop adds" (docs/truck-planner/05_FRONTEND.md 4.5): what the day clears more with this
 * stop in it, for how many more hours of the owner's day and how much an hour that comes to, and
 * the number of orders the stop needs to pay for the costs it adds. Both figures are estimates and
 * come as ranges with their label.
 */
export default function AddsLine({ adds, kind, warningCodes, dim }: AddsLineProps) {
  const view = addsView(adds, kind, warningCodes);
  return (
    <div className="rounded-lg px-3 py-2.5" style={{ background: 'var(--bg-panel)' }}>
      <h3 className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
        What this stop adds
      </h3>
      <div className="mt-1 flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <RangeValue estimate={view.takeHome} unit="money" layout="inline" size="sm" dim={dim} />
        {view.perHour !== null && view.hoursLead !== null ? (
          <>
            <span className="text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
              {view.hoursLead}
            </span>
            <RangeValue estimate={view.perHour} unit="money_per_hour" layout="inline" size="sm" dim={dim} />
          </>
        ) : null}
      </div>
      {view.breakEven !== null ? (
        <p className={'mt-1.5 text-[13px] font-bold tabular-nums' + (dim ? ' tp-dim' : '')} style={{ color: 'var(--ink)' }}>
          {view.breakEven}
        </p>
      ) : null}
      {view.loss !== null ? (
        <p className="mt-0.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
          {view.loss}
        </p>
      ) : null}
      {view.fallback !== null ? (
        <p className="mt-0.5 flex items-start gap-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
          <TriangleAlert size={13} aria-hidden className="mt-px flex-none" style={{ color: 'var(--fresh-aging)' }} />
          <span>{view.fallback}</span>
        </p>
      ) : null}
    </div>
  );
}

import type { UnpaidGapAlternative } from '../../../utils/truck/model';
import { wagesSaved } from '../../../utils/truck/planDraft';
import { RangeValue } from '../ui';

export interface UnpaidGapCardProps {
  /** The day with every paid wait turned into an unpaid break (the model's `unpaid_gap_alternative`). */
  alternative: UnpaidGapAlternative;
  dim: boolean;
  /** "Mark the wait as unpaid": sets the flag on every stop that has a wait. */
  onMark: () => void;
}

/**
 * "If the wait were an unpaid break" (docs/truck-planner/05_FRONTEND.md 4.5): shown while the crew
 * is paid through a wait between two stops. What the day would clear instead, what that comes to
 * per hour, and the wages it saves.
 */
export default function UnpaidGapCard({ alternative, dim, onMark }: UnpaidGapCardProps) {
  const saves = wagesSaved(alternative);
  return (
    <section className="bg-white rounded-xl border p-4 sm:p-5" style={{ borderColor: 'var(--line-soft)' }}>
      <h2 className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
        If the wait were an unpaid break
      </h2>
      <div className="mt-2">
        <RangeValue estimate={alternative.take_home} unit="money" size="md" dim={dim} />
      </div>
      <div className="mt-2">
        <RangeValue estimate={alternative.take_home_per_hour} unit="money_per_hour" layout="inline" size="sm" dim={dim} />
      </div>
      <p className={'mt-2 text-sm font-semibold' + (dim ? ' tp-dim' : '')} style={{ color: 'var(--body)' }}>
        {saves.lead}
        <span className="font-extrabold tabular-nums" style={{ color: 'var(--money-positive)' }}>
          {saves.figure}
        </span>
        {saves.tail}
      </p>
      <button type="button" className="btn btn-secondary mt-3 min-h-[44px] md:min-h-[36px] px-3 text-sm" onClick={onMark}>
        Mark the wait as unpaid
      </button>
    </section>
  );
}

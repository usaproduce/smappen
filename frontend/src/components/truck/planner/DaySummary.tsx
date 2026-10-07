import type { DayContext, DayResult, TruckProfile } from '../../../utils/truck/model';
import { costLines, dayFacts, workedLine } from '../../../utils/truck/planDraft';
import { RangeValue, StatList, StatRow } from '../ui';
import MoneyTable from './MoneyTable';

export interface DaySummaryProps {
  /** The day as the model evaluated it. */
  result: DayResult;
  profile: TruckProfile;
  /** The context of the date: the fuel price and where it comes from. */
  context: DayContext;
  /** The drive legs of the day are still on their way. */
  dim: boolean;
  /** Opens "Why this number" for the whole day. */
  onWhy: () => void;
}

const CARD = 'bg-white rounded-xl border p-4 sm:p-5';

/**
 * What the day clears (docs/truck-planner/05_FRONTEND.md 4.5): take-home and take-home per hour of
 * the owner's day as estimates with their range and label, the sales lines on an expected, a weak
 * and a strong day, then the day's own costs and its length. The costs are fixed amounts once the
 * timeline is known, so each is one figure.
 */
export default function DaySummary({ result, profile, context, dim, onWhy }: DaySummaryProps) {
  const totals = result.totals;
  const costs = costLines(totals, profile, context);
  const facts = dayFacts(result.timeline);
  return (
    <section className={CARD} style={{ borderColor: 'var(--line-soft)' }} aria-label="What the day clears">
      <div className="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2">
        <RangeValue estimate={totals.take_home} unit="money" size="xl" label="TAKE-HOME" onWhy={onWhy} dim={dim} />
        <RangeValue
          estimate={totals.take_home_per_hour}
          unit="money_per_hour"
          size="md"
          label="PER HOUR OF YOUR DAY"
          note={workedLine(totals)}
          dim={dim}
        />
      </div>

      <div className="mt-4">
        <MoneyTable totals={totals} tips={profile.tips_include} dim={dim} />
      </div>

      <div className={'mt-3' + (dim ? ' tp-dim' : '')}>
        <h3 className="text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
          The day's own costs
        </h3>
        <StatList>
          {costs.map((line) => (
            <StatRow key={line.label} label={line.label} value={line.value} sub={line.sub} />
          ))}
        </StatList>
      </div>

      <dl
        className={'mt-1 grid grid-cols-2 gap-x-4 gap-y-2 border-t pt-3 min-[420px]:grid-cols-3' + (dim ? ' tp-dim' : '')}
        style={{ borderColor: 'var(--line-soft)' }}
      >
        {facts.map((line) => (
          <div key={line.label} className="min-w-0">
            <dt className="text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
              {line.label}
            </dt>
            <dd className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
              {line.value}
            </dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

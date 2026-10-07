import { useMemo, type ReactNode } from 'react';
import { apiErrorMessage, type ServiceLog } from '../../../api/truck';
import { addDays } from '../../../utils/truck/model';
import type { Estimate } from '../../../utils/truck/model';
import { fmtCount, fmtDay, fmtPercent, fmtWindow } from '../../../utils/truck/format';
import { resultText } from '../../../utils/truck/logView';
import { VERDICT_WORDS } from '../../../utils/truck/wording';
import { useNow, useServices, useTruck } from '../data';
import { DataTable, QueryError, RangeValue, SkeletonRows, type Column } from '../ui';

export interface SpotResultsProps {
  spotId: string;
}

const LOAD_FAILED = 'Could not load the services logged here.';
/** The server lists at most 730 dates at once: today and the 729 days before it. */
const DAYS_BACK = 729;

/** The prediction kept with a logged service as the estimate it was. */
function estimateOf(service: ServiceLog): Estimate | null {
  const p = service.prediction;
  if (p === null) return null;
  return { value: p.predicted, low: p.low, high: p.high, confidence: p.confidence };
}

function resultOf(service: ServiceLog): string {
  const p = service.prediction;
  if (p === null) return service.sold_out ? VERDICT_WORDS.sold_out : 'No estimate was kept';
  return resultText({ actual: service.actual, low: p.low, high: p.high, sold_out: service.sold_out, predicted: p.predicted });
}

/**
 * "Your results here" (docs/truck-planner/05_FRONTEND.md 4.4): the services logged at one spot,
 * newest first, each against the estimate that was kept with it, and what they have taught the
 * model about this spot. Logged results outrank the model: the line under the table says by how
 * much this spot differs from what the model expects for the truck.
 */
export default function SpotResults({ spotId }: SpotResultsProps) {
  const { cal } = useTruck();
  const today = useNow().date;
  const from = useMemo(() => addDays(today, -DAYS_BACK), [today]);
  const query = useServices({ spot_id: spotId, from });
  const learned = Object.prototype.hasOwnProperty.call(cal.spots, spotId) ? cal.spots[spotId] : null;

  const columns: Column<ServiceLog>[] = [
    { key: 'date', header: 'Date', render: (s) => <span className="whitespace-nowrap">{fmtDay(s.date, 'long')}</span> },
    { key: 'hours', header: 'Hours', render: (s) => <span className="whitespace-nowrap">{fmtWindow(s.open_minute, s.close_minute)}</span> },
    { key: 'orders', header: 'Orders', align: 'right', render: (s) => fmtCount(s.actual) },
    {
      key: 'estimate',
      header: 'Estimate',
      render: (s) => {
        const estimate = estimateOf(s);
        return estimate === null ? <span style={{ color: 'var(--body)' }}>None kept</span> : <RangeValue estimate={estimate} unit="orders" size="sm" layout="inline" />;
      },
    },
    { key: 'result', header: 'Result', render: resultOf },
  ];

  const card = (s: ServiceLog) => {
    const estimate = estimateOf(s);
    return (
      <div className="space-y-1 text-sm">
        <div className="font-bold" style={{ color: 'var(--ink)' }}>
          {fmtDay(s.date, 'long')}, {fmtWindow(s.open_minute, s.close_minute)}
        </div>
        <div className="flex flex-wrap gap-x-5 gap-y-1">
          <Fact label="Orders">
            <span className="font-bold tabular-nums">{fmtCount(s.actual)}</span>
          </Fact>
          <Fact label="Estimate">{estimate === null ? 'None kept' : <RangeValue estimate={estimate} unit="orders" size="sm" layout="inline" />}</Fact>
        </div>
        <Fact label="Result">{resultOf(s)}</Fact>
      </div>
    );
  };

  const line = (
    <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
      {learned !== null && learned.n > 0
        ? 'Here you sell ' +
          fmtPercent(learned.factor) +
          ' of what the model expects for your truck (' +
          fmtCount(learned.n) +
          (learned.n === 1 ? ' service).' : ' services).')
        : 'No services logged here yet.'}
    </p>
  );

  if (query.data === undefined) {
    if (query.isError) {
      return (
        <QueryError
          message={apiErrorMessage(query.error, LOAD_FAILED) ?? LOAD_FAILED}
          onRetry={() => {
            void query.refetch();
          }}
        />
      );
    }
    return (
      <div aria-busy="true">
        <SkeletonRows rows={2} rowHeight={40} />
      </div>
    );
  }

  if (query.data.length === 0) return line;

  // The card this list sits in is narrow on a desktop (the left column of the spot page) and wide
  // on a tablet, so the choice between table and cards follows the width it actually has.
  return (
    <div className="@container space-y-3">
      <div className="hidden @[560px]:block">
        <DataTable caption="Services logged at this spot" columns={columns} rows={query.data} rowKey={(s) => s.id} dense />
      </div>
      <ul className="tp-stat-list @[560px]:hidden" aria-label="Services logged at this spot">
        {query.data.map((s) => (
          <li key={s.id} className="py-2.5 first:pt-0">
            {card(s)}
          </li>
        ))}
      </ul>
      {line}
    </div>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
        {label}
      </span>
      <div className="font-semibold" style={{ color: 'var(--ink)' }}>
        {children}
      </div>
    </div>
  );
}

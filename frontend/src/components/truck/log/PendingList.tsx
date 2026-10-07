import { Link } from 'react-router-dom';
import { fmtDay, fmtWindow } from '../../../utils/truck/format';
import { logHref } from '../../../utils/truck/logForm';
import type { UnloggedStop } from '../../../utils/truck/logView';
import { QueryError, SkeletonRows } from '../ui';

export interface PendingListProps {
  /**
   * Planned stops of the last seven days whose closing time has passed and that no logged service
   * refers to, newest first (`unloggedStops`); undefined while the plans or the services are on
   * their way.
   */
  stops: readonly UnloggedStop[] | undefined;
  /** The plans or the services could not be loaded. */
  failed: boolean;
  onRetry: () => void;
}

const CARD = 'bg-white rounded-xl border p-4 sm:p-5';

/**
 * "Not logged yet" (docs/truck-planner/05_FRONTEND.md 4.7): every planned stop that still waits for
 * its numbers, each with "Log it", which fills the quick entry with that stop. The link is the same
 * one the Today page uses, so both arrive at the same form in the same state.
 */
export default function PendingList({ stops, failed, onRetry }: PendingListProps) {
  return (
    <section className={CARD + ' min-w-0'} style={{ borderColor: 'var(--line-soft)' }} aria-labelledby="tp-log-pending-title">
      <h2 id="tp-log-pending-title" className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
        Not logged yet
      </h2>
      {stops === undefined ? (
        failed ? (
          <div className="mt-3">
            <QueryError message="Could not load your planned stops." onRetry={onRetry} />
          </div>
        ) : (
          <div className="mt-3" aria-busy="true">
            <SkeletonRows rows={2} rowHeight={44} />
          </div>
        )
      ) : stops.length === 0 ? (
        <p className="mt-1.5 text-sm font-semibold" style={{ color: 'var(--body)' }}>
          Nothing is waiting. A stop you plan shows up here once its closing time has passed.
        </p>
      ) : (
        <ul className="tp-stat-list mt-1.5">
          {stops.map((stop) => (
            <li key={stop.stopId} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 py-2.5">
              <span className="min-w-0 text-sm font-bold" style={{ color: 'var(--ink)' }}>
                <span className="whitespace-nowrap tabular-nums">{fmtDay(stop.date, 'medium')}</span>
                {' · '}
                <span className="break-words">{stop.name}</span>
                {' · '}
                <span className="whitespace-nowrap tabular-nums">{fmtWindow(stop.openMinute, stop.closeMinute)}</span>
              </span>
              <Link
                to={logHref(stop)}
                className="btn btn-secondary h-11 md:h-9 flex-none px-3 text-sm"
                aria-label={'Log it: ' + stop.name + ', ' + fmtDay(stop.date, 'medium')}
              >
                Log it
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

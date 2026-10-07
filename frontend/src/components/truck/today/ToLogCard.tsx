import { Link } from 'react-router-dom';
import type { ServiceLog } from '../../../api/truck';
import { fmtDay, fmtWindow } from '../../../utils/truck/format';
import { logHref, storedEstimate } from '../../../utils/truck/logForm';
import { verdictOf, type UnloggedStop } from '../../../utils/truck/logView';
import { LOG_TEXT, latestLine } from '../../../utils/truck/nextAction';
import { VERDICT_WORDS } from '../../../utils/truck/wording';
import { VerdictTag } from '../log/ResultCard';
import { QueryError, RangeValue, SkeletonRows } from '../ui';

export interface ToLogCardProps {
  /** Today's civil date in the truck's time zone. */
  today: string;
  /**
   * Planned stops of the last seven days whose closing time has passed and that no logged service
   * refers to, newest first, at most five; undefined while the plans, the services or the spots
   * are on their way.
   */
  unlogged: readonly UnloggedStop[] | undefined;
  /** What could not be loaded, as a sentence; null when everything arrived or is still on its way. */
  failed: string | null;
  onRetry: () => void;
  /** The service served last among those of the last seven days, with the name of its place. */
  latest: { service: ServiceLog; name: string } | null;
  /** The truck has logged a service at some time. */
  everLogged: boolean;
}

/**
 * "Log what happened" (docs/truck-planner/05_FRONTEND.md 4.1): the planned stops that still wait
 * for their numbers, each one tap from the quick entry, and how the latest logged service sat
 * against its estimate. The estimate is the one kept with the service, with its range and label;
 * the owner's own count is what moves the next estimates.
 */
export default function ToLogCard({ today, unlogged, failed, onRetry, latest, everLogged }: ToLogCardProps) {
  const estimate = latest === null ? null : storedEstimate(latest.service);
  let verdict: keyof typeof VERDICT_WORDS | null = null;
  if (latest !== null) {
    if (estimate !== null) {
      verdict = verdictOf({ actual: latest.service.actual, low: estimate.low, high: estimate.high, sold_out: latest.service.sold_out });
    } else if (latest.service.sold_out) {
      verdict = 'sold_out';
    }
  }

  return (
    <section aria-labelledby="tp-today-log" className="bg-white rounded-xl border p-4 sm:p-5" style={{ borderColor: 'var(--line-soft)' }}>
      <h2 id="tp-today-log" className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
        {LOG_TEXT.title}
      </h2>

      {unlogged === undefined ? (
        failed !== null ? (
          <div className="mt-3">
            <QueryError message={failed} onRetry={onRetry} />
          </div>
        ) : (
          <div className="mt-3" aria-busy="true">
            <SkeletonRows rows={2} rowHeight={44} />
          </div>
        )
      ) : (
        <>
          {unlogged.length > 0 ? (
            <ul className="tp-stat-list mt-1.5">
              {unlogged.map((stop) => (
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
                    aria-label={LOG_TEXT.logIt + ': ' + stop.name + ', ' + fmtDay(stop.date, 'medium')}
                  >
                    {LOG_TEXT.logIt}
                  </Link>
                </li>
              ))}
            </ul>
          ) : null}

          {latest !== null ? (
            <div className={unlogged.length > 0 ? 'border-t pt-3' : 'mt-1.5'} style={{ borderColor: 'var(--line-soft)' }}>
              <p className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                {latestLine(latest.name, latest.service, today)}
              </p>
              {estimate !== null || verdict !== null ? (
                <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-sm font-semibold" style={{ color: 'var(--body)' }}>
                  {estimate !== null ? (
                    <>
                      <span>{LOG_TEXT.estimateWas}</span>
                      <RangeValue estimate={estimate} unit="orders" layout="inline" size="sm" />
                    </>
                  ) : null}
                  {verdict !== null ? <VerdictTag verdict={verdict} words={VERDICT_WORDS[verdict]} /> : null}
                </div>
              ) : null}
            </div>
          ) : unlogged.length === 0 ? (
            <p className="mt-1.5 text-sm font-semibold" style={{ color: 'var(--body)' }}>
              {everLogged ? LOG_TEXT.quiet : LOG_TEXT.empty}
            </p>
          ) : null}

          {everLogged || unlogged.length > 0 ? (
            <Link
              to="/truck/log"
              className="mt-2 inline-flex min-h-[44px] md:min-h-0 items-center text-sm font-bold underline underline-offset-2"
              style={{ color: 'var(--ink)' }}
            >
              {LOG_TEXT.openLog}
            </Link>
          ) : null}
        </>
      )}
    </section>
  );
}

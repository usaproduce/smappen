import { useMemo, useState, type ReactNode } from 'react';
import { Info, Target } from 'lucide-react';
import { apiErrorMessage } from '../../../api/truck';
import {
  ACCURACY_TEXT,
  accuracyTiles,
  spotAccuracyRows,
  unscoredNote,
  type SpotAccuracyRow,
} from '../../../utils/truck/accuracyView';
import { fmtCount } from '../../../utils/truck/format';
import { useAccuracy, useTruck } from '../data';
import { DataTable, EmptyState, QueryError, SkeletonCard, SkeletonChart, nextSort, sortRows, type Column } from '../ui';
import AccuracyChart from './AccuracyChart';
import AccuracyTiles from './AccuracyTiles';
import FactorsCard from './FactorsCard';

export interface AccuracyTabProps {
  /** Names of the spots by id (deleted spots included). */
  names: Readonly<Record<string, string>>;
  /** False while the spot list is still on its way: rows would name no spot yet. */
  namesReady: boolean;
  /** The spot list could not be loaded. */
  namesFailed: boolean;
  onRetryNames: () => void;
  /** "Log a service": back to the quick entry. */
  onLogService: () => void;
}

const CAPTION = 'Accuracy by spot';
const SPOTS_FAILED = 'Could not load your spots.';

type Sort = { key: string; dir: 'asc' | 'desc' };

/** A figure that may be missing, as a sort value: missing ones go last. */
function orNaN(x: number | null): number {
  return x === null ? NaN : x;
}

/**
 * The accuracy tab (docs/truck-planner/05_FRONTEND.md 4.7): how close the estimates have been, how
 * often the actual fell inside the stated range, and the factors the truck and each spot now
 * carry. Everything is the server's report (route 37) and the calibration of the context, shown as
 * returned: sold-out services are counted and never scored.
 */
export default function AccuracyTab({ names, namesReady, namesFailed, onRetryNames, onLogService }: AccuracyTabProps) {
  const { cal } = useTruck();
  const query = useAccuracy();
  const data = query.data;
  const [sort, setSort] = useState<Sort>({ key: 'services', dir: 'desc' });

  const rows = useMemo(() => (data === undefined ? [] : spotAccuracyRows(data.accuracy, cal, names)), [data, cal, names]);

  const heading = (
    <h2 className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
      {ACCURACY_TEXT.heading}
    </h2>
  );

  if (data === undefined) {
    return (
      <div className="space-y-4">
        {heading}
        {query.isError ? (
          <QueryError
            message={apiErrorMessage(query.error, ACCURACY_TEXT.loadFailed) ?? ACCURACY_TEXT.loadFailed}
            onRetry={() => {
              void query.refetch();
            }}
          />
        ) : (
          <div className="space-y-4" aria-busy="true">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <SkeletonCard />
              <SkeletonCard />
              <SkeletonCard />
              <SkeletonCard />
            </div>
            <SkeletonChart height={200} />
          </div>
        )}
      </div>
    );
  }

  const note = unscoredNote(data.unscored_without_prediction);
  const noteLine =
    note === null ? null : (
      <p className="flex items-start gap-1.5 text-sm font-semibold" style={{ color: 'var(--body)' }}>
        <Info size={15} aria-hidden className="mt-0.5 flex-none" />
        <span>{note}</span>
      </p>
    );

  if (data.accuracy.n_total === 0) {
    return (
      <div className="space-y-4">
        {heading}
        <EmptyState icon={Target} title={ACCURACY_TEXT.emptyTitle} body={ACCURACY_TEXT.emptyBody} action={{ label: 'Log a service', onClick: onLogService }} />
        {noteLine}
      </div>
    );
  }

  const columns: Column<SpotAccuracyRow>[] = [
    { key: 'spot', header: 'Spot', sortValue: (r) => r.name, render: (r) => <span className="break-words">{r.name}</span> },
    { key: 'services', header: 'Services', align: 'right', sortValue: (r) => r.services, render: (r) => fmtCount(r.services) },
    { key: 'sold-out', header: 'Sold out', align: 'right', sortValue: (r) => r.soldOut, render: (r) => fmtCount(r.soldOut) },
    { key: 'bias', header: 'Bias', sortValue: (r) => orNaN(r.bias), render: (r) => <span className="whitespace-nowrap tabular-nums">{r.biasText}</span> },
    { key: 'miss', header: 'Typical miss', align: 'right', sortValue: (r) => orNaN(r.miss), render: (r) => r.missText },
    { key: 'inside', header: 'Inside the range', align: 'right', sortValue: (r) => orNaN(r.coverage), render: (r) => r.insideText },
    { key: 'factor', header: 'Spot factor', align: 'right', sortValue: (r) => orNaN(r.factor), render: (r) => r.factorText },
  ];
  const sortColumn = columns.find((c) => c.key === sort.key);
  const ordered = sortColumn === undefined || sortColumn.sortValue === undefined ? rows : sortRows(rows, sortColumn.sortValue, sort.dir, (r) => r.spotId);

  return (
    <div className="space-y-4">
      {heading}
      <AccuracyTiles tiles={accuracyTiles(data.accuracy)} />
      {noteLine}

      {/* From 1280 px the factors sit beside the chart; in one column they come last. */}
      <div className="grid gap-4 xl:grid-cols-12 xl:items-start">
        <div className="min-w-0 xl:col-span-8 xl:row-start-1">
          <AccuracyChart entries={data.entries} names={names} />
        </div>

        <section className="@container min-w-0 xl:col-span-12 xl:row-start-2" aria-labelledby="tp-log-by-spot">
          <h3 id="tp-log-by-spot" className="mb-2 text-base font-extrabold" style={{ color: 'var(--ink)' }}>
            {ACCURACY_TEXT.bySpotTitle}
          </h3>
          {namesFailed ? (
            <QueryError message={SPOTS_FAILED} onRetry={onRetryNames} />
          ) : !namesReady ? (
            <div aria-busy="true">
              <SkeletonCard height={120} />
            </div>
          ) : (
            <>
              <div className="hidden @[820px]:block [&_.tp-scroll-x]:relative">
                <DataTable
                  caption={CAPTION}
                  columns={columns}
                  rows={rows}
                  rowKey={(r) => r.spotId}
                  sort={sort}
                  onSort={(key) => setSort((current) => nextSort(current, key))}
                  empty="No spot has a scored service yet."
                />
              </div>
              <ul className="grid gap-2 @[560px]:grid-cols-2 @[820px]:hidden" aria-label={CAPTION}>
                {ordered.map((r) => (
                  <li key={r.spotId} className="min-w-0 rounded-xl border bg-white p-3" style={{ borderColor: 'var(--line-soft)' }}>
                    <div className="break-words text-sm font-bold" style={{ color: 'var(--ink)' }}>
                      {r.name}
                    </div>
                    <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                      <Fact label="Services">{fmtCount(r.services)}</Fact>
                      <Fact label="Sold out">{fmtCount(r.soldOut)}</Fact>
                      <Fact label="Bias">{r.biasText}</Fact>
                      <Fact label="Typical miss">{r.missText}</Fact>
                      <Fact label="Inside the range">{r.insideText}</Fact>
                      <Fact label="Spot factor">{r.factorText}</Fact>
                    </dl>
                  </li>
                ))}
              </ul>
            </>
          )}
        </section>

        <div className="min-w-0 xl:col-span-4 xl:col-start-9 xl:row-start-1">
          {namesFailed ? (
            <QueryError message={SPOTS_FAILED} onRetry={onRetryNames} />
          ) : namesReady ? (
            <FactorsCard cal={cal} names={names} />
          ) : (
            <div aria-busy="true">
              <SkeletonCard height={160} />
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>
        {label}
      </dt>
      <dd className="font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
        {children}
      </dd>
    </div>
  );
}

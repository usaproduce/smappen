import { useEffect, useMemo, type ReactNode } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { RefreshCw } from 'lucide-react';
import {
  REGION_REBUILD_SENTENCE,
  apiErrorMessage,
  isNoTruckError,
  isRegionRebuildError,
  truckKeys,
  type TruckRecord,
} from '../../api/truck';
import { TP_CHUNK_SENTINEL } from '../../utils/truck/model';
import { VersionMismatch, buildAssumptions, versionsMatch } from '../../utils/truck/assemble';
import { TruckContext, regionRebuilding, type TruckContextValue } from './data/TruckContext';
import { useBootstrap, type BootstrapData } from './data/useBootstrap';
import { QueryError } from './ui';
import SetupTruck from './SetupTruck';

/**
 * The gate every Truck Planner page sits behind (docs/truck-planner/05_FRONTEND.md 1.4). A pathless
 * layout route: it reads the bootstrap answer once and renders, in this order of precedence,
 *
 *   loading          the page-shaped skeleton
 *   request failed   one sentence and "Try again"
 *   out of date      the reload card (the server runs another model version than this bundle, so
 *                    browser and server numbers would disagree; no page renders)
 *   no truck yet     the first-run step, on every /truck route
 *   ready            the page, inside TruckContext
 *
 * It is also the one place that reacts to the two 409 answers every route can give: "Set up your
 * truck first" (the truck was deleted in another tab) and the region rebuild notice. Both mean the
 * bootstrap answer is out of date, so it is read again.
 */

const LOAD_FAILED = 'Could not load Truck Planner.';
const PAGE_FRAME = 'max-w-7xl mx-auto px-4 md:px-6 py-4 md:py-6';

export default function TruckGate() {
  const qc = useQueryClient();
  const { pathname } = useLocation();
  const isMap = pathname.split('/')[2] === 'map';
  const query = useBootstrap();

  useEffect(() => {
    const onError = (error: unknown) => {
      if (isNoTruckError(error) || isRegionRebuildError(error)) {
        void qc.invalidateQueries({ queryKey: truckKeys.bootstrap() });
      }
    };
    const offQueries = qc.getQueryCache().subscribe((event) => {
      if (event.type === 'updated' && event.action.type === 'error' && event.query.queryKey[0] === 'truck') {
        onError(event.action.error);
      }
    });
    const offMutations = qc.getMutationCache().subscribe((event) => {
      if (event.type === 'updated' && event.action.type === 'error') onError(event.action.error);
    });
    return () => {
      offQueries();
      offMutations();
    };
  }, [qc]);

  const data = query.data;
  if (data === undefined) {
    if (query.isError) {
      return (
        <GateShell isMap={isMap} padded>
          <QueryError
            message={apiErrorMessage(query.error, LOAD_FAILED) ?? LOAD_FAILED}
            onRetry={() => {
              void query.refetch();
            }}
          />
        </GateShell>
      );
    }
    return (
      <GateShell isMap={isMap}>
        <GateSkeleton isMap={isMap} />
      </GateShell>
    );
  }

  if (!versionsMatch(data.model_version, data.seeds_revision)) {
    return (
      <GateShell isMap={isMap} padded>
        <OutOfDate />
      </GateShell>
    );
  }

  if (!data.has_truck || data.truck === null) {
    return (
      <GateShell isMap={isMap} padded>
        <SetupTruck regions={data.regions} />
      </GateShell>
    );
  }

  return <Ready data={data} truck={data.truck} isMap={isMap} />;
}

/** The page with its context. Split off so that the hooks below run only when there is a truck. */
function Ready({ data, truck, isMap }: { data: BootstrapData; truck: TruckRecord; isMap: boolean }) {
  const info = data.assumptions;
  const A = useMemo(() => {
    try {
      return buildAssumptions(info);
    } catch (e) {
      if (e instanceof VersionMismatch) return null;
      throw e;
    }
  }, [info]);

  const { calibration, region, fuel, counts, routing, limits } = data;
  const timezone = data.timezone ?? truck.timezone;
  const value = useMemo((): TruckContextValue | null => {
    if (A === null || fuel === null) return null;
    return { truck, profile: truck.profile, A, cal: calibration, region, fuel, timezone, counts, routing, limits };
  }, [truck, A, calibration, region, fuel, timezone, counts, routing, limits]);

  if (A === null) {
    return (
      <GateShell isMap={isMap} padded>
        <OutOfDate />
      </GateShell>
    );
  }
  if (value === null) {
    // A truck without a fuel price is an answer this bundle cannot work with.
    return (
      <GateShell isMap={isMap} padded>
        <QueryError message={LOAD_FAILED} />
      </GateShell>
    );
  }

  return (
    <TruckContext.Provider value={value}>
      <GateShell isMap={isMap} strip={regionRebuilding(region) ? <RebuildStrip isMap={isMap} /> : null}>
        <Outlet />
      </GateShell>
    </TruckContext.Provider>
  );
}

/**
 * The gate's own element. It carries the chunk sentinel as an attribute, which is what proves at
 * build time that model code stayed in the lazy chunk (frontend/scripts/check-truck-chunks.mjs).
 *
 * The layout gives the map section no page frame: the area under the sub-nav belongs to the map.
 * There the shell fills that area, keeps an optional strip above the page, and hands the page a
 * positioned box of what is left. `padded` restores the page frame for the gate's own states.
 */
function GateShell({
  isMap,
  strip,
  padded,
  children,
}: {
  isMap: boolean;
  strip?: ReactNode;
  padded?: boolean;
  children: ReactNode;
}) {
  if (!isMap) {
    return (
      <div data-tp-chunk={TP_CHUNK_SENTINEL}>
        {strip}
        {children}
      </div>
    );
  }
  return (
    <div data-tp-chunk={TP_CHUNK_SENTINEL} className="absolute inset-0 flex flex-col">
      {strip}
      <div className={'relative flex-1 min-h-0' + (padded ? ' overflow-y-auto' : '')}>
        {padded ? <div className={PAGE_FRAME}>{children}</div> : children}
      </div>
    </div>
  );
}

function GateSkeleton({ isMap }: { isMap: boolean }) {
  if (isMap) {
    return (
      <div
        className="absolute inset-0 grid place-items-center text-sm font-semibold"
        style={{ color: 'var(--body)' }}
        aria-busy="true"
      >
        Loading map...
      </div>
    );
  }
  return (
    <div className="space-y-4" aria-busy="true">
      <div className="skeleton rounded-xl" style={{ height: 96 }} />
      <div className="skeleton rounded-xl" style={{ height: 72 }} />
      <div className="skeleton rounded-xl" style={{ height: 72 }} />
      <div className="skeleton rounded-xl" style={{ height: 72 }} />
    </div>
  );
}

function OutOfDate() {
  return (
    <section
      className="bg-white rounded-xl border p-4 sm:p-5 mx-auto"
      style={{ maxWidth: 480, borderColor: 'var(--line-soft)' }}
      role="alert"
    >
      <h1 className="text-lg font-extrabold" style={{ color: 'var(--ink)' }}>
        This page is out of date
      </h1>
      <p className="text-sm font-medium mt-1.5" style={{ color: 'var(--body)' }}>
        Truck Planner was updated. Reload to get the current version.
      </p>
      <button
        type="button"
        className="btn btn-primary h-11 md:h-9 px-4 text-sm mt-4"
        onClick={() => window.location.reload()}
      >
        Reload
      </button>
    </section>
  );
}

/** Shown above every page while the region data is built with other model constants. */
function RebuildStrip({ isMap }: { isMap: boolean }) {
  return (
    <div
      role="status"
      className={
        'flex items-start gap-2 px-3 py-2.5 text-sm font-semibold ' +
        (isMap ? 'border-b' : 'rounded-xl border mb-4')
      }
      style={{ background: 'var(--fresh-aging-bg)', borderColor: 'var(--line-soft)', color: 'var(--ink)' }}
    >
      <RefreshCw size={15} className="flex-shrink-0 mt-0.5" style={{ color: 'var(--fresh-aging)' }} aria-hidden="true" />
      <span>{REGION_REBUILD_SENTENCE}</span>
    </div>
  );
}

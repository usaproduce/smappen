import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, Compass, Info, RefreshCw, SlidersHorizontal, TriangleAlert } from 'lucide-react';
import toast from 'react-hot-toast';
import { apiErrorMessage, apiErrorStatus, truckApi, truckKeys, type LeadStatus } from '../../../api/truck';
import { withinTolerance } from '../../../utils/truck/assemble';
import { fmtCount } from '../../../utils/truck/format';
import { modFloor, stopMoney, typicalContext, vectorsMatch, windowOrders } from '../../../utils/truck/model';
import type { SpotTerms, Visibility } from '../../../utils/truck/model';
import {
  SCOUT,
  SCOUT_NEEDS_REGION,
  activeFilterCount,
  clearedFilters,
  contactShareLine,
  countLine,
  countyOptions,
  hideKey,
  hostFitSentence,
  kindCountLine,
  kindGroups,
  leftListMessage,
  placesLabel,
  readScoutList,
  readScoutParams,
  scoutIntro,
  whyTitle,
  writeScoutParams,
  type KindGroup,
  type ScoutCandidate,
  type ScoutFilterState,
} from '../../../utils/truck/scoutView';
import { GC, STALE, refreshScout, truckRetry, useSaveLead, useScout, useSimulate, useTruck } from '../data';
import ScoutCard, { KindDigest, scoutCardId } from '../scout/ScoutCard';
import ScoutFilters, { KindNav, KindSelect } from '../scout/ScoutFilters';
import SaveLeadModal from '../scout/SaveLeadModal';
import { EmptyState, PermissionNotice, QueryError, Sheet, SkeletonCard, SkeletonRows, SourceLine, WhyDrawer, type WhySubject } from '../ui';

/** How many places of each kind the overview of all kinds names. */
const OVERVIEW_TOP = 3;

const REFRESH_FAILED = 'Could not refresh Scout.';
const CARD = 'rounded-xl border bg-white';

/**
 * One kind of place in depth (route 38 with `types`): the server then lists more of that kind than
 * the balanced answer holds.
 */
function fetchKind(hide: readonly LeadStatus[], kind: string, refresh: boolean): Promise<unknown> {
  return truckApi.scout({ hide: hide.slice(), types: [kind], refresh });
}

/**
 * Scout (docs/truck-planner/05_FRONTEND.md 4.8): places within reach of the base that could be
 * asked to host the truck. The server's list is balanced by kind of place, so the page opens on
 * every kind side by side, a few places each, and one press shows a single kind with its cards.
 *
 * What the page never does: it never says that a place takes trucks, never shows the score the
 * server ranks with, and never stores anything Google answered. The two estimates of a card are the
 * server's and are shown as returned.
 *
 * The address keeps the state (1.2): `hide` (the statuses the server leaves out), `type` (the kind
 * being looked at), `county`, `kitchen` and `contact` (filters applied here).
 */
export default function ScoutPage() {
  const qc = useQueryClient();
  const { A, profile, cal, region } = useTruck();
  const [params, setParams] = useSearchParams();
  const state = useMemo(() => readScoutParams(params), [params]);
  const setState = useCallback(
    (next: ScoutFilterState) => setParams((current) => writeScoutParams(current, next), { replace: true }),
    [setParams],
  );

  // ---- The list ---------------------------------------------------------------------------------
  const query = useScout(state.hide);
  const list = useMemo(() => (query.data === undefined ? null : readScoutList(query.data)), [query.data]);
  const groups = useMemo(() => (list === null ? [] : kindGroups(list, state)), [list, state]);
  const kind = list !== null && state.kind !== null && groups.some((group) => group.kind === state.kind) ? state.kind : null;
  const group: KindGroup | null = kind === null ? null : groups.find((g) => g.kind === kind) ?? null;

  // More of one kind, asked for by its button. An answer that is still in the cache shows at once.
  const [moreAsked, setMoreAsked] = useState<string[]>([]);
  const hidden = hideKey(state.hide);
  const deepKey = useMemo(() => [...truckKeys.scout(hidden), 'kind', kind ?? ''] as const, [hidden, kind]);
  const hideList = state.hide;
  const deepQuery = useQuery({
    queryKey: deepKey,
    queryFn: () => fetchKind(hideList, kind ?? '', false),
    enabled: kind !== null && moreAsked.indexOf(kind) >= 0,
    staleTime: STALE.scout,
    gcTime: GC.scout,
    refetchOnWindowFocus: false,
    retry: truckRetry,
  });
  const deepData = kind === null ? undefined : deepQuery.data;
  const deepGroup = useMemo((): KindGroup | null => {
    if (kind === null || deepData === undefined) return null;
    return kindGroups(readScoutList(deepData), state).find((g) => g.kind === kind) ?? null;
  }, [kind, deepData, state]);

  const shownGroup = deepGroup !== null ? deepGroup : group;
  const cards = shownGroup === null ? [] : shownGroup.candidates;
  let shownTotal = 0;
  for (const g of groups) shownTotal += g.candidates.length;
  const counties = useMemo(() => (list === null ? [] : countyOptions(list, region === null ? null : region.counties)), [list, region]);
  const regionCounties = region === null ? null : region.counties;
  const filterCount = activeFilterCount(state);

  // ---- Switching kinds --------------------------------------------------------------------------
  const results = useRef<HTMLDivElement>(null);
  const [focusKey, setFocusKey] = useState<string | null>(null);
  const [filtersOpen, setFiltersOpen] = useState(false);

  const pickKind = (next: string | null) => {
    setState({ ...state, kind: next });
    // The list starts over: bring its top back into view when the page was scrolled past it.
    const el = results.current;
    if (el !== null && el.getBoundingClientRect().top < 0) el.scrollIntoView({ block: 'start' });
  };

  // A place picked in the overview: its card comes into view once its kind is on the page.
  useEffect(() => {
    if (focusKey === null) return;
    const card = document.getElementById(scoutCardId(focusKey));
    if (card === null) return;
    card.scrollIntoView({ block: 'start' });
    const name = document.getElementById(scoutCardId(focusKey) + '-name');
    if (name !== null) name.focus({ preventScroll: true });
    setFocusKey(null);
  }, [focusKey, kind, cards]);

  // ---- Refresh ----------------------------------------------------------------------------------
  const [refreshing, setRefreshing] = useState(false);
  const refresh = async () => {
    if (refreshing) return;
    setRefreshing(true);
    try {
      await refreshScout(qc, state.hide);
      // One request after the other. The kind on screen is asked again; the other kinds are
      // dropped and asked for when their button is pressed.
      if (kind !== null && deepData !== undefined) qc.setQueryData(deepKey, await fetchKind(state.hide, kind, true));
      qc.removeQueries({
        queryKey: truckKeys.scout(hidden),
        predicate: (q) => q.queryKey.length > 3 && q.queryKey[4] !== (kind ?? ''),
      });
      setMoreAsked(kind !== null && deepData !== undefined ? [kind] : []);
    } catch (e) {
      const message = apiErrorMessage(e, REFRESH_FAILED);
      if (message) toast.error(message);
    } finally {
      setRefreshing(false);
    }
  };

  // ---- A lead that leaves the list ----------------------------------------------------------------
  const { mutate: saveLead } = useSaveLead();
  const onStatus = (candidate: ScoutCandidate, status: LeadStatus, previous: LeadStatus) => {
    if (state.hide.indexOf(status) < 0) return;
    const placeKey = candidate.place.place_key;
    toast(
      (t) => (
        <span>
          {leftListMessage(candidate.place.name, status)}{' '}
          <button
            type="button"
            className="font-bold underline underline-offset-2"
            onClick={() => {
              toast.dismiss(t.id);
              saveLead({ placeKey, patch: { status: previous } });
            }}
          >
            Undo
          </button>
        </span>
      ),
      { duration: 6000 },
    );
  };

  // ---- "Why this number" (on click only) --------------------------------------------------------
  const [whyFor, setWhyFor] = useState<ScoutCandidate | null>(null);
  const whyRequest = useMemo(() => {
    if (whyFor === null || whyFor.result.best_window === null) return null;
    const visibilities: Visibility[] = ['normal'];
    return {
      point: { lat: whyFor.place.lat, lng: whyFor.place.lng },
      // The host the server ranked with: the place itself, at the typical size of its kind.
      host: whyFor.result.host_size > 0 ? { place_key: whyFor.place.place_key } : null,
      visibilities,
    };
  }, [whyFor]);
  const sim = useSimulate(whyRequest);
  const simData = whyRequest !== null && !sim.isPlaceholderData ? sim.data : undefined;
  const whySubject = useMemo((): Extract<WhySubject, { kind: 'window' }> | null => {
    if (whyFor === null || simData === undefined) return null;
    const best = whyFor.result.best_window;
    const vectors = simData.vectors.normal;
    if (best === null || vectors === undefined) return null;
    const terms: SpotTerms = { spot_id: null, visibility: 'normal', host: simData.host, fee_flat: 0, fee_pct: 0, fee_min: 0, allowed: null };
    // The estimator is never called with terms and vectors that do not match (rule 6 of 2.5).
    if (!vectorsMatch(A, terms, vectors)) return null;
    const ctx = typicalContext(A, best.dow);
    const ctxNext = typicalContext(A, modFloor(best.dow + 1, 7));
    const window = windowOrders(A, profile, terms, vectors, cal, ctx, ctxNext, best.open_minute, best.close_minute);
    return {
      kind: 'window',
      title: whyTitle(whyFor.place.name, best),
      window,
      vectors,
      terms,
      ctx,
      ctxNext,
      money: stopMoney(profile, terms, window.orders),
    };
  }, [whyFor, simData, A, profile, cal]);
  const whyFailed = whyFor !== null && whySubject === null && (sim.isError || simData !== undefined);

  // Development only: the browser's figure for the window against the one the server ranked with.
  useEffect(() => {
    if (!import.meta.env.DEV || whySubject === null || whyFor === null) return;
    const local = whySubject.window.orders.value;
    const server = whyFor.result.orders.value;
    if (!withinTolerance(local, server)) console.warn('[truck] estimator drift', 'scout ' + whyFor.place.place_key, { local, server });
  }, [whySubject, whyFor]);

  const [saveFor, setSaveFor] = useState<ScoutCandidate | null>(null);

  // ---- What is on the page ----------------------------------------------------------------------
  const heading = (
    <h1 className="flex items-center gap-2 text-2xl font-extrabold" style={{ color: 'var(--ink)' }}>
      <Compass size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> {SCOUT.title}
    </h1>
  );

  if (list === null) {
    const needsRegion = query.isError && apiErrorStatus(query.error) === 409 && apiErrorMessage(query.error, '') === SCOUT_NEEDS_REGION;
    return (
      <div className="space-y-4">
        {heading}
        {needsRegion ? (
          <EmptyState icon={Compass} title={SCOUT.noRegionTitle} body={SCOUT.noRegionBody} />
        ) : query.isError ? (
          <QueryError
            message={apiErrorMessage(query.error, SCOUT.loadFailed) ?? SCOUT.loadFailed}
            onRetry={() => {
              void query.refetch();
            }}
          />
        ) : (
          <div className="grid gap-4 lg:grid-cols-[260px_minmax(0,1fr)]" aria-busy="true">
            <SkeletonCard height={320} className="hidden lg:block" />
            <SkeletonRows rows={4} rowHeight={120} />
          </div>
        )}
        <PermissionNotice variant="block" />
      </div>
    );
  }

  const filters = (prefix: string) => <ScoutFilters idPrefix={prefix} state={state} onChange={setState} counties={counties} />;
  const visibleGroups = groups.filter((g) => g.candidates.length > 0);
  const whyStateOf = (candidate: ScoutCandidate): 'idle' | 'loading' | 'failed' => {
    if (whyFor === null || whyFor.place.place_key !== candidate.place.place_key || whySubject !== null) return 'idle';
    return whyFailed ? 'failed' : 'loading';
  };
  const canAskMore =
    kind !== null && group !== null && deepGroup === null && list.quota > 0 && group.listed >= list.quota && group.screened > group.listed;
  const loadingMore = kind !== null && deepGroup === null && deepQuery.isFetching;

  let body;
  if (list.candidates.length === 0) {
    body = (
      <EmptyState
        icon={Compass}
        title={SCOUT.noneTitle}
        body={SCOUT.noneBody}
        action={{ label: SCOUT.noCountiesLink, to: '/truck/settings/truck' }}
        secondary={filterCount > 0 ? { label: SCOUT.clearFilters, onClick: () => setState(clearedFilters(state)) } : undefined}
      />
    );
  } else if (kind === null) {
    body =
      visibleGroups.length === 0 ? (
        <NoMatch text={SCOUT.noneFiltered} onClear={() => setState(clearedFilters(state))} />
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          {visibleGroups.map((g) => (
            <KindDigest
              key={g.kind}
              group={g}
              counties={regionCounties}
              top={OVERVIEW_TOP}
              onSeeAll={() => pickKind(g.kind)}
              onPick={(candidate) => {
                pickKind(g.kind);
                setFocusKey(candidate.place.place_key);
              }}
            />
          ))}
        </div>
      );
  } else if (shownGroup !== null) {
    body = (
      <section aria-labelledby="tp-scout-kind-title" className="space-y-3">
        <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
          <div className="min-w-0">
            <h2 id="tp-scout-kind-title" className="text-lg font-extrabold leading-snug" style={{ color: 'var(--ink)' }}>
              {shownGroup.label}
            </h2>
            <p className="text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
              {shownGroup.hostFit !== null ? hostFitSentence(shownGroup.hostFit) + ' ' : ''}
              {kindCountLine(shownGroup, cards.length)}
            </p>
          </div>
          <button type="button" className="btn btn-secondary h-11 md:h-9 flex-none px-3 text-sm" onClick={() => pickKind(null)}>
            <ChevronLeft size={15} aria-hidden /> {SCOUT.allKinds}
          </button>
        </div>

        {cards.length === 0 ? (
          <NoMatch text={SCOUT.noneOfKind} onClear={filterCount > 0 ? () => setState(clearedFilters(state)) : undefined} />
        ) : (
          <div className="space-y-3">
            {cards.map((candidate) => (
              <ScoutCard
                key={candidate.place.place_key}
                candidate={candidate}
                counties={regionCounties}
                whyState={whyStateOf(candidate)}
                onWhy={() => setWhyFor(candidate)}
                onSave={() => setSaveFor(candidate)}
                onStatus={(status, previous) => onStatus(candidate, status, previous)}
              />
            ))}
          </div>
        )}

        {canAskMore || loadingMore || deepQuery.isError ? (
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <button
              type="button"
              className="btn btn-secondary h-11 md:h-9 px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
              disabled={loadingMore}
              onClick={() => {
                if (kind === null) return;
                if (moreAsked.indexOf(kind) < 0) setMoreAsked([...moreAsked, kind]);
                else void deepQuery.refetch();
              }}
            >
              {loadingMore ? <span className="spinner" aria-hidden /> : null}
              {loadingMore ? SCOUT.showingMore : SCOUT.showMore}
            </button>
            {deepQuery.isError && !loadingMore ? (
              <span role="alert" className="flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
                <TriangleAlert size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--money-negative)' }} />
                {apiErrorMessage(deepQuery.error, SCOUT.moreFailed) ?? SCOUT.moreFailed}
              </span>
            ) : null}
          </div>
        ) : null}
      </section>
    );
  } else {
    body = <NoMatch text={SCOUT.noneOfKind} onClear={() => setState(clearedFilters(state))} />;
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        {heading}
        <button
          type="button"
          className="btn btn-secondary ml-auto h-11 md:h-9 px-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
          disabled={refreshing}
          onClick={() => {
            void refresh();
          }}
        >
          {refreshing ? <span className="spinner" aria-hidden /> : <RefreshCw size={14} aria-hidden />}
          {refreshing ? SCOUT.refreshing : SCOUT.refresh}
        </button>
      </div>

      <div className="max-w-3xl space-y-1.5">
        <p className="text-sm font-medium" style={{ color: 'var(--body)' }}>
          {scoutIntro(list.limit_minutes)}
        </p>
        <p className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
          {SCOUT.askLine}
        </p>
      </div>

      {list.licence_counties.length === 0 ? (
        <p
          role="status"
          className={CARD + ' flex items-start gap-2 p-3 text-[13px] font-semibold'}
          style={{ borderColor: 'var(--line-soft)', color: 'var(--ink)' }}
        >
          <Info size={15} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
          <span>
            {SCOUT.noCounties}{' '}
            <Link to="/truck/settings/truck" className="font-bold underline underline-offset-2">
              {SCOUT.noCountiesLink}
            </Link>
          </span>
        </p>
      ) : null}

      <p className="text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
        {countLine(shownTotal, list.screened)}
        {list.truncated ? (
          <span className="font-semibold" style={{ color: 'var(--body)' }}>
            {' '}
            {SCOUT.truncated}
          </span>
        ) : null}
      </p>

      {/* Below 1024 px: the kind switch as one select, the filters behind their button. */}
      <div className="flex items-end gap-2 lg:hidden">
        <KindSelect id="tp-scout-kind-select" groups={groups} kind={kind} onKind={pickKind} />
        <button type="button" className="btn btn-secondary h-11 flex-none px-3 text-sm" onClick={() => setFiltersOpen(true)}>
          <SlidersHorizontal size={15} aria-hidden /> {SCOUT.filters} ({fmtCount(filterCount)})
        </button>
      </div>

      <div className="grid gap-4 lg:grid-cols-[260px_minmax(0,1fr)] lg:items-start">
        {/* From 1024 px: the rail stays under the sub-navigation and scrolls on its own when it is taller than the window. */}
        <aside className="hidden space-y-3 lg:sticky lg:top-[110px] lg:block lg:max-h-[calc(100dvh_-_126px)] lg:overflow-y-auto lg:[scrollbar-width:thin]">
          <div className={CARD + ' p-2'} style={{ borderColor: 'var(--line-soft)' }}>
            <KindNav groups={groups} kind={kind} onKind={pickKind} />
          </div>
          <section aria-label={SCOUT.filters} className={CARD + ' p-4'} style={{ borderColor: 'var(--line-soft)' }}>
            {filters('tp-scout-rail-')}
          </section>
        </aside>
        <div ref={results} className="min-w-0 scroll-mt-28">
          {body}
        </div>
      </div>

      <footer className="space-y-2">
        <SourceLine kinds={['osm_sentence', 'drive']} />
        <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {contactShareLine(list)} {SCOUT.footerGoogle}
        </p>
        <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {SCOUT.footerSizes}
        </p>
        <PermissionNotice variant="block" />
      </footer>

      <Sheet
        open={filtersOpen}
        onClose={() => setFiltersOpen(false)}
        title={SCOUT.filters}
        footer={
          <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={() => setFiltersOpen(false)}>
            Show {placesLabel(shownTotal)}
          </button>
        }
      >
        {filters('tp-scout-sheet-')}
      </Sheet>

      <WhyDrawer open={whySubject !== null} onClose={() => setWhyFor(null)} subject={whySubject} />
      <SaveLeadModal candidate={saveFor} onClose={() => setSaveFor(null)} />
    </div>
  );
}

/** Nothing passes the filters: say so, and offer the way back. */
function NoMatch({ text, onClear }: { text: string; onClear?: () => void }) {
  return (
    <div className={CARD + ' p-4 sm:p-5'} style={{ borderColor: 'var(--line-soft)' }}>
      <p className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
        {text}
      </p>
      {onClear !== undefined ? (
        <button type="button" className="btn btn-secondary mt-3 h-11 md:h-9 px-3 text-sm" onClick={onClear}>
          {SCOUT.clearFilters}
        </button>
      ) : null}
    </div>
  );
}

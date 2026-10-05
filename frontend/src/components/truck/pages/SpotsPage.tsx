import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Plus, Search, Store } from 'lucide-react';
import { apiErrorMessage } from '../../../api/truck';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { fmtCount } from '../../../utils/truck/format';
import { COMPARE_MAX, orderSpots, spotMatches, spotSummaries, type SpotSort } from '../../../utils/truck/spotSummary';
import { regionRebuilding, useSpots, useTruck } from '../data';
import SpotForm from '../spot/SpotForm';
import SpotTable, { type SpotTableRow } from '../spots/SpotTable';
import { EmptyState, Modal, PermissionNotice, QueryError, SkeletonCard, SkeletonRows } from '../ui';

const LOAD_FAILED = 'Could not load your spots.';
const NO_INITIAL = {};

/**
 * Saved spots (docs/truck-planner/05_FRONTEND.md 4.4): every spot with the best window of the
 * typical week, its orders and what it leaves after food and fees, each as a range with its label.
 * The figures are computed in the browser from each spot's stored vectors, with the window length
 * chosen on the spot card, so the list and the card agree.
 *
 * The address keeps the list state: `?q=` the search, `?sort=` (`best`, `name`), and `?new=1`
 * opens "Add a spot".
 */
export default function SpotsPage() {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const { A, profile, cal, region } = useTruck();
  const hours = useTruckUiStore((s) => s.windowHours);
  const ticked = useTruckUiStore((s) => s.compareIds);
  const patchUi = useTruckUiStore((s) => s.patch);
  const query = useSpots({ archived: false });
  const spots = query.data;

  const sort: SpotSort = params.get('sort') === 'name' ? 'name' : 'best';
  const adding = params.get('new') === '1';
  const [search, setSearch] = useState(() => params.get('q') ?? '');

  /** One change of the address; the other parameters stay as they are. */
  const setParam = (key: string, value: string | null) => {
    setParams(
      (current) => {
        const next = new URLSearchParams(current);
        if (value === null || value === '') next.delete(key);
        else next.set(key, value);
        return next;
      },
      { replace: true },
    );
  };

  // The search text reaches the address a moment after the last keystroke.
  const urlSearch = params.get('q') ?? '';
  useEffect(() => {
    if (search === urlSearch) return undefined;
    const timer = window.setTimeout(() => setParam('q', search), 300);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, urlSearch]);

  const summaries = useMemo(() => (spots === undefined ? [] : spotSummaries(spots, A, profile, cal, hours)), [spots, A, profile, cal, hours]);

  const rows = useMemo((): SpotTableRow[] => {
    if (spots === undefined) return [];
    const byId = new Map(spots.map((spot) => [spot.id, spot]));
    const summaryById = new Map(summaries.map((summary) => [summary.spotId, summary]));
    const out: SpotTableRow[] = [];
    for (const id of orderSpots(spots, summaries, sort)) {
      const spot = byId.get(id);
      const summary = summaryById.get(id);
      if (spot !== undefined && summary !== undefined && spotMatches(spot, search)) out.push({ spot, summary });
    }
    return out;
  }, [spots, summaries, sort, search]);

  // Only spots that are still in the list can be compared: a deleted one drops out of the count.
  const known = spots === undefined ? ticked : ticked.filter((id) => spots.some((spot) => spot.id === id));
  const canCompare = known.length >= 2 && known.length <= COMPARE_MAX;

  const tick = (id: string, on: boolean) => {
    const next = on ? [...known.filter((other) => other !== id), id] : known.filter((other) => other !== id);
    patchUi({ compareIds: next });
  };

  const heading = (
    <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
      <Store size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Spots
    </h1>
  );

  const addModal = (
    <Modal open={adding} onClose={() => setParam('new', null)} title="Add a spot" size="lg">
      <SpotForm
        mode="create"
        initial={NO_INITIAL}
        presentation="modal"
        onSaved={(spot) => navigate('/truck/spots/' + encodeURIComponent(spot.id))}
        onCancel={() => setParam('new', null)}
      />
    </Modal>
  );

  if (spots === undefined) {
    return (
      <div className="space-y-4">
        {heading}
        {query.isError ? (
          <QueryError
            message={apiErrorMessage(query.error, LOAD_FAILED) ?? LOAD_FAILED}
            onRetry={() => {
              void query.refetch();
            }}
          />
        ) : (
          <div className="space-y-3" aria-busy="true">
            <SkeletonCard height={44} />
            <SkeletonRows rows={4} rowHeight={64} />
          </div>
        )}
        <PermissionNotice variant="line" />
      </div>
    );
  }

  if (spots.length === 0) {
    return (
      <div className="space-y-4">
        {heading}
        <EmptyState
          icon={Store}
          title="No spots yet"
          body="Open the map, click a point and save it. Or add one by address."
          action={{ label: 'Open the map', to: '/truck/map' }}
          secondary={{ label: 'Add by address', onClick: () => setParam('new', '1') }}
        />
        <PermissionNotice variant="line" />
        {addModal}
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        {heading}
        <span className="text-sm font-bold tabular-nums" style={{ color: 'var(--body)' }}>
          {fmtCount(spots.length)} saved
        </span>
        <div className="ml-auto flex flex-wrap items-center gap-2">
          {canCompare ? (
            <Link to={'/truck/spots/compare?ids=' + known.map(encodeURIComponent).join(',')} className="btn btn-secondary h-11 md:h-9 px-3 text-sm">
              Compare ({fmtCount(known.length)})
            </Link>
          ) : (
            <button
              type="button"
              className="btn btn-secondary h-11 md:h-9 px-3 text-sm"
              disabled
              title="Tick two to four spots to compare them."
              style={{ opacity: 0.55, cursor: 'not-allowed' }}
            >
              Compare ({fmtCount(known.length)})
            </button>
          )}
          <button type="button" className="btn btn-primary h-11 md:h-9 px-3 text-sm" onClick={() => setParam('new', '1')}>
            <Plus size={15} aria-hidden /> Add spot
          </button>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-0 flex-1 sm:max-w-xs">
          <Search size={15} aria-hidden className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--slate)' }} />
          <input
            type="search"
            className="input h-11 md:h-9 text-sm font-semibold"
            style={{ paddingLeft: 34 }}
            placeholder="Search spots"
            aria-label="Search spots"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <select
          className="select h-11 md:h-9 text-sm font-semibold"
          style={{ width: 'auto', paddingTop: 0, paddingBottom: 0 }}
          aria-label="Order of the list"
          value={sort}
          onChange={(e) => setParam('sort', e.target.value === 'name' ? 'name' : null)}
        >
          <option value="best">Best window first</option>
          <option value="name">Name</option>
        </select>
      </div>

      <SpotTable
        rows={rows}
        ticked={known}
        tickLimit={COMPARE_MAX}
        onTick={tick}
        onOpen={(id) => navigate('/truck/spots/' + encodeURIComponent(id))}
        hrefOf={(id) => '/truck/spots/' + encodeURIComponent(id)}
        rebuilding={regionRebuilding(region)}
        empty={'No spot matches "' + search.trim() + '".'}
      />

      <PermissionNotice variant="line" />
      {addModal}
    </div>
  );
}

import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Store, X } from 'lucide-react';
import { apiErrorMessage, type DrivePoint, type Spot } from '../../../api/truck';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { trafficIsNeutral } from '../../../utils/truck/assemble';
import { fmtWeekday } from '../../../utils/truck/format';
import {
  COMPARE_MAX,
  formatCompareWindow,
  highestExpected,
  parseCompareIds,
  parseCompareWindow,
  spotOneStopDay,
  spotSummaries,
  spotWindowFigures,
  typicalWeek,
  typicalWeekWithFuel,
  type WindowChoice,
} from '../../../utils/truck/spotSummary';
import { SPOT_STATE_TAGS, STRIPS } from '../../../utils/truck/wording';
import { regionRebuilding, useDriveTimes, useNow, useSpots, useTruck } from '../data';
import CompareTable, { type CompareColumn } from '../spots/CompareTable';
import { PermissionNotice, QueryError, SkeletonCard, Tabs, TimeField } from '../ui';

const LOAD_FAILED = 'Could not load your spots.';
const CARD = 'bg-white rounded-xl border p-4 sm:p-5';
const NO_POINTS: DrivePoint[] = [];
const DAYS = [0, 1, 2, 3, 4, 5, 6];
/** Where "The same window" starts when no spot has a best window: a weekday lunch. */
const DEFAULT_WINDOW: WindowChoice = { dow: 3, open: 660, close: 840 };

interface SameWindow {
  dow: number;
  open: number | null;
  close: number | null;
}

/**
 * Two to four spots side by side (docs/truck-planner/05_FRONTEND.md 4.4). The address says what is
 * compared: `?ids=a,b,c` and `?win=` (`best`, or one window for every spot written `dow-open-close`).
 *
 * Every figure is computed in the browser from each spot's stored vectors, with the same inputs
 * the spot card uses, and the drive of all columns comes from one request (base to each spot and
 * back).
 */
export default function SpotComparePage() {
  const [params, setParams] = useSearchParams();
  const { A, profile, cal, fuel, region } = useTruck();
  const today = useNow().date;
  const hours = useTruckUiStore((s) => s.windowHours);
  const patchUi = useTruckUiStore((s) => s.patch);
  const query = useSpots({ archived: false });
  const spots = query.data;

  const idsParam = params.get('ids');
  const ids = useMemo(() => parseCompareIds(idsParam), [idsParam]);
  const winParam = params.get('win');
  const urlWindow = parseCompareWindow(winParam);
  const [same, setSame] = useState<SameWindow>(() => (urlWindow === 'best' ? DEFAULT_WINDOW : urlWindow));
  const mode: 'best' | 'same' = urlWindow === 'best' ? 'best' : 'same';
  // The address leads: a window that arrives with it (a link, the back button) fills the fields.
  useEffect(() => {
    const fromAddress = parseCompareWindow(winParam);
    if (fromAddress !== 'best') setSame(fromAddress);
  }, [winParam]);

  const chosen = useMemo((): Spot[] => {
    if (spots === undefined) return [];
    const out: Spot[] = [];
    for (const id of ids) {
      const spot = spots.find((s) => s.id === id);
      if (spot !== undefined) out.push(spot);
    }
    return out;
  }, [spots, ids]);

  const setIds = (next: string[]) => {
    const kept = next.slice(0, COMPARE_MAX);
    patchUi({ compareIds: kept });
    setParams(
      (current) => {
        const p = new URLSearchParams(current);
        if (kept.length === 0) p.delete('ids');
        else p.set('ids', kept.join(','));
        return p;
      },
      { replace: true },
    );
  };

  const setWindow = (win: 'best' | WindowChoice) => {
    setParams(
      (current) => {
        const p = new URLSearchParams(current);
        if (win === 'best') p.delete('win');
        else p.set('win', formatCompareWindow(win));
        return p;
      },
      { replace: true },
    );
  };

  const summaries = useMemo(() => spotSummaries(chosen, A, profile, cal, hours), [chosen, A, profile, cal, hours]);
  const typical = useMemo(() => typicalWeek(A), [A]);
  const typicalFuel = useMemo(() => typicalWeekWithFuel(A, fuel), [A, fuel]);

  // One drive-time request for the whole comparison: base to each spot and back.
  const points = useMemo((): DrivePoint[] => {
    if (chosen.length === 0) return NO_POINTS;
    return [{ id: 'base', lat: profile.base.lat, lng: profile.base.lng }, ...chosen.map((s) => ({ id: s.id, lat: s.point.lat, lng: s.point.lng }))];
  }, [chosen, profile.base.lat, profile.base.lng]);
  const pairs = useMemo((): [string, string][] => chosen.flatMap((s): [string, string][] => [['base', s.id], [s.id, 'base']]), [chosen]);
  const drive = useDriveTimes(points, pairs);

  const sameChoice: WindowChoice | null =
    same.open !== null && same.close !== null && same.close > same.open ? { dow: same.dow, open: same.open, close: same.close } : null;
  const rebuilding = regionRebuilding(region);

  const columns = useMemo((): CompareColumn[] => {
    const built = chosen.map((spot, i): CompareColumn => {
      const summary = summaries[i];
      const choice = mode === 'best' ? summary.best : sameChoice;
      const figures = choice === null ? null : mode === 'best' && summary.window !== null && summary.money !== null
        ? { window: summary.window, money: summary.money }
        : spotWindowFigures(spot, A, profile, cal, typical, choice);
      const sentLeg = drive.legs.find((l) => l.from_id === 'base' && l.to_id === spot.id) ?? null;
      // While the legs of this set of spots are on their way a spot without its own leg waits.
      const dayPending = drive.status === 'pending' || (drive.updating && sentLeg === null);
      const day = choice === null || figures === null || dayPending ? null : spotOneStopDay(spot, A, profile, cal, typicalFuel, today, drive.legInputs, choice);
      const stale = spot.vectors_state === 'stale' || summary.state === 'mismatch';
      return {
        spot,
        state: summary.state,
        staleWord: stale ? (rebuilding ? SPOT_STATE_TAGS.rebuilding : SPOT_STATE_TAGS.stale) : null,
        choice: figures === null ? (mode === 'best' ? null : choice) : choice,
        window: figures === null ? null : figures.window,
        money: figures === null ? null : figures.money,
        day,
        dayPending,
        sentLeg,
        week: summary.week,
        services: Object.prototype.hasOwnProperty.call(cal.spots, spot.id) ? cal.spots[spot.id].n : 0,
        highest: false,
      };
    });
    const withDay = built.filter((c) => c.day !== null && c.day.stops.length > 0);
    // "Highest expected" only says something when there is more than one figure to compare.
    const top = withDay.length >= 2 ? highestExpected(withDay.map((c) => ({ id: c.spot.id, takeHome: c.day === null ? null : c.day.totals.take_home }))) : null;
    return built.map((c) => (c.spot.id === top ? { ...c, highest: true } : c));
  }, [chosen, summaries, mode, sameChoice?.dow, sameChoice?.open, sameChoice?.close, A, profile, cal, typical, typicalFuel, today, drive.legs, drive.legInputs, drive.status, drive.updating, rebuilding]);

  const heading = (
    <>
      <Link
        to="/truck/spots"
        className="inline-flex min-h-[44px] md:min-h-0 items-center gap-1.5 text-sm font-bold underline underline-offset-2"
        style={{ color: 'var(--ink)' }}
      >
        <ArrowLeft size={15} aria-hidden /> All spots
      </Link>
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <Store size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Compare spots
      </h1>
    </>
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
            <SkeletonCard height={96} />
            <SkeletonCard height={360} />
          </div>
        )}
        <PermissionNotice variant="line" />
      </div>
    );
  }

  const chosenIds = chosen.map((s) => s.id);
  const others = spots.filter((s) => !chosenIds.includes(s.id));
  const straightLines = columns.some((c) => c.day !== null && c.day.timeline.legs.some((leg) => leg.source === 'fallback'));

  if (chosen.length < 2) {
    return (
      <div className="space-y-4">
        {heading}
        <section className={CARD} style={{ borderColor: 'var(--line-soft)' }}>
          <p className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
            Pick at least two spots to compare.
          </p>
          {spots.length === 0 ? (
            <p className="mt-2 text-sm font-semibold" style={{ color: 'var(--body)' }}>
              You have no saved spots yet.{' '}
              <Link to="/truck/spots" className="underline underline-offset-2" style={{ color: 'var(--ink)' }}>
                Go to Spots
              </Link>
            </p>
          ) : (
            <ul className="mt-3 grid gap-1.5 sm:grid-cols-2">
              {spots.map((spot) => {
                const on = chosenIds.includes(spot.id);
                return (
                  <li key={spot.id}>
                    <label
                      className={'flex min-h-[44px] cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2' + (on ? '' : ' bg-white')}
                      style={{ borderColor: on ? 'var(--brand)' : 'var(--line)', background: on ? 'var(--brand-light)' : undefined }}
                    >
                      <input
                        type="checkbox"
                        className="h-4 w-4 flex-none"
                        style={{ accentColor: 'var(--brand)' }}
                        checked={on}
                        onChange={(e) => setIds(e.target.checked ? [...chosenIds, spot.id] : chosenIds.filter((id) => id !== spot.id))}
                      />
                      <span className="min-w-0 text-sm font-bold" style={{ color: 'var(--ink)' }}>
                        {spot.name}
                        {spot.address !== '' ? (
                          <span className="block text-xs font-semibold" style={{ color: 'var(--body)' }}>
                            {spot.address}
                          </span>
                        ) : null}
                      </span>
                    </label>
                  </li>
                );
              })}
            </ul>
          )}
        </section>
        <PermissionNotice variant="line" />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {heading}

      <section className={CARD + ' space-y-3'} style={{ borderColor: 'var(--line-soft)' }}>
        <div className="flex flex-wrap items-center gap-2">
          {chosen.map((spot) => (
            <span
              key={spot.id}
              className="inline-flex h-11 md:h-8 max-w-full items-center gap-1 rounded-full pl-3 pr-1 text-sm font-bold"
              style={{ background: 'var(--bg-panel)', color: 'var(--ink)' }}
            >
              <span className="truncate">{spot.name}</span>
              <button
                type="button"
                className="inline-flex h-9 w-9 md:h-6 md:w-6 flex-none items-center justify-center rounded-full"
                style={{ color: 'var(--body)' }}
                aria-label={'Remove ' + spot.name + ' from the comparison'}
                onClick={() => setIds(chosenIds.filter((id) => id !== spot.id))}
              >
                <X size={14} aria-hidden />
              </button>
            </span>
          ))}
          {chosen.length < COMPARE_MAX && others.length > 0 ? (
            <select
              className="select h-11 md:h-8! text-sm font-semibold"
              style={{ width: 'auto', maxWidth: '100%', paddingTop: 0, paddingBottom: 0 }}
              aria-label="Add spot"
              value=""
              onChange={(e) => {
                if (e.target.value !== '') setIds([...chosenIds, e.target.value]);
              }}
            >
              <option value="">Add spot</option>
              {others.map((spot) => (
                <option key={spot.id} value={spot.id}>
                  {spot.name}
                </option>
              ))}
            </select>
          ) : null}
        </div>

        <div className="flex flex-wrap items-end gap-x-4 gap-y-3">
          <div>
            <div className="label">Compare on</div>
            <Tabs
              variant="segmented"
              ariaLabel="Compare on"
              value={mode}
              onChange={(id) => {
                if (id === 'best') setWindow('best');
                else {
                  // Start from the first spot's best window, so the first figures are familiar.
                  const first = summaries.length > 0 && summaries[0].best !== null ? summaries[0].best : DEFAULT_WINDOW;
                  setSame(first);
                  setWindow(first);
                }
              }}
              tabs={[
                { id: 'best', label: "Each spot's best window" },
                { id: 'same', label: 'The same window' },
              ]}
            />
          </div>
          {mode === 'same' ? (
            <>
              <div>
                <label htmlFor="tp-compare-dow" className="label">
                  Day
                </label>
                <select
                  id="tp-compare-dow"
                  className="select h-11 md:h-9 text-sm font-semibold"
                  style={{ width: 'auto', paddingTop: 0, paddingBottom: 0 }}
                  value={same.dow}
                  onChange={(e) => {
                    const next = { ...same, dow: Number(e.target.value) };
                    setSame(next);
                    if (next.open !== null && next.close !== null && next.close > next.open) setWindow({ dow: next.dow, open: next.open, close: next.close });
                  }}
                >
                  {DAYS.map((dow) => (
                    <option key={dow} value={dow}>
                      {fmtWeekday(dow, 'long')}
                    </option>
                  ))}
                </select>
              </div>
              <TimeField
                id="tp-compare-open"
                label="From"
                className="w-[190px]"
                value={same.open}
                onCommit={(open) => {
                  const next = { ...same, open };
                  setSame(next);
                  if (next.open !== null && next.close !== null && next.close > next.open) setWindow({ dow: next.dow, open: next.open, close: next.close });
                }}
              />
              <TimeField
                id="tp-compare-close"
                label="Until"
                className="w-[230px]"
                value={same.close}
                after={same.open === null ? undefined : same.open}
                allowNextDay
                error={same.open !== null && same.close !== null && !(same.close > same.open) ? 'The closing time must be after the opening time.' : undefined}
                onCommit={(close) => {
                  const next = { ...same, close };
                  setSame(next);
                  if (next.open !== null && next.close !== null && next.close > next.open) setWindow({ dow: next.dow, open: next.open, close: next.close });
                }}
              />
            </>
          ) : null}
        </div>
        <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
          A typical week: no weather and no holiday. The one-stop day adds prep, the drive from your base, wages and fuel.
        </p>
      </section>

      {drive.status === 'error' || straightLines ? (
        <p role="status" className="rounded-xl border px-3 py-2.5 text-sm font-semibold" style={{ background: 'var(--fresh-aging-bg)', borderColor: 'var(--line-soft)', color: 'var(--ink)' }}>
          {STRIPS.driveTimesUnavailable}
        </p>
      ) : null}

      <CompareTable columns={columns} trafficNeutral={trafficIsNeutral(A)} rebuilding={rebuilding} />

      <PermissionNotice variant="line" />
    </div>
  );
}

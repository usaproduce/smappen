import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Archive, ArrowLeft, ChevronDown, ChevronUp, Ellipsis, Store, Trash2, TriangleAlert } from 'lucide-react';
import { apiErrorMessage, apiErrorStatus, type Spot } from '../../../api/truck';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { trafficIsNeutral } from '../../../utils/truck/assemble';
import { fmtDuration, fmtMiles, fmtWeekday, fmtWindow } from '../../../utils/truck/format';
import { COMPARE_MAX, allowedDaysText, choiceOfBest, feeText, hostText, spotBodyOf, type WindowChoice } from '../../../utils/truck/spotSummary';
import { SPOT_STATE_TAGS, STANDING, VISIBILITY_TEXT, driveFallbackReason, driveSourceLabel } from '../../../utils/truck/wording';
import ErrorBoundary from '../../ErrorBoundary';
import { useArchiveSpot, useNow, useSpot, useSpotEstimate, useTruck } from '../data';
import SpotAnalysis from '../spot/SpotAnalysis';
import SpotForm from '../spot/SpotForm';
import SpotResults from '../spots/SpotResults';
import { Modal, OpenInMaps, PermissionNotice, QueryError, SkeletonCard, SkeletonRows, StatList, StatRow } from '../ui';

const LOAD_FAILED = 'Could not load this spot.';
const CARD = 'bg-white rounded-xl border p-4 sm:p-5';
/** The window the drive is timed for when the week has no best window: a weekday lunch. */
const DEFAULT_WINDOW: WindowChoice = { dow: 3, open: 660, close: 840 };

/**
 * One saved spot (docs/truck-planner/05_FRONTEND.md 4.4): its analysis, its terms, the drive from
 * the base and what was logged there. Desktop: terms and results on the left, the analysis on the
 * right. Below that: one column, the analysis first.
 *
 * An unknown id shows "This spot no longer exists."; a spot the owner deleted, reached by a direct
 * link, shows the tag "Deleted" and no actions.
 */
export default function SpotDetailPage() {
  const { spotId } = useParams();
  const query = useSpot(spotId);
  const spot = query.data;

  if (spot === undefined) {
    const missing = query.isError && apiErrorStatus(query.error) === 404;
    return (
      <div className="space-y-4">
        <BackLink />
        {missing || spotId === undefined ? (
          <section className={CARD} style={{ borderColor: 'var(--line-soft)' }}>
            <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
              <Store size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Spot
            </h1>
            <p className="mt-2 text-sm font-bold" style={{ color: 'var(--ink)' }}>
              This spot no longer exists.
            </p>
            <Link to="/truck/spots" className="btn btn-secondary mt-3 h-11 md:h-9 px-3 text-sm">
              All spots
            </Link>
          </section>
        ) : query.isError ? (
          <QueryError
            message={apiErrorMessage(query.error, LOAD_FAILED) ?? LOAD_FAILED}
            onRetry={() => {
              void query.refetch();
            }}
          />
        ) : (
          <div className="space-y-4" aria-busy="true">
            <SkeletonCard height={72} />
            <div className="grid gap-4 lg:grid-cols-12">
              <SkeletonRows rows={3} rowHeight={120} className="lg:col-span-5" />
              <SkeletonCard height={420} className="lg:col-span-7" />
            </div>
          </div>
        )}
        <PermissionNotice variant="line" />
      </div>
    );
  }

  if (spot.archived) return <DeletedSpot spot={spot} />;
  // Keyed by the spot: another spot starts with its own form and its own menus.
  return <SavedSpot key={spot.id} spot={spot} />;
}

function BackLink() {
  return (
    <Link
      to="/truck/spots"
      className="inline-flex min-h-[44px] md:min-h-0 items-center gap-1.5 text-sm font-bold underline underline-offset-2"
      style={{ color: 'var(--ink)' }}
    >
      <ArrowLeft size={15} aria-hidden /> All spots
    </Link>
  );
}

function SavedSpot({ spot }: { spot: Spot }) {
  const navigate = useNavigate();
  const today = useNow().date;
  const compareIds = useTruckUiStore((s) => s.compareIds);
  const patchUi = useTruckUiStore((s) => s.patch);
  const archive = useArchiveSpot();
  const [confirming, setConfirming] = useState(false);
  const [termsOpen, setTermsOpen] = useState(false);
  const termsId = useId();
  const initial = useMemo(() => spotBodyOf(spot), [spot]);
  const id = encodeURIComponent(spot.id);

  const compare = () => {
    // This spot joins the ones already ticked; the oldest tick makes room when four are taken.
    const others = compareIds.filter((other) => other !== spot.id);
    const next = [...others.slice(Math.max(0, others.length - (COMPARE_MAX - 1))), spot.id];
    patchUi({ compareIds: next });
    navigate('/truck/spots/compare?ids=' + next.map(encodeURIComponent).join(','));
  };

  const remove = () => {
    archive
      .mutateAsync(spot.id)
      .then(() => navigate('/truck/spots'))
      .catch(() => {
        // The hook has shown the server's sentence.
      });
  };

  return (
    <div className="space-y-4">
      <BackLink />

      <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
        <div className="min-w-0">
          <h1 className="text-2xl font-extrabold flex items-start gap-2" style={{ color: 'var(--ink)' }}>
            <Store size={22} className="mt-1 flex-none" style={{ color: 'var(--brand)' }} aria-hidden="true" />
            <span className="min-w-0 break-words">{spot.name}</span>
          </h1>
          {spot.address !== '' ? (
            <p className="mt-0.5 text-sm font-semibold" style={{ color: 'var(--body)' }}>
              {spot.address}
            </p>
          ) : null}
          <div className="mt-1">
            <OpenInMaps href={spot.maps_url} />
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Link to={'/truck/plan/' + today + '?add=' + id} className="btn btn-primary h-11 md:h-9 px-3 text-sm">
            Plan a day here
          </Link>
          <Link to={'/truck/log?new=1&spot=' + id} className="btn btn-secondary h-11 md:h-9 px-3 text-sm">
            Log a service
          </Link>
          <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={compare}>
            Compare
          </button>
          <MoreMenu onDelete={() => setConfirming(true)} />
        </div>
      </header>

      <div className="grid gap-4 lg:grid-cols-12">
        <div className="order-2 min-w-0 space-y-4 lg:order-1 lg:col-span-5">
          <section className={CARD} style={{ borderColor: 'var(--line-soft)' }} aria-labelledby={termsId + 'h'}>
            <div className="flex items-center justify-between gap-3">
              <h2 id={termsId + 'h'} className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
                Terms
              </h2>
              <button
                type="button"
                className="inline-flex min-h-[44px] items-center gap-1 text-sm font-bold md:hidden"
                style={{ color: 'var(--ink)' }}
                aria-expanded={termsOpen}
                aria-controls={termsId + 'body'}
                onClick={() => setTermsOpen(!termsOpen)}
              >
                {termsOpen ? <ChevronUp size={16} aria-hidden /> : <ChevronDown size={16} aria-hidden />}
                {termsOpen ? 'Hide' : 'Edit'}
              </button>
            </div>
            {!termsOpen ? (
              <p className="mt-1 text-sm font-semibold md:hidden" style={{ color: 'var(--body)' }}>
                <TermsLine spot={spot} />
              </p>
            ) : null}
            <div id={termsId + 'body'} className={termsOpen ? 'mt-3' : 'mt-3 hidden md:block'}>
              <SpotForm
                mode="edit"
                spotId={spot.id}
                initial={initial}
                presentation="inline"
                onSaved={() => {
                  // The form keeps its place: the analysis beside it follows the saved spot.
                }}
                onCancel={() => setTermsOpen(false)}
              />
            </div>
          </section>

          <section className={CARD} style={{ borderColor: 'var(--line-soft)' }}>
            <h2 className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
              Getting there
            </h2>
            <GettingThere spot={spot} />
          </section>

          <section className={CARD + ' min-w-0'} style={{ borderColor: 'var(--line-soft)' }}>
            <h2 className="mb-3 text-base font-extrabold" style={{ color: 'var(--ink)' }}>
              Your results here
            </h2>
            <SpotResults spotId={spot.id} />
          </section>
        </div>

        <div className="order-1 min-w-0 space-y-4 lg:order-2 lg:col-span-7">
          {/* The analysis draws its own cards on a page; a crash in it leaves the rest of the page standing. */}
          <ErrorBoundary scope="Spot analysis" inline>
            <SpotAnalysis subject={{ kind: 'spot', spot }} layout="page" />
          </ErrorBoundary>
          <PermissionNotice variant="block" />
        </div>
      </div>

      <Modal
        open={confirming}
        onClose={() => setConfirming(false)}
        title={'Delete ' + spot.name + '?'}
        size="sm"
        footer={
          <>
            <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={() => setConfirming(false)} disabled={archive.isPending}>
              Keep it
            </button>
            <button type="button" className="btn btn-danger h-11 md:h-9 px-3 text-sm" onClick={remove} disabled={archive.isPending}>
              <Trash2 size={15} aria-hidden /> {archive.isPending ? 'Deleting...' : 'Delete spot'}
            </button>
          </>
        }
      >
        <p>{STANDING.deleteSpot}</p>
      </Modal>
    </div>
  );
}

/** The terms of a spot in one line, for the closed "Terms" card on a phone and for a deleted spot. */
function TermsLine({ spot }: { spot: Spot }) {
  const allowed = spot.terms.allowed;
  const parts = [feeText(spot.terms), VISIBILITY_TEXT[spot.terms.visibility].label + ' visibility', hostText(spot)];
  if (allowed !== null) {
    parts.push(allowedDaysText(allowed.days, (dow) => fmtWeekday(dow, 'short')) + ', ' + fmtWindow(allowed.open_minute, allowed.close_minute));
  }
  return <>{parts.join(' · ')}</>;
}

/**
 * "Getting there": the drive from the base, timed for the spot's best window of the typical week
 * (a weekday lunch when the week has none), with the label that says where the time comes from.
 */
function GettingThere({ spot }: { spot: Spot }) {
  const { A, profile } = useTruck();
  const est = useSpotEstimate({ spot, terms: spot.terms });
  const choice = est.best.length > 0 ? choiceOfBest(est.best[0]) : DEFAULT_WINDOW;
  const oneStopDay = est.oneStopDay;
  const day = useMemo(() => oneStopDay(choice.dow, choice.open, choice.close), [oneStopDay, choice.dow, choice.open, choice.close]);
  const leg = day !== null && day.timeline.legs.length > 0 ? day.timeline.legs[0] : null;
  const sent = est.driveLegs.find((l) => l.from_id === 'base' && l.to_id === spot.id) ?? null;
  const route = <OpenInMaps route={{ origin: profile.base, destination: spot.point }} />;

  if (est.status === 'none') {
    return (
      <div className="mt-2 space-y-2">
        <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
          {SPOT_STATE_TAGS.none}, so the drive is not timed either.
        </p>
        {route}
      </div>
    );
  }
  if (leg === null) {
    return (
      <div className="mt-2 space-y-2">
        <div aria-busy="true">
          <SkeletonRows rows={1} rowHeight={40} />
        </div>
        {route}
      </div>
    );
  }

  const label = driveSourceLabel({
    legSource: leg.source,
    driveSource: sent === null ? null : sent.source,
    departMinute: leg.depart_minute,
    trafficNeutral: trafficIsNeutral(A),
  });
  const reason = leg.source === 'fallback' ? driveFallbackReason(sent === null ? null : sent.fallback_reason) : null;

  return (
    <div className="mt-1 space-y-2">
      {/* In the narrow column the label keeps its one line; the source under the time wraps instead. */}
      <StatList className="[&_dt]:flex-none">
        <StatRow
          label="Drive from base"
          value={fmtDuration(leg.minutes) + ', ' + fmtMiles(leg.miles)}
          sub={
            <span className="inline-flex items-start justify-end gap-1">
              {leg.source === 'fallback' ? <TriangleAlert size={13} aria-hidden className="mt-px flex-none" style={{ color: 'var(--fresh-aging)' }} /> : null}
              <span>
                {label}
                {reason !== null ? '. ' + reason : ''}
              </span>
            </span>
          }
        />
      </StatList>
      <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
        Timed for {fmtWeekday(choice.dow, 'long')} {fmtWindow(choice.open, choice.close)}
        {est.best.length > 0 ? ', the best window of a typical week.' : '.'}
      </p>
      {route}
    </div>
  );
}

/** The overflow menu of the header: the one action that is not offered as a button. */
function MoreMenu({ onDelete }: { onDelete: () => void }) {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const menuId = useId();

  useEffect(() => {
    if (!open) return undefined;
    const onPointer = (e: PointerEvent) => {
      if (box.current !== null && e.target instanceof Node && !box.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setOpen(false);
        if (trigger.current !== null) trigger.current.focus();
      }
    };
    document.addEventListener('pointerdown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onPointer);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div ref={box} className="relative">
      <button
        ref={trigger}
        type="button"
        className="btn btn-secondary h-11 w-11 md:h-9 md:w-9"
        style={{ padding: 0 }}
        aria-label="More actions"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={() => setOpen(!open)}
      >
        <Ellipsis size={18} aria-hidden />
      </button>
      {open ? (
        <div
          id={menuId}
          role="menu"
          aria-label="More actions"
          className="absolute right-0 top-full mt-1 w-44 rounded-lg border bg-white p-1 shadow-float"
          style={{ borderColor: 'var(--line-soft)', zIndex: 40 }}
        >
          <button
            type="button"
            role="menuitem"
            autoFocus
            className="flex min-h-[44px] md:min-h-[36px] w-full items-center gap-2 rounded-md px-2.5 text-left text-sm font-bold hover:bg-slate-50"
            style={{ color: 'var(--money-negative)' }}
            onClick={() => {
              setOpen(false);
              onDelete();
            }}
          >
            <Trash2 size={15} aria-hidden /> Delete spot
          </button>
        </div>
      ) : null}
    </div>
  );
}

/**
 * A spot the owner deleted, reached by a direct link (an old plan, an old log): what it was and
 * what was logged there, with the tag "Deleted" and nothing to do with it. Its analysis still
 * shows: days planned there keep their estimates.
 */
function DeletedSpot({ spot }: { spot: Spot }) {
  return (
    <div className="space-y-4">
      <BackLink />
      <header>
        <h1 className="text-2xl font-extrabold flex flex-wrap items-center gap-2" style={{ color: 'var(--ink)' }}>
          <Store size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" />
          <span className="min-w-0 break-words">{spot.name}</span>
          <span className="tp-chip">
            <Archive size={12} strokeWidth={2.75} aria-hidden />
            {SPOT_STATE_TAGS.archived}
          </span>
        </h1>
        {spot.address !== '' ? (
          <p className="mt-0.5 text-sm font-semibold" style={{ color: 'var(--body)' }}>
            {spot.address}
          </p>
        ) : null}
      </header>
      <div className="grid gap-4 lg:grid-cols-12">
        <div className="order-2 min-w-0 space-y-4 lg:order-1 lg:col-span-5">
          <section className={CARD} style={{ borderColor: 'var(--line-soft)' }}>
            <h2 className="text-base font-extrabold" style={{ color: 'var(--ink)' }}>
              Terms
            </h2>
            <p className="mt-1 text-sm font-semibold" style={{ color: 'var(--ink)' }}>
              <TermsLine spot={spot} />
            </p>
            <p className="mt-2 text-sm font-semibold" style={{ color: 'var(--body)' }}>
              You deleted this spot. Days planned here and the services logged here are kept.
            </p>
          </section>
          <section className={CARD + ' min-w-0'} style={{ borderColor: 'var(--line-soft)' }}>
            <h2 className="mb-3 text-base font-extrabold" style={{ color: 'var(--ink)' }}>
              Your results here
            </h2>
            <SpotResults spotId={spot.id} />
          </section>
        </div>
        <div className="order-1 min-w-0 space-y-4 lg:order-2 lg:col-span-7">
          <ErrorBoundary scope="Spot analysis" inline>
            <SpotAnalysis subject={{ kind: 'spot', spot }} layout="page" />
          </ErrorBoundary>
          <PermissionNotice variant="block" />
        </div>
      </div>
    </div>
  );
}

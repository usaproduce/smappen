import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { REGION_REBUILD_SENTENCE, type HostHint, type Spot } from '../../../api/truck';
import { MAP_DOMAIN, stopMoney, typicalContext } from '../../../utils/truck/model';
import type { LocationVectors, SpotTerms } from '../../../utils/truck/model';
import { trafficIsNeutral, typicalWithFuel } from '../../../utils/truck/assemble';
import { fmtCoord, fmtHowLong } from '../../../utils/truck/format';
import { POINT_TERMS, countyLabel, driveSummary, whoIsHere, windowSlot } from '../../../utils/truck/hourControl';
import { SPOT_STATE_TAGS, WHY } from '../../../utils/truck/wording';
import { useTruckHourStore } from '../../../stores/truckHourStore';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { regionRebuilding, useNow, useSettledHow, useSpotEstimate, useTruck } from '../data';
import {
  OpenInMaps,
  PermissionNotice,
  QueryError,
  SkeletonCard,
  SkeletonChart,
  SkeletonRows,
  SourceLine,
  WhyDrawer,
  type WhySubject,
} from '../ui';
import BestWindows, { windowLabel } from './sections/BestWindows';
import Competition from './sections/Competition';
import MoneySection from './sections/MoneySection';
import ThisHour from './sections/ThisHour';
import WeekSection from './sections/WeekSection';
import WhoIsHere from './sections/WhoIsHere';

export interface SpotAnalysisProps {
  /** A clicked point, or a saved spot. */
  subject: { kind: 'point'; lat: number; lng: number } | { kind: 'spot'; spot: Spot };
  /** Estimate with these terms instead of the subject's own (a form previewing its draft). */
  termsOverride?: SpotTerms;
  /** `card`: inside the map's sheet. `page`: embedded in the spot detail page. */
  layout: 'card' | 'page';
  /** "Save as spot" was pressed; the places that could be the host come along for the form. */
  onSaveAsSpot?: (hosts: HostHint[]) => void;
}

const NO_HOSTS: HostHint[] = [];
const COMPUTE_START = 'tp:spot-compute-start';
const COMPUTE_MEASURE = 'tp:spot-compute';

/** One titled block: a hairline-separated stretch of the card, or a card of its own on a page. */
function Section({ title, sub, page, children }: { title: string; sub?: string; page: boolean; children: ReactNode }) {
  const Heading = page ? 'h2' : 'h3';
  return (
    <section
      className={page ? 'bg-white rounded-xl border p-4 sm:p-5' : 'border-t py-4'}
      style={{ borderColor: 'var(--line-soft)' }}
    >
      <Heading className="text-base font-extrabold leading-snug" style={{ color: 'var(--ink)' }}>
        {title}
      </Heading>
      {sub !== undefined ? (
        <p className="text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
          {sub}
        </p>
      ) : null}
      <div className="mt-2.5">{children}</div>
    </section>
  );
}

/** A state of the whole card in words: outside the area, being rebuilt, no estimate yet. */
function StateBlock({ title, text, page }: { title?: string; text?: string; page: boolean }) {
  const Heading = page ? 'h2' : 'h3';
  return (
    <section
      role="status"
      className={(page ? 'bg-white rounded-xl border p-4 sm:p-5' : 'border-t py-4') + ' space-y-1.5'}
      style={{ borderColor: 'var(--line-soft)' }}
    >
      {title !== undefined ? (
        <Heading className="text-base font-extrabold leading-snug" style={{ color: 'var(--ink)' }}>
          {title}
        </Heading>
      ) : null}
      {text !== undefined ? (
        <p className="text-sm font-semibold" style={{ color: title !== undefined ? 'var(--body)' : 'var(--ink)' }}>
          {text}
        </p>
      ) : null}
    </section>
  );
}

/** Skeleton blocks in the shape of the sections "This hour" to "Money". */
function Pending({ page }: { page: boolean }) {
  return (
    <div aria-busy="true" className={page ? 'space-y-4' : 'space-y-4 border-t py-4'} style={{ borderColor: 'var(--line-soft)' }}>
      <SkeletonCard height={92} />
      <SkeletonRows rows={3} rowHeight={54} />
      <SkeletonChart height={112} />
      <SkeletonRows rows={3} rowHeight={30} />
      <SkeletonCard height={64} />
      <SkeletonRows rows={4} rowHeight={30} />
    </div>
  );
}

/**
 * Everything about one place (docs/truck-planner/05_FRONTEND.md 4.3): expected orders this hour,
 * the best windows of a typical week, the week at a glance, who is there, the competition, and the
 * money of the selected window down to what a one-stop day would clear. The map's spot card wraps
 * it in a sheet; the spot detail page embeds it.
 *
 * Every figure comes from `useSpotEstimate`, which runs the estimator in the browser on exact
 * vectors, and every estimate is printed by `RangeValue`: a value, its range and its label, with
 * "Why this number" one press away. While new vectors are on their way the last matching numbers
 * stay on screen dimmed. Nothing here says whether a truck may trade at the place; the standing
 * notice under the sections says whose job that is.
 */
export default function SpotAnalysis({ subject, termsOverride, layout, onSaveAsSpot }: SpotAnalysisProps) {
  const { A, profile, region, fuel } = useTruck();
  const today = useNow().date;
  const settledHow = useSettledHow(150);
  const windowHours = useTruckUiStore((s) => s.windowHours);
  const page = layout === 'page';

  const spot = subject.kind === 'spot' ? subject.spot : null;
  const lat = subject.kind === 'point' ? subject.lat : subject.spot.point.lat;
  const lng = subject.kind === 'point' ? subject.lng : subject.spot.point.lng;
  const isPoint = subject.kind === 'point';
  const point = useMemo(() => (isPoint ? { lat, lng } : null), [isPoint, lat, lng]);
  const terms = termsOverride ?? (spot !== null ? spot.terms : POINT_TERMS);
  const name = spot !== null ? spot.name : 'This point';

  // 5.9, "tp:spot-compute": from the render that first sees new vectors to the moment they are on screen.
  performance.clearMarks(COMPUTE_START);
  performance.mark(COMPUTE_START);

  const est = useSpotEstimate({ point, spot, terms, withPlaces: spot !== null });

  const measured = useRef<LocationVectors | null>(null);
  const vectors = est.vectors;
  useEffect(() => {
    if (vectors === null || vectors === measured.current) return;
    measured.current = vectors;
    try {
      performance.measure(COMPUTE_MEASURE, COMPUTE_START);
    } catch {
      // The start mark is gone (another render cleared it): nothing to measure this time.
    }
  }, [vectors]);

  // The window the money section adds up: best window 1 until a row is chosen. The choice belongs to
  // one window length: another length starts on its own best window.
  const [chosen, setChosen] = useState({ hours: 0, index: 0 });
  const slots = useMemo(() => est.best.map(windowSlot), [est.best]);
  const index = chosen.hours === windowHours && chosen.index < slots.length ? chosen.index : 0;
  const slot = slots.length > 0 ? slots[index] : null;

  const hourOf = est.hour;
  const windowOn = est.windowOn;
  const oneStopDay = est.oneStopDay;
  const shownTerms = est.terms;
  const driveLegs = est.driveLegs;

  const hourWindow = useMemo(() => hourOf(settledHow), [hourOf, settledHow]);
  const hour = hourWindow !== null && hourWindow.hours.length > 0 ? hourWindow.hours[0].result : null;
  const who = useMemo(() => (hour === null ? null : whoIsHere(hour)), [hour]);
  const selected = useMemo(() => (slot === null ? null : windowOn(slot.dow, slot.open, slot.close)), [slot, windowOn]);
  const money = useMemo(
    () => (selected === null || shownTerms === null ? null : stopMoney(profile, shownTerms, selected.orders)),
    [selected, shownTerms, profile],
  );
  const day = useMemo(() => (slot === null ? null : oneStopDay(slot.dow, slot.open, slot.close)), [slot, oneStopDay]);
  const trafficNeutral = useMemo(() => trafficIsNeutral(A), [A]);
  const drive = useMemo(
    () => (day === null ? null : driveSummary(day.timeline.legs, driveLegs, trafficNeutral)),
    [day, driveLegs, trafficNeutral],
  );

  const [why, setWhy] = useState<WhySubject | null>(null);

  const whyHour = () => {
    if (hourWindow === null || vectors === null || shownTerms === null) return;
    const dow = (settledHow - (settledHow % 24)) / 24;
    setWhy({
      kind: 'window',
      title: name + ': ' + fmtHowLong(settledHow),
      window: hourWindow,
      vectors,
      terms: shownTerms,
      ctx: typicalContext(A, dow),
      ctxNext: typicalContext(A, (dow + 1) % 7),
    });
  };
  const whyWindow = () => {
    if (slot === null || selected === null || vectors === null || shownTerms === null) return;
    setWhy({
      kind: 'window',
      title: name + ': ' + windowLabel(slot) + ', typical week',
      window: selected,
      vectors,
      terms: shownTerms,
      ctx: typicalContext(A, slot.dow),
      ctxNext: typicalContext(A, (slot.dow + 1) % 7),
      money: money === null ? undefined : money,
    });
  };
  const whyDay = () => {
    if (slot === null || day === null) return;
    setWhy({
      kind: 'day',
      title: name + ': a one-stop day, ' + windowLabel(slot),
      result: day,
      stopNames: [name],
      ctx: typicalWithFuel(A, slot.dow, fuel),
    });
  };

  const selectWindow = (next: number) => {
    setChosen({ hours: windowHours, index: next });
    const picked = slots[next];
    if (picked === undefined) return;
    const hours = useTruckHourStore.getState();
    hours.setPlaying(false);
    hours.setHow(picked.how);
  };
  const pickHow = (how: number) => {
    const hours = useTruckHourStore.getState();
    hours.setPlaying(false);
    hours.setHow(how);
  };

  const rebuilding = regionRebuilding(region);
  const county = countyLabel(est.located !== null ? est.located.county_fips : spot !== null ? spot.county_fips : null, region?.counties);
  const tag =
    spot === null
      ? null
      : spot.archived
        ? // On the spot page the title carries this tag: the page prints it beside the name.
          page
          ? null
          : SPOT_STATE_TAGS.archived
        : spot.vectors_state === 'stale'
          ? rebuilding
            ? SPOT_STATE_TAGS.rebuilding
            : SPOT_STATE_TAGS.stale
          : null;
  const hostCounts = shownTerms !== null && shownTerms.host !== null && shownTerms.host.size > 0;
  const outsideOnly = est.status === 'outside' && !hostCounts;
  const dim = est.dim;

  let body: ReactNode;
  if (est.status === 'none') {
    body = <StateBlock title={SPOT_STATE_TAGS.none} page={page} />;
  } else if (est.status === 'error') {
    body = (
      <div className={page ? undefined : 'border-t py-4'} style={{ borderColor: 'var(--line-soft)' }}>
        <QueryError message="Could not estimate this point." onRetry={est.refetch} />
      </div>
    );
  } else if (est.week === null || vectors === null || hourWindow === null || hour === null || who === null) {
    body = est.status === 'rebuilding' ? <StateBlock text={REGION_REBUILD_SENTENCE} page={page} /> : <Pending page={page} />;
  } else if (outsideOnly) {
    body = (
      <StateBlock
        title="Outside the loaded area"
        text={
          region !== null
            ? 'This point is outside ' + region.name + '. There is no local data here, so nothing can be estimated.'
            : 'There is no local data for this area, so only a host you describe will count.'
        }
        page={page}
      />
    );
  } else {
    body = (
      <div className={page ? 'space-y-4' : undefined}>
        <Section title="This hour" page={page}>
          <ThisHour
            how={settledHow}
            result={hourWindow}
            people={who.people}
            hostPeople={who.host !== null ? who.host.people : null}
            dim={dim}
            onWhy={whyHour}
          />
        </Section>

        <Section title="Best windows" sub="Typical week" page={page}>
          <BestWindows
            slots={slots}
            windowOn={windowOn}
            hours={windowHours}
            onHours={(hours) => useTruckUiStore.getState().patch({ windowHours: hours })}
            selected={index}
            onSelect={selectWindow}
            spotId={spot !== null && !spot.archived ? spot.id : null}
            today={today}
            dim={dim}
          />
        </Section>

        <Section title="Week at a glance" page={page}>
          <WeekSection
            week={est.week}
            slots={slots}
            yMax={MAP_DOMAIN.opportunity}
            capacity={profile.capacity_orders_per_hour}
            onPickHow={pickHow}
            dim={dim}
          />
        </Section>

        <Section title="Who is here" sub={fmtHowLong(settledHow)} page={page}>
          <WhoIsHere who={who} hostName={spot !== null && spot.host_details !== null ? spot.host_details.name : null} dim={dim} />
        </Section>

        <Section title="Competition" page={page}>
          <Competition
            pull={vectors.rivals[hour.regime]}
            regime={hour.regime}
            outlets={est.outlets}
            outletsTotal={est.outletsTotal}
            listStatus={est.placesStatus}
            onRetry={est.refetch}
            dim={dim}
          />
        </Section>

        {slot !== null && selected !== null && money !== null && shownTerms !== null ? (
          <>
            <Section title="Money" sub={windowLabel(slot) + ', typical week'} page={page}>
              <MoneySection
                windowText={windowLabel(slot)}
                money={money}
                terms={shownTerms}
                tips={profile.tips_include}
                day={day}
                drive={drive}
                dim={dim}
                onWhyDay={whyDay}
              />
            </Section>
            <div className={page ? undefined : 'pb-4'}>
              <button type="button" className="btn btn-secondary h-11 md:h-9 w-full text-sm" onClick={whyWindow}>
                {WHY.button}
              </button>
            </div>
          </>
        ) : null}
      </div>
    );
  }

  const canSave = onSaveAsSpot !== undefined && subject.kind === 'point';
  const spotActions = spot !== null && !spot.archived && !page;

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 pb-2.5">
        <p className="min-w-0 text-[13px] font-semibold tabular-nums" style={{ color: 'var(--body)' }}>
          {fmtCoord(lat, lng)}
          {county !== null ? ' · ' + county : ''}
        </p>
        {/* On a touch screen the link is 44 px tall; it gives 8 px of that back at each end, where nothing else is. */}
        {!page ? (
          <span className="-my-2 inline-flex md:my-0">{spot !== null ? <OpenInMaps href={spot.maps_url} /> : <OpenInMaps point={{ lat, lng }} />}</span>
        ) : null}
        {tag !== null ? (
          <span className="tp-chip" role="status">
            {tag}
          </span>
        ) : null}
        {est.status === 'ready' && est.located !== null && !est.located.in_region ? (
          <p className="basis-full text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
            Just outside the loaded counties. People across the county line are only partly counted.
          </p>
        ) : null}
        {est.status === 'outside' && hostCounts ? (
          <p className="basis-full text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
            Outside the loaded area. Only the host you described counts.
          </p>
        ) : null}
      </div>

      {body}

      <div className={(page ? 'pt-4' : 'border-t pt-3') + ' space-y-1.5'} style={{ borderColor: 'var(--line-soft)' }}>
        <PermissionNotice variant="line" />
        <SourceLine kinds={['vintages']} vintages={region !== null ? region.vintages : null} />
      </div>

      {canSave || spotActions ? (
        <div
          className={
            page
              ? 'mt-3 flex flex-wrap items-center gap-2'
              : 'bg-white sticky -bottom-4 -mx-4 -mb-4 mt-3 flex flex-wrap items-center gap-2 border-t px-4 py-3'
          }
          style={{ borderColor: 'var(--line-soft)' }}
        >
          {canSave && onSaveAsSpot !== undefined ? (
            <>
              <button
                type="button"
                className="btn btn-primary h-11 md:h-9 min-w-0 flex-1 text-sm"
                disabled={est.status === 'rebuilding'}
                onClick={() => onSaveAsSpot(est.hostsNearby !== null ? est.hostsNearby : NO_HOSTS)}
              >
                Save as spot
              </button>
              {outsideOnly && region !== null ? (
                <p className="basis-full text-xs font-semibold" style={{ color: 'var(--body)' }}>
                  Only a host you describe will count.
                </p>
              ) : null}
            </>
          ) : null}
          {spotActions && spot !== null ? (
            <>
              <Link to={'/truck/spots/' + encodeURIComponent(spot.id)} className="btn btn-primary h-11 md:h-9 min-w-0 flex-1 text-sm">
                Open spot
              </Link>
              <Link
                to={'/truck/plan/' + today + '?add=' + encodeURIComponent(spot.id)}
                className="btn btn-secondary h-11 md:h-9 min-w-0 flex-1 text-sm"
              >
                Plan a day here
              </Link>
            </>
          ) : null}
        </div>
      ) : null}

      <WhyDrawer open={why !== null} onClose={() => setWhy(null)} subject={why} />
    </div>
  );
}

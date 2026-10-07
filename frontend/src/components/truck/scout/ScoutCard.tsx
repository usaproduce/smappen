import { Link } from 'react-router-dom';
import {
  Ban,
  Bookmark,
  CalendarCheck,
  Car,
  ChevronRight,
  Clock,
  EyeOff,
  Globe,
  Layers,
  MapPinned,
  MessageSquare,
  Phone,
  Plus,
  Star,
  TriangleAlert,
  Users,
  Utensils,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { LeadStatus, RegionCounty } from '../../../api/truck';
import { coord6 } from '../../../utils/truck/assemble';
import { fmtCount } from '../../../utils/truck/format';
import {
  SCOUT,
  bestWindowSentence,
  cardView,
  driveSentence,
  hostFitShort,
  kitchenSentence,
  leadStatusLabel,
  mergedSentence,
  placeAddress,
  placeWhere,
  recordedContact,
  recordedHours,
  seeAllLabel,
  sizeSentence,
  tripShort,
  type KindGroup,
  type ScoutCandidate,
} from '../../../utils/truck/scoutView';
import { placeTypeLabel } from '../../../utils/truck/wording';
import { OpenInMaps, RangeValue } from '../ui';
import ContactLookup from './ContactLookup';
import LeadControls from './LeadControls';

const STATUS_ICON: Record<LeadStatus, LucideIcon | null> = {
  new: null,
  shortlisted: Star,
  contacted: MessageSquare,
  booked: CalendarCheck,
  declined: Ban,
  hidden: EyeOff,
};

/** A lead's status as a neutral chip: a word with a glyph of its own. Nothing for a place the owner has not marked. */
function StatusChip({ status, small }: { status: LeadStatus; small?: boolean }) {
  const Icon = STATUS_ICON[status];
  if (Icon === null) return null;
  return (
    <span className={'tp-chip' + (small === true ? ' tp-chip-sm' : '')}>
      <Icon size={12} strokeWidth={2.6} aria-hidden />
      {leadStatusLabel(status)}
    </span>
  );
}

function SavedChip({ small }: { small?: boolean }) {
  return (
    <span className={'tp-chip' + (small === true ? ' tp-chip-sm' : '')}>
      <Bookmark size={12} strokeWidth={2.6} aria-hidden />
      Saved spot
    </span>
  );
}

/** The id of a card's element: the overview brings a card into view by it. */
export function scoutCardId(placeKey: string): string {
  return 'tp-scout-' + placeKey.replace(/[^A-Za-z0-9_-]/g, '-');
}

export interface ScoutCardProps {
  candidate: ScoutCandidate;
  /** The counties of the truck's region, for the county's name. */
  counties: readonly RegionCounty[] | null;
  /** The breakdown of this card's estimate is being worked out, or could not be. */
  whyState: 'idle' | 'loading' | 'failed';
  /** Opens "Why this number" for the best window. */
  onWhy: () => void;
  /** Opens "Save as spot". */
  onSave: () => void;
  /** The owner chose another status. */
  onStatus: (status: LeadStatus, previous: LeadStatus) => void;
}

function Fact({ icon: Icon, children }: { icon: LucideIcon; children: string }) {
  return (
    <li className="flex items-start gap-2">
      <Icon size={14} aria-hidden className="mt-[3px] flex-none" style={{ color: 'var(--slate)' }} />
      <span className="min-w-0">{children}</span>
    </li>
  );
}

/**
 * One place that could be asked (docs/truck-planner/05_FRONTEND.md 4.8): where it is, what its best
 * three hours of a typical week might be worth, what the drive costs, how to reach it, and where the
 * owner stands with it. The two estimates are the server's and are shown as returned, each as a
 * range with its label. The server's score ranks the list and is never shown.
 *
 * Nothing on the card says that the place takes trucks.
 */
export default function ScoutCard({ candidate, counties, whyState, onWhy, onSave, onStatus }: ScoutCardProps) {
  const { place, result, lead } = candidate;
  const view = cardView(candidate);
  const id = scoutCardId(place.place_key);
  const where = placeWhere(place, counties);
  const address = placeAddress(place);
  const drive = driveSentence(candidate);
  const merged = mergedSentence(candidate.merged);
  const hours = recordedHours(place);
  const contact = recordedContact(place);
  const mapHref = '/truck/map?pt=' + coord6(place.lat) + ',' + coord6(place.lng) + '&scout=1';

  return (
    <article
      id={id}
      aria-labelledby={id + '-name'}
      className="@container scroll-mt-28 rounded-xl border bg-white p-4 sm:p-5"
      style={{ borderColor: 'var(--line-soft)' }}
    >
      <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1.5">
        <div className="min-w-0">
          <h3 id={id + '-name'} tabIndex={-1} className="text-base font-extrabold leading-snug focus:outline-none" style={{ color: 'var(--ink)' }}>
            <span className="tabular-nums">{fmtCount(result.position)}.</span> {place.name}
          </h3>
          <p className="mt-0.5 text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
            {placeTypeLabel(candidate.kind)}
            {where !== '' ? ' · ' + where : ''}
          </p>
          {address !== null ? (
            <p className="text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
              {address}
            </p>
          ) : null}
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          <StatusChip status={lead.status} />
          {view.saved ? <SavedChip /> : null}
        </div>
      </div>

      <div className="mt-3 grid gap-x-6 gap-y-4 @[680px]:grid-cols-[minmax(0,1fr)_minmax(0,300px)]">
        <div className="min-w-0 space-y-2.5">
          <p className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
            {bestWindowSentence(result.best_window)}
          </p>

          {view.showEstimates ? (
            <div className="space-y-1.5">
              <div>
                <RangeValue
                  estimate={result.orders}
                  unit="orders"
                  layout="inline"
                  size="sm"
                  onWhy={view.canWhy ? onWhy : undefined}
                  note={candidate.at_capacity ? SCOUT.capacityNote : undefined}
                />
              </div>
              <div>
                <RangeValue estimate={result.contribution} unit="money" layout="inline" size="sm" label="LEFT AFTER FOOD AND FEES" />
              </div>
              {whyState === 'loading' ? (
                <p role="status" className="flex items-center gap-1.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
                  <span className="spinner" aria-hidden /> {SCOUT.whyWorking}
                </p>
              ) : null}
              {whyState === 'failed' ? (
                <p role="alert" className="flex items-start gap-1.5 text-xs font-semibold" style={{ color: 'var(--ink)' }}>
                  <TriangleAlert size={13} aria-hidden className="mt-px flex-none" style={{ color: 'var(--money-negative)' }} />
                  <span>{SCOUT.whyFailed}</span>
                </p>
              ) : null}
            </div>
          ) : (
            <p className="text-sm font-semibold" style={{ color: 'var(--ink)' }}>
              {SCOUT.savedLine}{' '}
              {lead.spot_id !== null ? (
                <Link
                  to={'/truck/spots/' + encodeURIComponent(lead.spot_id)}
                  className="inline-flex min-h-[44px] md:min-h-0 items-center font-bold underline underline-offset-2"
                  style={{ color: 'var(--ink)' }}
                >
                  {SCOUT.openSpot}
                </Link>
              ) : null}
            </p>
          )}

          <ul className="space-y-1 text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
            {drive !== null ? <Fact icon={Car}>{drive}</Fact> : null}
            <Fact icon={Utensils}>{kitchenSentence(place.kitchen, result.kitchen)}</Fact>
            {view.showSize ? <Fact icon={Users}>{sizeSentence(result)}</Fact> : null}
            {merged !== null ? <Fact icon={Layers}>{merged}</Fact> : null}
            {hours !== null ? <Fact icon={Clock}>{hours}</Fact> : null}
          </ul>
        </div>

        <div className="min-w-0 space-y-3">
          <div>
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
              <h4 className="label" style={{ marginBottom: 0 }}>
                Contact
              </h4>
              {contact.phone !== null || contact.website !== null ? <span className="tp-chip tp-chip-sm">{SCOUT.osmTag}</span> : null}
            </div>
            {contact.phone === null && contact.website === null ? (
              <p className="mt-1 text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
                {SCOUT.noContact}
              </p>
            ) : (
              <ul className="mt-1 space-y-0.5">
                {contact.phone !== null ? (
                  <li className="flex items-center gap-1.5 text-sm font-bold tabular-nums" style={{ color: 'var(--ink)' }}>
                    <Phone size={14} aria-hidden className="flex-none" style={{ color: 'var(--slate)' }} />
                    {contact.phone.href !== null ? (
                      <a href={contact.phone.href} className="inline-flex min-h-[44px] md:min-h-0 items-center underline underline-offset-2">
                        {contact.phone.text}
                      </a>
                    ) : (
                      <span>{contact.phone.text}</span>
                    )}
                  </li>
                ) : null}
                {contact.website !== null ? (
                  <li className="flex items-center gap-1.5 text-sm font-bold" style={{ color: 'var(--ink)' }}>
                    <Globe size={14} aria-hidden className="flex-none" style={{ color: 'var(--slate)' }} />
                    {contact.website.href !== null ? (
                      <a
                        href={contact.website.href}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex min-h-[44px] md:min-h-0 min-w-0 items-center break-all underline underline-offset-2"
                      >
                        {contact.website.text}
                      </a>
                    ) : (
                      <span className="min-w-0 break-all">{contact.website.text}</span>
                    )}
                  </li>
                ) : null}
              </ul>
            )}
          </div>
          <ContactLookup candidate={candidate} />
          <LeadControls candidate={candidate} onStatus={onStatus} />
        </div>
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-2 border-t pt-3" style={{ borderColor: 'var(--line-soft)' }}>
        <OpenInMaps href={candidate.maps_url} variant="button" />
        <Link to={mapHref} className="btn btn-secondary h-11 md:h-9 px-3 text-sm">
          <MapPinned size={14} aria-hidden /> {SCOUT.showOnMap}
        </Link>
        {view.canSave ? (
          <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={onSave}>
            <Plus size={15} aria-hidden /> {SCOUT.saveAsSpot}
          </button>
        ) : null}
      </div>
    </article>
  );
}

export interface KindDigestProps {
  group: KindGroup;
  counties: readonly RegionCounty[] | null;
  /** How many places of the kind the overview names. */
  top: number;
  /** "See all": the page switches to this kind. */
  onSeeAll: () => void;
  /** A place was picked: the page switches to its kind and brings its card into view. */
  onPick: (candidate: ScoutCandidate) => void;
}

/**
 * One kind of place in the overview of all kinds: its first few places by name, with the town and
 * the drive, so the kinds can be read side by side. The estimates are on the cards of the kind:
 * a figure without its range and its label is never shown here.
 */
export function KindDigest({ group, counties, top, onSeeAll, onPick }: KindDigestProps) {
  const id = 'tp-scout-kind-' + group.kind;
  const rows = group.candidates.slice(0, top);
  return (
    <section aria-labelledby={id} className="rounded-xl border bg-white p-4" style={{ borderColor: 'var(--line-soft)' }}>
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 id={id} className="text-base font-extrabold leading-snug" style={{ color: 'var(--ink)' }}>
            {group.label}
          </h2>
          {group.hostFit !== null ? (
            <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
              {hostFitShort(group.hostFit)}
            </p>
          ) : null}
        </div>
        <button
          type="button"
          className="btn btn-secondary h-11 md:h-8 flex-none px-2.5 text-[13px]"
          style={{ gap: 2 }}
          aria-label={seeAllLabel(group.candidates.length) + ': ' + group.label}
          onClick={onSeeAll}
        >
          {seeAllLabel(group.candidates.length)}
          <ChevronRight size={14} aria-hidden />
        </button>
      </div>
      <ol className="tp-stat-list mt-2">
        {rows.map((candidate) => {
          const where = placeWhere(candidate.place, counties);
          const trip = tripShort(candidate);
          const meta = [where, trip].filter((part): part is string => part !== null && part !== '').join(' · ');
          const contact = recordedContact(candidate.place);
          return (
            <li key={candidate.place.place_key}>
              <button
                type="button"
                className="tp-row-click flex min-h-[52px] w-full items-start gap-2.5 rounded-lg px-1.5 py-2 text-left"
                onClick={() => onPick(candidate)}
              >
                <span className="w-5 flex-none text-right text-sm font-extrabold tabular-nums" style={{ color: 'var(--ink)' }}>
                  {fmtCount(candidate.result.position)}.
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-bold leading-snug" style={{ color: 'var(--ink)' }}>
                    {candidate.place.name}
                  </span>
                  <span className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
                    {meta !== '' ? <span>{meta}</span> : null}
                    {contact.phone !== null ? (
                      <span className="inline-flex items-center" title="Phone on record">
                        <Phone size={12} aria-hidden />
                        <span className="sr-only">Phone on record</span>
                      </span>
                    ) : null}
                    {contact.website !== null ? (
                      <span className="inline-flex items-center" title="Website on record">
                        <Globe size={12} aria-hidden />
                        <span className="sr-only">Website on record</span>
                      </span>
                    ) : null}
                    <StatusChip status={candidate.lead.status} small />
                    {candidate.lead.spot_id !== null ? <SavedChip small /> : null}
                  </span>
                </span>
                <ChevronRight size={16} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
              </button>
            </li>
          );
        })}
      </ol>
    </section>
  );
}

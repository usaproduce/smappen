import { useState } from 'react';
import {
  Archive,
  ArrowDown,
  ArrowUp,
  CalendarDays,
  ChevronDown,
  ChevronRight,
  GripVertical,
  Hourglass,
  Info,
  MapPin,
  RefreshCw,
  Trash2,
  TriangleAlert,
  Utensils,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { Spot } from '../../../api/truck';
import type { DraftStop } from '../../../stores/truckPlanDraftStore';
import type { DayStop, LatLng, StopKind, TimelineStop } from '../../../utils/truck/model';
import { KIND_LABELS, arriveLine, waitToggleLabel, type StopProblem } from '../../../utils/truck/planDraft';
import type { WarningRow } from '../../../utils/truck/warnings';
import { SPOT_STATE_TAGS } from '../../../utils/truck/wording';
import { NumberField, OpenInMaps, RangeValue, SkeletonRows, TimeField, Toggle } from '../ui';
import AddsLine from './AddsLine';
import CateringTermsForm from './CateringTermsForm';
import EventTermsForm from './EventTermsForm';

export interface StopCardProps {
  /** Position in the day, 0 = first. */
  index: number;
  /** How many stops the day has. */
  count: number;
  stop: DraftStop;
  /** What the stop is called ("Stop 2" when it has no name). */
  name: string;
  /** The saved spots the owner can choose from (deleted ones left out), in list order. */
  spots: readonly Spot[];
  /** The spot of a spot stop: a deleted one included, null when it is not in the list. */
  spot: Spot | null;
  /** Where the stop is, for its link to Google Maps. */
  point: LatLng | null;
  /** Why the stop cannot be evaluated yet. */
  problem: StopProblem | null;
  /** The stop as the model evaluated it; null while the day is not evaluated. */
  evaluated: DayStop | null;
  /** The stop's times in the day; null while the day is not evaluated. */
  timed: TimelineStop | null;
  /** Codes of the model's warnings about this stop. */
  warningCodes: readonly string[];
  /** The warnings repeated in the card, because they are about the times edited in it. */
  notices: readonly WarningRow[];
  /** The day's figures are on their way for the first time. */
  pending: boolean;
  /** The drive legs are being read again: figures may still change. */
  dim: boolean;
  /** The region data is being rebuilt: a spot whose stored figures are old cannot be refreshed now. */
  rebuilding: boolean;
  /** The truck's own setup and pack-up minutes, shown where the stop sets none. */
  setupDefault: number;
  teardownDefault: number;
  onPatch: (patch: Partial<DraftStop>) => void;
  onMove: (delta: -1 | 1) => void;
  onRemove: () => void;
  /** Opens "Why this number" for this stop's orders; left out where there is nothing to open. */
  onWhy?: () => void;
  /** Arms and disarms dragging of the card (desktop). */
  onGrip?: (down: boolean) => void;
}

const KIND_ICON: Record<StopKind, LucideIcon> = { spot: MapPin, event: CalendarDays, catering: Utensils };

const ICON_BUTTON =
  'inline-flex h-11 w-11 md:h-9 md:w-9 flex-none items-center justify-center rounded-lg border bg-white disabled:opacity-40 disabled:cursor-not-allowed';
const ICON_BUTTON_STYLE = { borderColor: 'var(--line)', color: 'var(--ink)' };

const PROBLEM_TEXT: Record<StopProblem, string> = {
  spot_missing: 'This spot is no longer in your list. Choose another one or remove the stop.',
  no_estimate: 'This spot has no estimate yet, so the day cannot be worked out with it.',
  details_missing: 'Fill in the details of this stop to see its figures.',
};

/**
 * One stop of the day (docs/truck-planner/05_FRONTEND.md 4.5): which spot, when it opens and
 * closes, when the truck arrives, the orders and what they leave, what the stop adds to the day,
 * and the wait before it. Every figure is the model's, for the day as it stands in the draft.
 */
export default function StopCard(props: StopCardProps) {
  const { index, count, stop, name, spots, spot, point, problem, evaluated, timed, warningCodes, notices, pending, dim, rebuilding } = props;
  const { onPatch, onMove, onRemove, onWhy, onGrip } = props;
  const uid = 'tp-stop-' + stop.id;
  const position = index + 1;
  const [timesOpen, setTimesOpen] = useState(stop.setup_minutes !== null || stop.teardown_minutes !== null);
  const KindIcon = KIND_ICON[stop.kind];
  const backwards = stop.close_minute <= stop.open_minute;
  const wait = index > 0 && timed !== null && timed.gap_before_minutes > 0 ? timed.gap_before_minutes : null;

  let stateTag: { icon: LucideIcon; text: string } | null = null;
  if (spot !== null) {
    if (spot.archived) stateTag = { icon: Archive, text: 'Deleted spot' };
    else if (spot.vectors_state === 'none') stateTag = { icon: Hourglass, text: SPOT_STATE_TAGS.none };
    else if (spot.vectors_state === 'stale') stateTag = { icon: RefreshCw, text: rebuilding ? SPOT_STATE_TAGS.rebuilding : SPOT_STATE_TAGS.stale };
  }

  return (
    <article
      className="bg-white rounded-xl border p-4 sm:p-5"
      style={{ borderColor: 'var(--line-soft)' }}
      aria-label={'Stop ' + String(position) + ' of ' + String(count) + ': ' + name}
    >
      <div className="flex flex-wrap items-center gap-x-2 gap-y-2">
        {onGrip !== undefined ? (
          <span
            aria-hidden
            title="Drag to reorder"
            className="-ml-2 hidden h-9 w-6 flex-none cursor-grab items-center justify-center rounded lg:inline-flex"
            style={{ color: 'var(--slate)' }}
            onPointerDown={() => onGrip(true)}
            onPointerUp={() => onGrip(false)}
          >
            <GripVertical size={16} />
          </span>
        ) : null}
        <span
          className="inline-flex h-7 w-7 flex-none items-center justify-center rounded-full text-[13px] font-extrabold tabular-nums"
          style={{ background: 'var(--bg-panel)', color: 'var(--ink)' }}
        >
          <span className="sr-only">Stop </span>
          {position}
        </span>
        <div className="min-w-0 flex-1 basis-48">
          {stop.kind === 'spot' ? (
            <select
              id={uid + '-spot'}
              className="select h-11 md:h-9 text-sm font-bold"
              style={{ paddingTop: 0, paddingBottom: 0 }}
              aria-label={'Spot of stop ' + String(position)}
              value={spot === null ? '' : spot.id}
              onChange={(e) => {
                if (e.target.value !== '') onPatch({ spot_id: e.target.value });
              }}
            >
              {spot === null ? <option value="">Choose a spot</option> : null}
              {spot !== null && spot.archived ? <option value={spot.id}>{spot.name} (deleted)</option> : null}
              {spots.map((option) => (
                <option key={option.id} value={option.id}>
                  {option.name}
                </option>
              ))}
            </select>
          ) : (
            <input
              id={uid + '-name'}
              type="text"
              className="input h-11 md:h-9 text-sm font-bold"
              aria-label={'Name of stop ' + String(position)}
              placeholder={stop.kind === 'event' ? 'Name of the event' : 'Name of the catering job'}
              maxLength={120}
              autoComplete="off"
              value={stop.label}
              onChange={(e) => onPatch({ label: e.target.value })}
            />
          )}
        </div>
        <div className="flex w-full items-center gap-2 sm:w-auto">
          {/* The tags wrap among themselves, so two of them never push the buttons out of the card. */}
          <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
            <span className="tp-chip">
              <KindIcon size={12} strokeWidth={2.6} aria-hidden />
              {KIND_LABELS[stop.kind]}
            </span>
            {stateTag !== null ? (
              <span className="tp-chip">
                <stateTag.icon size={12} strokeWidth={2.6} aria-hidden />
                {stateTag.text}
              </span>
            ) : null}
          </div>
          <div className="flex flex-none items-center gap-1">
            <button
              type="button"
              className={ICON_BUTTON}
              style={ICON_BUTTON_STYLE}
              aria-label={'Move earlier: ' + name}
              title="Move earlier"
              data-tp-move="earlier"
              disabled={index === 0}
              onClick={() => onMove(-1)}
            >
              <ArrowUp size={16} aria-hidden />
            </button>
            <button
              type="button"
              className={ICON_BUTTON}
              style={ICON_BUTTON_STYLE}
              aria-label={'Move later: ' + name}
              title="Move later"
              data-tp-move="later"
              disabled={index === count - 1}
              onClick={() => onMove(1)}
            >
              <ArrowDown size={16} aria-hidden />
            </button>
            <button
              type="button"
              className={ICON_BUTTON}
              style={ICON_BUTTON_STYLE}
              aria-label={'Remove stop: ' + name}
              title="Remove stop"
              data-tp-remove=""
              onClick={onRemove}
            >
              <Trash2 size={16} aria-hidden />
            </button>
          </div>
        </div>
      </div>

      {point !== null ? (
        <div className="mt-1">{spot !== null ? <OpenInMaps href={spot.maps_url} /> : <OpenInMaps point={point} />}</div>
      ) : null}

      <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <TimeField
          id={uid + '-open'}
          label="Open"
          value={stop.open_minute}
          onCommit={(minute) => {
            if (minute !== null) onPatch({ open_minute: minute });
          }}
        />
        <TimeField
          id={uid + '-close'}
          label="Close"
          value={stop.close_minute}
          onCommit={(minute) => {
            if (minute !== null) onPatch({ close_minute: minute });
          }}
          after={stop.open_minute}
          allowNextDay
          max={2880}
          help=""
          error={backwards ? 'Closing must be after opening.' : undefined}
        />
      </div>
      {timed !== null ? (
        <p className={'mt-2 text-[13px] font-bold tabular-nums' + (dim ? ' tp-dim' : '')} style={{ color: 'var(--ink)' }}>
          {arriveLine(timed)}
        </p>
      ) : null}

      {notices.length > 0 ? (
        <ul className="mt-2 space-y-1">
          {notices.map((row) => {
            const Icon = row.level === 'info' ? Info : TriangleAlert;
            return (
              <li key={String(row.index) + row.code} className="flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
                <Icon
                  size={14}
                  aria-hidden
                  className="mt-0.5 flex-none"
                  style={{ color: row.level === 'error' ? 'var(--money-negative)' : 'var(--fresh-aging)' }}
                />
                <span>
                  <span className="font-extrabold">{row.prefix}</span> {row.text}
                </span>
              </li>
            );
          })}
        </ul>
      ) : null}

      {stop.kind === 'event' ? (
        <div className="mt-3">
          <EventTermsForm stop={stop} onChange={onPatch} />
        </div>
      ) : null}
      {stop.kind === 'catering' ? (
        <div className="mt-3">
          <CateringTermsForm stop={stop} onChange={onPatch} />
        </div>
      ) : null}

      <div className="mt-3">
        {evaluated !== null ? (
          <div className="grid grid-cols-2 gap-3">
            <RangeValue
              estimate={evaluated.orders}
              unit="orders"
              size="md"
              label="ORDERS"
              onWhy={onWhy}
              note={warningCodes.indexOf('capacity_bound') >= 0 ? 'Limited by how fast the truck can serve.' : undefined}
              dim={dim}
            />
            <RangeValue estimate={evaluated.money.contribution} unit="money" size="sm" label="LEFT AFTER FOOD AND FEES" dim={dim} />
          </div>
        ) : problem !== null ? (
          <p className="flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
            <Info size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
            <span>{PROBLEM_TEXT[problem]}</span>
          </p>
        ) : pending ? (
          <div aria-busy="true">
            <SkeletonRows rows={1} rowHeight={72} />
          </div>
        ) : (
          <p className="text-[13px] font-semibold" style={{ color: 'var(--body)' }}>
            The figures of this stop show once the day can be worked out.
          </p>
        )}
      </div>

      {wait !== null ? (
        <div className="mt-3">
          <Toggle
            id={uid + '-unpaid'}
            label={waitToggleLabel(wait)}
            checked={stop.gap_before_unpaid}
            onChange={(checked) => onPatch({ gap_before_unpaid: checked })}
          />
        </div>
      ) : null}

      {evaluated !== null ? (
        <div className="mt-3">
          <AddsLine adds={evaluated.adds} kind={stop.kind} warningCodes={warningCodes} dim={dim} />
        </div>
      ) : null}

      <div className="mt-2">
        <button
          type="button"
          className="inline-flex min-h-[44px] md:min-h-[28px] items-center gap-1 text-[13px] font-bold underline underline-offset-2"
          style={{ color: 'var(--body)' }}
          aria-expanded={timesOpen}
          aria-controls={uid + '-times'}
          onClick={() => setTimesOpen(!timesOpen)}
        >
          {timesOpen ? <ChevronDown size={14} aria-hidden /> : <ChevronRight size={14} aria-hidden />}
          Setup and pack-up times
        </button>
        <div id={uid + '-times'} hidden={!timesOpen} className="mt-1">
          {timesOpen ? (
            // One under the other on a phone, like "Open" and "Close": side by side the truck's
            // default in the placeholder would be cut off.
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <NumberField
                id={uid + '-setup'}
                label="Setup"
                value={stop.setup_minutes}
                onCommit={(minutes) => onPatch({ setup_minutes: minutes })}
                integer
                min={0}
                max={240}
                suffix="min"
                placeholder={'Truck default: ' + String(props.setupDefault)}
              />
              <NumberField
                id={uid + '-teardown'}
                label="Pack-up"
                value={stop.teardown_minutes}
                onCommit={(minutes) => onPatch({ teardown_minutes: minutes })}
                integer
                min={0}
                max={240}
                suffix="min"
                placeholder={'Truck default: ' + String(props.teardownDefault)}
              />
            </div>
          ) : null}
        </div>
      </div>
    </article>
  );
}

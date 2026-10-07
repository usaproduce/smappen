import { useEffect, useId, useState } from 'react';
import { ChevronDown, ChevronRight, Info, MapPin, PenLine } from 'lucide-react';
import type { DraftStop } from '../../../stores/truckPlanDraftStore';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { fmtCoord, parseCoords } from '../../../utils/truck/format';
import type { EventType } from '../../../utils/truck/model';
import {
  EVENT_TYPES,
  STOP_TERMS,
  STOP_TERM_LIMITS,
  eventDraftOf,
  eventMissing,
  eventTermsOf,
  isTypicalEventFee,
  missingSentence,
  sameEventTerms,
  type EventDraft,
} from '../../../utils/truck/scoutView';
import { EVENT_TYPE_LABELS } from '../../../utils/truck/wording';
import GooglePlaceAutocomplete from '../../common/GooglePlaceAutocomplete';
import { useTruck, useTruckMapsLoader } from '../data';
import { Field, MoneyField, NumberField } from '../ui';

export interface EventTermsFormProps {
  /** The event stop being edited. */
  stop: DraftStop;
  /** The fields that changed; the planner patches its draft with them. */
  onChange: (patch: Partial<DraftStop>) => void;
}

/**
 * The details of an event stop (docs/truck-planner/05_FRONTEND.md 4.5 rule 5): where it is, how many
 * people the organiser expects while the truck is there, how many food vendors share them, what kind
 * of event it is, and the fee. The name is the field at the top of the stop's card.
 *
 * The planner works the stop out once the place, the attendance and the number of vendors are in.
 * An event is estimated from its attendance alone, so the model labels it "Very rough" whatever the
 * owner's logged services say. The fee starts from typical terms and says so until it is changed.
 */
export default function EventTermsForm({ stop, onChange }: EventTermsFormProps) {
  const ids = useId();
  const { A } = useTruck();
  const [draft, setDraft] = useState<EventDraft>(() => eventDraftOf(stop.event));

  // Terms that arrive from outside (the saved day, an undo) replace what the fields hold.
  const saved = stop.event;
  useEffect(() => {
    if (saved !== null) setDraft((current) => (sameEventTerms(eventTermsOf(current), saved) ? current : eventDraftOf(saved)));
  }, [saved]);

  const commit = (next: EventDraft) => {
    setDraft(next);
    const terms = eventTermsOf(next);
    if (!sameEventTerms(terms, stop.event)) onChange({ event: terms });
  };

  const missing = missingSentence(eventMissing(stop, draft));
  const typical = isTypicalEventFee(A, stop);

  return (
    <div className="space-y-3 border-t pt-3" style={{ borderColor: 'var(--line-soft)' }}>
      <StopPlaceField
        idPrefix={ids}
        point={stop.point}
        address={stop.address}
        onChange={(place) => onChange({ point: place.point, address: place.address, ...(stop.label.trim() === '' && place.name !== '' ? { label: place.name } : {}) })}
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <NumberField
          id={ids + 'attendance'}
          label={STOP_TERMS.attendance}
          value={draft.attendance}
          onCommit={(attendance) => commit({ ...draft, attendance })}
          integer
          min={STOP_TERM_LIMITS.attendanceMin}
          max={STOP_TERM_LIMITS.attendanceMax}
          required
          help={STOP_TERMS.attendanceHelp}
        />
        <NumberField
          id={ids + 'vendors'}
          label={STOP_TERMS.vendors}
          value={draft.vendors}
          onCommit={(vendors) => commit({ ...draft, vendors })}
          integer
          min={STOP_TERM_LIMITS.vendorsMin}
          max={STOP_TERM_LIMITS.vendorsMax}
          required
        />
      </div>

      <Field id={ids + 'type'} label={STOP_TERMS.eventType}>
        {(control) => (
          <select
            {...control}
            className="select h-11 md:h-9 text-sm font-semibold"
            style={{ paddingTop: 0, paddingBottom: 0 }}
            value={draft.eventType}
            onChange={(e) => commit({ ...draft, eventType: e.target.value as EventType })}
          >
            {EVENT_TYPES.map((type) => (
              <option key={type} value={type}>
                {EVENT_TYPE_LABELS[type]}
              </option>
            ))}
          </select>
        )}
      </Field>

      <fieldset>
        <legend className="mb-1 flex flex-wrap items-center gap-x-2 gap-y-1">
          <span className="label" style={{ marginBottom: 0 }}>
            Fee
          </span>
          {typical ? <span className="tp-chip tp-chip-sm">{STOP_TERMS.typicalFee}</span> : null}
        </legend>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <MoneyField
            id={ids + 'fee-flat'}
            label={STOP_TERMS.feeFlat}
            value={stop.fee_flat}
            onCommit={(fee) => onChange({ fee_flat: fee ?? 0 })}
            min={STOP_TERM_LIMITS.feeMin}
            max={STOP_TERM_LIMITS.feeMax}
          />
          <NumberField
            id={ids + 'fee-pct'}
            label={STOP_TERMS.feePct}
            format="percent"
            value={stop.fee_pct}
            onCommit={(fee) => onChange({ fee_pct: fee ?? 0 })}
            min={STOP_TERM_LIMITS.feePctMin}
            max={STOP_TERM_LIMITS.feePctMax}
          />
          <MoneyField
            id={ids + 'fee-min'}
            label={STOP_TERMS.feeMin}
            value={stop.fee_min}
            onCommit={(fee) => onChange({ fee_min: fee ?? 0 })}
            min={STOP_TERM_LIMITS.feeMin}
            max={STOP_TERM_LIMITS.feeMax}
          />
        </div>
        <p className="mt-1 text-xs font-semibold" style={{ color: 'var(--body)' }}>
          {STOP_TERMS.feeHelp}
        </p>
      </fieldset>

      {missing !== null ? <StopFormNote strong>{missing}</StopFormNote> : null}
      <StopFormNote>{STOP_TERMS.eventNote}</StopFormNote>
    </div>
  );
}

/** One line under the fields of an event or a catering stop: what is still needed, or how its figures read. */
export function StopFormNote({ children, strong }: { children: string; strong?: boolean }) {
  return (
    <p className={'flex items-start gap-1.5 text-[13px] ' + (strong === true ? 'font-bold' : 'font-semibold')} style={{ color: strong === true ? 'var(--ink)' : 'var(--body)' }}>
      <Info size={14} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--slate)' }} />
      <span>{children}</span>
    </p>
  );
}

// -------------------------------------------------------------------------------------------------
// The place of an event or a catering stop
// -------------------------------------------------------------------------------------------------

/** The props every address field of Truck Planner passes to the shared address widget (1.6). */
const ADDRESS_TYPES: string[] = [];
const ADDRESS_COUNTRIES = ['us'];
const ADDRESS_FIELDS = ['place_id', 'name', 'formatted_address', 'geometry'];

export interface StopPlace {
  point: { lat: number; lng: number };
  address: string;
  /** The name of a picked address, for a stop that has no name yet; empty for typed coordinates. */
  name: string;
}

export interface StopPlaceFieldProps {
  idPrefix: string;
  point: { lat: number; lng: number } | null;
  address: string;
  onChange: (place: StopPlace) => void;
}

/**
 * "Address or place" of an event or a catering stop: found by address search or typed as
 * coordinates. Never taken from the device: Truck Planner does not ask the browser where it is.
 * The place is what the drive there and back is timed from.
 */
export function StopPlaceField({ idPrefix, point, address, onChange }: StopPlaceFieldProps) {
  const [open, setOpen] = useState(point === null);
  const [coordsOpen, setCoordsOpen] = useState(false);
  const [coordsText, setCoordsText] = useState('');

  const typed = coordsText.trim() === '' ? null : parseCoords(coordsText);
  const coordsError = coordsText.trim() !== '' && typed === null ? STOP_TERMS.coordsError : null;
  const showEditor = open || point === null;

  const pick = (place: StopPlace) => {
    onChange(place);
    setOpen(false);
    setCoordsOpen(false);
    setCoordsText('');
  };

  return (
    <div role="group" aria-labelledby={idPrefix + 'place-label'}>
      <div id={idPrefix + 'place-label'} className="label">
        {STOP_TERMS.placeLabel}
      </div>
      {point !== null ? (
        <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
          <p className="flex min-w-0 items-start gap-1.5 text-sm font-semibold" style={{ color: 'var(--ink)' }}>
            <MapPin size={15} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--brand)' }} />
            <span className="min-w-0">
              {address !== '' ? <span className="block">{address}</span> : null}
              <span className="block tabular-nums" style={{ color: address !== '' ? 'var(--body)' : 'var(--ink)' }}>
                {fmtCoord(point.lat, point.lng)}
              </span>
            </span>
          </p>
          <button
            type="button"
            className="inline-flex min-h-[44px] md:min-h-0 items-center gap-1 text-[13px] font-bold underline underline-offset-2"
            style={{ color: 'var(--ink)' }}
            aria-expanded={open}
            onClick={() => setOpen(!open)}
          >
            <PenLine size={13} aria-hidden />
            {open ? STOP_TERMS.placeKeep : STOP_TERMS.placeChange}
          </button>
        </div>
      ) : null}
      {showEditor ? (
        <div className={point !== null ? 'mt-2 space-y-1.5' : 'space-y-1.5'}>
          <PlaceSearch onPick={pick} />
          <button
            type="button"
            className="inline-flex min-h-[44px] md:min-h-[32px] items-center gap-1 text-[13px] font-bold"
            style={{ color: 'var(--nav-active-fg)' }}
            aria-expanded={coordsOpen}
            onClick={() => setCoordsOpen(!coordsOpen)}
          >
            {coordsOpen ? <ChevronDown size={14} aria-hidden /> : <ChevronRight size={14} aria-hidden />}
            {STOP_TERMS.coordsToggle}
          </button>
          {coordsOpen ? (
            <div className="flex flex-wrap items-end gap-2">
              <Field id={idPrefix + 'coords'} label={STOP_TERMS.coordsLabel} error={coordsError} className="min-w-0 flex-1">
                {(control) => (
                  <input
                    {...control}
                    type="text"
                    className="input h-11 md:h-9 text-sm tabular-nums font-semibold"
                    placeholder={STOP_TERMS.coordsPlaceholder}
                    autoComplete="off"
                    value={coordsText}
                    onChange={(e) => setCoordsText(e.target.value)}
                  />
                )}
              </Field>
              <button
                type="button"
                className={'btn btn-secondary h-11 md:h-9 px-3 text-sm' + (coordsError !== null ? ' mb-5' : '')}
                disabled={typed === null}
                onClick={() => {
                  if (typed === null) return;
                  // Typed coordinates carry no address: the old one would describe another place.
                  const same = point !== null && point.lat === typed.lat && point.lng === typed.lng;
                  pick({ point: { lat: typed.lat, lng: typed.lng }, address: same ? address : '', name: '' });
                }}
              >
                {STOP_TERMS.coordsUse}
              </button>
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

/**
 * Address search with the truck props of the shared widget. A component of its own, so Google is
 * only loaded once a stop needs a place. When Google cannot be used the sentence says so and the
 * coordinates field under it remains.
 */
function PlaceSearch({ onPick }: { onPick: (place: StopPlace) => void }) {
  const { isLoaded, loadError } = useTruckMapsLoader();
  const refused = useTruckUiStore((s) => s.mapsAuthFailed);
  if (loadError !== undefined || refused) {
    return (
      <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
        {STOP_TERMS.addressUnavailable}
      </p>
    );
  }
  if (!isLoaded) return <div aria-hidden className="skeleton" style={{ height: 40, borderRadius: 8 }} />;
  return (
    <GooglePlaceAutocomplete
      placeholder={STOP_TERMS.placeSearch}
      types={ADDRESS_TYPES}
      countries={ADDRESS_COUNTRIES}
      fields={ADDRESS_FIELDS}
      unavailableText={STOP_TERMS.addressUnavailable}
      onPlace={(place) =>
        onPick({
          point: { lat: place.lat, lng: place.lng },
          address: (place.address || place.name || '').slice(0, STOP_TERM_LIMITS.addressMax),
          name: place.name || '',
        })
      }
    />
  );
}

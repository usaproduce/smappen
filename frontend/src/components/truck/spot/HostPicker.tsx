import type { ReactNode } from 'react';
import { TriangleAlert } from 'lucide-react';
import type { HostHint } from '../../../api/truck';
import { SEGMENTS } from '../../../utils/truck/model';
import type { SegmentKey } from '../../../utils/truck/model';
import { fmtPlain } from '../../../utils/truck/format';
import {
  SPOT_LIMITS,
  distanceText,
  draftHostSegment,
  linkPlace,
  type HostChoice,
  type SpotDraft,
  type SpotDraftErrors,
} from '../../../utils/truck/spotSummary';
import { HOST_SIZE_LABEL, placeTypeLabel, segmentGroup, segmentLabel } from '../../../utils/truck/wording';
import { Field, NumberField, SkeletonRows, Toggle } from '../ui';

export interface HostPickerProps {
  /** Prefix of every field id of this group. */
  idPrefix: string;
  draft: SpotDraft;
  onChange: (next: SpotDraft) => void;
  /** Places within reach of the point that could be the host, nearest first; null while unknown. */
  places: readonly HostHint[] | null;
  /** `no-point`: the spot has no place yet. `loading`, `error`: the list is on its way, or failed. */
  placesState: 'no-point' | 'loading' | 'ready' | 'error' | 'unavailable';
  /** The save was tried: show what stops it. */
  errors: SpotDraftErrors;
  /** The OpenStreetMap credit, printed under the list of places by the form itself. */
  placesCredit: ReactNode;
}

const CHOICES: { value: HostChoice; label: string }[] = [
  { value: 'none', label: 'No host (street or lot)' },
  { value: 'place', label: 'A place nearby' },
  { value: 'describe', label: 'I will describe it' },
];

const RADIO_ROW = 'flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5 min-h-[44px] md:min-h-0';

/**
 * "Is there a host?" (docs/truck-planner/05_FRONTEND.md 4.4): no host, a place nearby, or a host
 * the owner describes. A linked place is kept as its place key; the server derives who its people
 * are, a typical size and whether it sells food. The label of the size follows the group of the
 * host's segment: people there in its busiest hour, people who work there, people who live there.
 *
 * Its grids follow the width of the form around it (the form is the container of the `@[...]`
 * classes), so the same picker fits the modal and the narrow column of the spot page.
 */
export default function HostPicker({ idPrefix, draft, onChange, places, placesState, errors, placesCredit }: HostPickerProps) {
  const segment = draftHostSegment(draft);
  const showSize = segment !== null;
  const hasTypical = draft.hostChoice === 'place' && draft.placeDefaultSize > 0;
  const typical = fmtPlain(draft.placeDefaultSize, 1);

  const choose = (value: HostChoice) => {
    if (value === draft.hostChoice) return;
    onChange({ ...draft, hostChoice: value });
  };

  // The saved link of a spot may be missing from the list (the list holds the ten nearest).
  const linkedMissing =
    draft.hostChoice === 'place' && draft.placeKey !== null && (places === null || !places.some((p) => p.place_key === draft.placeKey));

  return (
    <fieldset className="space-y-3">
      <legend className="label">Is there a host?</legend>
      <div className="grid gap-2 @[560px]:grid-cols-3">
        {CHOICES.map((choice) => {
          const on = draft.hostChoice === choice.value;
          return (
            <label
              key={choice.value}
              className={RADIO_ROW + (on ? '' : ' bg-white')}
              style={{ borderColor: on ? 'var(--brand)' : 'var(--line)', background: on ? 'var(--brand-light)' : undefined }}
            >
              <input
                type="radio"
                name={idPrefix + 'host-choice'}
                className="mt-0.5 h-4 w-4 flex-none"
                style={{ accentColor: 'var(--brand)' }}
                checked={on}
                onChange={() => choose(choice.value)}
              />
              <span className="text-sm font-bold leading-snug" style={{ color: 'var(--ink)' }}>
                {choice.label}
              </span>
            </label>
          );
        })}
      </div>

      {draft.hostChoice === 'place' ? (
        <div className="space-y-2">
          {placesState === 'no-point' ? (
            <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
              Pick the place of the spot first. The places around it are listed here.
            </p>
          ) : null}
          {placesState === 'loading' ? (
            <div aria-busy="true">
              <SkeletonRows rows={2} rowHeight={44} />
            </div>
          ) : null}
          {placesState === 'error' ? (
            <p className="text-sm font-semibold" style={{ color: 'var(--ink)' }}>
              Could not load the places nearby. Describe the host instead, or try again later.
            </p>
          ) : null}
          {placesState === 'unavailable' ? (
            <p className="text-sm font-semibold" style={{ color: 'var(--ink)' }}>
              The places nearby cannot be listed right now. Describe the host instead.
            </p>
          ) : null}
          {placesState === 'ready' && places !== null && places.length === 0 && !linkedMissing ? (
            <p className="text-sm font-semibold" style={{ color: 'var(--ink)' }}>
              No place within reach of this point in our data. Describe the host instead.
            </p>
          ) : null}
          {(places !== null && places.length > 0) || linkedMissing ? (
            <>
              <div className="space-y-1.5" role="radiogroup" aria-label="Places nearby">
                {linkedMissing ? (
                  <div>
                    <label className={RADIO_ROW} style={{ borderColor: 'var(--brand)', background: 'var(--brand-light)' }}>
                      <input type="radio" name={idPrefix + 'place'} className="mt-0.5 h-4 w-4 flex-none" style={{ accentColor: 'var(--brand)' }} checked readOnly />
                      <span className="min-w-0 text-sm font-bold" style={{ color: 'var(--ink)' }}>
                        {draft.placeName !== '' ? draft.placeName : 'The place this spot is linked to'}
                        <span className="block text-xs font-semibold" style={{ color: 'var(--body)' }}>
                          {placeTypeLabel(draft.placeType)}
                        </span>
                      </span>
                    </label>
                  </div>
                ) : null}
                {(places ?? []).map((place) => {
                  const on = draft.placeKey === place.place_key;
                  return (
                    <div key={place.place_key}>
                      <label
                        className={RADIO_ROW + (on ? '' : ' bg-white')}
                        style={{ borderColor: on ? 'var(--brand)' : 'var(--line)', background: on ? 'var(--brand-light)' : undefined }}
                      >
                        <input
                          type="radio"
                          name={idPrefix + 'place'}
                          className="mt-0.5 h-4 w-4 flex-none"
                          style={{ accentColor: 'var(--brand)' }}
                          checked={on}
                          onChange={() => onChange(linkPlace(draft, place))}
                        />
                        <span className="min-w-0 flex-1 text-sm font-bold" style={{ color: 'var(--ink)' }}>
                          {place.name !== '' ? place.name : 'Unnamed place'}
                          <span className="block text-xs font-semibold" style={{ color: 'var(--body)' }}>
                            {placeTypeLabel(place.place_type)}, {distanceText(place.distance_m)} away
                          </span>
                        </span>
                      </label>
                    </div>
                  );
                })}
              </div>
              {placesCredit}
            </>
          ) : null}
          {errors.place !== undefined ? <FieldProblem text={errors.place} /> : null}
          {draft.placeKey !== null && draft.placeSegment === null && !linkedMissing ? (
            <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
              This kind of place brings no crowd of its own, so the estimate does not change. The link is kept with the spot.
            </p>
          ) : null}
        </div>
      ) : null}

      {draft.hostChoice === 'describe' ? (
        <Field id={idPrefix + 'segment'} label="Who the host's people are" error={errors.segment} required>
          {(control) => (
            <select
              {...control}
              className="select h-11 md:h-9 text-sm font-semibold"
              style={{ paddingTop: 0, paddingBottom: 0 }}
              value={draft.segment ?? ''}
              onChange={(e) => onChange({ ...draft, segment: e.target.value === '' ? null : (e.target.value as SegmentKey) })}
            >
              <option value="">Choose one</option>
              {SEGMENTS.map((key) => (
                <option key={key} value={key}>
                  {segmentLabel(key)}
                </option>
              ))}
            </select>
          )}
        </Field>
      ) : null}

      {showSize && segment !== null ? (
        <div className="grid gap-3 @[420px]:grid-cols-2">
          <NumberField
            id={idPrefix + 'size'}
            label={HOST_SIZE_LABEL[segmentGroup(segment)]}
            value={draft.size}
            onCommit={(size) => onChange({ ...draft, size })}
            min={SPOT_LIMITS.sizeMin}
            max={SPOT_LIMITS.sizeMax}
            required={!hasTypical}
            placeholder={hasTypical ? typical : undefined}
            help={hasTypical ? 'Typical for this kind of place: ' + typical + '. Enter the real figure if you know it.' : undefined}
            error={errors.size}
          />
          <div className="@[420px]:pt-5">
            <Toggle
              id={idPrefix + 'only-food'}
              label="Your truck is the only food here"
              checked={draft.onlyFood}
              onChange={(onlyFood) => onChange({ ...draft, onlyFood })}
              help="Turn this off if the host sells its own food."
            />
          </div>
        </div>
      ) : null}

      {draft.hostChoice !== 'none' ? (
        <div className="grid gap-3 @[420px]:grid-cols-2">
          <TextField
            id={idPrefix + 'host-name'}
            label="Host name"
            value={draft.hostName}
            maxLength={SPOT_LIMITS.hostNameMax}
            error={errors.hostName}
            onChange={(hostName) => onChange({ ...draft, hostName })}
          />
          <TextField
            id={idPrefix + 'host-contact'}
            label="Contact name"
            value={draft.hostContact}
            maxLength={SPOT_LIMITS.hostContactMax}
            error={errors.hostContact}
            onChange={(hostContact) => onChange({ ...draft, hostContact })}
          />
          <TextField
            id={idPrefix + 'host-phone'}
            label="Phone"
            type="tel"
            value={draft.hostPhone}
            maxLength={SPOT_LIMITS.hostPhoneMax}
            error={errors.hostPhone}
            onChange={(hostPhone) => onChange({ ...draft, hostPhone })}
          />
          <TextField
            id={idPrefix + 'host-website'}
            label="Website"
            type="url"
            value={draft.hostWebsite}
            maxLength={SPOT_LIMITS.hostWebsiteMax}
            error={errors.hostWebsite}
            onChange={(hostWebsite) => onChange({ ...draft, hostWebsite })}
          />
        </div>
      ) : null}
    </fieldset>
  );
}

function FieldProblem({ text }: { text: string }) {
  return (
    <p role="alert" className="flex items-start gap-1 text-xs font-semibold" style={{ color: 'var(--money-negative)' }}>
      <TriangleAlert size={13} aria-hidden className="mt-px flex-none" />
      <span>{text}</span>
    </p>
  );
}

function TextField({
  id,
  label,
  value,
  maxLength,
  error,
  type = 'text',
  onChange,
}: {
  id: string;
  label: string;
  value: string;
  maxLength: number;
  error?: string;
  type?: 'text' | 'tel' | 'url';
  onChange: (value: string) => void;
}) {
  return (
    <Field id={id} label={label} error={error}>
      {(control) => (
        <input
          {...control}
          type={type}
          className="input h-11 md:h-9 text-sm font-semibold"
          value={value}
          maxLength={maxLength}
          autoComplete="off"
          onChange={(e) => onChange(e.target.value)}
        />
      )}
    </Field>
  );
}

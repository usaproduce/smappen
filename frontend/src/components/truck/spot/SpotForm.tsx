import { useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ChevronDown, ChevronRight, MapPin, MapPinned, PenLine, TriangleAlert } from 'lucide-react';
import { REGION_REBUILD_SENTENCE, type HostHint, type Spot, type SpotBody } from '../../../api/truck';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { fmtCoord, fmtWeekday, fmtWindow, parseCoords } from '../../../utils/truck/format';
import type { Visibility } from '../../../utils/truck/model';
import {
  SPOT_LIMITS,
  bodyFromDraft,
  choiceOfBest,
  describeLinkedPlace,
  distanceText,
  draftFromBody,
  draftModelTerms,
  draftPlaceKey,
  hostQuestion,
  linkPlace,
  patchFromDrafts,
  spotBodyOf,
  typicalWeek,
  validateSpotDraft,
  windowFigures,
  type SpotDraft,
} from '../../../utils/truck/spotSummary';
import { SPOT_STATE_TAGS, VISIBILITY_TEXT, placeTypeLabel } from '../../../utils/truck/wording';
import GooglePlaceAutocomplete from '../../common/GooglePlaceAutocomplete';
import { regionRebuilding, useCreateSpot, useSpot, useSpotEstimate, useTruck, useTruckMapsLoader, useUpdateSpot } from '../data';
import { Field, MoneyField, NumberField, QueryError, RangeValue, SkeletonRows, SourceLine, TimeField, Toggle } from '../ui';
import HostPicker from './HostPicker';

export interface SpotFormProps {
  mode: 'create' | 'edit';
  /** What the form starts from: the point of a map click, or the saved spot's fields. */
  initial: Partial<SpotBody>;
  /** The spot being edited (`mode: 'edit'`). */
  spotId?: string;
  /** Places within reach of the point that could be the host, nearest first. */
  nearbyHosts?: HostHint[];
  /** `modal`: inside a Modal for a new spot. `inline`: on the spot detail page. */
  presentation: 'modal' | 'inline';
  onSaved: (spot: Spot) => void;
  onCancel: () => void;
}

/** The props every address field of Truck Planner passes to the shared address widget (1.6). */
const ADDRESS_TYPES: string[] = [];
const ADDRESS_COUNTRIES = ['us'];
const ADDRESS_FIELDS = ['place_id', 'name', 'formatted_address', 'geometry'];
const ADDRESS_UNAVAILABLE = 'Address search is unavailable. Enter coordinates instead.';

const VISIBILITIES: Visibility[] = ['hidden', 'normal', 'prominent'];
const DAYS = [0, 1, 2, 3, 4, 5, 6];

/**
 * The spot form (docs/truck-planner/05_FRONTEND.md 4.4): place, host, visibility, fee, days and
 * hours. Nothing saves on blur: "Save spot" sends route 11 for a new spot and, for a saved one,
 * route 14 with only what changed. A value outside its range stays in its field with the range
 * message and holds the save back; nothing is clamped.
 *
 * The estimate under the form follows the draft. Switching visibility, the fee, the only-food
 * switch or the size of a visitor host recomputes in the browser at once; a new point, another
 * linked place, another segment or the size of a worker or resident host asks the server for new
 * vectors first, and the last matching figures stay on screen dimmed meanwhile.
 *
 * The form lists places from OpenStreetMap in two spots (the question it may start with, and the
 * list of "A place nearby"), and prints the credit under each itself.
 */
export default function SpotForm({ mode, initial, spotId, nearbyHosts, presentation, onSaved, onCancel }: SpotFormProps) {
  const ids = useId();
  const { A, profile, cal, region } = useTruck();
  const windowHours = useTruckUiStore((s) => s.windowHours);
  const editing = mode === 'edit';
  const saved = useSpot(editing ? spotId : null).data ?? null;
  const create = useCreateSpot();
  const update = useUpdateSpot();
  const busy = create.isPending || update.isPending;

  const [base, setBase] = useState<SpotDraft>(() => draftFromBody(initial));
  const [draft, setDraft] = useState<SpotDraft>(base);
  const [asked, setAsked] = useState(false);
  const [tried, setTried] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [placeOpen, setPlaceOpen] = useState(() => base.point === null);
  const [coordsOpen, setCoordsOpen] = useState(false);
  const [coordsText, setCoordsText] = useState('');
  const form = useRef<HTMLFormElement>(null);

  const terms = useMemo(() => draftModelTerms(draft, editing && spotId !== undefined ? spotId : null), [draft, editing, spotId]);
  const est = useSpotEstimate({
    point: draft.point,
    spot: saved,
    terms,
    hostPlaceKey: draftPlaceKey(draft),
    editing: true,
    // A saved spot is estimated from its stored vectors; the places around it are asked for only
    // when the owner wants to pick one.
    withPlaces: editing && draft.hostChoice === 'place',
    windowHours,
  });

  // The places around the point: the list that came with the estimate of this point, and until it
  // is there the list the caller handed over (the spot card's, for the point that was clicked).
  const places: readonly HostHint[] | null = est.hostsNearby ?? nearbyHosts ?? null;
  // What the list knows about the linked place of a saved spot (its name, its typical size) is
  // added to both drafts, so it never counts as a change.
  const shown = useMemo(() => describeLinkedPlace(draft, places), [draft, places]);
  const shownBase = useMemo(() => describeLinkedPlace(base, places), [base, places]);
  const errors = validateSpotDraft(shown);
  const visible = tried ? errors : {};
  const patch = useMemo(() => patchFromDrafts(shownBase, shown), [shownBase, shown]);
  const dirty = !editing || Object.keys(patch).length > 0;

  const rebuilding = regionRebuilding(region);
  let placesState: 'no-point' | 'loading' | 'ready' | 'error' | 'unavailable' = 'loading';
  if (draft.point === null) placesState = 'no-point';
  else if (places !== null) placesState = 'ready';
  else if (rebuilding) placesState = 'unavailable';
  else if (est.status === 'error') placesState = 'error';

  const question = !editing && !asked && draft.hostChoice === 'none' ? hostQuestion(places) : null;

  // The best window of the typical week with these terms, through the same function the list uses.
  const typical = useMemo(() => typicalWeek(A), [A]);
  const bestWindow = est.best.length > 0 ? est.best[0] : null;
  const figures = useMemo(() => {
    if (bestWindow === null || est.terms === null || est.vectors === null) return null;
    return windowFigures(est.terms, est.vectors, A, profile, cal, typical, choiceOfBest(bestWindow));
  }, [bestWindow, est.terms, est.vectors, A, profile, cal, typical]);

  const change = (next: SpotDraft) => {
    setDraft(next);
    setProblem(null);
  };

  const setPoint = (lat: number, lng: number, address: string, name: string) => {
    change({ ...draft, point: { lat, lng }, address, name: draft.name.trim() === '' ? name : draft.name });
    setPlaceOpen(false);
    setCoordsOpen(false);
    setCoordsText('');
  };

  const typedCoords = coordsText.trim() === '' ? null : parseCoords(coordsText);
  const coordsError = coordsText.trim() !== '' && typedCoords === null ? 'Enter latitude and longitude, like 38.9696, -77.3861.' : null;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (busy) return;
    // A number or time field that still shows its own refusal holds the save back too: what is on
    // screen there is not what would be saved.
    const refused = form.current === null ? null : form.current.querySelector<HTMLElement>('[aria-invalid="true"]');
    if (Object.keys(errors).length > 0 || refused !== null) {
      setTried(true);
      setProblem('Check the marked fields first. Nothing was saved.');
      if (refused !== null) refused.focus();
      return;
    }
    setProblem(null);
    if (!editing) {
      create
        .mutateAsync(bodyFromDraft(shown))
        .then((spot) => onSaved(spot))
        .catch(() => {
          // The hook has shown the server's sentence (the 409 "You can keep at most 500 spots" as it is).
        });
      return;
    }
    if (spotId === undefined || Object.keys(patch).length === 0) return;
    update
      .mutateAsync({ id: spotId, patch })
      .then((spot) => {
        const next = draftFromBody(spotBodyOf(spot));
        setBase(next);
        setDraft(next);
        setTried(false);
        onSaved(spot);
      })
      .catch(() => {
        // The hook has rolled back and shown the server's sentence.
      });
  };

  const cancel = () => {
    setDraft(base);
    setTried(false);
    setProblem(null);
    setPlaceOpen(base.point === null);
    setCoordsOpen(false);
    setCoordsText('');
    onCancel();
  };

  const modal = presentation === 'modal';

  return (
    // The form sits in a 720 px modal and in the narrow column of the spot page: its grids follow
    // the width the form itself has (a container query), not the width of the window.
    <form ref={form} onSubmit={submit} noValidate className="@container space-y-5">
      {question !== null ? (
        <div className="rounded-xl border p-3 sm:p-4" style={{ background: 'var(--bg-panel)', borderColor: 'var(--line-soft)' }}>
          <p className="text-sm font-bold" style={{ color: 'var(--ink)' }}>
            Is this at {question.name} ({placeTypeLabel(question.place_type)}, {distanceText(question.distance_m)} away)?
          </p>
          <div className="mt-2.5 flex flex-wrap gap-2">
            <button
              type="button"
              className="btn btn-secondary h-11 md:h-9 px-3 text-sm"
              onClick={() => {
                change(linkPlace(draft, question));
                setAsked(true);
              }}
            >
              Yes, link it
            </button>
            <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={() => setAsked(true)}>
              No
            </button>
          </div>
          <SourceLine kinds={['osm']} className="mt-2.5" />
        </div>
      ) : null}

      <Group title="Place">
        <div role="group" aria-labelledby={ids + 'place-label'}>
          <div id={ids + 'place-label'} className="label">
            Address or place
          </div>
          {draft.point !== null ? (
            <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
              <p className="flex min-w-0 items-start gap-1.5 text-sm font-semibold" style={{ color: 'var(--ink)' }}>
                <MapPin size={15} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--brand)' }} />
                <span className="min-w-0">
                  {draft.address !== '' ? <span className="block">{draft.address}</span> : null}
                  <span className="block tabular-nums" style={{ color: draft.address !== '' ? 'var(--body)' : 'var(--ink)' }}>
                    {fmtCoord(draft.point.lat, draft.point.lng)}
                  </span>
                </span>
              </p>
              <button
                type="button"
                className="inline-flex min-h-[44px] md:min-h-0 items-center gap-1 text-[13px] font-bold underline underline-offset-2"
                style={{ color: 'var(--ink)' }}
                aria-expanded={placeOpen}
                onClick={() => setPlaceOpen(!placeOpen)}
              >
                <PenLine size={13} aria-hidden />
                {placeOpen ? 'Keep this place' : 'Change'}
              </button>
            </div>
          ) : null}
          {placeOpen ? (
            <div className={draft.point !== null ? 'mt-2 space-y-1.5' : 'space-y-1.5'}>
              <PlaceSearch onPick={(place) => setPoint(place.lat, place.lng, place.address, place.name)} />
              <button
                type="button"
                className="inline-flex min-h-[44px] md:min-h-[32px] items-center gap-1 text-[13px] font-bold"
                style={{ color: 'var(--nav-active-fg)' }}
                aria-expanded={coordsOpen}
                onClick={() => setCoordsOpen(!coordsOpen)}
              >
                {coordsOpen ? <ChevronDown size={14} aria-hidden /> : <ChevronRight size={14} aria-hidden />}
                Enter coordinates instead
              </button>
              {coordsOpen ? (
                <div className="flex flex-wrap items-end gap-2">
                  <Field id={ids + 'coords'} label="Latitude, longitude" error={coordsError} className="min-w-0 flex-1">
                    {(control) => (
                      <input
                        {...control}
                        type="text"
                        className="input h-11 md:h-9 text-sm tabular-nums font-semibold"
                        placeholder="38.9696, -77.3861"
                        autoComplete="off"
                        value={coordsText}
                        onChange={(e) => setCoordsText(e.target.value)}
                      />
                    )}
                  </Field>
                  <button
                    type="button"
                    className={'btn btn-secondary h-11 md:h-9 px-3 text-sm' + (coordsError !== null ? ' mb-5' : '')}
                    disabled={typedCoords === null}
                    onClick={() => {
                      if (typedCoords === null) return;
                      // Typed coordinates carry no address: the old one would describe another place.
                      const same = draft.point !== null && draft.point.lat === typedCoords.lat && draft.point.lng === typedCoords.lng;
                      setPoint(typedCoords.lat, typedCoords.lng, same ? draft.address : '', '');
                    }}
                  >
                    Use these coordinates
                  </button>
                </div>
              ) : null}
              {!editing ? (
                <div>
                  <Link
                    to="/truck/map?pick=spot"
                    className="inline-flex min-h-[44px] md:min-h-[32px] items-center gap-1.5 text-[13px] font-bold underline underline-offset-2"
                    style={{ color: 'var(--ink)' }}
                  >
                    <MapPinned size={14} aria-hidden />
                    Pick on the map
                  </Link>
                </div>
              ) : null}
            </div>
          ) : null}
          {visible.point !== undefined ? <Problem text={visible.point} /> : null}
          {visible.address !== undefined ? <Problem text={visible.address} /> : null}
        </div>

        <Field id={ids + 'name'} label="Name" error={visible.name} required>
          {(control) => (
            <input
              {...control}
              type="text"
              className="input h-11 md:h-9 text-sm font-semibold"
              value={draft.name}
              maxLength={SPOT_LIMITS.nameMax}
              autoComplete="off"
              onChange={(e) => change({ ...draft, name: e.target.value })}
            />
          )}
        </Field>

        <Field id={ids + 'notes'} label="Notes" error={visible.notes}>
          {(control) => (
            <textarea
              {...control}
              className="textarea text-sm font-semibold"
              style={{ height: 'auto', minHeight: 72 }}
              rows={3}
              maxLength={SPOT_LIMITS.notesMax}
              placeholder="Gate code, where to park, who to ask for"
              value={draft.notes}
              onChange={(e) => change({ ...draft, notes: e.target.value })}
            />
          )}
        </Field>
      </Group>

      <Group title="Host">
        <HostPicker
          idPrefix={ids}
          draft={shown}
          onChange={change}
          places={places}
          placesState={placesState}
          errors={visible}
          placesCredit={<SourceLine kinds={['osm']} />}
        />
      </Group>

      <Group title="Visibility">
        <fieldset>
          <legend className="label">How easy is the truck to see?</legend>
          <div className="grid gap-2 @[560px]:grid-cols-3">
            {VISIBILITIES.map((level) => {
              const on = draft.visibility === level;
              const text = VISIBILITY_TEXT[level];
              return (
                <label
                  key={level}
                  className={'flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5 min-h-[44px] md:min-h-0' + (on ? '' : ' bg-white')}
                  style={{ borderColor: on ? 'var(--brand)' : 'var(--line)', background: on ? 'var(--brand-light)' : undefined }}
                >
                  <input
                    type="radio"
                    name={ids + 'visibility'}
                    className="mt-0.5 h-4 w-4 flex-none"
                    style={{ accentColor: 'var(--brand)' }}
                    checked={on}
                    onChange={() => change({ ...draft, visibility: level })}
                  />
                  <span className="min-w-0">
                    <span className="block text-sm font-bold leading-snug" style={{ color: 'var(--ink)' }}>
                      {text.label}
                    </span>
                    {text.help !== '' ? (
                      <span className="block text-xs font-semibold" style={{ color: 'var(--body)' }}>
                        {text.help}
                      </span>
                    ) : null}
                  </span>
                </label>
              );
            })}
          </div>
        </fieldset>
      </Group>

      <Group title="Fee">
        <div className="grid gap-3 @[420px]:grid-cols-3">
          <MoneyField
            id={ids + 'fee-flat'}
            label="Flat fee"
            value={draft.fee_flat}
            onCommit={(fee_flat) => change({ ...draft, fee_flat })}
            min={SPOT_LIMITS.feeMin}
            max={SPOT_LIMITS.feeMax}
            error={visible.fee_flat}
          />
          <NumberField
            id={ids + 'fee-pct'}
            label="Share of sales"
            format="percent"
            value={draft.fee_pct}
            onCommit={(fee_pct) => change({ ...draft, fee_pct })}
            min={SPOT_LIMITS.feePctMin}
            max={SPOT_LIMITS.feePctMax}
            error={visible.fee_pct}
          />
          <MoneyField
            id={ids + 'fee-min'}
            label="Minimum fee"
            value={draft.fee_min}
            onCommit={(fee_min) => change({ ...draft, fee_min })}
            min={SPOT_LIMITS.feeMin}
            max={SPOT_LIMITS.feeMax}
            error={visible.fee_min}
          />
        </div>
        <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
          You pay the flat fee plus the share of sales, or the minimum if that is more.
        </p>
      </Group>

      <Group title="Days and hours">
        <Toggle
          id={ids + 'allowed-on'}
          label="Only on certain days or hours"
          checked={draft.allowedOn}
          onChange={(allowedOn) => change({ ...draft, allowedOn })}
          help="The planner warns when a stop falls outside these."
        />
        {draft.allowedOn ? (
          <div className="space-y-3">
            <fieldset>
              <legend className="label">Days</legend>
              <div className="flex flex-wrap gap-1.5">
                {DAYS.map((dow) => {
                  const on = draft.allowedDays[dow] === true;
                  return (
                    <label
                      key={dow}
                      className={'inline-flex h-11 md:h-9 cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 text-sm font-bold' + (on ? '' : ' bg-white')}
                      style={{ borderColor: on ? 'var(--brand)' : 'var(--line)', background: on ? 'var(--brand-light)' : undefined, color: 'var(--ink)' }}
                    >
                      <input
                        type="checkbox"
                        className="h-4 w-4"
                        style={{ accentColor: 'var(--brand)' }}
                        checked={on}
                        onChange={(e) => change({ ...draft, allowedDays: draft.allowedDays.map((was, i) => (i === dow ? e.target.checked : was)) })}
                      />
                      {fmtWeekday(dow, 'short')}
                    </label>
                  );
                })}
              </div>
              {visible.allowedDays !== undefined ? <Problem text={visible.allowedDays} /> : null}
            </fieldset>
            <div className="grid gap-3 @[420px]:grid-cols-2">
              <TimeField
                id={ids + 'allowed-open'}
                label="From"
                value={draft.allowedOpen}
                onCommit={(allowedOpen) => change({ ...draft, allowedOpen })}
                error={visible.allowedOpen}
              />
              <TimeField
                id={ids + 'allowed-close'}
                label="Until"
                value={draft.allowedClose}
                onCommit={(allowedClose) => change({ ...draft, allowedClose })}
                after={draft.allowedOpen === null ? undefined : draft.allowedOpen}
                allowNextDay
                error={visible.allowedClose}
              />
            </div>
          </div>
        ) : null}
      </Group>

      <section aria-label="Estimate with these terms" className="rounded-xl border bg-white p-3 sm:p-4" style={{ borderColor: 'var(--line)' }}>
        <h3 className="text-sm font-extrabold" style={{ color: 'var(--ink)' }}>
          With these terms
        </h3>
        <div className="mt-2">
          {draft.point === null ? (
            <Sentence>Pick the place of the spot to see an estimate.</Sentence>
          ) : est.status === 'rebuilding' && figures === null ? (
            <Sentence>{REGION_REBUILD_SENTENCE}</Sentence>
          ) : est.status === 'error' ? (
            <QueryError message="Could not estimate this point." onRetry={est.refetch} />
          ) : est.status === 'none' ? (
            <Sentence>{SPOT_STATE_TAGS.none}. The estimate comes with the first save.</Sentence>
          ) : est.status === 'pending' ? (
            <div aria-busy="true">
              <SkeletonRows rows={2} rowHeight={28} />
            </div>
          ) : (
            <div className="space-y-2">
              {est.status === 'outside' ? (
                <Sentence>
                  {region !== null
                    ? 'This point is outside ' + region.name + '. There is no local data here, so only a host you describe will count.'
                    : 'There is no local data for this area, so only a host you describe will count.'}
                </Sentence>
              ) : null}
              {est.status === 'rebuilding' ? <Sentence>{REGION_REBUILD_SENTENCE}</Sentence> : null}
              {figures === null ? (
                est.status === 'outside' ? null : (
                  <Sentence>No hour of the week reaches one order here.</Sentence>
                )
              ) : (
                <>
                  <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
                    Best window of a typical week:{' '}
                    <span className="font-bold" style={{ color: 'var(--ink)' }}>
                      {fmtWeekday(figures.choice.dow, 'long')} {fmtWindow(figures.choice.open, figures.choice.close)}
                    </span>
                  </p>
                  <div className="grid gap-3 @[420px]:grid-cols-2">
                    <RangeValue estimate={figures.window.orders} unit="orders" size="md" label="ORDERS" dim={est.dim} />
                    <RangeValue estimate={figures.money.contribution} unit="money" size="md" label="LEFT AFTER FOOD AND FEES" dim={est.dim} />
                  </div>
                  {est.dim ? <Sentence>Updating for the new place or host. These are the figures from before.</Sentence> : null}
                </>
              )}
            </div>
          )}
        </div>
      </section>

      <div
        className={
          'flex flex-wrap items-center justify-end gap-2 ' +
          // In a modal the buttons stay in view: stuck to the bottom edge of the scrolling body, over its padding.
          (modal ? 'sticky -bottom-4 z-10 -mx-4 sm:-mx-5 -mb-4 border-t bg-white px-4 sm:px-5 py-3' : 'border-t pt-3')
        }
        style={{ borderColor: 'var(--line-soft)' }}
      >
        {problem !== null ? (
          <p role="alert" className="mr-auto flex items-start gap-1.5 text-[13px] font-bold" style={{ color: 'var(--money-negative)' }}>
            <TriangleAlert size={14} aria-hidden className="mt-0.5 flex-none" />
            <span>{problem}</span>
          </p>
        ) : editing && dirty ? (
          <p className="mr-auto text-[13px] font-bold" style={{ color: 'var(--ink)' }}>
            Unsaved changes
          </p>
        ) : null}
        <button type="button" className="btn btn-secondary h-11 md:h-9 px-3 text-sm" onClick={cancel} disabled={busy || (editing && !dirty)}>
          Cancel
        </button>
        <button type="submit" className={'btn h-11 md:h-9 px-4 text-sm ' + (modal ? 'btn-primary' : 'btn-secondary')} disabled={busy || !dirty}>
          {busy ? 'Saving...' : 'Save spot'}
        </button>
      </div>
    </form>
  );
}

function Group({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-3">
      <h3 className="border-b pb-1.5 text-sm font-extrabold" style={{ color: 'var(--ink)', borderColor: 'var(--line-soft)' }}>
        {title}
      </h3>
      {children}
    </section>
  );
}

function Sentence({ children }: { children: ReactNode }) {
  return (
    <p className="text-sm font-semibold" style={{ color: 'var(--body)' }}>
      {children}
    </p>
  );
}

function Problem({ text }: { text: string }) {
  return (
    <p role="alert" className="mt-1 flex items-start gap-1 text-xs font-semibold" style={{ color: 'var(--money-negative)' }}>
      <TriangleAlert size={13} aria-hidden className="mt-px flex-none" />
      <span>{text}</span>
    </p>
  );
}

/**
 * Address search with the truck props of the shared widget. A component of its own, so Google is
 * only loaded once the owner opens the place editor. When Google cannot be used the sentence says
 * so and the coordinates field under it remains.
 */
function PlaceSearch({ onPick }: { onPick: (place: { lat: number; lng: number; address: string; name: string }) => void }) {
  const { isLoaded, loadError } = useTruckMapsLoader();
  const refused = useTruckUiStore((s) => s.mapsAuthFailed);
  if (loadError !== undefined || refused) {
    return (
      <p className="text-xs font-semibold" style={{ color: 'var(--body)' }}>
        {ADDRESS_UNAVAILABLE}
      </p>
    );
  }
  if (!isLoaded) return <div aria-hidden className="skeleton" style={{ height: 40, borderRadius: 8 }} />;
  return (
    <GooglePlaceAutocomplete
      placeholder="Search an address"
      types={ADDRESS_TYPES}
      countries={ADDRESS_COUNTRIES}
      fields={ADDRESS_FIELDS}
      unavailableText={ADDRESS_UNAVAILABLE}
      onPlace={(place) => onPick({ lat: place.lat, lng: place.lng, address: place.address || place.name, name: place.name })}
    />
  );
}

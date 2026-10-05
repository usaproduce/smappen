import { useId, useState, type FormEvent } from 'react';
import toast from 'react-hot-toast';
import { ChevronDown, ChevronRight, TriangleAlert, Truck } from 'lucide-react';
import type { RegionInfo } from '../../api/truck';
import { SEEDS } from '../../utils/truck/model';
import { parseCoords } from '../../utils/truck/format';
import GooglePlaceAutocomplete from '../common/GooglePlaceAutocomplete';
import { useSaveProfile } from './data/mutations';
import { useAddressSearchAvailable } from './data/useMapsLoader';
import { Field, MoneyField } from './ui';

/**
 * The first-run step (docs/truck-planner/05_FRONTEND.md 1.4): shown by the gate in place of the
 * page, on every /truck route, until the organization has a truck. Three things: a name, the base
 * and the average ticket. The server fills every other field from its defaults.
 *
 * The base comes from address search or typed coordinates (later also from a click on the map).
 * Never from the device: Truck Planner does not ask the browser where it is.
 */

const NAME_MAX = 120;

/** The props every address field of Truck Planner passes to the shared address widget (1.6). */
const ADDRESS_TYPES: string[] = [];
const ADDRESS_COUNTRIES = ['us'];
const ADDRESS_FIELDS = ['place_id', 'name', 'formatted_address', 'geometry'];
const ADDRESS_UNAVAILABLE = 'Address search is unavailable. Enter coordinates instead.';

const TIMEZONE_ASSUMED = 'We assumed Eastern time for this truck.';

interface Base {
  lat: number;
  lng: number;
  address: string;
}

/** True when a point lies in the bounding box of a region. The box only hints: the server decides membership. */
function inBox(point: { lat: number; lng: number }, region: RegionInfo): boolean {
  const b = region.bbox;
  return point.lat >= b.lat_min && point.lat <= b.lat_max && point.lng >= b.lng_min && point.lng <= b.lng_max;
}

/** The out-of-area line, naming the regions that do have data. */
function outsideSentence(regions: readonly RegionInfo[]): string {
  const names = regions.map((region) => region.name).join(', ');
  return (
    'This is outside the area we have data for' +
    (names === '' ? '' : ' (' + names + ')') +
    '. You can save it, but the map and the estimates will be empty.'
  );
}

export default function SetupTruck({ regions }: { regions: readonly RegionInfo[] }) {
  const ids = useId();
  const nameId = ids + 'name';
  const baseLabelId = ids + 'base';
  const baseHelpId = ids + 'base-help';
  const coordsId = ids + 'coords';

  const ticketSeed = SEEDS.profile_defaults.avg_ticket;
  const searchAvailable = useAddressSearchAvailable();
  const save = useSaveProfile();

  const [name, setName] = useState('');
  const [nameTouched, setNameTouched] = useState(false);
  const [picked, setPicked] = useState<Base | null>(null);
  const [coordsOpen, setCoordsOpen] = useState(false);
  const [coordsText, setCoordsText] = useState('');
  const [ticket, setTicket] = useState<number | null>(ticketSeed.value);

  // The input stops at 120 characters, so the only way to be wrong is to be empty.
  const trimmedName = name.trim();
  const nameValid = trimmedName.length >= 1 && trimmedName.length <= NAME_MAX;
  const nameError = nameTouched && trimmedName.length === 0 ? 'Required' : null;

  // Coordinates, once typed, are what counts; picking an address clears them again.
  const hasCoordsText = coordsText.trim() !== '';
  const typed = hasCoordsText ? parseCoords(coordsText) : null;
  const coordsError = hasCoordsText && typed === null ? 'Enter latitude and longitude, like 38.9696, -77.3861.' : null;
  const base: Base | null = hasCoordsText ? (typed === null ? null : { lat: typed.lat, lng: typed.lng, address: '' }) : picked;
  // Without address search the coordinates field is the only way in, so it is simply there.
  const showCoords = !searchAvailable || coordsOpen || hasCoordsText;

  const outside = base !== null && !regions.some((region) => inBox(base, region));
  const ready = nameValid && base !== null && ticket !== null && !save.isPending;

  function submit(event: FormEvent) {
    event.preventDefault();
    setNameTouched(true);
    if (!nameValid || base === null || ticket === null || save.isPending) return;
    // The promise, not a callback of mutate(): by the time the save has settled the gate has taken
    // this step off the screen, and callbacks of an unmounted component are never called.
    save
      .mutateAsync({ name: trimmedName, base: { lat: base.lat, lng: base.lng, address: base.address }, avg_ticket: ticket })
      .then((answer) => {
        // The page behind this step is on screen by now: say what the server decided.
        if (answer.warnings.includes('timezone_assumed')) toast(TIMEZONE_ASSUMED, { duration: 8000 });
        if (answer.warnings.includes('base_outside_region')) toast(outsideSentence(regions), { duration: 8000 });
      })
      .catch(() => {
        // The hook has already shown the server's sentence.
      });
  }

  return (
    <form
      onSubmit={submit}
      noValidate
      className="bg-white rounded-xl border p-4 sm:p-6 mx-auto"
      style={{ maxWidth: 560, borderColor: 'var(--line-soft)' }}
    >
      <h1 className="text-2xl font-extrabold flex items-center gap-2" style={{ color: 'var(--ink)' }}>
        <Truck size={22} style={{ color: 'var(--brand)' }} aria-hidden="true" /> Set up your truck
      </h1>
      <p className="text-sm font-medium mt-1.5" style={{ color: 'var(--body)' }}>
        Three things to start. You can change them, and everything else, in Settings.
      </p>

      <div className="mt-5 space-y-5">
        <Field id={nameId} label="Truck name" error={nameError} required>
          {(control) => (
            <input
              {...control}
              type="text"
              className="input h-11 md:h-10 text-sm"
              value={name}
              maxLength={NAME_MAX}
              autoComplete="off"
              onChange={(e) => setName(e.target.value)}
              onBlur={() => setNameTouched(true)}
            />
          )}
        </Field>

        {/* The address widget owns its input, so the two ways of giving the base share one group label. */}
        <div role="group" aria-labelledby={baseLabelId} aria-describedby={baseHelpId}>
          <div id={baseLabelId} className="label">
            Where the truck starts and ends its day
          </div>
          {searchAvailable && (
            <>
              <GooglePlaceAutocomplete
                placeholder="Search an address"
                types={ADDRESS_TYPES}
                countries={ADDRESS_COUNTRIES}
                fields={ADDRESS_FIELDS}
                unavailableText={ADDRESS_UNAVAILABLE}
                onPlace={(place) => {
                  setPicked({ lat: place.lat, lng: place.lng, address: place.address || place.name });
                  setCoordsText('');
                }}
                onChange={() => setPicked(null)}
              />
              <button
                type="button"
                className="inline-flex items-center gap-1 mt-1.5 min-h-[44px] md:min-h-[32px] text-[13px] font-bold"
                style={{ color: 'var(--nav-active-fg)' }}
                aria-expanded={showCoords}
                aria-controls={coordsId + '-box'}
                onClick={() => {
                  // Closing it takes the coordinates back: what is not on screen must not count.
                  if (showCoords) setCoordsText('');
                  setCoordsOpen(!showCoords);
                }}
              >
                {showCoords ? <ChevronDown size={14} aria-hidden="true" /> : <ChevronRight size={14} aria-hidden="true" />}
                Enter coordinates instead
              </button>
            </>
          )}
          {showCoords && (
            <div id={coordsId + '-box'}>
              <Field id={coordsId} label="Latitude, longitude" error={coordsError}>
                {(control) => (
                  <input
                    {...control}
                    type="text"
                    className="input h-11 md:h-10 text-sm tabular-nums"
                    placeholder="38.9696, -77.3861"
                    autoComplete="off"
                    value={coordsText}
                    onChange={(e) => setCoordsText(e.target.value)}
                  />
                )}
              </Field>
            </div>
          )}
          <p id={baseHelpId} className="text-xs font-semibold mt-2" style={{ color: 'var(--body)' }}>
            This is your base: a commissary, a lot or your driveway. It is only used for drive times and weather.
          </p>
          {outside && (
            <p role="status" className="flex items-start gap-1.5 text-[13px] font-semibold mt-2" style={{ color: 'var(--ink)' }}>
              <TriangleAlert size={15} className="flex-shrink-0 mt-0.5" style={{ color: 'var(--fresh-aging)' }} aria-hidden="true" />
              <span>{outsideSentence(regions)}</span>
            </p>
          )}
        </div>

        <MoneyField
          id={ids + 'ticket'}
          label="Average ticket"
          value={ticket}
          onCommit={setTicket}
          min={ticketSeed.min}
          max={ticketSeed.max}
          help="What one order comes to on average, before tax and tips."
          required
          className="max-w-[260px]"
        />
      </div>

      <button type="submit" className="btn btn-primary h-11 md:h-10 px-4 text-sm mt-6 w-full sm:w-auto" disabled={!ready}>
        {save.isPending ? 'Saving...' : 'Save and continue'}
      </button>

      <p className="text-xs font-semibold mt-5 pt-4 border-t" style={{ color: 'var(--body)', borderColor: 'var(--line-soft)' }}>
        Truck Planner never tracks your location. The only places it knows are the ones you enter.
      </p>
    </form>
  );
}

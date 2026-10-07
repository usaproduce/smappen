import { useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronDown, ChevronRight, Home, MapPinned, PenLine, TriangleAlert } from 'lucide-react';
import type { RegionInfo } from '../../../api/truck';
import { useTruckUiStore } from '../../../stores/truckUiStore';
import { fmtCoord, parseCoords } from '../../../utils/truck/format';
import GooglePlaceAutocomplete from '../../common/GooglePlaceAutocomplete';
import { useTruckMapsLoader } from '../data';
import { Field } from '../ui';

export interface BaseValue {
  lat: number;
  lng: number;
  address: string;
}

export interface BasePickerProps {
  value: BaseValue;
  onChange: (next: BaseValue) => void;
  /** Every region with data: a base outside all of their boxes gets the out-of-area hint. */
  regions: readonly RegionInfo[];
  error?: string;
}

/** The props every address field of Truck Planner passes to the shared address widget (1.6). */
const ADDRESS_TYPES: string[] = [];
const ADDRESS_COUNTRIES = ['us'];
const ADDRESS_FIELDS = ['place_id', 'name', 'formatted_address', 'geometry'];
const ADDRESS_UNAVAILABLE = 'Address search is unavailable. Enter coordinates instead.';

/** Where "Pick on the map" goes, and where the map sends the owner back to. */
const PICK_ON_MAP = '/truck/map?pick=base&return=' + encodeURIComponent('/truck/settings/truck');

/** True when a point lies in the bounding box of a region. The box only hints: the server decides membership. */
function inBox(point: { lat: number; lng: number }, region: RegionInfo): boolean {
  const b = region.bbox;
  return point.lat >= b.lat_min && point.lat <= b.lat_max && point.lng >= b.lng_min && point.lng <= b.lng_max;
}

/** The out-of-area line, naming the regions that do have data (the sentence of the first-run step). */
export function outsideSentence(regions: readonly RegionInfo[]): string {
  const names = regions.map((region) => region.name).join(', ');
  return 'This is outside the area we have data for' + (names === '' ? '' : ' (' + names + ')') + '. You can save it, but the map and the estimates will be empty.';
}

/**
 * The truck's base (docs/truck-planner/05_FRONTEND.md 4.9): where it starts and ends its day. It
 * is changed by address search, by typed coordinates or by a click on the map. Never from the
 * device: Truck Planner does not ask the browser where it is.
 */
export default function BasePicker({ value, onChange, regions, error }: BasePickerProps) {
  const ids = useId();
  const [open, setOpen] = useState(false);
  const [coordsOpen, setCoordsOpen] = useState(false);
  const [coordsText, setCoordsText] = useState('');

  const typed = coordsText.trim() === '' ? null : parseCoords(coordsText);
  const coordsError = coordsText.trim() !== '' && typed === null ? 'Enter latitude and longitude, like 38.9696, -77.3861.' : null;
  const outside = regions.length > 0 && !regions.some((region) => inBox(value, region));

  const pick = (next: BaseValue) => {
    onChange(next);
    setOpen(false);
    setCoordsOpen(false);
    setCoordsText('');
  };

  return (
    <div role="group" aria-labelledby={ids + 'label'}>
      <div id={ids + 'label'} className="label">
        Base
      </div>
      <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
        <p className="flex min-w-0 items-start gap-1.5 text-sm font-semibold" style={{ color: 'var(--ink)' }}>
          <Home size={15} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--brand)' }} />
          <span className="min-w-0">
            {value.address !== '' ? <span className="block">{value.address}</span> : null}
            <span className="block tabular-nums" style={{ color: value.address !== '' ? 'var(--body)' : 'var(--ink)' }}>
              {fmtCoord(value.lat, value.lng)}
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
          {open ? 'Keep this base' : 'Change'}
        </button>
      </div>

      {open ? (
        <div className="mt-2 space-y-1.5">
          <AddressSearch onPick={pick} />
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
                disabled={typed === null}
                onClick={() => {
                  if (typed !== null) pick({ lat: typed.lat, lng: typed.lng, address: '' });
                }}
              >
                Use these coordinates
              </button>
            </div>
          ) : null}
          <div>
            <Link
              to={PICK_ON_MAP}
              className="inline-flex min-h-[44px] md:min-h-[32px] items-center gap-1.5 text-[13px] font-bold underline underline-offset-2"
              style={{ color: 'var(--ink)' }}
            >
              <MapPinned size={14} aria-hidden />
              Pick on the map
            </Link>
          </div>
        </div>
      ) : null}

      <p className="mt-1.5 text-xs font-semibold" style={{ color: 'var(--body)' }}>
        A commissary, a lot or your driveway. It is only used for drive times and weather.
      </p>
      {outside ? (
        <p role="status" className="mt-1.5 flex items-start gap-1.5 text-[13px] font-semibold" style={{ color: 'var(--ink)' }}>
          <TriangleAlert size={15} aria-hidden className="mt-0.5 flex-none" style={{ color: 'var(--fresh-aging)' }} />
          <span>{outsideSentence(regions)}</span>
        </p>
      ) : null}
      {error !== undefined && error !== '' ? (
        <p role="alert" className="mt-1 flex items-start gap-1 text-xs font-semibold" style={{ color: 'var(--money-negative)' }}>
          <TriangleAlert size={13} aria-hidden className="mt-px flex-none" />
          <span>{error}</span>
        </p>
      ) : null}
    </div>
  );
}

/** The address widget with the truck props. Google is loaded only once the owner opens the editor. */
function AddressSearch({ onPick }: { onPick: (base: BaseValue) => void }) {
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
    <div className="tp-address">
      <GooglePlaceAutocomplete
        placeholder="Search an address"
        types={ADDRESS_TYPES}
        countries={ADDRESS_COUNTRIES}
        fields={ADDRESS_FIELDS}
        unavailableText={ADDRESS_UNAVAILABLE}
        onPlace={(place) => onPick({ lat: place.lat, lng: place.lng, address: place.address || place.name })}
      />
    </div>
  );
}

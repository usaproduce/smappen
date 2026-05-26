import { useEffect, useRef, useState } from 'react';
import { useJsApiLoader } from '@react-google-maps/api';
import { Search, MapPin, Loader2 } from 'lucide-react';
import { GOOGLE_MAPS_LIBRARIES } from '../../utils/mapsLoader';

/**
 * Shared Google Places autocomplete input.
 *
 * Wraps the Google Places JS SDK's classic Autocomplete on a plain
 * <input>. When the user picks a suggestion we read `place_id`, name,
 * formatted address, lat/lng, phone (international format), and website
 * — those are the fields the restaurant form needs.
 *
 * Why classic Autocomplete and not the new PlaceAutocompleteElement web
 * component:
 *   - It composes naturally with existing form chrome (label, error
 *     state, focus ring). The web component injects its own shadow DOM
 *     styling that doesn't blend with the rest of the app.
 *   - The classic widget bills via per-session pricing already — same
 *     as the other autocomplete in FirstRunWizard. No new SKU surface.
 *
 * Loader: we share the same options as every other useJsApiLoader call
 * via GOOGLE_MAPS_LIBRARIES so the loader doesn't crash with
 * "Loader must not be called again with different options".
 */

export interface GooglePlace {
  place_id: string;
  name: string;
  address: string;
  lat: number;
  lng: number;
  phone: string | null;
  website: string | null;
}

interface Props {
  value?: string;
  placeholder?: string;
  /** Bias to a single country (e.g. ['us']). Omit for worldwide. */
  countries?: string[];
  /** Types filter — default ['establishment'] so businesses surface ahead of street addresses. */
  types?: string[];
  onPlace: (place: GooglePlace) => void;
  /** Fired when the user types — lets the parent clear a previously-picked place if they edit. */
  onChange?: (raw: string) => void;
  autoFocus?: boolean;
}

export default function GooglePlaceAutocomplete({
  value: controlledValue,
  placeholder = 'Search restaurants, addresses, places…',
  countries,
  types = ['establishment'],
  onPlace,
  onChange,
  autoFocus,
}: Props) {
  const apiKey = (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '';
  const { isLoaded, loadError } = useJsApiLoader({
    googleMapsApiKey: apiKey,
    libraries: GOOGLE_MAPS_LIBRARIES,
  });

  const inputRef = useRef<HTMLInputElement>(null);
  const acRef = useRef<google.maps.places.Autocomplete | null>(null);
  const [internal, setInternal] = useState(controlledValue ?? '');

  // Sync controlled value into the input — but only when it differs, so
  // typing doesn't fight the parent.
  useEffect(() => {
    if (controlledValue !== undefined && controlledValue !== internal) {
      setInternal(controlledValue);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [controlledValue]);

  // Wire the Autocomplete once the SDK is loaded.
  useEffect(() => {
    if (!isLoaded || !inputRef.current) return;
    if (typeof google === 'undefined' || !google.maps?.places?.Autocomplete) return;

    const ac = new google.maps.places.Autocomplete(inputRef.current, {
      // Restrict the FIELDS we ask Google for. Each extra field is billed
      // (Place Details "Contact" SKU = phone + website). Keep this list
      // minimal and align with restaurant-form needs.
      fields: ['place_id', 'name', 'formatted_address', 'geometry', 'international_phone_number', 'website'],
      types,
      componentRestrictions: countries ? { country: countries } : undefined,
    });
    acRef.current = ac;

    const listener = ac.addListener('place_changed', () => {
      const place = ac.getPlace();
      if (!place?.place_id || !place.geometry?.location) {
        // User hit Enter without picking a suggestion — ignore.
        return;
      }
      const result: GooglePlace = {
        place_id: place.place_id,
        name:     place.name ?? '',
        address:  place.formatted_address ?? '',
        lat:      place.geometry.location.lat(),
        lng:      place.geometry.location.lng(),
        phone:    place.international_phone_number ?? null,
        website:  place.website ?? null,
      };
      setInternal(result.name || result.address);
      onPlace(result);
    });

    return () => {
      // The .pac-container Google injects into <body> survives even after
      // the input unmounts. Sweep it on cleanup so a stale dropdown
      // doesn't haunt the next page.
      google.maps.event.removeListener(listener);
      document.querySelectorAll('.pac-container').forEach((el) => el.remove());
    };
  }, [isLoaded, types, countries, onPlace]);

  if (loadError) {
    return (
      <div className="text-xs text-rose-600 px-2 py-1.5 rounded bg-rose-50">
        Couldn't load Google Maps autocomplete. You can still add a restaurant manually.
      </div>
    );
  }

  return (
    <div className="relative">
      {isLoaded ? (
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
      ) : (
        <Loader2 size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 animate-spin" />
      )}
      <input
        ref={inputRef}
        type="text"
        className="h-10 text-sm w-full pl-9 pr-3 rounded-md border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-violet-400 focus:border-violet-400"
        placeholder={isLoaded ? placeholder : 'Loading Google…'}
        disabled={!isLoaded}
        autoFocus={autoFocus}
        value={internal}
        onChange={(e) => {
          setInternal(e.target.value);
          onChange?.(e.target.value);
        }}
      />
      <MapPin size={11} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-violet-400 pointer-events-none" />
    </div>
  );
}

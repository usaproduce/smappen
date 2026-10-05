import { useJsApiLoader } from '@react-google-maps/api';
import { GOOGLE_MAPS_LIBRARIES } from '../../../utils/mapsLoader';
import { useTruckUiStore } from '../../../stores/truckUiStore';

/**
 * The one shared Google Maps loader call of Truck Planner (docs/truck-planner/05_FRONTEND.md 1.6).
 *
 * Every `useJsApiLoader` call on a page must pass identical options or the loader throws, and the
 * address widget calls it with exactly these two. So the map, the first-run step and the spot form
 * load Google only through this hook, and nothing here may gain a third option.
 */
export function useTruckMapsLoader(): { isLoaded: boolean; loadError: Error | undefined } {
  const { isLoaded, loadError } = useJsApiLoader({
    googleMapsApiKey: (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '',
    libraries: GOOGLE_MAPS_LIBRARIES,
  });
  return { isLoaded, loadError };
}

/**
 * True when the address widget can be shown: Google has loaded, without an error, and has not
 * refused the key. Otherwise the address field is left out and the coordinates field remains.
 */
export function useAddressSearchAvailable(): boolean {
  const { isLoaded, loadError } = useTruckMapsLoader();
  const authFailed = useTruckUiStore((s) => s.mapsAuthFailed);
  return isLoaded && !loadError && !authFailed;
}

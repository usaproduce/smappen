import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';
import type { TruckMapHandle, TruckMapProps } from './types';

/**
 * STUB of the map engine's React surface (docs/truck-planner/05_FRONTEND.md 5.1 and 9.2). The map
 * engine package replaces the body and keeps the name, the props and the handle.
 *
 * Until then it is a grey panel that still behaves like a map for the page around it: a click
 * reports the centre of the region (or of the initial camera), the handle answers, and the status
 * says that there are no colours. No Google map is constructed, so no map load is logged.
 */
const TruckMap = forwardRef<TruckMapHandle, TruckMapProps>(function TruckMap(props, ref) {
  const { region, initialCamera, cursor, onClick, onStatus, children } = props;
  const camera = useRef({ lat: initialCamera.lat, lng: initialCamera.lng, zoom: initialCamera.zoom });

  useImperativeHandle(
    ref,
    () => ({
      flyTo: (next) => {
        camera.current = { lat: next.lat, lng: next.lng, zoom: next.zoom ?? camera.current.zoom };
      },
      getCenter: () => ({ lat: camera.current.lat, lng: camera.current.lng }),
      hourStrip: (_dow, done) => done(new Uint8Array(24)),
    }),
    [],
  );

  const hasData = region !== null && region.usable && region.pack !== null;
  useEffect(() => {
    onStatus?.(hasData ? 'failed' : 'no-region');
  }, [hasData, onStatus]);

  const centre = region !== null ? region.center : { lat: initialCamera.lat, lng: initialCamera.lng };

  return (
    <div
      className="absolute inset-0 grid place-items-center text-sm font-semibold"
      style={{ background: 'var(--bg-panel)', color: 'var(--body)', cursor: cursor === 'crosshair' ? 'crosshair' : 'default' }}
      onClick={() => onClick?.({ lat: centre.lat, lng: centre.lng })}
      data-tp-map-stub=""
    >
      Map engine not installed
      {/* Pins mount (and render nothing) so that the page's pin components run as they will later. */}
      {children}
    </div>
  );
});

export default TruckMap;

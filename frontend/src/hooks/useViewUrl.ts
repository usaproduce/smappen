import { useEffect } from 'react';
import { useMapStore } from '../stores/mapStore';

/**
 * VT17 — keep the URL hash in sync with the current map viewport so
 * refresh preserves position + sharing a link drops the recipient at
 * the same view. Format: `#map=lat,lng,zoom`.
 *
 * - Reads the hash on mount and applies it (one-shot).
 * - Writes back whenever the map idles (debounced to avoid churn).
 */
export function useViewUrl() {
  const mapInstance = useMapStore((s) => s.mapInstance);
  const setCenter = useMapStore((s) => s.setCenter);
  const setZoom = useMapStore((s) => s.setZoom);

  // One-shot: read on first map load.
  useEffect(() => {
    if (!mapInstance) return;
    const m = location.hash.match(/map=(-?\d+\.?\d*),(-?\d+\.?\d*),(\d+\.?\d*)/);
    if (m) {
      const lat = parseFloat(m[1]);
      const lng = parseFloat(m[2]);
      const z = parseFloat(m[3]);
      if (Number.isFinite(lat) && Number.isFinite(lng) && Number.isFinite(z)) {
        setCenter({ lat, lng });
        setZoom(z);
        mapInstance.setCenter({ lat, lng });
        mapInstance.setZoom(z);
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mapInstance]);

  // Write hash on idle.
  useEffect(() => {
    if (!mapInstance) return;
    let timer = 0;
    function write() {
      const c = mapInstance!.getCenter();
      const z = mapInstance!.getZoom();
      if (!c || z == null) return;
      const next = `map=${c.lat().toFixed(4)},${c.lng().toFixed(4)},${Math.round(z)}`;
      // Preserve any non-map portion of the hash (e.g. compare modal state).
      const parts = location.hash.replace(/^#/, '').split('&').filter((p) => !p.startsWith('map='));
      parts.push(next);
      history.replaceState(null, '', '#' + parts.join('&'));
    }
    const listener = mapInstance.addListener('idle', () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(write, 250);
    });
    return () => {
      google.maps.event.removeListener(listener);
      window.clearTimeout(timer);
    };
  }, [mapInstance]);
}

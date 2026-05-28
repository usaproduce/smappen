import { Marker } from '@react-google-maps/api';
import { useMemo } from 'react';
import { useMapStore } from '../../stores/mapStore';
import { allOuterRings } from '../../utils/geo';
import type { Area } from '../../types';

/**
 * For MultiPolygon territories, area.center_lat/lng comes from the address
 * the user dropped, which can land OFF the polygon (or in one piece of a
 * union, ignoring the others). Recompute as the centroid of the largest
 * ring by vertex count — gives a visually-centered pin on the biggest piece.
 */
function bestPinPosition(area: Area): { lat: number; lng: number } | null {
  const g: any = area.geometry;
  // Single Polygon or any non-territory area: trust the stored center.
  if (!g || g.type !== 'MultiPolygon') {
    return area.center_lat != null && area.center_lng != null
      ? { lat: area.center_lat, lng: area.center_lng }
      : null;
  }
  // Multi-piece: pick the ring with the most vertices (≈ biggest area for
  // census-derived shapes) and return its centroid.
  const rings = allOuterRings(g);
  if (rings.length === 0) {
    return area.center_lat != null && area.center_lng != null
      ? { lat: area.center_lat, lng: area.center_lng }
      : null;
  }
  let best = rings[0];
  for (const r of rings) if (r.length > best.length) best = r;
  let sumLat = 0, sumLng = 0;
  for (const [lng, lat] of best) { sumLat += lat; sumLng += lng; }
  return { lat: sumLat / best.length, lng: sumLng / best.length };
}

function pinSvg(color: string): string {
  // viewBox is padded 1px on each side so the 2px center-aligned stroke
  // doesn't get clipped at the tip/edges (path touches 0/22/28).
  const svg = `
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="30" viewBox="-1 -1 24 30">
  <path d="M11 0C5 0 0 4.5 0 10.3 0 18 11 28 11 28s11-10 11-17.7C22 4.5 17 0 11 0z"
        fill="${color}" stroke="#fff" stroke-width="2"/>
  <circle cx="11" cy="10.5" r="3.2" fill="#fff"/>
</svg>`;
  return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
}

function PinMarker({ area, onClick }: { area: Area; onClick: () => void }) {
  // Memoize the icon descriptor so @react-google-maps/api doesn't recreate the
  // marker on every parent render. `google.maps.*` is only safe to touch after
  // the API has loaded — useMemo runs at render but we still need a guard.
  const icon = useMemo(() => {
    if (typeof google === 'undefined' || !google.maps) return undefined;
    return {
      url: pinSvg(area.fill_color || '#7848BB'),
      scaledSize: new google.maps.Size(24, 30),
      anchor: new google.maps.Point(12, 29),
    };
  }, [area.fill_color]);

  const position = useMemo(() => bestPinPosition(area), [area.geometry, area.center_lat, area.center_lng]);
  if (!position) return null;
  return (
    <Marker
      position={position}
      icon={icon as any}
      onClick={onClick}
      title={area.name}
      zIndex={1000}
    />
  );
}

export default function AreaCenterPins({ areas }: { areas: Area[] }) {
  const { selectArea } = useMapStore();
  return (
    <>
      {areas.map((a) => (
        <PinMarker key={`pin-${a.id}`} area={a} onClick={() => selectArea(a.id)} />
      ))}
    </>
  );
}

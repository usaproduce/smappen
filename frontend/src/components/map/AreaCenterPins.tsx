import { Marker } from '@react-google-maps/api';
import { useMemo } from 'react';
import { useMapStore } from '../../stores/mapStore';
import type { Area } from '../../types';

function pinSvg(color: string): string {
  const svg = `
<svg xmlns="http://www.w3.org/2000/svg" width="22" height="28" viewBox="0 0 22 28">
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
      scaledSize: new google.maps.Size(22, 28),
      anchor: new google.maps.Point(11, 28),
    };
  }, [area.fill_color]);

  if (area.center_lat == null || area.center_lng == null) return null;
  return (
    <Marker
      position={{ lat: area.center_lat, lng: area.center_lng }}
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

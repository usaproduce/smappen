import { Marker } from '@react-google-maps/api';
import { useMapStore } from '../../stores/mapStore';
import type { Area } from '../../types';

function pinSvg(color: string): string {
  // 28px-tall map pin with white border + center dot.
  const svg = `
<svg xmlns="http://www.w3.org/2000/svg" width="22" height="28" viewBox="0 0 22 28">
  <path d="M11 0C5 0 0 4.5 0 10.3 0 18 11 28 11 28s11-10 11-17.7C22 4.5 17 0 11 0z"
        fill="${color}" stroke="#fff" stroke-width="2"/>
  <circle cx="11" cy="10.5" r="3.2" fill="#fff"/>
</svg>`;
  return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
}

export default function AreaCenterPins({ areas }: { areas: Area[] }) {
  const { selectArea } = useMapStore();
  return (
    <>
      {areas.map((a) => {
        if (a.center_lat == null || a.center_lng == null) return null;
        return (
          <Marker
            key={`pin-${a.id}`}
            position={{ lat: a.center_lat, lng: a.center_lng }}
            icon={{
              url: pinSvg(a.fill_color || '#7848BB'),
              scaledSize: new google.maps.Size(22, 28),
              anchor: new google.maps.Point(11, 28),
            } as any}
            onClick={() => selectArea(a.id)}
            title={a.name}
            zIndex={1000}
          />
        );
      })}
    </>
  );
}

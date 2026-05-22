import { Marker, InfoWindow } from '@react-google-maps/api';
import { useState } from 'react';
import { useMapStore } from '../../stores/mapStore';

export default function POIMarkers() {
  const { poiResults, showPOIs } = useMapStore();
  const [activeId, setActiveId] = useState<string | null>(null);

  if (!showPOIs) return null;

  return (
    <>
      {poiResults.map((p: any) => {
        const lat = p.location?.latitude;
        const lng = p.location?.longitude;
        if (lat == null || lng == null) return null;
        return (
          <Marker
            key={p.id}
            position={{ lat, lng }}
            icon={{
              path: typeof google !== 'undefined' ? google.maps.SymbolPath.CIRCLE : 0 as any,
              scale: 7,
              fillColor: '#dc2626',
              fillOpacity: 0.85,
              strokeColor: '#ffffff',
              strokeWeight: 2,
            }}
            onClick={() => setActiveId(p.id)}
          >
            {activeId === p.id && (
              <InfoWindow position={{ lat, lng }} onCloseClick={() => setActiveId(null)}>
                <div className="text-sm" style={{ minWidth: 200 }}>
                  <div className="font-semibold">{p.displayName?.text}</div>
                  <div className="text-xs text-slate-500">{p.formattedAddress}</div>
                  {p.rating && <div className="mt-1">⭐ {p.rating} ({p.userRatingCount})</div>}
                  {p.nationalPhoneNumber && <div><a href={`tel:${p.nationalPhoneNumber}`}>{p.nationalPhoneNumber}</a></div>}
                  {p.websiteUri && <div><a href={p.websiteUri} target="_blank" rel="noreferrer">Website</a></div>}
                </div>
              </InfoWindow>
            )}
          </Marker>
        );
      })}
    </>
  );
}

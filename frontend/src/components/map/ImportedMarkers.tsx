import { useEffect, useRef } from 'react';
import { MarkerClusterer } from '@googlemaps/markerclusterer';
import { useMapStore } from '../../stores/mapStore';
import type { ImportedPoint } from '../../types';

export default function ImportedMarkers({ points }: { points: ImportedPoint[] }) {
  const { mapInstance, showImportedPoints } = useMapStore();
  const clusterRef = useRef<MarkerClusterer | null>(null);
  const markersRef = useRef<google.maps.Marker[]>([]);

  useEffect(() => {
    if (!mapInstance) return;
    clusterRef.current?.clearMarkers();
    markersRef.current.forEach((m) => m.setMap(null));
    markersRef.current = [];

    if (!showImportedPoints) return;
    const markers = points.map((p) => {
      const m = new google.maps.Marker({
        position: { lat: p.lat, lng: p.lng },
        title: p.label ?? p.address ?? '',
      });
      const info = new google.maps.InfoWindow({
        content: `<div style="font-size:12px;"><b>${p.label ?? ''}</b><br>${p.address ?? ''}</div>`,
      });
      m.addListener('click', () => info.open({ map: mapInstance, anchor: m }));
      return m;
    });
    markersRef.current = markers;
    clusterRef.current = new MarkerClusterer({ map: mapInstance, markers });

    return () => {
      clusterRef.current?.clearMarkers();
      markers.forEach((m) => m.setMap(null));
    };
  }, [mapInstance, points, showImportedPoints]);

  return null;
}

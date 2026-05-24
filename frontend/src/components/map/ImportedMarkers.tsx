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
    // #12 — branded cluster bubbles: deep violet gradient with white count.
    // Default MarkerClusterer pin styling is generic blue; this matches the
    // rest of the Smappen palette and tells the user at a glance "this is
    // a cluster of N imported points" instead of a Google-style anonymous
    // map marker.
    const renderer = {
      render: ({ count, position }: { count: number; position: google.maps.LatLng }) => {
        const size = Math.min(72, 40 + Math.log10(count) * 12);
        const svg = encodeURIComponent(`
          <svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 64 64">
            <defs>
              <radialGradient id="g" cx="50%" cy="40%" r="55%">
                <stop offset="0%" stop-color="#9b6dd8"/>
                <stop offset="100%" stop-color="#7848BB"/>
              </radialGradient>
            </defs>
            <circle cx="32" cy="32" r="28" fill="url(#g)" stroke="white" stroke-width="3"/>
          </svg>
        `);
        return new google.maps.Marker({
          position,
          icon: {
            url: 'data:image/svg+xml;charset=UTF-8,' + svg,
            scaledSize: new google.maps.Size(size, size),
            anchor: new google.maps.Point(size / 2, size / 2),
          },
          label: { text: String(count), color: '#fff', fontSize: '12px', fontWeight: '700' },
          zIndex: 100 + count,
        });
      },
    };
    clusterRef.current = new MarkerClusterer({ map: mapInstance, markers, renderer });

    return () => {
      clusterRef.current?.clearMarkers();
      markers.forEach((m) => m.setMap(null));
    };
  }, [mapInstance, points, showImportedPoints]);

  return null;
}

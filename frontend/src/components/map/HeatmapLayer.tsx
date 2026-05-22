import { useEffect, useRef } from 'react';
import { useMapStore } from '../../stores/mapStore';
import type { ImportedPoint } from '../../types';

export default function HeatmapLayer({ points }: { points: ImportedPoint[] }) {
  const { mapInstance } = useMapStore();
  const layerRef = useRef<google.maps.visualization.HeatmapLayer | null>(null);

  useEffect(() => {
    if (!mapInstance || typeof google === 'undefined' || !google.maps.visualization) return;
    const data = points.map((p) => new google.maps.LatLng(p.lat, p.lng));
    if (layerRef.current) layerRef.current.setMap(null);
    layerRef.current = new google.maps.visualization.HeatmapLayer({ data, map: mapInstance, radius: 30, opacity: 0.7 });
    return () => layerRef.current?.setMap(null);
  }, [mapInstance, points]);

  return null;
}

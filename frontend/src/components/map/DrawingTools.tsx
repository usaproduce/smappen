import { useEffect, useRef } from 'react';
import { useMapStore } from '../../stores/mapStore';
import { googlePolygonToGeoJson } from '../../utils/geo';

export default function DrawingTools() {
  const { mapInstance, drawingType, startDrawing, setPendingIsochrone } = useMapStore();
  const dmRef = useRef<google.maps.drawing.DrawingManager | null>(null);

  useEffect(() => {
    if (!mapInstance || typeof google === 'undefined' || !google.maps?.drawing) return;

    const dm = new google.maps.drawing.DrawingManager({
      drawingMode: null,
      drawingControl: false,
      polygonOptions: { fillColor: '#6B4EFF', fillOpacity: 0.3, strokeColor: '#6B4EFF', strokeWeight: 2, editable: true },
      circleOptions: { fillColor: '#6B4EFF', fillOpacity: 0.3, strokeColor: '#6B4EFF', strokeWeight: 2, editable: true },
    });
    dm.setMap(mapInstance);
    dmRef.current = dm;

    const polyListener = google.maps.event.addListener(dm, 'polygoncomplete', (poly: google.maps.Polygon) => {
      const geom = googlePolygonToGeoJson(poly);
      setPendingIsochrone({ type: 'manual', geometry: geom });
      poly.setMap(null);
      startDrawing(null);
    });
    const circleListener = google.maps.event.addListener(dm, 'circlecomplete', (circle: google.maps.Circle) => {
      const center = circle.getCenter();
      const radiusM = circle.getRadius();
      if (center) {
        setPendingIsochrone({ type: 'radius', lat: center.lat(), lng: center.lng(), radius_km: radiusM / 1000 });
      }
      circle.setMap(null);
      startDrawing(null);
    });

    return () => {
      google.maps.event.removeListener(polyListener);
      google.maps.event.removeListener(circleListener);
      dm.setMap(null);
    };
  }, [mapInstance]);

  useEffect(() => {
    if (!dmRef.current || typeof google === 'undefined') return;
    if (drawingType === 'polygon') dmRef.current.setDrawingMode(google.maps.drawing.OverlayType.POLYGON);
    else if (drawingType === 'circle') dmRef.current.setDrawingMode(google.maps.drawing.OverlayType.CIRCLE);
    else dmRef.current.setDrawingMode(null);
  }, [drawingType]);

  return null;
}

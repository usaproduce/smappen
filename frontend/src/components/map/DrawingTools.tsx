import { useEffect } from 'react';
import { useMapStore } from '../../stores/mapStore';
import { googlePolygonToGeoJson } from '../../utils/geo';

const STROKE = { strokeColor: '#6B4EFF', strokeWeight: 2 };
// A click this close (screen px) to a vertex counts as hitting that vertex.
const SNAP_PX = 10;

/**
 * Click-to-draw polygon tool built from plain map listeners.
 *
 * Google removed the Drawing library in Maps JS v3.65 — constructing
 * google.maps.drawing.DrawingManager now throws and takes the whole map down
 * with it — so don't reintroduce it. Click adds a vertex; double-click or a
 * click on the first vertex finishes; Escape cancels.
 */
export default function DrawingTools() {
  const { mapInstance, drawingType, startDrawing, setPendingIsochrone } = useMapStore();

  useEffect(() => {
    if (!mapInstance || typeof google === 'undefined' || drawingType !== 'polygon') return;
    const map = mapInstance;
    const pts: google.maps.LatLng[] = [];
    // Both overlays are non-clickable so every click still reaches the map.
    const fill = new google.maps.Polygon({ fillColor: STROKE.strokeColor, fillOpacity: 0.3, strokeWeight: 0, clickable: false, map });
    const outline = new google.maps.Polyline({ ...STROKE, clickable: false, map });
    map.setOptions({ disableDoubleClickZoom: true });

    const near = (a: google.maps.LatLng, b: google.maps.LatLng) => {
      const metersPerPx = (156543.03392 * Math.cos((a.lat() * Math.PI) / 180)) / 2 ** (map.getZoom() ?? 10);
      return google.maps.geometry.spherical.computeDistanceBetween(a, b) / metersPerPx <= SNAP_PX;
    };
    const redraw = (cursor?: google.maps.LatLng) => {
      const path = cursor ? [...pts, cursor] : pts;
      fill.setPath(path);
      outline.setPath(path);
    };
    const finish = () => {
      fill.setPath(pts);
      setPendingIsochrone({ type: 'manual', geometry: googlePolygonToGeoJson(fill) });
      startDrawing(null);
    };

    const listeners = [
      map.addListener('click', (e: google.maps.MapMouseEvent) => {
        if (!e.latLng) return;
        if (pts.length >= 3 && near(e.latLng, pts[0])) { finish(); return; }
        // A double-click also fires click — don't stack duplicate vertices.
        if (pts.length && near(e.latLng, pts[pts.length - 1])) return;
        pts.push(e.latLng);
        redraw();
      }),
      map.addListener('dblclick', (e: google.maps.MapMouseEvent) => {
        if (e.latLng && pts.length && !near(e.latLng, pts[pts.length - 1])) pts.push(e.latLng);
        if (pts.length >= 3) finish();
      }),
      map.addListener('mousemove', (e: google.maps.MapMouseEvent) => {
        if (e.latLng && pts.length) redraw(e.latLng);
      }),
    ];
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') startDrawing(null); };
    window.addEventListener('keydown', onKey);

    return () => {
      listeners.forEach((l) => google.maps.event.removeListener(l));
      window.removeEventListener('keydown', onKey);
      fill.setMap(null);
      outline.setMap(null);
      // Re-enable only once the finishing double-click has played out —
      // restoring it immediately lets that same double-click zoom the map.
      window.setTimeout(() => map.setOptions({ disableDoubleClickZoom: false }), 400);
    };
  }, [mapInstance, drawingType, startDrawing, setPendingIsochrone]);

  return null;
}

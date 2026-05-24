import { useEffect, useMemo, useState } from 'react';
import { GoogleMap, Polygon } from '@react-google-maps/api';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import AreaPolygon from './AreaPolygon';
import AreaCenterPins from './AreaCenterPins';
import POIMarkers from './POIMarkers';
import ImportedMarkers from './ImportedMarkers';
import DrawingTools from './DrawingTools';
import ChoroplethLayer from './ChoroplethLayer';
import HeatmapPanel from './HeatmapPanel';
import { SMAPPEN_MAP_STYLE } from '../../utils/mapStyle';
import type { HeatmapResponse } from '../../api/heatmap';

export default function MapCanvas() {
  const {
    center, zoom, setMapInstance, drawingType, placePinFor,
    setPendingIsochrone, mapInstance, showHeatmap, heatmapMetric,
    timeMachine,
  } = useMapStore();
  const { areas, importedPoints } = useProjectStore();
  const [heatmapMeta, setHeatmapMeta] = useState<HeatmapResponse['meta'] | null>(null);

  // Materialize the time-machine polygon path from the geometry attached to
  // the store. Memoized so we don't rebuild Polygon paths on every render —
  // the play loop fires up to 4× per second.
  const tmPath = useMemo(() => {
    const ring = timeMachine?.geometry?.coordinates?.[0];
    if (!Array.isArray(ring)) return null;
    const out: google.maps.LatLngLiteral[] = [];
    for (const pt of ring) {
      if (Array.isArray(pt) && pt.length >= 2 && Number.isFinite(pt[0]) && Number.isFinite(pt[1])) {
        out.push({ lat: pt[1], lng: pt[0] });
      }
    }
    return out.length >= 3 ? out : null;
  }, [timeMachine?.geometry]);

  useEffect(() => {
    if (!mapInstance) return;
    const listener = mapInstance.addListener('click', (e: google.maps.MapMouseEvent) => {
      if (placePinFor === 'isochrone' && e.latLng) {
        setPendingIsochrone({ lat: e.latLng.lat(), lng: e.latLng.lng() });
      }
    });
    return () => google.maps.event.removeListener(listener);
  }, [mapInstance, placePinFor, setPendingIsochrone]);

  return (
    <div className="absolute inset-0">
      <GoogleMap
        mapContainerStyle={{ width: '100%', height: '100%' }}
        center={center}
        zoom={zoom}
        onLoad={setMapInstance}
        onUnmount={() => setMapInstance(null)}
        options={{
          mapTypeControl: false,
          streetViewControl: false,
          fullscreenControl: false,
          zoomControl: false,
          styles: SMAPPEN_MAP_STYLE,
          draggableCursor: drawingType === 'pin' ? 'crosshair' : undefined,
        }}
      >
        {showHeatmap && <ChoroplethLayer metric={heatmapMetric} onMetaChange={setHeatmapMeta} />}
        {areas.map((a) => a.geometry ? <AreaPolygon key={a.id} area={a} heatmapOn={showHeatmap} /> : null)}
        <AreaCenterPins areas={areas} />
        <ImportedMarkers points={importedPoints} />
        <POIMarkers />
        <DrawingTools />
        {tmPath && timeMachine && (
          <Polygon
            paths={tmPath}
            options={{
              fillColor: timeMachine.color,
              fillOpacity: 0.28,
              strokeColor: timeMachine.color,
              strokeWeight: 3,
              clickable: false,
              zIndex: 999,
            }}
          />
        )}
      </GoogleMap>
      {showHeatmap && <HeatmapPanel meta={heatmapMeta} />}
    </div>
  );
}

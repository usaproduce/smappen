import { useEffect } from 'react';
import { GoogleMap } from '@react-google-maps/api';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import AreaPolygon from './AreaPolygon';
import POIMarkers from './POIMarkers';
import ImportedMarkers from './ImportedMarkers';
import DrawingTools from './DrawingTools';
import HeatmapLayer from './HeatmapLayer';

export default function MapCanvas() {
  const { center, zoom, setMapInstance, drawingType, placePinFor, setPendingIsochrone, mapInstance, showHeatmap } = useMapStore();
  const { areas, importedPoints } = useProjectStore();

  useEffect(() => {
    if (!mapInstance) return;
    const listener = mapInstance.addListener('click', async (e: google.maps.MapMouseEvent) => {
      if (placePinFor === 'isochrone' && e.latLng) {
        setPendingIsochrone({ lat: e.latLng.lat(), lng: e.latLng.lng() });
      }
    });
    return () => google.maps.event.removeListener(listener);
  }, [mapInstance, placePinFor]);

  return (
    <GoogleMap
      mapContainerStyle={{ width: '100%', height: '100%' }}
      center={center}
      zoom={zoom}
      onLoad={setMapInstance}
      onUnmount={() => setMapInstance(null)}
      options={{
        mapTypeControl: true,
        streetViewControl: false,
        fullscreenControl: true,
        zoomControl: true,
        draggableCursor: drawingType === 'pin' ? 'crosshair' : undefined,
      }}
    >
      {areas.map((a) => a.geometry ? <AreaPolygon key={a.id} area={a} /> : null)}
      <ImportedMarkers points={importedPoints} />
      <POIMarkers />
      <DrawingTools />
      {showHeatmap && <HeatmapLayer points={importedPoints} />}
    </GoogleMap>
  );
}

import { Polygon, InfoWindow } from '@react-google-maps/api';
import { useState } from 'react';
import { useMapStore } from '../../stores/mapStore';
import { geoJsonToGooglePath, polygonCentroid } from '../../utils/geo';
import type { Area } from '../../types';

export default function AreaPolygon({ area, heatmapOn = false }: { area: Area; heatmapOn?: boolean }) {
  const { selectedAreaId, selectArea } = useMapStore();
  const isSelected = selectedAreaId === area.id;
  const [hover, setHover] = useState(false);

  if (!area.geometry) return null;
  const path = geoJsonToGooglePath(area.geometry);

  // In heatmap mode, fill becomes invisible and stroke flips to white per Smappen styling.
  const strokeColor = heatmapOn ? '#FFFFFF' : (area.stroke_color || area.fill_color);
  const strokeWeight = heatmapOn ? 3 : (isSelected ? 3 : (area.stroke_weight ?? 2));
  const fillOpacity = heatmapOn ? 0 : (isSelected ? Math.min(0.5, (area.fill_opacity ?? 0.2) + 0.1) : (area.fill_opacity ?? 0.2));

  return (
    <>
      <Polygon
        path={path}
        options={{
          fillColor: area.fill_color,
          fillOpacity,
          strokeColor,
          strokeWeight,
          strokeOpacity: 1,
          clickable: true,
          zIndex: isSelected ? 5 : 1,
        }}
        onClick={() => selectArea(area.id)}
        onMouseOver={() => setHover(true)}
        onMouseOut={() => setHover(false)}
      />
      {hover && (
        <InfoWindow position={polygonCentroid(area.geometry)} options={{ disableAutoPan: true }}>
          <div className="text-sm">
            <div className="font-semibold">{area.name}</div>
            <div className="text-slate-500 text-xs">
              {area.travel_mode} {area.travel_time_minutes ? `· ${area.travel_time_minutes} min` : ''}
            </div>
          </div>
        </InfoWindow>
      )}
    </>
  );
}

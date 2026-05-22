import { Polygon, InfoWindow } from '@react-google-maps/api';
import { useState } from 'react';
import { useMapStore } from '../../stores/mapStore';
import { geoJsonToGooglePath, polygonCentroid } from '../../utils/geo';
import type { Area } from '../../types';

export default function AreaPolygon({ area }: { area: Area }) {
  const { selectedAreaId, selectArea } = useMapStore();
  const isSelected = selectedAreaId === area.id;
  const [hover, setHover] = useState(false);

  if (!area.geometry) return null;
  const path = geoJsonToGooglePath(area.geometry);

  return (
    <>
      <Polygon
        path={path}
        options={{
          fillColor: area.fill_color,
          fillOpacity: isSelected ? Math.min(0.5, area.fill_opacity + 0.15) : area.fill_opacity,
          strokeColor: area.stroke_color,
          strokeWeight: isSelected ? 3 : area.stroke_weight,
          clickable: true,
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
              {area.travel_mode} · {area.travel_time_minutes ? `${area.travel_time_minutes} min` : ''}
            </div>
          </div>
        </InfoWindow>
      )}
    </>
  );
}

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
  const fillColor = area.fill_color || '#7848BB';
  const strokeColor = heatmapOn ? '#FFFFFF' : (area.stroke_color || fillColor);
  const strokeWeight = heatmapOn ? 3 : (isSelected ? 3 : (area.stroke_weight ?? 2));
  const fillOpacity = heatmapOn ? 0 : (isSelected ? Math.min(0.5, (area.fill_opacity ?? 0.2) + 0.1) : (area.fill_opacity ?? 0.2));

  return (
    <>
      <Polygon
        path={path}
        options={{
          fillColor,
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
      {hover && (() => {
        // Surface a one-line stat preview alongside the area name. Reads
        // demographics_cache in both nested + flat shapes.
        const dc: any = (area as any).demographics_cache ?? {};
        const pop = typeof dc.population?.total === 'number' ? dc.population.total
          : typeof dc.population === 'number' ? dc.population
          : null;
        const income = dc.income?.median_household_income ?? dc.median_household_income;
        return (
          <InfoWindow position={polygonCentroid(area.geometry)} options={{ disableAutoPan: true }}>
            <div className="text-sm" style={{ minWidth: 160 }}>
              <div className="font-semibold" style={{ color: '#1A1A2E' }}>{area.name}</div>
              <div className="text-slate-500 text-xs">
                {area.travel_mode} {area.travel_time_minutes ? `· ${area.travel_time_minutes} min` : ''}
              </div>
              {(pop || income) && (
                <div className="text-xs text-slate-700 mt-1 font-medium">
                  {pop != null && <>{pop.toLocaleString()} people</>}
                  {income != null && pop != null ? ' · ' : ''}
                  {income != null && <>${Math.round(income).toLocaleString()} med inc</>}
                </div>
              )}
            </div>
          </InfoWindow>
        );
      })()}
    </>
  );
}

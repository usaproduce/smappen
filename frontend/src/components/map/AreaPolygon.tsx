import { Polygon, InfoWindow } from '@react-google-maps/api';
import { useState } from 'react';
import { useMapStore } from '../../stores/mapStore';
import { geoJsonToGooglePaths, polygonCentroid } from '../../utils/geo';
import type { Area } from '../../types';

export default function AreaPolygon({ area, heatmapOn = false }: { area: Area; heatmapOn?: boolean }) {
  const { selectedAreaId, selectArea } = useMapStore();
  const isSelected = selectedAreaId === area.id;
  const [hover, setHover] = useState(false);

  if (!area.geometry) return null;
  // After migration 011 territory geometries can be MultiPolygon (multiple
  // disjoint pieces — common when k-means clusters source tracts that aren't
  // spatially contiguous). Render one <Polygon> per piece so each shape on
  // the map is a real, accurate boundary instead of a stretched convex hull.
  const paths = geoJsonToGooglePaths(area.geometry as any);
  if (paths.length === 0) return null;

  // Heatmap mode: fill goes transparent and stroke flips to white so the
  // outline stays visible over colored tracts.
  const fillColor = area.fill_color || '#7848BB';
  const strokeColor = heatmapOn ? '#FFFFFF' : (area.stroke_color || fillColor);
  const strokeWeight = heatmapOn ? 3 : (isSelected ? 3 : (area.stroke_weight ?? 2));
  const fillOpacity = heatmapOn ? 0 : (isSelected ? Math.min(0.5, (area.fill_opacity ?? 0.2) + 0.1) : (area.fill_opacity ?? 0.2));

  return (
    <>
      {paths.map((path, i) => (
        <Polygon
          key={i}
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
      ))}
      {hover && (() => {
        const dc: any = (area as any).demographics_cache ?? {};
        const pop = typeof dc.population?.total === 'number' ? dc.population.total
          : typeof dc.population === 'number' ? dc.population
          : null;
        const income = dc.income?.median_household_income ?? dc.median_household_income;
        return (
          <InfoWindow position={polygonCentroid(area.geometry as any)} options={{ disableAutoPan: true }}>
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

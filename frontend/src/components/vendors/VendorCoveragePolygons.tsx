import { useEffect, useMemo, useState } from 'react';
import { Polygon } from '@react-google-maps/api';
import type { VendorDetail } from '../../api/vendorMap';

/**
 * Paints the selected vendor's coverage geometry. Picks one of the
 * Douglas-Peucker simplified tiers based on map zoom so street-level
 * inspection gets the detailed polygon, metro-level overview gets a
 * cheap simplified one. Falls back through tiers gracefully when older
 * rows haven't been re-simplified yet.
 *
 *   zoom ≥ 14  → simplified_100m (full street detail)
 *   zoom ≥ 10  → simplified_1km  (city)
 *   zoom <  10 → simplified_10km (metro / regional)
 *
 * Style: affiliated vendors get a brand-violet outline so their
 * footprint reads as a distinct, "Carafe-verified" surface against
 * the calm slate of independent vendors.
 */

type Coverage = VendorDetail['coverage'][number];

type Props = {
  vendor: VendorDetail['vendor'];
  coverage: Coverage[];
  /** Live map zoom — updates polygons without remounting. */
  zoom: number;
};

export default function VendorCoveragePolygons({ vendor, coverage, zoom }: Props) {
  const affiliated = !!vendor.is_affiliated;
  const palette = affiliated
    ? { fill: '#7848BB', fillOpacity: 0.12, stroke: '#7848BB', strokeOpacity: 0.85, weight: 2.2 }
    : { fill: '#64748b', fillOpacity: 0.10, stroke: '#475569', strokeOpacity: 0.75, weight: 1.6 };

  // Paths are recomputed only when zoom crosses a tier boundary OR coverage
  // changes — keeps Polygon path arrays referentially stable so Google
  // Maps doesn't tear down and re-create the overlay on every pan.
  const tier = pickTier(zoom);
  const polygons = useMemo(
    () => coverage.flatMap((c) => geometryToPaths(coverageGeometryForTier(c, tier))),
    [coverage, tier],
  );

  // Track first-render so the polygons fade in on selection — the alternative
  // (snap-on) reads as a jump on slow networks.
  const [opacity, setOpacity] = useState(0);
  useEffect(() => {
    const t = window.setTimeout(() => setOpacity(1), 30);
    return () => window.clearTimeout(t);
  }, [coverage]);

  return (
    <>
      {polygons.map((path, i) => (
        <Polygon
          key={`${vendor.id}-${tier}-${i}`}
          path={path}
          options={{
            fillColor: palette.fill,
            fillOpacity: palette.fillOpacity * opacity,
            strokeColor: palette.stroke,
            strokeOpacity: palette.strokeOpacity * opacity,
            strokeWeight: palette.weight,
            clickable: false,
            zIndex: affiliated ? 4 : 3,
          }}
        />
      ))}
    </>
  );
}

/* ── tier picker ──────────────────────────────────────────────────────── */
type Tier = '100m' | '1km' | '10km' | 'full';

function pickTier(zoom: number): Tier {
  if (zoom >= 14) return '100m';
  if (zoom >= 10) return '1km';
  return '10km';
}

/** Returns the most-appropriate available tier — `full` is the last-resort
 *  fallback for older rows that haven't been re-simplified yet. */
function coverageGeometryForTier(c: Coverage, tier: Tier): any | null {
  if (tier === '100m') return c.geometry_100m ?? c.geometry_1km ?? c.geometry_10km ?? c.geometry;
  if (tier === '1km')  return c.geometry_1km  ?? c.geometry_10km ?? c.geometry_100m ?? c.geometry;
  return c.geometry_10km ?? c.geometry_1km ?? c.geometry_100m ?? c.geometry;
}

/* ── GeoJSON → google.maps.LatLng paths ─────────────────────────────── */
type LatLng = { lat: number; lng: number };

function geometryToPaths(g: any): LatLng[][] {
  if (!g) return [];
  if (g.type === 'Polygon')      return [ringsToPath(g.coordinates)];
  if (g.type === 'MultiPolygon') return g.coordinates.map(ringsToPath);
  return [];
}

function ringsToPath(rings: any): LatLng[] {
  // GeoJSON Polygon coordinates: [outer ring, ...holes]. Google Maps
  // <Polygon path=> wants the OUTER ring — holes via the second-level
  // `paths` form. For now we render the outer ring only; coverage rarely
  // has internal holes that change a "served here?" decision and the
  // detail endpoint hasn't been observed to emit any.
  const outer = Array.isArray(rings?.[0]) ? rings[0] : [];
  return outer.map((c: [number, number]) => ({ lng: c[0], lat: c[1] }));
}

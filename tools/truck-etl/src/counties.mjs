// County polygons: validation of the TIGERweb response, point-in-polygon and bounds (03_DATA.md 2.4, 5.5).

export class CountiesError extends Error {}

/**
 * Validates a TIGERweb GeoJSON response against the region's county list and builds the lookup structure.
 * @param {object} geojson parsed FeatureCollection, coordinates [lng, lat]
 * @param {string[]} countyFips the region's five-digit county codes
 */
export function buildCounties(geojson, countyFips) {
  if (geojson === null || typeof geojson !== 'object') throw new CountiesError('counties: response is not a JSON object');
  if ('error' in geojson) throw new CountiesError(`counties: the service answered an error: ${JSON.stringify(geojson.error).slice(0, 200)}`);
  if (geojson.exceededTransferLimit) throw new CountiesError('counties: the response was cut off (exceededTransferLimit)');
  if (geojson.type !== 'FeatureCollection' || !Array.isArray(geojson.features)) {
    throw new CountiesError('counties: expected a GeoJSON FeatureCollection');
  }
  const wanted = new Set(countyFips);
  const byFips = new Map();
  for (const feature of geojson.features) {
    const fips = feature && feature.properties ? feature.properties.GEOID : undefined;
    if (typeof fips !== 'string') throw new CountiesError('counties: a feature has no GEOID property');
    if (!wanted.has(fips)) throw new CountiesError(`counties: feature ${fips} is not a county of the region file`);
    if (byFips.has(fips)) throw new CountiesError(`counties: county ${fips} appears twice`);
    const geometry = feature.geometry;
    let polygons;
    if (geometry && geometry.type === 'Polygon') polygons = [geometry.coordinates];
    else if (geometry && geometry.type === 'MultiPolygon') polygons = geometry.coordinates;
    else throw new CountiesError(`counties: county ${fips} has no Polygon or MultiPolygon geometry`);
    let west = Infinity; let south = Infinity; let east = -Infinity; let north = -Infinity;
    const packed = [];
    for (const polygon of polygons) {
      const rings = [];
      for (const ring of polygon) {
        if (!Array.isArray(ring) || ring.length < 4) throw new CountiesError(`counties: county ${fips} has a ring with fewer than 4 positions`);
        const xs = new Float64Array(ring.length);
        const ys = new Float64Array(ring.length);
        for (let i = 0; i < ring.length; i++) {
          const x = ring[i][0];
          const y = ring[i][1];
          if (typeof x !== 'number' || typeof y !== 'number' || !Number.isFinite(x) || !Number.isFinite(y)) {
            throw new CountiesError(`counties: county ${fips} has a non-numeric position`);
          }
          xs[i] = x;
          ys[i] = y;
          if (x < west) west = x;
          if (x > east) east = x;
          if (y < south) south = y;
          if (y > north) north = y;
        }
        rings.push({ xs, ys });
      }
      packed.push(rings);
    }
    byFips.set(fips, { fips, polygons: packed, west, south, east, north });
  }
  for (const fips of countyFips) {
    if (!byFips.has(fips)) throw new CountiesError(`counties: county ${fips} of the region file is missing from the response`);
  }
  const counties = [...byFips.values()].sort((a, b) => (a.fips < b.fips ? -1 : a.fips > b.fips ? 1 : 0));
  const bounds = {
    lat_min: Math.min(...counties.map((c) => c.south)),
    lng_min: Math.min(...counties.map((c) => c.west)),
    lat_max: Math.max(...counties.map((c) => c.north)),
    lng_max: Math.max(...counties.map((c) => c.east)),
  };
  return { counties, bounds };
}

/** Even-odd ray casting against one ring. */
function insideRing(x, y, ring) {
  const { xs, ys } = ring;
  let inside = false;
  for (let i = 0, j = xs.length - 1; i < xs.length; j = i++) {
    const yi = ys[i];
    const yj = ys[j];
    if ((yi > y) !== (yj > y) && x < (xs[j] - xs[i]) * (y - yi) / (yj - yi) + xs[i]) inside = !inside;
  }
  return inside;
}

/**
 * County of a point: counties in ascending FIPS order, bounding-box test first, even-odd over every ring of
 * every polygon of the county, first hit wins.
 * @param {{counties: object[]}} lookup result of buildCounties
 * @returns {string|null} five-digit FIPS or null
 */
export function countyOf(lookup, lat, lng) {
  for (const county of lookup.counties) {
    if (lng < county.west || lng > county.east || lat < county.south || lat > county.north) continue;
    let crossings = 0;
    for (const polygon of county.polygons) {
      for (const ring of polygon) if (insideRing(lng, lat, ring)) crossings++;
    }
    if (crossings % 2 === 1) return county.fips;
  }
  return null;
}

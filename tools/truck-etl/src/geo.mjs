// Distance and neighbour search (03_DATA.md section 0, 02_MODEL.md 4.3).
// haversineM is the model's haversine_m, operation for operation, so the loader's cross-check of
// nearby_etl against the PHP model holds to the last digits.

const PI = 3.141592653589793;

/**
 * Great-circle distance in metres on a sphere of radius `radiusM`.
 * @param {number} lat1 @param {number} lng1 @param {number} lat2 @param {number} lng2
 * @param {number} radiusM sphere radius in metres (seed constants.earth_radius_m)
 */
export function haversineM(lat1, lng1, lat2, lng2, radiusM) {
  const p1 = lat1 * PI / 180.0;
  const p2 = lat2 * PI / 180.0;
  const dp = (lat2 - lat1) * PI / 180.0;
  const dl = (lng2 - lng1) * PI / 180.0;
  const sp = Math.sin(dp / 2.0);
  const sl = Math.sin(dl / 2.0);
  let a = sp * sp + Math.cos(p1) * Math.cos(p2) * sl * sl;
  if (a < 0.0) a = 0.0; else if (a > 1.0) a = 1.0;
  return 2.0 * radiusM * Math.asin(Math.sqrt(a));
}

/** Bounding-box half-widths in degrees for `radiusM` metres, with the 1 % guard band of 03_DATA.md 9.3. */
export function boxHalfWidths(radiusM, atAbsLat, earthRadiusM) {
  const dLat = (radiusM / earthRadiusM) * 180.0 / PI * 1.01;
  const dLng = dLat / Math.max(0.01, Math.cos(atAbsLat * PI / 180.0));
  return { dLat, dLng };
}

const KEY_SPAN = 4194304; // 2^22 columns per row of buckets
const KEY_HALF = 2097152;

/**
 * Fixed lat/lng bucket grid for "everything within r metres" queries.
 * Buckets are at least `radiusM` high and, evaluated at `maxAbsLat`, at least `radiusM` wide everywhere
 * equator-ward of that latitude, so the 3 by 3 neighbourhood of a point's bucket holds every item within
 * `radiusM` of it. Items are plain indexes; the caller keeps the coordinate arrays.
 */
export class BucketGrid {
  /**
   * @param {number} radiusM largest query radius this grid will serve
   * @param {number} maxAbsLat largest absolute latitude of any item or query point
   * @param {number} earthRadiusM
   */
  constructor(radiusM, maxAbsLat, earthRadiusM) {
    const { dLat, dLng } = boxHalfWidths(radiusM, maxAbsLat, earthRadiusM);
    this.radiusM = radiusM;
    this.earthRadiusM = earthRadiusM;
    this.dLat = dLat;
    this.dLng = dLng;
    /** @type {Map<number, number[]>} */
    this.buckets = new Map();
    this.lats = [];
    this.lngs = [];
  }

  key(lat, lng) {
    return Math.floor(lat / this.dLat) * KEY_SPAN + (Math.floor(lng / this.dLng) + KEY_HALF);
  }

  /** Adds an item and returns its index (insertion order). */
  add(lat, lng) {
    const index = this.lats.length;
    this.lats.push(lat);
    this.lngs.push(lng);
    const k = this.key(lat, lng);
    const bucket = this.buckets.get(k);
    if (bucket) bucket.push(index); else this.buckets.set(k, [index]);
    return index;
  }

  /**
   * Calls cb(index, distanceM) for every item within `radiusM` (inclusive) of the point.
   * Order: buckets south to north, west to east, items in insertion order. Callers that need another order sort.
   * A callback that returns true stops the search.
   */
  forEachWithin(lat, lng, radiusM, cb) {
    if (radiusM > this.radiusM) throw new Error(`BucketGrid built for ${this.radiusM} m cannot serve ${radiusM} m`);
    const gi = Math.floor(lat / this.dLat);
    const gj = Math.floor(lng / this.dLng) + KEY_HALF;
    for (let a = gi - 1; a <= gi + 1; a++) {
      for (let b = gj - 1; b <= gj + 1; b++) {
        const bucket = this.buckets.get(a * KEY_SPAN + b);
        if (!bucket) continue;
        for (let n = 0; n < bucket.length; n++) {
          const index = bucket[n];
          const d = haversineM(lat, lng, this.lats[index], this.lngs[index], this.earthRadiusM);
          if (d <= radiusM && cb(index, d) === true) return true;
        }
      }
    }
    return false;
  }

  /** True when at least one item lies within `radiusM` of the point. */
  anyWithin(lat, lng, radiusM) {
    return this.forEachWithin(lat, lng, radiusM, () => true);
  }
}

/** Inclusive box test. `box` = {south, west, north, east}. */
export function insideBox(lat, lng, box) {
  return lat >= box.south && lat <= box.north && lng >= box.west && lng <= box.east;
}

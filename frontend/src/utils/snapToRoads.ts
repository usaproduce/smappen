/**
 * #17 — Snap polygon vertices to the nearest road network via Google's
 * Roads API. Useful when a user freehands a delivery zone and wants the
 * boundary to follow actual streets, not a sloppy hand-drawn outline.
 *
 * Roads API has a 100-points-per-request limit. We chunk and re-stitch.
 * Returns the same polygon with each vertex moved to its closest road
 * point. If a vertex is too far from any road (rural), Roads API drops it
 * — we KEEP the original vertex to avoid leaving holes.
 *
 * Cost: $10 / 1000 requests; each request handles up to 100 points. A
 * 50-vertex polygon = $0.01.
 */
const ROADS_URL = 'https://roads.googleapis.com/v1/snapToRoads';

export async function snapPolygonToRoads(
  ring: [number, number][],   // [lng, lat]
  apiKey: string,
): Promise<[number, number][]> {
  if (!ring || ring.length < 3 || !apiKey) return ring;
  const out: [number, number][] = new Array(ring.length);
  const chunks: { start: number; pts: [number, number][] }[] = [];
  for (let i = 0; i < ring.length; i += 100) {
    chunks.push({ start: i, pts: ring.slice(i, i + 100) });
  }

  for (const chunk of chunks) {
    const path = chunk.pts.map(([lng, lat]) => `${lat},${lng}`).join('|');
    const url = `${ROADS_URL}?path=${encodeURIComponent(path)}&interpolate=false&key=${apiKey}`;
    try {
      const resp = await fetch(url);
      if (!resp.ok) throw new Error('Roads API ' + resp.status);
      const data = await resp.json();
      // The response has `snappedPoints: [{ location: {latitude, longitude}, originalIndex }]`
      // originalIndex maps to the input position WITHIN the chunk; vertices
      // with no nearby road don't appear, so we keep the original.
      const seenOriginal = new Set<number>();
      for (const sp of data.snappedPoints ?? []) {
        const origIdx = sp.originalIndex;
        if (typeof origIdx !== 'number') continue;
        const target = chunk.start + origIdx;
        out[target] = [sp.location.longitude, sp.location.latitude];
        seenOriginal.add(origIdx);
      }
      for (let i = 0; i < chunk.pts.length; i++) {
        if (!seenOriginal.has(i)) {
          out[chunk.start + i] = chunk.pts[i];
        }
      }
    } catch {
      // On any failure, fall back to the original points for this chunk.
      for (let i = 0; i < chunk.pts.length; i++) {
        out[chunk.start + i] = chunk.pts[i];
      }
    }
  }

  // Ensure the ring is closed.
  if (out[0] && out[out.length - 1] && (out[0][0] !== out[out.length - 1][0] || out[0][1] !== out[out.length - 1][1])) {
    out[out.length - 1] = [...out[0]];
  }
  return out;
}

/**
 * Smoothed-freehand alternative — when snap-to-roads isn't desired (rural
 * sales territories, building footprints), apply a Chaikin corner-cutting
 * pass which rounds sharp corners. 1 iteration ~= mild smoothing, 3 = silky.
 */
export function chaikinSmooth(
  ring: [number, number][],
  iterations = 2,
): [number, number][] {
  if (ring.length < 3) return ring;
  let pts = ring;
  for (let k = 0; k < iterations; k++) {
    const next: [number, number][] = [];
    for (let i = 0; i < pts.length - 1; i++) {
      const [x0, y0] = pts[i];
      const [x1, y1] = pts[i + 1];
      next.push([0.75 * x0 + 0.25 * x1, 0.75 * y0 + 0.25 * y1]);
      next.push([0.25 * x0 + 0.75 * x1, 0.25 * y0 + 0.75 * y1]);
    }
    next.push(next[0]); // close
    pts = next;
  }
  return pts;
}

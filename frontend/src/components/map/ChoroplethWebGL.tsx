/**
 * #11 — WebGL choropleth skeleton.
 *
 * The current ChoroplethLayer renders one Google Maps `<Polygon>` per tract.
 * At ~10K tracts in view, this jank's the map pretty hard because each
 * Polygon is its own overlay with its own event handlers + DOM/canvas
 * elements behind the scenes.
 *
 * The right fix uses Google Maps' WebGLOverlayView, which gives us a raw
 * WebGL2 context bound to the map's camera. We upload the tract triangle
 * mesh ONCE into a vertex buffer, then re-color via a fragment shader per
 * metric change — no per-frame layout work. Conservative estimate: 60fps
 * at 20K tracts on a mid-range laptop.
 *
 * Why this file is a skeleton:
 *
 *   • Triangulating tract polygons (Earcut / poly2tri) needs ~600 lines
 *     of TS + WebAssembly. Out of scope for a single sprint.
 *   • Camera projection from lat/lng → screen needs Maps Vector mode
 *     (currently raster). We need to migrate to mapId-based vector tiles
 *     first, which means trial-running a styled vector mapId from Google
 *     Cloud + handling the cost difference (vector $7 vs raster $2 / 1K).
 *   • Color lookup tables for the choropleth need shader uniforms keyed
 *     on quantile breaks — straightforward but adds a UBO upload per
 *     metric switch.
 *
 * The proper implementation goes here when those prereqs are met. For now
 * the existing ChoroplethLayer (Polygon-per-tract) remains the production
 * path — performant enough for ~5K tracts in view, which is most reasonable
 * zoom levels.
 *
 * To enable when ready:
 *   1. Acquire a Vector mapId from Google Cloud and put it in .env.
 *   2. Implement triangulation in `triangulateRing()` below.
 *   3. Wire `WebGLOverlayView` (see https://developers.google.com/maps/documentation/javascript/webgl).
 *   4. Switch MapCanvas to render <ChoroplethWebGL/> instead of <ChoroplethLayer/>.
 */

import { useMapStore } from '../../stores/mapStore';

/** PLACEHOLDER — returns nothing, doesn't render. Kept so imports type-check. */
export default function ChoroplethWebGL() {
  // No-op for now. Real implementation will need mapInstance + featuresRef
  // and a WebGL2 context attached via WebGLOverlayView. Empty render keeps
  // the surface area available for the eventual switch.
  void useMapStore;
  return null;
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers (to be implemented)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Earcut-style triangulation of a polygon ring. Returns [v0x, v0y, v1x, v1y, ...]
 * in clip-space coordinates (Google Maps vector mode camera handles the
 * lat/lng → clip-space transform via gl.projectFromLatLngAltitude).
 *
 * Plan: pull in earcut@2.x (16KB, dependency-free) when this is wired.
 */
export function triangulateRing(_ring: [number, number][]): number[] {
  return [];
}

/** Vertex + fragment shader source. Documented for reviewer convenience. */
export const SHADERS = {
  vertex: `
    attribute vec3 a_position;
    uniform mat4 u_mvp;
    varying vec2 v_normalized;
    void main() {
      gl_Position = u_mvp * vec4(a_position, 1.0);
      v_normalized = a_position.xy * 0.5 + 0.5;
    }
  `,
  fragment: `
    precision mediump float;
    uniform sampler2D u_palette;       // 1×256 LUT, sampled by metric value
    uniform float u_metric_min;
    uniform float u_metric_max;
    attribute float a_value;           // per-vertex metric value
    varying float v_value;
    void main() {
      float t = (v_value - u_metric_min) / max(u_metric_max - u_metric_min, 0.001);
      gl_FragColor = texture2D(u_palette, vec2(clamp(t, 0.0, 1.0), 0.5));
      gl_FragColor.a *= 0.65;
    }
  `,
};

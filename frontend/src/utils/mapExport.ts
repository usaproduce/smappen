import toast from 'react-hot-toast';
import { collabApi } from '../api/advanced';
import { allOuterRings, polygonBounds } from './geo';
import type { Area } from '../types';

/**
 * Map screenshot — composite renderer designed for parity with the on-screen map.
 *
 *   1. Auto-fit to the bounds of the supplied areas with light padding, and
 *      derive the integer Static Maps zoom + center that produces those
 *      bounds at the chosen W×H. Falls back to the caller's center/zoom
 *      if no areas are present.
 *   2. Fetch a Static Maps PNG using the same MapTypeStyle the live map is
 *      rendering with, so basemap colors and labels match the browser view.
 *   3. Composite the heatmap layer (if visible) and every area's polygon +
 *      pin on top, using the same fill/stroke/opacity rules AreaPolygon.tsx
 *      and AreaCenterPins.tsx use for their passive (non-selected) state.
 */

const STATIC_MAPS_URL = 'https://maps.googleapis.com/maps/api/staticmap';
const STATIC_MAPS_MAX_URL = 8192; // documented limit
const TILE_SIZE = 256;
const DEFAULT_FILL = '#7848BB';

export interface MapSnapshotOpts {
  // Framing — bounds wins. Falls back to lat/lng/zoom, then to areas.
  bounds?: { north: number; south: number; east: number; west: number };
  lat?: number;
  lng?: number;
  zoom?: number;

  // Layer data
  areas?: Area[];
  heatmapFeatures?: { geometry: any; value: number | null; color: string }[] | null;
  showHeatmap?: boolean;

  // Style — pass the active google.maps.MapTypeStyle so basemap matches.
  mapStyle?: google.maps.MapTypeStyle[];

  // Output
  filename?: string;
  width?: number;
  height?: number;
  paddingPct?: number; // % of viewport to leave as breathing room (default 6%)
}

export async function downloadMapSnapshot(opts: MapSnapshotOpts) {
  const key = (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '';
  if (!key) { toast.error('Map key not configured for static export'); return; }

  // Static Maps free tier caps at 1280×1280 (2560×2560 at scale=2).
  const W = Math.min(1280, opts.width ?? 1280);
  const H = Math.min(1280, opts.height ?? 800);
  const scale = 2;
  const padPct = Math.max(0, Math.min(0.2, opts.paddingPct ?? 0.06));

  const areas = (opts.areas ?? []).filter((a) => a.geometry);

  // ── Framing ───────────────────────────────────────────────────────────
  let centerLat: number, centerLng: number, zoom: number;
  if (opts.bounds) {
    const fit = fitBoundsToCenterZoom(opts.bounds, W, H, padPct);
    centerLat = fit.lat; centerLng = fit.lng; zoom = fit.zoom;
  } else {
    const b = areaCollectionBounds(areas);
    if (b) {
      const fit = fitBoundsToCenterZoom(b, W, H, padPct);
      centerLat = fit.lat; centerLng = fit.lng; zoom = fit.zoom;
    } else if (opts.lat != null && opts.lng != null && opts.zoom != null) {
      centerLat = opts.lat; centerLng = opts.lng; zoom = Math.round(opts.zoom);
    } else {
      toast.error('Nothing to export'); return;
    }
  }
  zoom = Math.max(1, Math.min(20, zoom));

  // ── Basemap request ──────────────────────────────────────────────────
  const styleParams = opts.mapStyle ? mapStyleToStaticParams(opts.mapStyle) : [];

  const params = new URLSearchParams({
    center: `${centerLat.toFixed(6)},${centerLng.toFixed(6)}`,
    zoom: String(zoom),
    size: `${W}x${H}`,
    scale: String(scale),
    maptype: 'roadmap',
    key,
  });
  // Append styles one at a time; stop before the URL limit so the request
  // doesn't get rejected outright.
  let baseUrl = `${STATIC_MAPS_URL}?${params.toString()}`;
  for (const s of styleParams) {
    const next = `${baseUrl}&style=${encodeURIComponent(s)}`;
    if (next.length > STATIC_MAPS_MAX_URL) break;
    baseUrl = next;
  }

  toast.loading('Rendering map…', { id: 'snapshot' });

  let baseImg: HTMLImageElement;
  try {
    const resp = await fetch(baseUrl);
    if (!resp.ok) {
      toast.error('Static Maps API rejected the request (HTTP ' + resp.status + ')', { id: 'snapshot' });
      return;
    }
    const blob = await resp.blob();
    if (blob.size < 5000) {
      toast.error('Static Maps returned an empty image', { id: 'snapshot' });
      return;
    }
    baseImg = await blobToImage(blob);
  } catch (e: any) {
    toast.error('Could not fetch basemap: ' + (e?.message ?? 'network error'), { id: 'snapshot' });
    return;
  }

  // ── Canvas composite ─────────────────────────────────────────────────
  const canvas = document.createElement('canvas');
  canvas.width = W * scale;
  canvas.height = H * scale;
  const ctx = canvas.getContext('2d');
  if (!ctx) { toast.error('Canvas not available', { id: 'snapshot' }); return; }
  ctx.imageSmoothingEnabled = true;
  ctx.imageSmoothingQuality = 'high';
  ctx.lineJoin = 'round';
  ctx.lineCap = 'round';
  ctx.drawImage(baseImg, 0, 0, canvas.width, canvas.height);

  const worldSize = TILE_SIZE * Math.pow(2, zoom);
  const centerPx = latLngToPixel(centerLat, centerLng, worldSize);
  const half = { x: canvas.width / 2, y: canvas.height / 2 };
  const project = (lng: number, lat: number) => {
    const p = latLngToPixel(lat, lng, worldSize);
    return {
      x: (p.x - centerPx.x) * scale + half.x,
      y: (p.y - centerPx.y) * scale + half.y,
    };
  };

  // ── Heatmap (under areas) ─────────────────────────────────────────────
  // Mirror ChoroplethLayer: 0.6 fill alpha, 0.5px white stroke at scale.
  if (opts.showHeatmap && opts.heatmapFeatures && opts.heatmapFeatures.length > 0) {
    ctx.save();
    ctx.lineWidth = 0.5 * scale;
    ctx.strokeStyle = '#ffffff';
    for (const feat of opts.heatmapFeatures) {
      ctx.fillStyle = feat.color;
      ctx.globalAlpha = 0.6;
      drawGeometry(ctx, feat.geometry, project, /*fill*/ true, /*stroke*/ false);
      ctx.globalAlpha = 1;
      drawGeometry(ctx, feat.geometry, project, /*fill*/ false, /*stroke*/ true);
    }
    ctx.restore();
  }

  // ── Area polygons ─────────────────────────────────────────────────────
  // Passive state of AreaPolygon.tsx (no selection pulse, no hover bump).
  const heatmapOn = !!opts.showHeatmap;
  for (const a of areas) {
    const fillColor = a.fill_color || DEFAULT_FILL;
    const strokeColor = heatmapOn ? '#ffffff' : (a.stroke_color || fillColor);
    const strokeWeight = heatmapOn ? 3 : (a.stroke_weight ?? 2);

    // Density-boost mirrors AreaPolygon.tsx so dense urban polygons read
    // as visually denser in the export, matching what the user sees.
    const densPerKm2: number | null = (a as any).demographics_cache?.population?.density_per_sq_km ?? null;
    const densityBoost = (typeof densPerKm2 === 'number' && densPerKm2 > 0)
      ? Math.min(1, Math.max(0, (Math.log10(densPerKm2) - 1) / 3))
      : 0;
    const baseOpacity = a.fill_opacity ?? 0.2;
    // When the heatmap is on, AreaPolygon hides non-selected fills (0) so
    // the choropleth shows through; we replicate that.
    const fillOpacity = heatmapOn
      ? 0
      : Math.min(0.55, Math.max(0.05, baseOpacity - 0.075 + densityBoost * 0.15));

    ctx.save();
    if (fillOpacity > 0) {
      ctx.fillStyle = fillColor;
      ctx.globalAlpha = fillOpacity;
      drawGeometry(ctx, a.geometry as any, project, /*fill*/ true, /*stroke*/ false);
    }
    // Stroke at full alpha — independent of fill opacity (the old export
    // washed strokes out because globalAlpha was set before both).
    ctx.globalAlpha = 1;
    ctx.strokeStyle = strokeColor;
    ctx.lineWidth = strokeWeight * scale;
    drawGeometry(ctx, a.geometry as any, project, /*fill*/ false, /*stroke*/ true);
    ctx.restore();
  }

  // ── Center pins (one per area) ────────────────────────────────────────
  for (const a of areas) {
    const pos = bestPinPosition(a);
    if (!pos) continue;
    const p = project(pos.lng, pos.lat);
    drawPin(ctx, p.x, p.y, a.fill_color || DEFAULT_FILL, scale);
  }

  // ── Export ────────────────────────────────────────────────────────────
  canvas.toBlob((blob) => {
    if (!blob) { toast.error('Export failed', { id: 'snapshot' }); return; }
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = opts.filename ?? `smappen-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.png`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
    const heatCount = (opts.showHeatmap && opts.heatmapFeatures?.length) || 0;
    toast.success(
      `Saved · ${areas.length} area${areas.length === 1 ? '' : 's'}` +
      (heatCount > 0 ? ` + ${heatCount} heatmap polys` : ''),
      { id: 'snapshot' },
    );
  }, 'image/png');
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

function blobToImage(blob: Blob): Promise<HTMLImageElement> {
  return new Promise((res, rej) => {
    const img = new Image();
    const url = URL.createObjectURL(blob);
    img.onload = () => { URL.revokeObjectURL(url); res(img); };
    img.onerror = (e) => { URL.revokeObjectURL(url); rej(e); };
    img.src = url;
  });
}

function latLngToPixel(lat: number, lng: number, worldSize: number) {
  const sinLat = Math.sin((lat * Math.PI) / 180);
  const x = ((lng + 180) / 360) * worldSize;
  const y = (0.5 - Math.log((1 + sinLat) / (1 - sinLat)) / (4 * Math.PI)) * worldSize;
  return { x, y };
}

function yPixelToLat(y: number, worldSize: number): number {
  const n = Math.PI - (2 * Math.PI * y) / worldSize;
  return (180 / Math.PI) * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
}

function areaCollectionBounds(
  areas: Area[],
): { north: number; south: number; east: number; west: number } | null {
  let minLat = Infinity, maxLat = -Infinity, minLng = Infinity, maxLng = -Infinity;
  for (const a of areas) {
    if (!a.geometry) continue;
    const b = polygonBounds(a.geometry as any);
    if (!Number.isFinite(b.minLat)) continue;
    if (b.minLat < minLat) minLat = b.minLat;
    if (b.maxLat > maxLat) maxLat = b.maxLat;
    if (b.minLng < minLng) minLng = b.minLng;
    if (b.maxLng > maxLng) maxLng = b.maxLng;
  }
  if (!Number.isFinite(minLat)) return null;
  return { north: maxLat, south: minLat, east: maxLng, west: minLng };
}

/** Highest integer zoom such that `bounds` fits inside W×H with `padFrac`
 *  padding on each side. Mercator-correct on both axes. */
function fitBoundsToCenterZoom(
  bounds: { north: number; south: number; east: number; west: number },
  W: number, H: number, padFrac: number,
): { lat: number; lng: number; zoom: number } {
  const availW = W * (1 - 2 * padFrac);
  const availH = H * (1 - 2 * padFrac);
  let lngSpan = bounds.east - bounds.west;
  if (lngSpan < 0) lngSpan += 360; // antimeridian-safe

  // Scan from max zoom down; pick the first level where both spans fit.
  let z = 1;
  for (let test = 20; test >= 1; test--) {
    const ws = TILE_SIZE * Math.pow(2, test);
    const widthPx = (lngSpan / 360) * ws;
    const yN = latLngToPixel(bounds.north, 0, ws).y;
    const yS = latLngToPixel(bounds.south, 0, ws).y;
    const heightPx = Math.abs(yS - yN);
    if (widthPx <= availW && heightPx <= availH) { z = test; break; }
  }

  const ws = TILE_SIZE * Math.pow(2, z);
  // Center latitude must be the lat at the y-pixel midpoint, not the simple
  // mean of north/south — Mercator stretches near the poles.
  const yMid = (latLngToPixel(bounds.north, 0, ws).y + latLngToPixel(bounds.south, 0, ws).y) / 2;
  return {
    lat: yPixelToLat(yMid, ws),
    lng: (bounds.east + bounds.west) / 2,
    zoom: z,
  };
}

function drawGeometry(
  ctx: CanvasRenderingContext2D,
  geom: any,
  project: (lng: number, lat: number) => { x: number; y: number },
  fill: boolean,
  stroke: boolean,
) {
  if (!geom) return;
  const rings = allOuterRings(geom);
  for (const ring of rings) {
    if (!ring || ring.length < 3) continue;
    ctx.beginPath();
    for (let i = 0; i < ring.length; i++) {
      const [lng, lat] = ring[i];
      const p = project(lng, lat);
      if (i === 0) ctx.moveTo(p.x, p.y);
      else ctx.lineTo(p.x, p.y);
    }
    ctx.closePath();
    if (fill) ctx.fill();
    if (stroke) ctx.stroke();
  }
}

/** Mirror of AreaCenterPins.bestPinPosition — keeps pins on the largest
 *  piece for MultiPolygon territories, falls back to the stored center
 *  for single Polygon / point-derived areas. */
function bestPinPosition(area: Area): { lat: number; lng: number } | null {
  const g: any = (area as any).geometry;
  if (!g || g.type !== 'MultiPolygon') {
    return area.center_lat != null && area.center_lng != null
      ? { lat: area.center_lat, lng: area.center_lng }
      : null;
  }
  const rings = allOuterRings(g);
  if (rings.length === 0) {
    return area.center_lat != null && area.center_lng != null
      ? { lat: area.center_lat, lng: area.center_lng }
      : null;
  }
  let best = rings[0];
  for (const r of rings) if (r.length > best.length) best = r;
  let sumLat = 0, sumLng = 0;
  for (const [lng, lat] of best) { sumLat += lat; sumLng += lng; }
  return { lat: sumLat / best.length, lng: sumLng / best.length };
}

/** Traces AreaCenterPins.pinSvg into Canvas2D so exported pins match
 *  on-screen pins exactly (same teardrop, same white inner dot, same
 *  2px white stroke). The SVG viewBox is 22×28 with the tip at (11,28). */
function drawPin(
  ctx: CanvasRenderingContext2D,
  x: number, y: number, color: string, scale: number,
) {
  const W = 22, H = 28;
  ctx.save();
  ctx.translate(x - (W / 2) * scale, y - H * scale);
  ctx.scale(scale, scale);

  // Subtle drop shadow so pins stay legible on busy basemaps.
  ctx.shadowColor = 'rgba(0,0,0,0.35)';
  ctx.shadowBlur = 2;
  ctx.shadowOffsetY = 1;

  // path d="M11 0C5 0 0 4.5 0 10.3 0 18 11 28 11 28s11-10 11-17.7C22 4.5 17 0 11 0z"
  ctx.beginPath();
  ctx.moveTo(11, 0);
  ctx.bezierCurveTo(5, 0, 0, 4.5, 0, 10.3);
  ctx.bezierCurveTo(0, 18, 11, 28, 11, 28);
  ctx.bezierCurveTo(11, 28, 22, 18, 22, 10.3);
  ctx.bezierCurveTo(22, 4.5, 17, 0, 11, 0);
  ctx.closePath();
  ctx.fillStyle = color;
  ctx.fill();
  ctx.lineWidth = 2;
  ctx.strokeStyle = '#ffffff';
  ctx.stroke();

  // Reset shadow before drawing the inner dot so it stays crisp.
  ctx.shadowColor = 'transparent';
  ctx.shadowBlur = 0;
  ctx.shadowOffsetY = 0;
  ctx.beginPath();
  ctx.arc(11, 10.5, 3.2, 0, Math.PI * 2);
  ctx.fillStyle = '#ffffff';
  ctx.fill();

  ctx.restore();
}

/** Convert google.maps.MapTypeStyle[] (used by the live map) to Static
 *  Maps `style=` URL parameters. Colors get re-encoded from #RRGGBB to
 *  0xRRGGBB; unknown stylers pass through as `key:value`. */
function mapStyleToStaticParams(styles: google.maps.MapTypeStyle[]): string[] {
  const out: string[] = [];
  for (const s of styles) {
    const parts: string[] = [];
    const ft = (s as any).featureType;
    const et = (s as any).elementType;
    if (ft) parts.push('feature:' + ft);
    if (et) parts.push('element:' + et);
    const stylers = (s as any).stylers as Array<Record<string, any>> | undefined;
    if (stylers) {
      for (const styler of stylers) {
        for (const [k, v] of Object.entries(styler)) {
          if (k === 'color' && typeof v === 'string') {
            parts.push('color:0x' + v.replace(/^#/, '').toUpperCase());
          } else {
            parts.push(`${k}:${v}`);
          }
        }
      }
    }
    if (parts.length > 0) out.push(parts.join('|'));
  }
  return out;
}

/** Project-level snapshot (versioning). Wired to the Cmd+S shortcut. */
export async function saveProjectSnapshot(projectId: string) {
  try {
    const r = await collabApi.snapshot(projectId, '');
    toast.success(`Saved v${r.version_number}`);
  } catch (e: any) {
    toast.error(e?.response?.data?.error ?? 'Snapshot failed');
  }
}

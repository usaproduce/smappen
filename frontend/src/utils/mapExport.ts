import toast from 'react-hot-toast';
import { collabApi } from '../api/advanced';
import { allOuterRings, polygonBounds } from './geo';
import { useMapStore } from '../stores/mapStore';
import { paletteById, gradientCss, interpolatePalette } from './heatmapColors';
import type { Area } from '../types';
import type { HeatmapMetric, HeatmapResponse } from '../api/heatmap';

/**
 * Map screenshot — composite renderer designed for parity with the on-screen
 * map plus the polish customers expect in exports they paste into emails or
 * client decks (legend, scale bar, title block, brand watermark).
 *
 *   1. Auto-fit to the bounds of the supplied areas with light padding, and
 *      derive the integer Static Maps zoom + center that produces those
 *      bounds at the chosen W×H. Falls back to the caller's center/zoom
 *      if no areas are present.
 *   2. Fetch a Static Maps PNG using the same MapTypeStyle the live map is
 *      rendering with, so basemap colors and labels match the browser view.
 *      Falls back to a neutral canvas if Static Maps fails — we'd rather
 *      ship a usable composite than nothing.
 *   3. Composite heatmap, area polygons, numbered pins, optional legend,
 *      scale bar, title block, and Smappen attribution on top.
 *   4. Either download as PNG or copy to clipboard depending on `target`.
 */

const STATIC_MAPS_URL = 'https://maps.googleapis.com/maps/api/staticmap';
const STATIC_MAPS_MAX_URL = 8192; // documented limit
const TILE_SIZE = 256;
const DEFAULT_FILL = '#7848BB';
const BRAND_PURPLE = '#7848BB';

export type SnapshotTarget = 'download' | 'clipboard';

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
  heatmapMeta?: HeatmapResponse['meta'] | null;
  heatmapMetric?: HeatmapMetric;
  heatmapPaletteId?: string;

  // Style — pass the active google.maps.MapTypeStyle so basemap matches.
  mapStyle?: google.maps.MapTypeStyle[];

  // Branding overlays
  title?: string;      // typically project name; rendered top-left
  subtitle?: string;   // typically the formatted date; rendered under title

  // Output
  filename?: string;
  width?: number;
  height?: number;
  paddingPct?: number; // % of viewport to leave as breathing room (default 6%)
  target?: SnapshotTarget; // 'download' (default) or 'clipboard'
}

export async function downloadMapSnapshot(opts: MapSnapshotOpts) {
  const key = (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '';

  // Static Maps free tier caps at 1280×1280 (2560×2560 at scale=2).
  const W = Math.min(1280, opts.width ?? 1280);
  const H = Math.min(1280, opts.height ?? 800);
  const scale = 2;
  const padPct = Math.max(0, Math.min(0.2, opts.paddingPct ?? 0.06));
  const target: SnapshotTarget = opts.target ?? 'download';

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

  // Race-condition fix: showHeatmap on but features haven't been published yet
  // by ChoroplethLayer. Poll the store for up to 2s before giving up and
  // continuing without the heatmap.
  let heatmapFeatures = opts.heatmapFeatures ?? null;
  if (opts.showHeatmap && (!heatmapFeatures || heatmapFeatures.length === 0)) {
    toast.loading('Waiting for heatmap…', { id: 'snapshot' });
    const t0 = performance.now();
    while (performance.now() - t0 < 2000) {
      const fresh = useMapStore.getState().heatmapFeatures;
      if (fresh && fresh.length > 0) { heatmapFeatures = fresh; break; }
      await new Promise((r) => setTimeout(r, 100));
    }
  }

  toast.loading('Fetching basemap…', { id: 'snapshot' });

  // ── Basemap request ──────────────────────────────────────────────────
  let baseImg: HTMLImageElement | null = null;
  if (key) {
    const styleParams = opts.mapStyle ? mapStyleToStaticParams(opts.mapStyle) : [];
    const params = new URLSearchParams({
      center: `${centerLat.toFixed(6)},${centerLng.toFixed(6)}`,
      zoom: String(zoom),
      size: `${W}x${H}`,
      scale: String(scale),
      maptype: 'roadmap',
      key,
    });
    let baseUrl = `${STATIC_MAPS_URL}?${params.toString()}`;
    // Pack styles in priority order so the most visually-impactful stylers
    // (water/landscape/roads) survive truncation rather than getting dropped
    // because they happened to appear later in the array.
    for (const s of styleParams) {
      const enc = encodeURIComponent(s);
      const overhead = '&style='.length;
      if (baseUrl.length + overhead + enc.length > STATIC_MAPS_MAX_URL) continue;
      baseUrl += `&style=${enc}`;
    }

    try {
      const resp = await fetch(baseUrl);
      if (resp.ok) {
        const blob = await resp.blob();
        if (blob.size >= 5000) baseImg = await blobToImage(blob);
      }
    } catch {
      /* fall through to neutral fallback */
    }
  }

  toast.loading('Compositing…', { id: 'snapshot' });

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

  if (baseImg) {
    ctx.drawImage(baseImg, 0, 0, canvas.width, canvas.height);
  } else {
    // Fallback "basemap": neutral background + subtle grid so polygons still
    // sit on something map-like. Users still get a usable export instead of
    // a hard failure when Static Maps is down or the API key is wrong.
    drawNeutralBasemap(ctx, canvas.width, canvas.height, !key ? 'no-api-key' : 'unavailable');
  }

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
  if (opts.showHeatmap && heatmapFeatures && heatmapFeatures.length > 0) {
    ctx.save();
    ctx.lineWidth = 0.5 * scale;
    ctx.strokeStyle = '#ffffff';
    for (const feat of heatmapFeatures) {
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
  // Number every pin so the export is cross-referenceable in a deck. For
  // up to 8 areas we also draw the name caption under the pin so the reader
  // doesn't need a separate legend; above 8, numbers alone keep it legible.
  const showNames = areas.length > 0 && areas.length <= 8;
  let pinIndex = 0;
  for (const a of areas) {
    pinIndex++;
    const pos = bestPinPosition(a);
    if (!pos) continue;
    const p = project(pos.lng, pos.lat);
    drawNumberedPin(ctx, p.x, p.y, a.fill_color || DEFAULT_FILL, scale, String(pinIndex));
    if (showNames && a.name) {
      drawPinCaption(ctx, p.x, p.y, a.name, scale);
    }
  }

  // ── Overlays (legend, scale bar, title, attribution) ──────────────────
  // Drawn last so they sit above everything.
  if (opts.showHeatmap && opts.heatmapMeta && opts.heatmapMetric) {
    drawHeatmapLegend(ctx, canvas.width, canvas.height, scale, {
      meta: opts.heatmapMeta,
      metric: opts.heatmapMetric,
      paletteId: opts.heatmapPaletteId,
    });
  }
  drawScaleBar(ctx, canvas.width, canvas.height, scale, centerLat, zoom);
  if (opts.title || opts.subtitle) {
    drawTitleBlock(ctx, scale, opts.title, opts.subtitle);
  }
  drawBrandFooter(ctx, canvas.width, canvas.height, scale);

  // ── Export ────────────────────────────────────────────────────────────
  toast.loading(target === 'clipboard' ? 'Copying…' : 'Saving…', { id: 'snapshot' });

  const blob: Blob | null = await new Promise((resolve) =>
    canvas.toBlob((b) => resolve(b), 'image/png'),
  );
  if (!blob) { toast.error('Export failed (encoding)', { id: 'snapshot' }); return; }

  const areaSummary = `${areas.length} area${areas.length === 1 ? '' : 's'}`;
  const heatCount = (opts.showHeatmap && heatmapFeatures?.length) || 0;
  const sizeKb = Math.round(blob.size / 1024);
  const summary = `${areaSummary}${heatCount > 0 ? ` + ${heatCount} heatmap polys` : ''} · ${sizeKb} KB`;

  if (target === 'clipboard') {
    try {
      if (!('clipboard' in navigator) || !(window as any).ClipboardItem) {
        throw new Error('Clipboard images not supported in this browser');
      }
      await (navigator.clipboard as any).write([
        new (window as any).ClipboardItem({ 'image/png': blob }),
      ]);
      toast.success(`Copied to clipboard · ${summary}`, { id: 'snapshot' });
    } catch (e: any) {
      toast.error('Clipboard failed: ' + (e?.message ?? 'unknown'), { id: 'snapshot' });
    }
    return;
  }

  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = opts.filename ?? defaultFilename();
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
  toast.success(`Saved · ${summary}`, { id: 'snapshot' });
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

function defaultFilename() {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `smappen-${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}.png`;
}

/** Slugify a project name into a filename-safe token. */
export function slugForFilename(s: string | null | undefined, fallback = 'map'): string {
  if (!s) return fallback;
  return s
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '')
    .slice(0, 40) || fallback;
}

export function buildSnapshotFilename(projectName: string | null | undefined): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  const stamp = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}`;
  return `smappen-${slugForFilename(projectName)}-${stamp}.png`;
}

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

/** Pin + number badge variant of AreaCenterPins.pinSvg. The number replaces
 *  the white inner dot — same teardrop silhouette, same drop shadow, but
 *  now legible at-a-glance in a multi-area export. */
function drawNumberedPin(
  ctx: CanvasRenderingContext2D,
  x: number, y: number, color: string, scale: number, label: string,
) {
  const W = 22, H = 28;
  ctx.save();
  ctx.translate(x - (W / 2) * scale, y - H * scale);
  ctx.scale(scale, scale);

  ctx.shadowColor = 'rgba(0,0,0,0.35)';
  ctx.shadowBlur = 2;
  ctx.shadowOffsetY = 1;

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

  ctx.shadowColor = 'transparent';
  ctx.shadowBlur = 0;
  ctx.shadowOffsetY = 0;

  ctx.beginPath();
  ctx.arc(11, 10.5, 6.5, 0, Math.PI * 2);
  ctx.fillStyle = '#ffffff';
  ctx.fill();

  ctx.fillStyle = darkenForContrast(color);
  // Shrink the font slightly for 3-digit labels so they don't overflow the badge.
  const fontPx = label.length >= 3 ? 7 : label.length === 2 ? 8 : 9;
  ctx.font = `700 ${fontPx}px Inter, system-ui, sans-serif`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(label, 11, 10.5);

  ctx.restore();
}

/** Small pill caption rendered under a pin (when area count is low enough
 *  that names won't pile up on each other). */
function drawPinCaption(
  ctx: CanvasRenderingContext2D,
  x: number, y: number, name: string, scale: number,
) {
  const text = name.length > 28 ? name.slice(0, 26) + '…' : name;
  const fontPx = 11 * scale;
  ctx.save();
  ctx.font = `600 ${fontPx}px Inter, system-ui, sans-serif`;
  const metrics = ctx.measureText(text);
  const padX = 6 * scale;
  const padY = 3 * scale;
  const w = metrics.width + padX * 2;
  const h = fontPx + padY * 2;
  const px = x - w / 2;
  const py = y + 4 * scale; // sits just under the pin tip
  ctx.fillStyle = 'rgba(255,255,255,0.92)';
  ctx.strokeStyle = 'rgba(15,23,42,0.18)';
  ctx.lineWidth = 1 * scale;
  roundedRect(ctx, px, py, w, h, 4 * scale);
  ctx.fill();
  ctx.stroke();
  ctx.fillStyle = '#1A1A2E';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(text, x, py + h / 2 + 0.5 * scale);
  ctx.restore();
}

/** Picks a dark text color that contrasts on a white badge given the pin
 *  base color. We always render the number on white, so we just darken the
 *  pin hue toward near-black if it's a light fill. */
function darkenForContrast(hex: string): string {
  const h = hex.replace('#', '');
  if (h.length !== 6) return '#1A1A2E';
  const r = parseInt(h.slice(0, 2), 16);
  const g = parseInt(h.slice(2, 4), 16);
  const b = parseInt(h.slice(4, 6), 16);
  const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  if (lum < 0.45) return hex; // dark pin → use its own color for visual link
  return '#1A1A2E';
}

function roundedRect(
  ctx: CanvasRenderingContext2D,
  x: number, y: number, w: number, h: number, r: number,
) {
  const rr = Math.min(r, w / 2, h / 2);
  ctx.beginPath();
  ctx.moveTo(x + rr, y);
  ctx.arcTo(x + w, y, x + w, y + h, rr);
  ctx.arcTo(x + w, y + h, x, y + h, rr);
  ctx.arcTo(x, y + h, x, y, rr);
  ctx.arcTo(x, y, x + w, y, rr);
  ctx.closePath();
}

// ── Scale bar (bottom-right) ────────────────────────────────────────────
function drawScaleBar(
  ctx: CanvasRenderingContext2D,
  W: number, H: number, scale: number,
  centerLat: number, zoom: number,
) {
  // meters per device pixel of the static map; scale=2 means each canvas
  // pixel is half a static-map pixel, so canvas pixels per meter = scale/mPerPx.
  const mPerStaticPx = 156543.03392 * Math.cos((centerLat * Math.PI) / 180) / Math.pow(2, zoom);
  const canvasPxPerMeter = scale / mPerStaticPx;
  const targetPx = 140 * scale; // ~140 CSS pixels wide

  const niceMeters = [
    5, 10, 25, 50, 100, 200, 500,
    1_000, 2_000, 5_000, 10_000, 20_000, 50_000,
    100_000, 200_000, 500_000, 1_000_000, 2_000_000, 5_000_000,
  ];
  let chosen = niceMeters[0];
  for (const m of niceMeters) {
    if (m * canvasPxPerMeter <= targetPx) chosen = m;
  }
  const barLenPx = chosen * canvasPxPerMeter;
  // Same logic in miles so we can render a paired imperial bar above.
  const niceMiles = [0.05, 0.1, 0.25, 0.5, 1, 2, 5, 10, 25, 50, 100, 200, 500, 1000, 2000];
  const mPerMile = 1609.344;
  const canvasPxPerMile = canvasPxPerMeter * mPerMile;
  let chosenMi = niceMiles[0];
  for (const mi of niceMiles) {
    if (mi * canvasPxPerMile <= targetPx) chosenMi = mi;
  }
  const barLenPxMi = chosenMi * canvasPxPerMile;
  const label = chosen >= 1000 ? `${(chosen / 1000).toLocaleString()} km` : `${chosen} m`;
  const labelMi = chosenMi >= 1 ? `${chosenMi} mi` : `${chosenMi} mi`;

  // Bottom-right anchor, inset so it sits clear of the Smappen attribution.
  const inset = 16 * scale;
  const baseY = H - 48 * scale;
  const rightX = W - inset;

  ctx.save();
  // White rounded pill background for legibility on any basemap.
  const padX = 8 * scale;
  const padY = 6 * scale;
  const fontPx = 10 * scale;
  ctx.font = `600 ${fontPx}px Inter, system-ui, sans-serif`;
  const widest = Math.max(barLenPx, barLenPxMi);
  const labelW = Math.max(ctx.measureText(label).width, ctx.measureText(labelMi).width);
  const pillW = Math.max(widest, labelW) + padX * 2;
  const pillH = (fontPx * 2 + 6 * scale) + padY * 2;
  const pillX = rightX - pillW;
  const pillY = baseY - pillH;

  ctx.fillStyle = 'rgba(255,255,255,0.95)';
  ctx.strokeStyle = 'rgba(15,23,42,0.15)';
  ctx.lineWidth = 1 * scale;
  roundedRect(ctx, pillX, pillY, pillW, pillH, 6 * scale);
  ctx.fill();
  ctx.stroke();

  // Two stacked bars: km on top, mi on bottom, with their labels to the right.
  const drawBar = (lenPx: number, label: string, y: number) => {
    const x0 = pillX + padX;
    ctx.strokeStyle = '#1A1A2E';
    ctx.lineWidth = 1.5 * scale;
    ctx.beginPath();
    ctx.moveTo(x0, y);
    ctx.lineTo(x0 + lenPx, y);
    ctx.stroke();
    // tick marks
    ctx.beginPath();
    ctx.moveTo(x0, y - 3 * scale);
    ctx.lineTo(x0, y + 3 * scale);
    ctx.moveTo(x0 + lenPx, y - 3 * scale);
    ctx.lineTo(x0 + lenPx, y + 3 * scale);
    ctx.stroke();
    ctx.fillStyle = '#1A1A2E';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, x0 + lenPx + 4 * scale, y);
  };
  drawBar(barLenPx, label, pillY + padY + fontPx / 2);
  drawBar(barLenPxMi, labelMi, pillY + padY + fontPx + 6 * scale + fontPx / 2);

  ctx.restore();
}

// ── Heatmap legend (bottom-left) ────────────────────────────────────────
function drawHeatmapLegend(
  ctx: CanvasRenderingContext2D,
  _W: number, H: number, scale: number,
  args: { meta: HeatmapResponse['meta']; metric: HeatmapMetric; paletteId?: string },
) {
  const palette = paletteById(args.paletteId ?? 'smappen-pastel');
  const inset = 16 * scale;
  const padX = 10 * scale;
  const padY = 8 * scale;
  const gradW = 200 * scale;
  const gradH = 10 * scale;
  const titleSize = 11 * scale;
  const valueSize = 10 * scale;

  ctx.save();
  ctx.font = `700 ${titleSize}px Inter, system-ui, sans-serif`;
  const titleText = metricLabel(args.metric);
  const titleW = ctx.measureText(titleText).width;
  ctx.font = `600 ${valueSize}px Inter, system-ui, sans-serif`;
  const minText = formatMetricValue(args.meta.min, args.metric);
  const maxText = formatMetricValue(args.meta.max, args.metric);
  const rangeText = `${minText} → ${maxText}`;
  const rangeW = ctx.measureText(rangeText).width;

  const pillW = Math.max(gradW, titleW, rangeW) + padX * 2;
  const pillH = titleSize + gradH + valueSize + padY * 2 + 8 * scale;
  const pillX = inset;
  const pillY = H - inset - pillH - 56 * scale; // sit above the brand footer

  ctx.fillStyle = 'rgba(255,255,255,0.95)';
  ctx.strokeStyle = 'rgba(15,23,42,0.15)';
  ctx.lineWidth = 1 * scale;
  roundedRect(ctx, pillX, pillY, pillW, pillH, 8 * scale);
  ctx.fill();
  ctx.stroke();

  ctx.fillStyle = '#1A1A2E';
  ctx.font = `700 ${titleSize}px Inter, system-ui, sans-serif`;
  ctx.textAlign = 'left';
  ctx.textBaseline = 'top';
  ctx.fillText(titleText, pillX + padX, pillY + padY);

  // Gradient swatch — sample the palette across the bar width so we get the
  // same color ramp as the on-screen legend.
  const gradX = pillX + padX;
  const gradY = pillY + padY + titleSize + 4 * scale;
  const grad = ctx.createLinearGradient(gradX, gradY, gradX + gradW, gradY);
  const stops = palette.stops;
  const positions = palette.positions ?? stops.map((_, i) => i / (stops.length - 1));
  for (let i = 0; i < stops.length; i++) {
    grad.addColorStop(Math.max(0, Math.min(1, positions[i])), stops[i]);
  }
  // Fallback if interpolation drifts: sample explicitly every 5%.
  for (let p = 0; p <= 1.001; p += 0.05) {
    grad.addColorStop(Math.min(1, p), interpolatePalette(palette, p));
  }
  ctx.fillStyle = grad;
  roundedRect(ctx, gradX, gradY, gradW, gradH, 3 * scale);
  ctx.fill();
  ctx.strokeStyle = 'rgba(15,23,42,0.25)';
  ctx.stroke();

  ctx.fillStyle = '#475569';
  ctx.font = `600 ${valueSize}px Inter, system-ui, sans-serif`;
  ctx.textBaseline = 'top';
  ctx.textAlign = 'left';
  ctx.fillText(minText, gradX, gradY + gradH + 3 * scale);
  ctx.textAlign = 'right';
  ctx.fillText(maxText, gradX + gradW, gradY + gradH + 3 * scale);

  ctx.restore();
}

function metricLabel(m: HeatmapMetric): string {
  switch (m) {
    case 'population': return 'Population';
    case 'population_density': return 'Population density (people/km²)';
    case 'median_income': return 'Median household income';
    case 'median_home_value': return 'Median home value';
    case 'unemployment_rate': return 'Unemployment rate';
    case 'housing_units': return 'Housing units';
  }
}
function formatMetricValue(n: number | null | undefined, metric: HeatmapMetric): string {
  if (n === null || n === undefined || Number.isNaN(n)) return '—';
  if (metric === 'median_income' || metric === 'median_home_value') return '$' + Math.round(n).toLocaleString();
  if (metric === 'unemployment_rate') return n.toFixed(1) + '%';
  if (Math.abs(n) >= 1000) return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
  return Math.round(n).toLocaleString();
}

// ── Title block (top-left) ──────────────────────────────────────────────
function drawTitleBlock(
  ctx: CanvasRenderingContext2D,
  scale: number,
  title?: string, subtitle?: string,
) {
  if (!title && !subtitle) return;
  const inset = 16 * scale;
  const padX = 12 * scale;
  const padY = 8 * scale;
  const titleSize = 16 * scale;
  const subtitleSize = 11 * scale;
  ctx.save();
  ctx.font = `800 ${titleSize}px Inter, system-ui, sans-serif`;
  const titleW = title ? ctx.measureText(title).width : 0;
  ctx.font = `500 ${subtitleSize}px Inter, system-ui, sans-serif`;
  const subW = subtitle ? ctx.measureText(subtitle).width : 0;
  const pillW = Math.max(titleW, subW) + padX * 2;
  const pillH = (title ? titleSize : 0) + (subtitle ? subtitleSize + 3 * scale : 0) + padY * 2;
  ctx.fillStyle = 'rgba(255,255,255,0.95)';
  ctx.strokeStyle = 'rgba(15,23,42,0.15)';
  ctx.lineWidth = 1 * scale;
  roundedRect(ctx, inset, inset, pillW, pillH, 8 * scale);
  ctx.fill();
  ctx.stroke();
  let y = inset + padY;
  if (title) {
    ctx.font = `800 ${titleSize}px Inter, system-ui, sans-serif`;
    ctx.fillStyle = '#1A1A2E';
    ctx.textBaseline = 'top';
    ctx.textAlign = 'left';
    ctx.fillText(title, inset + padX, y);
    y += titleSize + 3 * scale;
  }
  if (subtitle) {
    ctx.font = `500 ${subtitleSize}px Inter, system-ui, sans-serif`;
    ctx.fillStyle = '#475569';
    ctx.textBaseline = 'top';
    ctx.textAlign = 'left';
    ctx.fillText(subtitle, inset + padX, y);
  }
  ctx.restore();
}

// ── Smappen brand watermark (bottom-left, under legend) ─────────────────
function drawBrandFooter(
  ctx: CanvasRenderingContext2D,
  _W: number, H: number, scale: number,
) {
  const inset = 16 * scale;
  const padX = 8 * scale;
  const padY = 5 * scale;
  const fontPx = 11 * scale;
  const text = 'Made with smappen.com';
  ctx.save();
  ctx.font = `700 ${fontPx}px Inter, system-ui, sans-serif`;
  const textW = ctx.measureText(text).width;
  const dotW = 10 * scale;
  const gap = 6 * scale;
  const pillW = textW + dotW + gap + padX * 2;
  const pillH = fontPx + padY * 2;
  const pillX = inset;
  const pillY = H - inset - pillH;

  ctx.fillStyle = 'rgba(255,255,255,0.95)';
  ctx.strokeStyle = 'rgba(15,23,42,0.15)';
  ctx.lineWidth = 1 * scale;
  roundedRect(ctx, pillX, pillY, pillW, pillH, 6 * scale);
  ctx.fill();
  ctx.stroke();

  // Brand dot — small purple circle as a stand-in logo mark.
  ctx.fillStyle = BRAND_PURPLE;
  ctx.beginPath();
  ctx.arc(pillX + padX + dotW / 2, pillY + pillH / 2, dotW / 2, 0, Math.PI * 2);
  ctx.fill();

  ctx.fillStyle = '#1A1A2E';
  ctx.textAlign = 'left';
  ctx.textBaseline = 'middle';
  ctx.fillText(text, pillX + padX + dotW + gap, pillY + pillH / 2);
  ctx.restore();
}

// ── Neutral fallback basemap (no Static Maps response) ──────────────────
function drawNeutralBasemap(
  ctx: CanvasRenderingContext2D,
  W: number, H: number, reason: 'no-api-key' | 'unavailable',
) {
  ctx.save();
  ctx.fillStyle = '#EEF1F6';
  ctx.fillRect(0, 0, W, H);
  // Soft grid for visual structure.
  ctx.strokeStyle = '#DDE2EA';
  ctx.lineWidth = 1;
  const step = 64;
  for (let x = 0; x <= W; x += step) {
    ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, H); ctx.stroke();
  }
  for (let y = 0; y <= H; y += step) {
    ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke();
  }
  // Small inline notice so the operator knows why this looks austere.
  const note = reason === 'no-api-key'
    ? 'Basemap unavailable: no Maps API key configured'
    : 'Basemap unavailable: Static Maps did not respond';
  ctx.font = '600 18px Inter, system-ui, sans-serif';
  ctx.fillStyle = '#94A0B4';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(note, W / 2, H - 24);
  ctx.restore();
}

/** Convert google.maps.MapTypeStyle[] (used by the live map) to Static
 *  Maps `style=` URL parameters, sorted so that visually-impactful stylers
 *  (water, landscape, roads) survive URL-length truncation ahead of
 *  secondary ones (transit, admin, poi). Colors get re-encoded from
 *  #RRGGBB to 0xRRGGBB; unknown stylers pass through as `key:value`. */
function mapStyleToStaticParams(styles: google.maps.MapTypeStyle[]): string[] {
  // Map every featureType to a priority weight. Lower = more important.
  const prio = (ft: string | undefined): number => {
    if (!ft) return 0;                            // global stylers first
    if (ft.startsWith('water')) return 1;
    if (ft.startsWith('landscape')) return 2;
    if (ft.startsWith('road.highway')) return 3;
    if (ft.startsWith('road.arterial')) return 4;
    if (ft === 'poi.park') return 5;
    if (ft.startsWith('road')) return 6;
    if (ft.startsWith('administrative')) return 7;
    if (ft.startsWith('poi')) return 8;
    if (ft.startsWith('transit')) return 9;
    return 10;
  };

  const entries = styles.map((s, i) => {
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
    return { idx: i, p: prio(ft), str: parts.join('|') };
  }).filter((e) => e.str.length > 0);

  // Stable sort: priority first, original index as tiebreaker so visually
  // identical groups keep their relative order.
  entries.sort((a, b) => a.p - b.p || a.idx - b.idx);
  return entries.map((e) => e.str);
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

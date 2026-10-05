// Truck Planner - the pure logic of the map page and of its spot card
// (docs/truck-planner/05_FRONTEND.md 1.2, 4.2 and 4.3).
//
// Everything the map page decides without the DOM lives here, so it can be tested in Node: how the
// week plays, what a key does, what the URL says and how it is written back, which layout a width
// gets, what the hover hint reads, and how the spot card arranges a result of the model. Nothing
// here does model maths: figures are read from results of the estimator and only arranged.

import { HOURS_PER_WEEK, MAP_LAYERS, SEEDS, modFloor, qkey } from './model';
import type {
  BestWindow,
  HourResult,
  LatLng,
  Leg,
  MapLayer,
  SegmentGroup,
  SegmentKey,
  SpotTerms,
  StopMoney,
} from './model';
import { fmtFixed, fmtNumber } from './format';
import { bandLabel } from './palette';
import { driveFallbackReason, driveSourceLabel } from './wording';
import type { DriveLegSourceKey, FallbackReasonKey } from './wording';

// -------------------------------------------------------------------------------------------------
// Playing the week (4.2, interaction 5)
// -------------------------------------------------------------------------------------------------

/** Milliseconds one hour stays on screen while the week plays. */
export type PlaySpeedMs = 1200 | 600 | 300;

/** The options of the "Playback speed" select, slowest first. */
export const PLAY_SPEEDS: readonly { ms: PlaySpeedMs; label: string }[] = [
  { ms: 1200, label: 'Slow' },
  { ms: 600, label: 'Normal' },
  { ms: 300, label: 'Fast' },
];

/** A frame that arrives late never jumps further than this many hours: playback picks up where it stopped. */
export const MAX_STEPS_PER_FRAME = 4;

export interface PlaybackTick {
  /** Whole hours to move on this frame. */
  steps: number;
  /** Time already spent on the hour now showing; hand it back on the next frame. */
  carryMs: number;
}

/**
 * The accumulator of the playback loop. Each animation frame hands in the time since the last
 * frame; whole steps come out and the rest is carried, so the pace does not drift with the frame
 * rate. Paused, nothing moves and nothing is carried. Hours are stepped, never blended.
 */
export function advancePlayback(carryMs: number, elapsedMs: number, stepMs: number, playing: boolean): PlaybackTick {
  if (!playing || !(stepMs > 0)) return { steps: 0, carryMs: 0 };
  let elapsed = elapsedMs > 0 && elapsedMs !== Infinity ? elapsedMs : 0;
  const longest = MAX_STEPS_PER_FRAME * stepMs;
  if (elapsed > longest) elapsed = longest;
  const total = (carryMs > 0 ? carryMs : 0) + elapsed;
  const steps = Math.floor(total / stepMs);
  return { steps, carryMs: total - steps * stepMs };
}

/** An hour of the week moved by a number of hours; Sunday 11 PM wraps to Monday 12 AM and back. */
export function stepHow(how: number, delta: number): number {
  return modFloor(Math.floor(how) + Math.floor(delta), HOURS_PER_WEEK);
}

/** The same clock hour on another day of the week (0 = Monday). */
export function howOnDay(how: number, dow: number): number {
  const h = modFloor(Math.floor(how), HOURS_PER_WEEK);
  return modFloor(Math.floor(dow), 7) * 24 + (h % 24);
}

/** Another clock hour on the same day of the week. */
export function howAtHour(how: number, hour: number): number {
  const h = modFloor(Math.floor(how), HOURS_PER_WEEK);
  return h - (h % 24) + modFloor(Math.floor(hour), 24);
}

// -------------------------------------------------------------------------------------------------
// Keys (4.2, interaction 4)
// -------------------------------------------------------------------------------------------------

export type HourKeyAction = { kind: 'toggle-play' } | { kind: 'step'; delta: number } | { kind: 'now' };

/** Where the keyboard focus is when a key arrives, as far as the hour keys care. */
export interface KeyFocus {
  /** On the page itself: the document body or the main region. */
  onPage: boolean;
  /** Inside the hour bar. */
  inHourBar: boolean;
  /** Tag name of the focused element, lower case. */
  tag: string;
  /** The `type` of a focused input, else an empty string. */
  inputType: string;
  /** The focused element is editable text. */
  editable: boolean;
}

/**
 * What a key does to the hour: Space plays or pauses, Left and Right move one hour, Up and Down one
 * day, Home is "Now". Only while focus is on the page body or in the hour bar; never while typing
 * (a text field, a select), and never with a modifier key. Space is left to a focused button or
 * link, which it activates. Null means: not ours.
 */
export function hourKeyAction(key: string, focus: KeyFocus, modifier: boolean): HourKeyAction | null {
  if (modifier) return null;
  if (!focus.onPage && !focus.inHourBar) return null;
  const typing =
    focus.editable || focus.tag === 'select' || focus.tag === 'textarea' || (focus.tag === 'input' && focus.inputType !== 'range');
  if (typing) return null;
  switch (key) {
    case ' ':
    case 'Spacebar':
      if (focus.tag === 'button' || focus.tag === 'a' || focus.tag === 'summary') return null;
      return { kind: 'toggle-play' };
    case 'ArrowLeft':
      return { kind: 'step', delta: -1 };
    case 'ArrowRight':
      return { kind: 'step', delta: 1 };
    case 'ArrowUp':
      return { kind: 'step', delta: -24 };
    case 'ArrowDown':
      return { kind: 'step', delta: 24 };
    case 'Home':
      return { kind: 'now' };
    default:
      return null;
  }
}

// -------------------------------------------------------------------------------------------------
// The URL (1.2)
// -------------------------------------------------------------------------------------------------

export interface MapCameraLike {
  lat: number;
  lng: number;
  zoom: number;
}

/** What the query string of /truck/map says. Anything absent or not valid is null (or false). */
export interface MapParams {
  /** Camera centre; both are null unless both are valid. */
  lat: number | null;
  lng: number | null;
  /** Zoom, 8 to 19. */
  z: number | null;
  /** Hour of week, 0..167. */
  how: number | null;
  layer: MapLayer | null;
  /** `pt=lat,lng`: the spot card is open at this point. */
  pt: LatLng | null;
  /** `spot=<id>`: the spot card is open for this saved spot. It wins over `pt`. */
  spot: string | null;
  /** Pick mode: the next click sets the base or starts "Add spot". */
  pick: 'base' | 'spot' | null;
  /** `return=<path>`: where to go after a pick. Only paths under /truck are taken. */
  returnTo: string | null;
  /** `scout=1`: show Scout result dots. */
  scout: boolean;
  /** `tp_basemap=blank`: draw over the blank base map. */
  blank: boolean;
}

/** The zoom range of the map (5.1): a zoom outside it is not a camera this page can show. */
export const MIN_ZOOM = 8;
export const MAX_ZOOM = 19;

const COORD = /^-?\d{1,3}(?:\.\d+)?$/;
const ZOOM = /^\d{1,2}(?:\.\d+)?$/;
const WHOLE = /^\d{1,3}$/;
const SPOT_ID = /^[A-Za-z0-9_-]{1,64}$/;
const RETURN_PATH = /^\/truck(?:[/?#][^\s\\]*)?$/;

function coordOf(text: string | null, limit: number): number | null {
  if (text === null || !COORD.test(text)) return null;
  const x = Number(text);
  if (!(x >= -limit && x <= limit)) return null;
  return x === 0 ? 0 : x;
}

/** A path of this section to go back to after a pick. Anything that could leave /truck is refused. */
function returnPathOf(text: string | null): string | null {
  if (text === null || !RETURN_PATH.test(text)) return null;
  const path = text.split(/[?#]/)[0];
  if (path.indexOf('//') >= 0) return null;
  for (const part of path.split('/')) if (part === '..' || part === '.') return null;
  return text;
}

function pointOf(text: string | null): LatLng | null {
  if (text === null) return null;
  const at = text.indexOf(',');
  if (at < 0) return null;
  const lat = coordOf(text.slice(0, at), 90);
  const lng = coordOf(text.slice(at + 1), 180);
  return lat === null || lng === null ? null : { lat, lng };
}

/** Reads the query string (with or without its question mark). Never throws: a bad value is absent. */
export function readMapParams(search: string): MapParams {
  const q = new URLSearchParams(search);
  const lat = coordOf(q.get('lat'), 90);
  const lng = coordOf(q.get('lng'), 180);
  const both = lat !== null && lng !== null;

  let z: number | null = null;
  const zText = q.get('z');
  if (zText !== null && ZOOM.test(zText)) {
    const value = Number(zText);
    if (value >= MIN_ZOOM && value <= MAX_ZOOM) z = value;
  }

  let how: number | null = null;
  const howText = q.get('how');
  if (howText !== null && WHOLE.test(howText)) {
    const value = Number(howText);
    if (value < HOURS_PER_WEEK) how = value;
  }

  const layerText = q.get('layer');
  let layer: MapLayer | null = null;
  for (const id of MAP_LAYERS) if (id === layerText) layer = id;

  const spotText = q.get('spot');
  const spot = spotText !== null && SPOT_ID.test(spotText) ? spotText : null;
  const pickText = q.get('pick');

  return {
    lat: both ? lat : null,
    lng: both ? lng : null,
    z,
    how,
    layer,
    pt: spot !== null ? null : pointOf(q.get('pt')),
    spot,
    pick: pickText === 'base' || pickText === 'spot' ? pickText : null,
    returnTo: returnPathOf(q.get('return')),
    scout: q.get('scout') === '1',
    blank: q.get('tp_basemap') === 'blank',
  };
}

/** What a write changes. A key left out keeps what the URL has; null takes the key out. */
export interface MapParamsPatch {
  camera?: MapCameraLike | null;
  how?: number | null;
  layer?: MapLayer | null;
  pt?: LatLng | null;
  spot?: string | null;
  pick?: 'base' | 'spot' | null;
  returnTo?: string | null;
  scout?: boolean;
}

/** The keys this page owns, in the order they are written. Any other key is kept as it came. */
const OWN_KEYS = ['lat', 'lng', 'z', 'how', 'layer', 'pt', 'spot', 'pick', 'return', 'scout'];

function clampZoom(zoom: number): number {
  return zoom < MIN_ZOOM ? MIN_ZOOM : zoom > MAX_ZOOM ? MAX_ZOOM : zoom;
}

/** `pt` as the URL writes it: six decimals each, a plain comma between. */
export function pointParam(point: LatLng): string {
  return fmtFixed(point.lat, 6) + ',' + fmtFixed(point.lng, 6);
}

/**
 * The query string after a change, with its question mark, or an empty string when nothing is left.
 * The page's own keys come out in a fixed order and in their canonical spelling (centre with six
 * decimals, zoom with one), so writing what was read changes nothing; keys of other owners
 * (`tp_basemap`, `tp_perf`) are kept behind them.
 */
export function writeMapParams(search: string, patch: MapParamsPatch): string {
  const now = readMapParams(search);
  let camera: { lat: number; lng: number; zoom: number | null } | null = null;
  if (patch.camera === undefined) {
    if (now.lat !== null && now.lng !== null) camera = { lat: now.lat, lng: now.lng, zoom: now.z };
  } else if (patch.camera !== null) {
    camera = { lat: patch.camera.lat, lng: patch.camera.lng, zoom: clampZoom(patch.camera.zoom) };
  }
  const how = patch.how === undefined ? now.how : patch.how;
  const layer = patch.layer === undefined ? now.layer : patch.layer;
  const spot = patch.spot === undefined ? now.spot : patch.spot;
  const pt = patch.pt === undefined ? now.pt : patch.pt;
  const pick = patch.pick === undefined ? now.pick : patch.pick;
  const returnTo = patch.returnTo === undefined ? now.returnTo : patch.returnTo;
  const scout = patch.scout === undefined ? now.scout : patch.scout;

  const parts: string[] = [];
  if (camera !== null) {
    parts.push('lat=' + fmtFixed(camera.lat, 6), 'lng=' + fmtFixed(camera.lng, 6));
    if (camera.zoom !== null) parts.push('z=' + fmtFixed(camera.zoom, 1));
  }
  if (how !== null) parts.push('how=' + String(stepHow(how, 0)));
  if (layer !== null) parts.push('layer=' + layer);
  if (spot !== null) parts.push('spot=' + encodeURIComponent(spot));
  else if (pt !== null) parts.push('pt=' + pointParam(pt));
  if (pick !== null) {
    parts.push('pick=' + pick);
    if (returnTo !== null) parts.push('return=' + encodeURIComponent(returnTo).split('%2F').join('/'));
  }
  if (scout) parts.push('scout=1');

  new URLSearchParams(search).forEach((value, key) => {
    if (OWN_KEYS.indexOf(key) < 0) parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
  });
  return parts.length === 0 ? '' : '?' + parts.join('&');
}

/** Zoom of the first visit, and of a link that names a point without a camera. */
export const DEFAULT_ZOOM = 12;
export const POINT_ZOOM = 14;

function usablePoint(p: LatLng | null | undefined): p is LatLng {
  return p !== null && p !== undefined && p.lat >= -90 && p.lat <= 90 && p.lng >= -180 && p.lng <= 180;
}

/**
 * Where the map starts: the camera of the URL; else the point or the saved spot a link names, so
 * that the card it opens is not about a place off screen; else where the map was last left; else
 * the truck's base; else the centre of the region. Zoom 12 unless the URL or the last visit says
 * otherwise (14 on a named point).
 */
export function initialCamera(
  params: MapParams,
  persisted: MapCameraLike | null,
  base: LatLng,
  regionCenter: LatLng | null,
  spotPoint: LatLng | null = null,
): MapCameraLike {
  const known = params.z !== null ? params.z : persisted !== null ? clampZoom(persisted.zoom) : null;
  if (params.lat !== null && params.lng !== null) {
    return { lat: params.lat, lng: params.lng, zoom: known !== null ? known : DEFAULT_ZOOM };
  }
  const named = usablePoint(spotPoint) ? spotPoint : params.pt;
  if (named !== null) return { lat: named.lat, lng: named.lng, zoom: known !== null ? known : POINT_ZOOM };
  if (persisted !== null && usablePoint(persisted)) {
    return { lat: persisted.lat, lng: persisted.lng, zoom: known !== null ? known : DEFAULT_ZOOM };
  }
  const zoom = params.z !== null ? params.z : DEFAULT_ZOOM;
  if (usablePoint(base)) return { lat: base.lat, lng: base.lng, zoom };
  if (usablePoint(regionCenter)) return { lat: regionCenter.lat, lng: regionCenter.lng, zoom };
  return { lat: 0, lng: 0, zoom: MIN_ZOOM };
}

// -------------------------------------------------------------------------------------------------
// Layout (4.2)
// -------------------------------------------------------------------------------------------------

/** Width of the spot card as a right panel, in px. */
export const CARD_WIDTH = 420;

/**
 * Where the spot card sits: a right panel on a desktop (from 1024 px) and on a tablet held
 * sideways; a bottom sheet on a tablet held upright and on a phone.
 */
export function cardSide(width: number, height: number): 'right' | 'bottom' {
  if (width >= 1024) return 'right';
  if (width >= 768 && width > height) return 'right';
  return 'bottom';
}

/** True from 1024 px: the legend starts open. Below, it starts as the "Legend" button. */
export function legendStartsOpen(width: number): boolean {
  return width >= 1024;
}

/**
 * How the cards over the map are arranged, by the room the map itself has (the spot card takes
 * room from it): `wide` has the layer switch and the legend at the left, the status in the middle
 * and the tools at the right; `medium` puts the status under the tools; `tight` also folds the
 * tools into the "Map options" button; `narrow` stacks a full-width layer switch, the legend
 * button and that same button.
 */
export function floatLayout(mapWidth: number): 'wide' | 'medium' | 'tight' | 'narrow' {
  if (mapWidth >= 880) return 'wide';
  if (mapWidth >= 700) return 'medium';
  if (mapWidth >= 560) return 'tight';
  return 'narrow';
}

/**
 * How the hour bar is laid out, by its own width: `wide` is one row with seven day buttons,
 * `compact` one row with a day select, `stacked` two rows.
 */
export function hourBarMode(width: number): 'wide' | 'compact' | 'stacked' {
  if (width >= 960) return 'wide';
  if (width >= 640) return 'compact';
  return 'stacked';
}

// -------------------------------------------------------------------------------------------------
// Hover hint, legend marker, status (4.2)
// -------------------------------------------------------------------------------------------------

/** The lead of the hover hint's first line, by layer. */
export const HINT_LEADS: Readonly<Record<MapLayer, string>> = {
  opportunity: 'Orders an hour',
  people: 'People nearby',
  competition: 'Competition',
};

/** The second line of the hover hint. */
export const HINT_ACTION = 'Click for an estimate at this point';

/**
 * The first line of the hover hint: the legend band of the hovered cell, never a single figure
 * (a figure would come from the quantised pack and would carry no range and no label). An
 * uncoloured cell reads the lowest band.
 */
export function hintLine(layer: MapLayer, byte: number): string {
  return HINT_LEADS[layer] + ': ' + bandLabel(layer, byte);
}

/** A box in window coordinates. */
export interface Box {
  left: number;
  top: number;
  right: number;
  bottom: number;
}

/**
 * Where the hover hint goes: below and to the right of the pointer, flipped to the other side
 * where it would leave the box it has to stay in (the map: the hint never lies over the spot card
 * or the hour bar). Whole pixels.
 */
export function hintPlace(pointerX: number, pointerY: number, hintWidth: number, hintHeight: number, within: Box): { x: number; y: number } {
  const gap = 14;
  const edge = 8;
  let x = pointerX + gap;
  if (x + hintWidth > within.right - edge) x = pointerX - gap - hintWidth;
  if (x < within.left + edge) x = within.left + edge;
  let y = pointerY + gap;
  if (y + hintHeight > within.bottom - edge) y = pointerY - gap - hintHeight;
  if (y < within.top + edge) y = within.top + edge;
  return { x: Math.floor(x), y: Math.floor(y) };
}

/**
 * Where the hovered cell sits along the legend ramp, 0..1, or null for a cell without colour. The
 * byte is 255 * sqrt(value / top), which is also the scale of the ramp, so the share is byte / 255.
 */
export function markerShare(byte: number | null | undefined): number | null {
  if (byte === null || byte === undefined || !(byte > 0)) return null;
  return byte >= 255 ? 1 : byte / 255;
}

/** The legend title of each layer. */
export const LEGEND_TITLES: Readonly<Record<MapLayer, string>> = {
  opportunity: 'Expected orders per hour',
  people: 'People nearby',
  competition: 'Food competition',
};

/** The labels of the layer switch. */
export const LAYER_LABELS: Readonly<Record<MapLayer, string>> = {
  opportunity: 'Opportunity',
  people: 'People nearby',
  competition: 'Competition',
};

/** The caption under each legend. */
export const LEGEND_CAPTIONS: Readonly<Record<MapLayer, string>> = {
  opportunity:
    'Your truck parked at each hexagon in a typical week. No host, no weather. A rough guide for ranking places. Click a point for an estimate with its range. Colours near hospitals, campuses and stations rest on the weakest figures.',
  people: 'People present within walking distance. Nearer people count more.',
  competition: 'Pull of food outlets around each hexagon. 1 equals one quick-service outlet at the same spot.',
};

/** A title inside a sentence: its first letter in lower case (A-Z only). */
function lowerFirst(text: string): string {
  const code = text.charCodeAt(0);
  return code >= 65 && code <= 90 ? String.fromCharCode(code + 32) + text.slice(1) : text;
}

/** "Map of expected orders per hour. Use Spots and Scout for the same information as lists." */
export function mapRegionLabel(layer: MapLayer): string {
  return 'Map of ' + lowerFirst(LEGEND_TITLES[layer]) + '. Use Spots and Scout for the same information as lists.';
}

/** The states of the colour layer the page can be told about (5.8). */
export type LayerStatusName =
  | 'no-region'
  | 'loading'
  | 'building'
  | 'ready'
  | 'ready-2d'
  | 'zoomed-out'
  | 'failed'
  | 'version-mismatch';

export interface StatusLine {
  /** Picks the icon: a status is always a word and an icon. */
  kind: 'loading' | 'zoom' | 'warning' | 'info';
  text: string;
}

export const REBUILD_STATUS = 'Map data is being rebuilt after an update. New estimates are unavailable until it finishes.';
export const GOOGLE_FAILED_STATUS =
  'The Google map could not load, so the background map is hidden. Estimates and saved spots still work.';

/**
 * What the status chip says: nothing while the colours draw, else one sentence about the colour
 * layer, and one more when the Google map could not load. `gzBytes` is the compressed size of the
 * map data, which the loading sentence prints in megabytes with one decimal.
 */
export function statusLines(input: {
  status: LayerStatusName;
  rebuilding: boolean;
  gzBytes: number | null;
  googleFailed: boolean;
}): StatusLine[] {
  const lines: StatusLine[] = [];
  switch (input.status) {
    case 'no-region':
      lines.push(input.rebuilding ? { kind: 'info', text: REBUILD_STATUS } : { kind: 'info', text: 'No map data for this area yet.' });
      break;
    case 'loading':
    case 'building':
      lines.push({
        kind: 'loading',
        text:
          input.gzBytes !== null && input.gzBytes > 0
            ? 'Loading map data (' + fmtNumber(input.gzBytes / 1000000, 1) + ' MB, first time only)...'
            : 'Loading map data...',
      });
      break;
    case 'zoomed-out':
      lines.push({ kind: 'zoom', text: 'Zoom in to see the colours.' });
      break;
    case 'version-mismatch':
      lines.push({ kind: 'warning', text: 'The map data is from a different version. Reload the page.' });
      break;
    case 'failed':
      lines.push({
        kind: 'warning',
        text: 'Map colours are unavailable right now. You can still click the map for an estimate.',
      });
      break;
    default:
      break;
  }
  if (input.googleFailed) lines.push({ kind: 'warning', text: GOOGLE_FAILED_STATUS });
  return lines;
}

/** Relative heights of the 24 bars of the hour strip, 0..1: the tallest hour is 1. No numbers are shown. */
export function stripHeights(bytes: ArrayLike<number> | null): number[] {
  const out: number[] = [];
  let top = 0;
  if (bytes !== null) for (let h = 0; h < 24 && h < bytes.length; h++) if (bytes[h] > top) top = bytes[h];
  for (let h = 0; h < 24; h++) {
    const value = bytes !== null && h < bytes.length ? bytes[h] : 0;
    out.push(top > 0 && value > 0 ? value / top : 0);
  }
  return out;
}

// -------------------------------------------------------------------------------------------------
// The spot card (4.3)
// -------------------------------------------------------------------------------------------------

/** The terms of a clicked point: the truck at normal visibility, no host, no fee. */
export const POINT_TERMS: SpotTerms = {
  spot_id: null,
  visibility: 'normal',
  host: null,
  fee_flat: 0,
  fee_pct: 0,
  fee_min: 0,
  allowed: null,
};

export interface WindowSlot {
  /** Day of week of the first hour, 0 = Monday. */
  dow: number;
  /** Minutes from that day's midnight; `close` passes 1440 when the window runs past midnight. */
  open: number;
  close: number;
  /** Hour of week of the first hour. */
  how: number;
  hours: number;
}

/** A window of the week strip (start index and length in hours) as a day and two clock times. */
export function windowSlot(best: BestWindow): WindowSlot {
  const how = modFloor(best.start, HOURS_PER_WEEK);
  const hour = how % 24;
  return { dow: (how - hour) / 24, open: hour * 60, close: (hour + best.length) * 60, how, hours: best.length };
}

export interface WhoRow {
  segment: SegmentKey;
  /** The seed's label of the segment. */
  label: string;
  group: SegmentGroup;
  /** People of this segment present within walking distance (distance-weighted). */
  people: number;
  orders: number;
  /** Share of the hour's orders, 0..1; null when the hour has none. */
  share: number | null;
}

export interface WhoIsHere {
  /** Summed `nearby_present` of the sixteen segments. */
  people: number;
  /** True when that sum is under 1: "Almost nobody is within walking distance at this hour." */
  nobody: boolean;
  /** False when the hour's orders are 0: the rows are then ranked by people present. */
  hasOrders: boolean;
  /** The host's own people, when the spot has a host. */
  host: { segment: SegmentKey; label: string; people: number; orders: number; share: number | null } | null;
  /** The rows shown at first: at least 1 % of the hour's orders, largest first, at most six. */
  rows: WhoRow[];
  /** Every segment that has people or orders, in the same order, for "Show all". */
  all: WhoRow[];
}

/**
 * "Who is here" for one hour. With orders, segments are ranked by their orders and the short list
 * keeps those with at least 1 % of the hour's orders; without, they are ranked by the people
 * present. At most six rows at first. Ties keep the model's segment order.
 */
export function whoIsHere(hour: HourResult, limit = 6): WhoIsHere {
  const hasOrders = qkey(hour.orders) > 0;
  let people = 0;
  const rows: WhoRow[] = [];
  for (let i = 0; i < hour.segments.length; i++) {
    const s = hour.segments[i];
    people += s.nearby_present;
    rows.push({
      segment: s.segment,
      label: SEEDS.segments[s.segment].label,
      group: SEEDS.segments[s.segment].group,
      people: s.nearby_present,
      orders: s.orders,
      share: hasOrders ? s.orders / hour.orders : null,
    });
  }
  const rank = (row: WhoRow): number => qkey(hasOrders ? row.orders : row.people);
  const order = rows.map((row, index) => ({ row, index }));
  order.sort((a, b) => rank(b.row) - rank(a.row) || a.index - b.index);
  const all = order.map((o) => o.row).filter((row) => qkey(row.people) > 0 || qkey(row.orders) > 0);
  const listed = hasOrders ? all.filter((row) => row.share !== null && row.share >= 0.01) : all;
  const host =
    hour.host === null
      ? null
      : {
          segment: hour.host.segment,
          label: SEEDS.segments[hour.host.segment].label,
          people: hour.host.people_present,
          orders: hour.host.orders,
          share: hasOrders ? hour.host.orders / hour.orders : null,
        };
  return { people, nobody: people < 1, hasOrders, host, rows: listed.slice(0, limit), all };
}

/**
 * What one more order leaves at a stop's expected sales: the margin while the minimum fee is what
 * is paid, or the margin once the flat fee plus the share of sales is (02_MODEL 4.9).
 */
export function orderLeaves(money: StopMoney, terms: SpotTerms): number {
  const noFee = terms.fee_flat === 0 && terms.fee_pct === 0 && terms.fee_min === 0;
  const minimumApplies = noFee || terms.fee_flat + terms.fee_pct * money.sales.value <= terms.fee_min;
  return minimumApplies ? money.unit_margin.at_minimum : money.unit_margin.at_percentage;
}

/** What the drive rows read of a leg the server sent. */
export interface DriveLegLike {
  from_id: string;
  to_id: string;
  source: DriveLegSourceKey;
  fallback_reason: FallbackReasonKey | null;
}

export interface DriveSummary {
  /** Minutes from the base to the stop, and back. */
  outMinutes: number;
  backMinutes: number;
  /** Miles one way (the drive out). */
  miles: number;
  /** The source label of 6.7 for the drive out. */
  label: string;
  /** True for a straight-line estimate: the label then carries a caution icon. */
  straight: boolean;
  /** Why Google did not supply the leg, when the server said. */
  reason: string | null;
}

/**
 * The drive of a one-stop day: base to the stop and back, from the legs the model evaluated, with
 * the wording of the leg the server sent (none for a clicked point, which is always a straight
 * line). Null when the day has no such pair of legs.
 */
export function driveSummary(legs: readonly Leg[], sent: readonly DriveLegLike[], trafficNeutral: boolean): DriveSummary | null {
  let out: Leg | null = null;
  let back: Leg | null = null;
  for (const leg of legs) {
    if (leg.from_id === 'base' && out === null) out = leg;
    if (leg.to_id === 'base') back = leg;
  }
  if (out === null || back === null) return null;
  let server: DriveLegLike | null = null;
  for (const leg of sent) if (leg.from_id === out.from_id && leg.to_id === out.to_id) server = leg;
  const straight = out.source === 'fallback';
  return {
    outMinutes: out.minutes,
    backMinutes: back.minutes,
    miles: out.miles,
    label: driveSourceLabel({
      legSource: out.source,
      driveSource: server === null ? null : server.source,
      departMinute: out.depart_minute,
      trafficNeutral,
    }),
    straight,
    reason: straight && server !== null ? driveFallbackReason(server.fallback_reason) : null,
  };
}

/** "Fairfax County, VA" for a county code of the region, or null when the code is not one of its counties. */
export function countyLabel(
  fips: string | null | undefined,
  counties: readonly { fips: string; name: string; state: string }[] | null | undefined,
): string | null {
  if (fips === null || fips === undefined || counties === null || counties === undefined) return null;
  for (const county of counties) {
    if (county.fips === fips) return county.state === '' ? county.name : county.name + ', ' + county.state;
  }
  return null;
}

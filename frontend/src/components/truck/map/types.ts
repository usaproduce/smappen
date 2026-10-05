// Truck Planner map engine - the interfaces of docs/truck-planner/05_FRONTEND.md 5.1.
//
// The map page is written against these; the engine in this directory is built behind them.
// `CellPack` (5.2), `HexMesh` (5.3) and `Viewport` are declared next to `decodePack`, `buildMesh` and
// the viewport maths, which run in Node as well, and are re-exported from here.
//
// Types only: this file has no runtime content.

import type { ReactNode } from 'react';
import type { Assumptions, CalibrationState, TruckProfile } from '../../../utils/truck/model';
import type { RegionInfo } from '../../../api/truck';
import type { CellPack } from '../../../utils/truck/map/pack';
import type { HexMesh } from '../../../utils/truck/map/mesh';
import type { Viewport } from '../../../utils/truck/map/viewport';

export type { CellPack, CellPackHeader } from '../../../utils/truck/map/pack';
export type { HexMesh } from '../../../utils/truck/map/mesh';
export type { Viewport } from '../../../utils/truck/map/viewport';

export type MapLayerId = 'opportunity' | 'people' | 'competition';

/** Where the pack request stands, as `useCellPack` reports it and `HexLayer.setPack` takes it. */
export type PackState = 'idle' | 'loading' | 'error' | 'ready';

// -------------------------------------------------------------------------------------------------
// Renderer, host, layer (5.1)
// -------------------------------------------------------------------------------------------------

export interface Renderer {
  readonly kind: 'webgl2' | 'canvas2d';
  setMesh(mesh: HexMesh): void;
  setLut(lut: Uint8Array): void;
  /** One byte per cell. */
  setValues(values: Uint8Array): void;
  setOpacity(alpha: number): void;
  resize(width: number, height: number, dpr: number): void;
  render(vp: Viewport): void;
  dispose(): void;
}

export interface MapPointerEvent {
  lat: number;
  lng: number;
  clientX: number;
  clientY: number;
}

export interface MapCamera {
  lat: number;
  lng: number;
  zoom: number;
}

export interface MapHost {
  readonly kind: 'google' | 'blank';
  /**
   * Put the canvas under the pin layer on the base map and start reporting the viewport: once now
   * and then on every camera change and resize. A host can be attached again after `detach`, with
   * another canvas.
   */
  attach(canvas: HTMLCanvasElement, pinLayer: HTMLElement, onViewport: (vp: Viewport) => void): void;
  /** The place `Viewport.originX` and `originY` are measured to: the origin of the mesh. */
  setOrigin(lat: number, lng: number): void;
  /** Position inside the pin layer. Null while the host cannot tell (before its first viewport). */
  project(lat: number, lng: number): { x: number; y: number } | null;
  /** Pointer events of the map itself: never of a pin. Returns the function that stops listening. */
  on(event: 'move' | 'click' | 'leave', cb: (e: MapPointerEvent) => void): () => void;
  getCamera(): MapCamera;
  setCamera(c: { lat: number; lng: number; zoom?: number }): void;
  detach(): void;
}

export type LayerStatus =
  | 'no-region'
  | 'loading'
  | 'building'
  | 'ready'
  | 'ready-2d'
  | 'zoomed-out'
  | 'failed'
  | 'version-mismatch';

/** What the bulk scorer needs: recomputed only when one of the three changes. */
export interface LayerInputs {
  A: Assumptions;
  profile: TruckProfile;
  cal: CalibrationState | null;
}

export interface HexLayer {
  setPack(pack: CellPack | null, state: PackState): void;
  setInputs(i: LayerInputs): void;
  setLayer(id: MapLayerId): void;
  setHour(how: number, date: string | null): void;
  setTheme(t: 'light' | 'dark'): void;
  cellAt(lat: number, lng: number): { index: number; id: string; byte: number } | null;
  /** 24 mean bytes of the cells in view, computed when idle. A newer request replaces one still waiting. */
  hourStrip(dow: number, done: (bytes: Uint8Array) => void): void;
  /** The callback is called at once with the current status, then on every change. */
  onStatus(cb: (s: LayerStatus) => void): () => void;
  destroy(): void;
}

// -------------------------------------------------------------------------------------------------
// The React surface (5.1)
// -------------------------------------------------------------------------------------------------

/** The hovered cell: its id, its colour byte (0 = uncoloured) and where the pointer is. */
export interface MapHit {
  id: string;
  byte: number;
  clientX: number;
  clientY: number;
}

export interface TruckMapProps {
  /** The truck's region, or null. Without a usable region the map shows without colours. */
  region: RegionInfo | null;
  /** Where the map starts. Later moves go through the handle's `flyTo`. */
  initialCamera: MapCamera;
  layer: MapLayerId;
  pack: CellPack | null;
  packState: PackState;
  inputs: LayerInputs;
  /** Use the blank base map even when Google is available (`?tp_basemap=blank`). */
  forceBlank?: boolean;
  cursor?: 'default' | 'crosshair';
  /**
   * The cell under the pointer, at most once per animation frame while the pointer moves over the
   * map, and again when its byte changes with the hour. Null when the pointer leaves the map, moves
   * onto a pin or is over no cell of the pack.
   */
  onHover?: (hit: MapHit | null) => void;
  /** A click or tap on the map itself. A click on a pin never reaches it. */
  onClick?: (point: { lat: number; lng: number }) => void;
  /** The camera after it came to rest. */
  onCamera?: (camera: MapCamera) => void;
  onStatus?: (status: LayerStatus) => void;
  /**
   * When Google could not load and the blank base stands in, the map says so in a small status card
   * of its own (bottom left). Pass false when the page shows that sentence itself. Default true.
   */
  hostNotice?: boolean;
  /** `MapPin` elements. */
  children?: ReactNode;
}

export interface TruckMapHandle {
  flyTo(camera: { lat: number; lng: number; zoom?: number }): void;
  getCenter(): { lat: number; lng: number };
  hourStrip(dow: number, done: (bytes: Uint8Array) => void): void;
}

/** Stacking order of pins, lowest first: scout dots, saved spots, the selected point, the base. */
export type MapPinKind = 'scout' | 'spot' | 'selected' | 'base';

export interface MapPinProps {
  lat: number;
  lng: number;
  /** Decides the stacking order. Default `spot`. */
  kind?: MapPinKind;
  /**
   * The pin itself: a real button. The host keeps a zero-size anchor on the coordinate and the pin
   * is laid out from that point (its top left corner sits on it), so the pin moves itself to where
   * it belongs: `transform: translate(-50%, -100%)` for a teardrop whose tip marks the place,
   * `translate(-50%, -50%)` for a dot or a ring centred on it.
   */
  children: ReactNode;
}

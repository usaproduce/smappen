// Truck Planner map engine - the interfaces of docs/truck-planner/05_FRONTEND.md 5.1.
//
// These are final: the map page (FE-2) is written against them while the engine (FE-1) is built
// behind them. The engine replaces the stub bodies in this directory and keeps every exported name
// and every prop type. `CellPack` (5.2) and `HexMesh` (5.3) are declared here because the interfaces
// refer to them; the engine may move the two declarations next to `decodePack` and `buildMesh` and
// re-export them from this file.
//
// Types only: this file has no runtime content.

import type { ReactNode } from 'react';
import type { Assumptions, CalibrationState, TruckProfile } from '../../../utils/truck/model';
import type { RegionInfo } from '../../../api/truck';

export type MapLayerId = 'opportunity' | 'people' | 'competition';

// -------------------------------------------------------------------------------------------------
// The cell pack (5.2) and the mesh (5.3)
// -------------------------------------------------------------------------------------------------

/** The JSON header of a cell pack (03_DATA section 11). */
export interface CellPackHeader {
  format: string;
  format_version: number;
  region_id: string;
  dataset_version: string;
  model_version: string;
  pipeline_version: string;
  h3_res: number;
  cell_count: number;
  /** The box of the cell centres. */
  bounds: { lat_min: number; lng_min: number; lat_max: number; lng_max: number };
  /** The build-scope seed values the pack was built with. */
  kernel: Record<string, unknown>;
  segments: string[];
  /** 50 names: `c_day_<seg>` x 16, `c_eve_<seg>` x 16, `n_<seg>` x 16, `r_day`, `r_eve`. */
  columns: string[];
  quant: { type: string; levels: number };
  /** The largest value of each column: the decode scale. */
  scale: number[];
  sections: { name: string; type: string; layout?: string; offset: number; count: number }[];
  vintages: { census_reference_date: string; lodes_year: number; osm_snapshot_date: string };
  attribution: string[];
}

/** A decoded cell pack. It feeds colours only: no number printed anywhere comes from it. */
export interface CellPack {
  header: CellPackHeader;
  /** Number of cells. */
  n: number;
  /** Features per cell (50). */
  k: number;
  /** H3 ids as 15-character strings, ascending. */
  ids: string[];
  /** Row-major: the `k` features of cell `i` start at `i * k`. */
  features: Float32Array;
}

/** One static mesh of true H3 cell outlines, in world coordinates relative to the centre of the pack's bounds. */
export interface HexMesh {
  /** Number of cells. */
  n: number;
  /** The mesh origin: the centre of the pack's bounds. */
  originLat: number;
  originLng: number;
  /** The same point in the 256-unit Mercator world. */
  originX: number;
  originY: number;
  /** 12 numbers per cell: six vertices, x then y, relative to the origin. A pentagon repeats its last vertex. */
  positions: Float32Array;
  /** 12 indices per cell: a fan over its six vertices. */
  indices: Uint32Array;
  /** 2 numbers per cell: its centre, relative to the origin. */
  centers: Float32Array;
  /** 2 numbers per cell: half-width and half-height of its bounding box. */
  halfSizes: Float32Array;
}

/** Where the pack request stands, as `useCellPack` reports it and `HexLayer.setPack` takes it. */
export type PackState = 'idle' | 'loading' | 'error' | 'ready';

// -------------------------------------------------------------------------------------------------
// Renderer, host, layer (5.1)
// -------------------------------------------------------------------------------------------------

export interface Viewport {
  /** CSS px. */
  width: number;
  height: number;
  /** min(devicePixelRatio, 2). */
  dpr: number;
  /** CSS px per world unit (256-unit world). */
  scale: number;
  /** Canvas position, CSS px, of the mesh origin. */
  originX: number;
  originY: number;
}

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
  attach(canvas: HTMLCanvasElement, pinLayer: HTMLElement, onViewport: (vp: Viewport) => void): void;
  /** Position inside the pin layer. */
  project(lat: number, lng: number): { x: number; y: number } | null;
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
  /** 24 mean bytes of the cells in view, computed when idle. */
  hourStrip(dow: number, done: (bytes: Uint8Array) => void): void;
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
  onHover?: (hit: MapHit | null) => void;
  /** A click or tap on the map itself. A click on a pin never reaches it. */
  onClick?: (point: { lat: number; lng: number }) => void;
  /** The camera after it came to rest. */
  onCamera?: (camera: MapCamera) => void;
  onStatus?: (status: LayerStatus) => void;
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
  /** The pin itself: a real button, positioned by the host on every viewport change. */
  children: ReactNode;
}

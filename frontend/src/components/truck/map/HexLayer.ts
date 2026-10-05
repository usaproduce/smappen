// Truck Planner map engine - the hexagon layer (docs/truck-planner/05_FRONTEND.md 5.3 to 5.9).
//
// It owns the mesh, the frames and the renderer, and reacts to the pack, the inputs, the layer, the
// hour and the theme. Per tick of the hour control it does: fill the frame, hand it to the renderer,
// draw on the next animation frame. Per camera change it draws at once, inside the host's callback,
// so the layer moves in the same frame as the base map. While the browser is idle it scores the
// hours that come next, so a tick usually finds its frame ready.
//
// It never throws. Every public method and every host callback runs inside try and catch; a caught
// error is logged once, the canvas is cleared and the status becomes `failed`. Clicking the map,
// pins and the spot card keep working, because they do not depend on the layer.

import { cellToBoundary, latLngToCell } from 'h3-js';
import { HOURS_PER_WEEK, MODEL_VERSION } from '../../../utils/truck/model';
import { LAYER_OPACITY, buildLut } from '../../../utils/truck/palette';
import { createFrameSource, meanByte } from '../../../utils/truck/map/frames';
import type { FrameSource } from '../../../utils/truck/map/frames';
import { buildMesh } from '../../../utils/truck/map/mesh';
import { buildCellIndex, cellAt as pickCell } from '../../../utils/truck/map/pick';
import { alphaForScale, cullCells, isZoomedOut, worldRect } from '../../../utils/truck/map/viewport';
import { createCanvas2dRenderer } from './renderers/canvas2d';
import { createWebgl2Renderer } from './renderers/webgl2';
import type {
  CellPack,
  HexLayer,
  HexMesh,
  LayerInputs,
  LayerStatus,
  MapHost,
  MapLayerId,
  PackState,
  Renderer,
  Viewport,
} from './types';

// -------------------------------------------------------------------------------------------------
// Measurements (5.9)
// -------------------------------------------------------------------------------------------------

/** How many samples the layer keeps of each timing: the "last 120 frames" of the perf overlay. */
export const PERF_WINDOW = 120;

/** The last value and the 95th percentile of a timing, in milliseconds. Null before the first sample. */
export interface Timing {
  last: number | null;
  p95: number | null;
  samples: number;
}

/** What the perf overlay (`?tp_perf=1`) shows. Times are milliseconds. */
export interface LayerStats {
  status: LayerStatus;
  renderer: 'webgl2' | 'canvas2d' | 'none';
  cells: number;
  cellsInView: number;
  /** Score, bytes and upload of one hour tick: main-thread time. */
  tick: Timing;
  /** From an hour tick to the end of the animation frame that drew it. */
  tickToFrame: Timing;
  /** Script per camera frame: drawing the layer and placing pins. */
  draw: Timing;
  /** One hover pick. */
  pick: Timing;
  /** Building the mesh of the current pack, start to finish. */
  meshMs: number | null;
  /** The same without the pauses between its slices: the main-thread work of the build. */
  meshWorkMs: number | null;
  /** The longest slice of the build: the longest task it put on the main thread. */
  meshSliceMs: number | null;
  /** From the map's mount to its first coloured frame. */
  firstFrameMs: number | null;
  /** Frames of the current layer that are scored and kept: 168 when the whole week is ready. */
  framesKept: number;
}

class Samples {
  private readonly values = new Float64Array(PERF_WINDOW);
  private count = 0;
  private next = 0;

  push(ms: number): void {
    this.values[this.next] = ms;
    this.next = (this.next + 1) % PERF_WINDOW;
    if (this.count < PERF_WINDOW) this.count++;
  }

  timing(): Timing {
    if (this.count === 0) return { last: null, p95: null, samples: 0 };
    const last = this.values[(this.next + PERF_WINDOW - 1) % PERF_WINDOW];
    const sorted = Array.from(this.values.subarray(0, this.count)).sort((a, b) => a - b);
    const at = Math.min(sorted.length - 1, Math.ceil(0.95 * sorted.length) - 1);
    return { last, p95: sorted[at < 0 ? 0 : at], samples: this.count };
  }
}

function measure(name: string, start: number, end: number): void {
  try {
    performance.measure(name, { start, end });
  } catch {
    // an older browser without measure options: the perf overlay still has the layer's own samples
  }
}

// -------------------------------------------------------------------------------------------------
// What is kept per pack for the life of the page
// -------------------------------------------------------------------------------------------------

// The pack lives in the query cache; leaving the map and coming back hands the layer the same object,
// so the mesh and the index are built once per pack and found again here.
const meshes = new WeakMap<CellPack, Promise<HexMesh>>();
const meshTimes = new WeakMap<CellPack, { work: number; wall: number; slice: number }>();
const indexes = new WeakMap<CellPack, Map<string, number>>();

/** A slice of the mesh build ends, and the event loop gets its turn, once it has run this long. */
const MESH_SLICE_MS = 10;

/** A new task: whatever waits in the event loop (input, a frame) runs first. No timer clamp. */
function nextTask(): Promise<void> {
  return new Promise<void>((resolve) => {
    if (typeof MessageChannel === 'undefined') {
      window.setTimeout(resolve, 0);
      return;
    }
    const channel = new MessageChannel();
    channel.port1.onmessage = () => {
      channel.port1.close();
      resolve();
    };
    channel.port2.postMessage(null);
  });
}

function meshOf(pack: CellPack): Promise<HexMesh> {
  let known = meshes.get(pack);
  if (known === undefined) {
    const t0 = performance.now();
    // Three times: the work (the slices of the build added up), the longest slice, and start to
    // finish, which also holds whatever else the page did between the slices.
    let work = 0;
    let longest = 0;
    let mark = t0;
    const endSlice = (now: number): void => {
      const slice = now - mark;
      work += slice;
      if (slice > longest) longest = slice;
    };
    // Asked every 1,024 cells: go on, or let the event loop run first.
    const onYield = (): void | Promise<void> => {
      const now = performance.now();
      if (now - mark < MESH_SLICE_MS) return undefined;
      endSlice(now);
      return nextTask().then(() => {
        mark = performance.now();
      });
    };
    const building = buildMesh(pack.ids, (id) => cellToBoundary(id), pack.header.bounds, onYield).then((mesh) => {
      const t1 = performance.now();
      endSlice(t1);
      meshTimes.set(pack, { work, wall: t1 - t0, slice: longest });
      measure('tp:mesh', t0, t1);
      measure('tp:mesh-work', t1 - work, t1);
      return mesh;
    });
    meshes.set(pack, building);
    // A failed build is not remembered: the next layer tries again.
    building.catch(() => {
      if (meshes.get(pack) === building) meshes.delete(pack);
    });
    known = building;
  }
  return known;
}

function indexOf(pack: CellPack): Map<string, number> {
  let index = indexes.get(pack);
  if (index === undefined) {
    index = buildCellIndex(pack.ids);
    indexes.set(pack, index);
  }
  return index;
}

/** A pack the layer does not draw (5.2): another model version, or the data of another dataset version than the region's. */
function refusedPack(pack: CellPack, dataset: string | null): boolean {
  return pack.header.model_version !== MODEL_VERSION || (dataset !== null && pack.header.dataset_version !== dataset);
}

/**
 * Start the mesh of a pack before a layer asks for it. The mesh needs the pack and nothing else, so
 * the map component calls this as soon as the pack is decoded, also while the Google map is still
 * loading; the layer then finds the build done or under way. A pack the layer would refuse is left
 * alone, and a build that fails is the layer's to report when it asks.
 */
export function prepareMesh(pack: CellPack, dataset: string | null): void {
  try {
    if (refusedPack(pack, dataset)) return;
    meshOf(pack).catch(() => undefined);
  } catch {
    // nothing to prepare: the layer meets the same pack and reports it
  }
}

/** The outline of a cell as `[lat, lng]` pairs: what the hover outline is drawn from. */
export function cellOutline(id: string): number[][] | null {
  try {
    return cellToBoundary(id);
  } catch {
    return null;
  }
}

// -------------------------------------------------------------------------------------------------
// The layer
// -------------------------------------------------------------------------------------------------

export interface HexLayerOptions {
  host: MapHost;
  /** The element pins are portalled into; the layer hands it to the host together with its canvas. */
  pinLayer: HTMLElement;
  /** Called after the layer has drawn a viewport, in the same task: pins and the hover outline are placed here. */
  onViewport?: (vp: Viewport) => void;
  /** `performance.now()` of the moment the map mounted: where `tp:first-frame` starts. */
  mountedAt?: number;
}

/** The layer as the map component holds it: the interface of 5.1 plus what only the engine needs. */
export interface HexLayerEngine extends HexLayer {
  /** The region whose data the pack must be: a pack of another dataset version is refused. */
  setRegion(region: { dataset_version: string | null } | null): void;
  stats(): LayerStats;
  /** While on, every tick and every camera frame is also written to the browser's performance timeline. */
  setPerf(on: boolean): void;
}

/** If a lost WebGL context is not back after this long the layer goes on with the 2D renderer. */
const CONTEXT_RESTORE_MS = 3000;
/** An idle slice goes on scoring frames ahead while this much of it is left (milliseconds). */
const WARM_SLICE_MS = 5;
/** And never for longer than this, however long the idle period is. */
const WARM_BUDGET_MS = 12;

function makeCanvas(): HTMLCanvasElement {
  const canvas = document.createElement('canvas');
  canvas.setAttribute('aria-hidden', 'true');
  canvas.setAttribute('data-tp-hex', '');
  canvas.style.cssText = 'position:absolute;left:0;top:0;pointer-events:none;';
  return canvas;
}

type IdleDeadline = { timeRemaining(): number; didTimeout?: boolean };

function whenIdle(run: (deadline: IdleDeadline | null) => void): void {
  const w = window as unknown as {
    requestIdleCallback?: (cb: (deadline: IdleDeadline) => void, options?: { timeout: number }) => number;
  };
  if (typeof w.requestIdleCallback === 'function') w.requestIdleCallback((deadline) => run(deadline), { timeout: 300 });
  else window.setTimeout(() => run(null), 16);
}

export function createHexLayer(options: HexLayerOptions): HexLayerEngine {
  const { host, pinLayer } = options;
  const mountedAt = options.mountedAt ?? performance.now();

  let destroyed = false;
  let failed = false;
  let warned = false;
  let status: LayerStatus = 'no-region';
  const statusListeners = new Set<(s: LayerStatus) => void>();

  let expectedDataset: string | null = null;
  let pack: CellPack | null = null;
  let packState: PackState = 'idle';
  let mismatch = false;
  let buildToken = 0;
  let mesh: HexMesh | null = null;
  let index: Map<string, number> | null = null;
  let frames: FrameSource | null = null;
  let values = new Uint8Array(0);
  let hasValues = false;

  let inputs: LayerInputs | null = null;
  let layerId: MapLayerId = 'opportunity';
  let how = 0;
  let date: string | null = null;
  let refusedDate: string | null = null;
  let theme: 'light' | 'dark' = 'light';

  let vp: Viewport | null = null;
  let canvas = makeCanvas();
  let renderer: Renderer | null = null;
  let frameId = 0;
  let lostTimer = 0;
  let firstFrameMs: number | null = null;
  let tickAt = -1;
  let perf = false;

  const ticks = new Samples();
  const tickFrames = new Samples();
  const draws = new Samples();
  const picks = new Samples();

  // The mesh whose origin the host has been told: only then do viewports say where that mesh sits.
  let placed: HexMesh | null = null;

  let stripJob: { cancelled: boolean } | null = null;
  let stripScratch = new Uint8Array(0);
  let stripVisible = new Uint32Array(0);
  let warming = false;

  // ---- status and failure -----------------------------------------------------------------------

  function computeStatus(): LayerStatus {
    if (failed) return 'failed';
    if (mismatch) return 'version-mismatch';
    if (packState === 'error') return 'failed';
    if (packState === 'loading') return 'loading';
    if (packState === 'idle' || pack === null) return 'no-region';
    if (mesh === null || frames === null || placed !== mesh) return 'building';
    if (renderer === null) return 'failed';
    if (vp !== null && isZoomedOut(vp.scale)) return 'zoomed-out';
    if (renderer.kind === 'canvas2d') {
      return (renderer as Renderer & { overLimit?: boolean }).overLimit === true ? 'zoomed-out' : 'ready-2d';
    }
    return 'ready';
  }

  function updateStatus(): void {
    const next = computeStatus();
    if (next === status) return;
    status = next;
    canvas.setAttribute('data-tp-status', next);
    statusListeners.forEach((cb) => {
      try {
        cb(next);
      } catch {
        // a listener of the page must not stop the layer
      }
    });
  }

  function fail(e: unknown): void {
    if (!warned) {
      warned = true;
      console.warn('[truck-map]', e);
    }
    failed = true;
    try {
      renderNow();
    } catch {
      // nothing more to do: the canvas stays as it is
    }
    updateStatus();
  }

  function guard(run: () => void): void {
    if (destroyed) return;
    try {
      run();
    } catch (e) {
      fail(e);
    }
  }

  // ---- drawing ----------------------------------------------------------------------------------

  function drawable(): boolean {
    return !failed && !mismatch && packState === 'ready' && mesh !== null && placed === mesh && frames !== null && hasValues;
  }

  function renderNow(): void {
    if (destroyed || vp === null || renderer === null) return;
    const alpha = drawable() ? LAYER_OPACITY * alphaForScale(vp.scale) : 0;
    renderer.setOpacity(alpha);
    renderer.render(vp);
    if (alpha > 0 && firstFrameMs === null) {
      const now = performance.now();
      firstFrameMs = now - mountedAt;
      measure('tp:first-frame', mountedAt, now);
    }
  }

  function requestRender(): void {
    if (frameId !== 0 || destroyed) return;
    frameId = requestAnimationFrame(() => {
      frameId = 0;
      guard(() => {
        renderNow();
        if (tickAt >= 0) {
          tickFrames.push(performance.now() - tickAt);
          tickAt = -1;
        }
        updateStatus();
      });
    });
  }

  function onHostViewport(next: Viewport): void {
    if (destroyed) return;
    const t0 = performance.now();
    try {
      const resized = vp === null || vp.width !== next.width || vp.height !== next.height || vp.dpr !== next.dpr;
      vp = next;
      if (renderer !== null) {
        if (resized) renderer.resize(next.width, next.height, next.dpr);
        renderNow();
      }
      updateStatus();
    } catch (e) {
      fail(e);
    }
    if (options.onViewport !== undefined) {
      try {
        options.onViewport(next);
      } catch (e) {
        // Pins are the page's: a failure there is reported, and the layer goes on.
        if (!warned) {
          warned = true;
          console.warn('[truck-map]', e);
        }
      }
    }
    const t1 = performance.now();
    draws.push(t1 - t0);
    if (perf) measure('tp:draw', t0, t1);
  }

  // ---- the frame of the current layer and hour ---------------------------------------------------

  function refill(): void {
    if (frames === null || mesh === null || renderer === null) return;
    const t0 = performance.now();
    if (date !== null && date === refusedDate) date = null;
    try {
      frames.fill(layerId, how, date, values);
    } catch (e) {
      if (date === null) throw e;
      // A date the model refuses: say so once and show the typical week.
      if (!warned) {
        warned = true;
        console.warn('[truck-map]', e);
      }
      refusedDate = date;
      date = null;
      frames.fill(layerId, how, null, values);
    }
    renderer.setValues(values);
    hasValues = true;
    const t1 = performance.now();
    ticks.push(t1 - t0);
    if (perf) measure('tp:tick', t0, t1);
    if (tickAt < 0) tickAt = t0;
    requestRender();
    scheduleWarm();
  }

  // ---- the hours ahead, scored while the browser is idle -----------------------------------------

  function warmStep(deadline: IdleDeadline | null): void {
    warming = false;
    if (destroyed || frames === null || !drawable()) return;
    try {
      // Too little of this idle period is left for a pass over every cell: wait for the next one.
      if (deadline !== null && deadline.didTimeout !== true && deadline.timeRemaining() < WARM_SLICE_MS) {
        scheduleWarm();
        return;
      }
      const started = performance.now();
      // In the order a playing week reaches them.
      for (let ahead = 1; ahead < HOURS_PER_WEEK; ahead++) {
        const next = (how + ahead) % HOURS_PER_WEEK;
        if (frames.has(layerId, next, date)) continue;
        frames.warm(layerId, next, date);
        // A few frames per slice (each is a pass over every cell), and never a long task: an idle
        // period can last 50 ms, which is longer than a press or a key should wait.
        const spent = performance.now() - started;
        // Without idle callbacks the slices come from a timer and stay short.
        if (spent > (deadline !== null ? WARM_BUDGET_MS : WARM_SLICE_MS) || (deadline !== null && deadline.timeRemaining() < WARM_SLICE_MS)) {
          scheduleWarm();
          return;
        }
      }
    } catch (e) {
      fail(e);
    }
  }

  function scheduleWarm(): void {
    if (warming || destroyed) return;
    warming = true;
    whenIdle(warmStep);
  }

  // ---- renderers ---------------------------------------------------------------------------------

  function useRenderer(next: Renderer, nextCanvas: HTMLCanvasElement): void {
    renderer = next;
    canvas = nextCanvas;
    canvas.setAttribute('data-tp-renderer', next.kind);
    canvas.setAttribute('data-tp-status', status);
    next.setLut(buildLut(layerId, theme));
    if (mesh !== null) {
      next.setMesh(mesh);
      if (hasValues) next.setValues(values);
    }
  }

  /** Go on with the 2D renderer, on a canvas of its own: one that had a WebGL context gives no 2D context. */
  function switchTo2d(): void {
    if (destroyed || (renderer !== null && renderer.kind === 'canvas2d')) return;
    if (lostTimer !== 0) {
      window.clearTimeout(lostTimer);
      lostTimer = 0;
    }
    const fresh = makeCanvas();
    const next = createCanvas2dRenderer(fresh);
    const old = renderer;
    const oldCanvas = canvas;
    try {
      old?.dispose();
    } catch {
      // a renderer whose context is gone has nothing left to release
    }
    if (next === null) {
      renderer = null;
      fail(new Error('No canvas renderer is available.'));
      return;
    }
    useRenderer(next, fresh);
    vp = null;
    host.detach();
    host.attach(fresh, pinLayer, onHostViewport);
    oldCanvas.remove();
    updateStatus();
    requestRender();
  }

  function createRenderer(): void {
    const gl = createWebgl2Renderer(canvas, {
      onLost: () => {
        if (destroyed) return;
        if (lostTimer !== 0) window.clearTimeout(lostTimer);
        lostTimer = window.setTimeout(() => {
          lostTimer = 0;
          guard(switchTo2d);
        }, CONTEXT_RESTORE_MS);
      },
      onRestored: (ok) => {
        if (destroyed) return;
        if (lostTimer !== 0) {
          window.clearTimeout(lostTimer);
          lostTimer = 0;
        }
        guard(() => {
          if (ok) requestRender();
          else switchTo2d();
        });
      },
    });
    if (gl !== null) {
      useRenderer(gl, canvas);
      return;
    }
    // No WebGL2 (or it could not be set up, twice): the 2D renderer, on a canvas no context has touched.
    const fresh = makeCanvas();
    const flat = createCanvas2dRenderer(fresh);
    if (flat === null) throw new Error('No canvas renderer is available.');
    useRenderer(flat, fresh);
  }

  // ---- the pack ----------------------------------------------------------------------------------

  function dropPack(): void {
    buildToken++;
    pack = null;
    mesh = null;
    index = null;
    frames = null;
    placed = null;
    hasValues = false;
    mismatch = false;
  }

  // A built mesh goes on screen in three short tasks instead of one long one: the index of its ids;
  // then frames, the first frame and the buffers on the GPU; then the origin, which makes the host
  // answer with a viewport measured to this mesh, and that one is drawn.
  function install(forPack: CellPack, built: HexMesh): void {
    mesh = built;
    placed = null;
    values = new Uint8Array(built.n);
    hasValues = false;
    if (renderer !== null) renderer.setMesh(built);
    if (inputs !== null) frames = createFrameSource(forPack, inputs);
    refill();
  }

  function place(built: HexMesh): void {
    placed = built;
    host.setOrigin(built.originLat, built.originLng);
    renderNow();
    updateStatus();
  }

  function applyPack(nextPack: CellPack | null, nextState: PackState): void {
    if (nextState !== 'ready' || nextPack === null) {
      const nextPackState = nextState === 'ready' ? 'idle' : nextState;
      if (pack === null && packState === nextPackState) return;
      dropPack();
      packState = nextPackState;
      // Another pack state is a new start: an earlier failure of the layer no longer says anything.
      failed = false;
      renderNow();
      updateStatus();
      return;
    }
    const refused = refusedPack(nextPack, expectedDataset);
    if (nextPack === pack && packState === 'ready' && refused === mismatch && !failed) return;

    dropPack();
    failed = false;
    pack = nextPack;
    packState = 'ready';
    if (refused) {
      mismatch = true;
      renderNow();
      updateStatus();
      return;
    }
    const token = buildToken;
    updateStatus();
    const current = (): boolean => !destroyed && token === buildToken && !failed;
    meshOf(nextPack)
      .then((built) => {
        if (!current()) return undefined;
        guard(() => {
          index = indexOf(nextPack);
        });
        return nextTask()
          .then(() => {
            if (current()) guard(() => install(nextPack, built));
            return nextTask();
          })
          .then(() => {
            if (current()) guard(() => place(built));
          });
      })
      .catch((e) => {
        if (destroyed || token !== buildToken) return;
        fail(e);
      });
  }

  // ---- start -------------------------------------------------------------------------------------

  try {
    createRenderer();
    host.attach(canvas, pinLayer, onHostViewport);
  } catch (e) {
    fail(e);
  }

  return {
    setRegion(region: { dataset_version: string | null } | null): void {
      guard(() => {
        const next = region === null ? null : region.dataset_version;
        if (next === expectedDataset) return;
        expectedDataset = next;
        if (pack !== null) applyPack(pack, packState);
      });
    },

    setPack(nextPack: CellPack | null, nextState: PackState): void {
      guard(() => applyPack(nextPack, nextState));
    },

    setInputs(next: LayerInputs): void {
      guard(() => {
        inputs = next;
        if (frames !== null) {
          if (frames.setInputs(next)) refill();
        } else if (pack !== null && mesh !== null) {
          frames = createFrameSource(pack, next);
          refill();
          updateStatus();
        }
      });
    },

    setLayer(id: MapLayerId): void {
      guard(() => {
        if (id === layerId) return;
        layerId = id;
        if (renderer !== null) renderer.setLut(buildLut(layerId, theme));
        refill();
        requestRender();
      });
    },

    setHour(nextHow: number, nextDate: string | null): void {
      guard(() => {
        if (!Number.isFinite(nextHow)) return;
        if (nextHow === how && nextDate === date) return;
        how = nextHow;
        date = nextDate;
        refill();
      });
    },

    setTheme(t: 'light' | 'dark'): void {
      guard(() => {
        if (t === theme) return;
        theme = t;
        if (renderer !== null) renderer.setLut(buildLut(layerId, theme));
        requestRender();
      });
    },

    cellAt(lat: number, lng: number): { index: number; id: string; byte: number } | null {
      if (destroyed || pack === null || index === null) return null;
      try {
        const t0 = performance.now();
        const at = pickCell(index, lat, lng, latLngToCell, pack.header.h3_res);
        picks.push(performance.now() - t0);
        if (at < 0) return null;
        return { index: at, id: pack.ids[at], byte: drawable() ? values[at] : 0 };
      } catch (e) {
        fail(e);
        return null;
      }
    },

    hourStrip(dow: number, done: (bytes: Uint8Array) => void): void {
      if (stripJob !== null) stripJob.cancelled = true;
      const job = { cancelled: false };
      stripJob = job;
      const out = new Uint8Array(24);
      const day = ((Math.floor(Number.isFinite(dow) ? dow : 0) % 7) + 7) % 7;
      let hour = 0;
      let count = -1;

      const finish = (): void => {
        if (stripJob === job) stripJob = null;
        try {
          done(out);
        } catch {
          // the page's callback is the page's
        }
      };

      const step = (deadline: IdleDeadline | null): void => {
        if (job.cancelled || destroyed) return;
        try {
          if (frames === null || mesh === null || vp === null || !drawable()) {
            finish();
            return;
          }
          if (count < 0) {
            if (stripVisible.length !== mesh.n) stripVisible = new Uint32Array(mesh.n);
            if (stripScratch.length !== mesh.n) stripScratch = new Uint8Array(mesh.n);
            count = cullCells(mesh.centers, mesh.halfSizes, mesh.n, worldRect(vp), stripVisible);
          }
          const started = performance.now();
          while (hour < 24) {
            frames.fill(layerId, day * 24 + hour, date, stripScratch);
            out[hour] = meanByte(stripScratch, stripVisible, count);
            hour++;
            // A few hours per slice: each is a pass over every cell unless its frame is kept already.
            const spent = performance.now() - started;
            if (spent > (deadline !== null ? WARM_BUDGET_MS : WARM_SLICE_MS) || (deadline !== null && deadline.timeRemaining() < WARM_SLICE_MS)) break;
          }
          if (hour < 24) whenIdle(step);
          else finish();
        } catch (e) {
          fail(e);
          finish();
        }
      };
      whenIdle(step);
    },

    onStatus(cb: (s: LayerStatus) => void): () => void {
      statusListeners.add(cb);
      try {
        cb(status);
      } catch {
        // as in updateStatus
      }
      return () => {
        statusListeners.delete(cb);
      };
    },

    stats(): LayerStats {
      let inView = 0;
      try {
        if (mesh !== null && vp !== null) inView = cullCells(mesh.centers, mesh.halfSizes, mesh.n, worldRect(vp), null);
      } catch {
        inView = 0;
      }
      return {
        status,
        renderer: renderer === null ? 'none' : renderer.kind,
        cells: mesh === null ? 0 : mesh.n,
        cellsInView: inView,
        tick: ticks.timing(),
        tickToFrame: tickFrames.timing(),
        draw: draws.timing(),
        pick: picks.timing(),
        meshMs: pack !== null ? meshTimes.get(pack)?.wall ?? null : null,
        meshWorkMs: pack !== null ? meshTimes.get(pack)?.work ?? null : null,
        meshSliceMs: pack !== null ? meshTimes.get(pack)?.slice ?? null : null,
        firstFrameMs,
        framesKept: frames === null ? 0 : frames.kept(),
      };
    },

    setPerf(on: boolean): void {
      perf = on;
    },

    destroy(): void {
      if (destroyed) return;
      destroyed = true;
      buildToken++;
      if (stripJob !== null) stripJob.cancelled = true;
      if (frameId !== 0) cancelAnimationFrame(frameId);
      if (lostTimer !== 0) window.clearTimeout(lostTimer);
      statusListeners.clear();
      try {
        renderer?.dispose();
      } catch {
        // nothing left to release
      }
      try {
        host.detach();
      } catch {
        // the base map may already be gone
      }
      canvas.remove();
      renderer = null;
      mesh = null;
      frames = null;
      index = null;
      pack = null;
    },
  };
}

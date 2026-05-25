import { create } from 'zustand';

import type { HeatmapMetric, HeatmapLevel } from '../api/heatmap';
import type { AnalogCandidate } from '../api/analogs';
export type HeatmapLevelOverride = 'auto' | HeatmapLevel;

interface MapState {
  center: { lat: number; lng: number };
  zoom: number;
  selectedAreaId: string | null;
  drawingType: 'polygon' | 'circle' | 'pin' | null;
  placePinFor: 'isochrone' | null;
  showHeatmap: boolean;
  heatmapMetric: HeatmapMetric;
  heatmapLevel: HeatmapLevelOverride;
  heatmapPaletteId: string;
  heatmapLoading: boolean;
  /** Value of the currently-hovered tract for the gradient-position marker. */
  hoveredHeatmapValue: number | null;
  hoveredHeatmapName: string | null;
  showPOIs: boolean;
  showImportedPoints: boolean;
  poiResults: any[];
  pendingIsochrone: any | null;
  mapInstance: google.maps.Map | null;
  /** Active time-machine polygon. Set by TimeMachinePanel; MapCanvas draws it. */
  timeMachine: { geometry: any; hour: number; label: string; areaSqKm: number | null; color: string } | null;
  /** When set, AppLayout renders the TimeMachinePanel slide-up. Lets any
   *  surface (selected-area card, advanced panel, toolbar) open it the same way. */
  timeMachineRequest: { lat: number; lng: number; minutes: number; color: string } | null;
  /** Left-panel filter: show only favorited areas. Toggled by the toolbar star. */
  favoritesOnly: boolean;
  /** Right-panel active tab. Promoted out of RightPanel local state so the
   *  toolbar Demographics/Businesses/Reports buttons can deep-link into a tab. */
  rightPanelTab: 'overview' | 'demographics' | 'businesses' | 'data';
  /** Analog Finder results (Advanced panel → Analogs tab). MapCanvas reads
   *  this to render numbered candidate pins; AnalogTab writes it. */
  analogResults: AnalogCandidate[] | null;
  /** Source area used for the analog query (helps MapCanvas draw a halo on
   *  the source so the user can tell results from origin). */
  analogSourceAreaId: string | null;
  /** VT21 — when the user hovers an area row in the left panel, the matching
   *  polygon on the map should bump (heavier stroke + slightly raised fill
   *  opacity) so the cross-surface relationship is obvious. */
  hoveredAreaId: string | null;
  /** Per-area visibility — when an id is in this set, the area's polygon
   *  + center pin are hidden on the map but the row stays in the list (so
   *  the user can toggle it back on). Persisted to localStorage so tab
   *  reloads don't unhide everything. */
  hiddenAreaIds: Set<string>;
  /** Snapshot of the currently-rendered heatmap features. Updated by
   *  ChoroplethLayer on each successful fetch. Used by the screenshot
   *  composite to draw the heatmap polygons on top of the static-map base
   *  so the captured PNG actually includes the heatmap the user sees. */
  heatmapFeatures: { geometry: any; value: number | null; color: string }[] | null;
  setCenter: (c: { lat: number; lng: number }) => void;
  setZoom: (z: number) => void;
  selectArea: (id: string | null) => void;
  startDrawing: (t: 'polygon' | 'circle' | 'pin' | null, mode?: 'isochrone' | null) => void;
  toggleLayer: (k: 'showHeatmap' | 'showPOIs' | 'showImportedPoints') => void;
  toggleHeatmap: () => void;
  setHeatmapMetric: (m: HeatmapMetric) => void;
  setHeatmapLevel: (l: HeatmapLevelOverride) => void;
  setHeatmapPaletteId: (id: string) => void;
  setHeatmapLoading: (b: boolean) => void;
  setHoveredHeatmap: (value: number | null, name: string | null) => void;
  setPoiResults: (places: any[]) => void;
  setPendingIsochrone: (data: any | null) => void;
  setMapInstance: (m: google.maps.Map | null) => void;
  fitBoundsToArea: (geometry: any) => void;
  setTimeMachine: (tm: MapState['timeMachine']) => void;
  openTimeMachine: (opts?: Partial<NonNullable<MapState['timeMachineRequest']>>) => void;
  closeTimeMachine: () => void;
  toggleFavoritesOnly: () => void;
  setRightPanelTab: (t: MapState['rightPanelTab']) => void;
  setAnalogResults: (results: AnalogCandidate[] | null, sourceAreaId?: string | null) => void;
  clearAnalogResults: () => void;
  setHoveredAreaId: (id: string | null) => void;
  toggleAreaVisibility: (id: string) => void;
  isAreaHidden: (id: string) => boolean;
  setHeatmapFeatures: (features: { geometry: any; value: number | null; color: string }[] | null) => void;
  /** Bumped by the Layers tab whenever a custom layer is created, updated, or
   *  deleted — CustomLayerMarkers watches this to know when to refetch. */
  customLayersVersion: number;
  bumpCustomLayers: () => void;
}

export const useMapStore = create<MapState>((set, get) => ({
  center: { lat: 39.8283, lng: -98.5795 },
  zoom: 4,
  selectedAreaId: null,
  drawingType: null,
  placePinFor: null,
  showHeatmap: false,
  heatmapMetric: 'population_density',
  heatmapLevel: 'auto',
  heatmapPaletteId: 'smappen-pastel',
  heatmapLoading: false,
  hoveredHeatmapValue: null,
  hoveredHeatmapName: null,
  showPOIs: true,
  showImportedPoints: true,
  poiResults: [],
  pendingIsochrone: null,
  mapInstance: null,
  timeMachine: null,
  timeMachineRequest: null,
  favoritesOnly: false,
  rightPanelTab: 'overview',
  analogResults: null,
  analogSourceAreaId: null,
  hoveredAreaId: null,
  hiddenAreaIds: typeof localStorage !== 'undefined' && localStorage.getItem('smappen_hidden_areas')
    ? new Set(JSON.parse(localStorage.getItem('smappen_hidden_areas') || '[]'))
    : new Set<string>(),
  heatmapFeatures: null,
  setCenter: (center) => set({ center }),
  setZoom: (zoom) => set({ zoom }),
  selectArea: (id) => set({ selectedAreaId: id }),
  startDrawing: (drawingType, placePinFor = null) => set({ drawingType, placePinFor }),
  toggleLayer: (k) => set((s) => ({ [k]: !s[k] } as any)),
  toggleHeatmap: () => set((s) => ({ showHeatmap: !s.showHeatmap })),
  setHeatmapMetric: (heatmapMetric) => set({ heatmapMetric }),
  setHeatmapLevel: (heatmapLevel) => set({ heatmapLevel }),
  setHeatmapPaletteId: (heatmapPaletteId) => set({ heatmapPaletteId }),
  setHeatmapLoading: (heatmapLoading) => set({ heatmapLoading }),
  setHoveredHeatmap: (hoveredHeatmapValue, hoveredHeatmapName) =>
    set({ hoveredHeatmapValue, hoveredHeatmapName }),
  setPoiResults: (poiResults) => set({ poiResults }),
  setPendingIsochrone: (pendingIsochrone) => set({ pendingIsochrone }),
  setMapInstance: (mapInstance) => set({ mapInstance }),
  setTimeMachine: (timeMachine) => set({ timeMachine }),
  openTimeMachine: (opts) => {
    // Default origin = current map center; default minutes = 15; default color = brand.
    const map = get().mapInstance;
    const c = map?.getCenter();
    const fallback = {
      lat: c?.lat() ?? get().center.lat,
      lng: c?.lng() ?? get().center.lng,
      minutes: 15,
      color: '#7848BB',
    };
    set({ timeMachineRequest: { ...fallback, ...(opts ?? {}) } });
  },
  closeTimeMachine: () => set({ timeMachineRequest: null, timeMachine: null }),
  toggleFavoritesOnly: () => set((s) => ({ favoritesOnly: !s.favoritesOnly })),
  setRightPanelTab: (rightPanelTab) => set({ rightPanelTab }),
  setAnalogResults: (results, sourceAreaId = null) =>
    set({ analogResults: results, analogSourceAreaId: sourceAreaId ?? null }),
  clearAnalogResults: () => set({ analogResults: null, analogSourceAreaId: null }),
  setHoveredAreaId: (hoveredAreaId) => set({ hoveredAreaId }),
  toggleAreaVisibility: (id) => set((s) => {
    const next = new Set(s.hiddenAreaIds);
    if (next.has(id)) next.delete(id); else next.add(id);
    try { localStorage.setItem('smappen_hidden_areas', JSON.stringify(Array.from(next))); } catch {}
    return { hiddenAreaIds: next };
  }),
  isAreaHidden: (id) => get().hiddenAreaIds.has(id),
  setHeatmapFeatures: (heatmapFeatures) => set({ heatmapFeatures }),
  customLayersVersion: 0,
  bumpCustomLayers: () => set((s) => ({ customLayersVersion: s.customLayersVersion + 1 })),
  fitBoundsToArea: (geometry) => {
    const map = get().mapInstance;
    if (!map || !geometry?.coordinates?.[0]) return;
    const bounds = new google.maps.LatLngBounds();
    for (const [lng, lat] of geometry.coordinates[0]) {
      bounds.extend({ lat, lng });
    }
    map.fitBounds(bounds, 60);
  },
}));

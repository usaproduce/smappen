import { create } from 'zustand';

interface MapState {
  center: { lat: number; lng: number };
  zoom: number;
  selectedAreaId: string | null;
  drawingType: 'polygon' | 'circle' | 'pin' | null;
  placePinFor: 'isochrone' | null;
  showHeatmap: boolean;
  showPOIs: boolean;
  showImportedPoints: boolean;
  poiResults: any[];
  pendingIsochrone: any | null;
  mapInstance: google.maps.Map | null;
  setCenter: (c: { lat: number; lng: number }) => void;
  setZoom: (z: number) => void;
  selectArea: (id: string | null) => void;
  startDrawing: (t: 'polygon' | 'circle' | 'pin' | null, mode?: 'isochrone' | null) => void;
  toggleLayer: (k: 'showHeatmap' | 'showPOIs' | 'showImportedPoints') => void;
  setPoiResults: (places: any[]) => void;
  setPendingIsochrone: (data: any | null) => void;
  setMapInstance: (m: google.maps.Map | null) => void;
  fitBoundsToArea: (geometry: any) => void;
}

export const useMapStore = create<MapState>((set, get) => ({
  center: { lat: 39.8283, lng: -98.5795 },
  zoom: 4,
  selectedAreaId: null,
  drawingType: null,
  placePinFor: null,
  showHeatmap: false,
  showPOIs: true,
  showImportedPoints: true,
  poiResults: [],
  pendingIsochrone: null,
  mapInstance: null,
  setCenter: (center) => set({ center }),
  setZoom: (zoom) => set({ zoom }),
  selectArea: (id) => set({ selectedAreaId: id }),
  startDrawing: (drawingType, placePinFor = null) => set({ drawingType, placePinFor }),
  toggleLayer: (k) => set((s) => ({ [k]: !s[k] } as any)),
  setPoiResults: (poiResults) => set({ poiResults }),
  setPendingIsochrone: (pendingIsochrone) => set({ pendingIsochrone }),
  setMapInstance: (mapInstance) => set({ mapInstance }),
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

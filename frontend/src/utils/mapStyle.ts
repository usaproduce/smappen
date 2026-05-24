// Smappen-style light, desaturated Google Maps style.
export const SMAPPEN_MAP_STYLE: google.maps.MapTypeStyle[] = [
  { elementType: 'geometry', stylers: [{ saturation: -20 }] },
  { elementType: 'labels.text.fill', stylers: [{ color: '#4A4A5A' }] },
  { elementType: 'labels.text.stroke', stylers: [{ color: '#FFFFFF' }, { weight: 3 }] },
  { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#C5D8E8' }] },
  { featureType: 'landscape', elementType: 'geometry', stylers: [{ color: '#F0F0F0' }] },
  { featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.park', elementType: 'geometry', stylers: [{ color: '#D5E8D0' }] },
  { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#FFFFFF' }] },
  { featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [{ color: '#D1D1DB' }] },
  { featureType: 'road.arterial', elementType: 'geometry', stylers: [{ color: '#FFFFFF' }] },
  { featureType: 'road.local', elementType: 'geometry', stylers: [{ color: '#FFFFFF' }] },
  { featureType: 'transit', stylers: [{ visibility: 'simplified' }] },
  { featureType: 'administrative', elementType: 'geometry.stroke', stylers: [{ color: '#B0B0BC' }] },
];

// Dark-mode Google Maps style — applies when the app is in dark theme.
// Dimmed land, deep-blue water, light-gray text, muted POIs. Matches the
// app shell's dark palette so the map doesn't pop as a bright rectangle
// in the middle of an otherwise dark UI.
export const SMAPPEN_MAP_STYLE_DARK: google.maps.MapTypeStyle[] = [
  { elementType: 'geometry', stylers: [{ color: '#1a1a2e' }] },
  { elementType: 'labels.text.fill', stylers: [{ color: '#94a3b8' }] },
  { elementType: 'labels.text.stroke', stylers: [{ color: '#0f172a' }, { weight: 3 }] },
  { elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
  { featureType: 'administrative', elementType: 'geometry', stylers: [{ color: '#2d2147' }] },
  { featureType: 'administrative.country', elementType: 'labels.text.fill', stylers: [{ color: '#a78bda' }] },
  { featureType: 'administrative.locality', elementType: 'labels.text.fill', stylers: [{ color: '#cbd5e1' }] },
  { featureType: 'poi', elementType: 'labels.text.fill', stylers: [{ color: '#64748b' }] },
  { featureType: 'poi.park', elementType: 'geometry', stylers: [{ color: '#1d3a2d' }] },
  { featureType: 'poi.park', elementType: 'labels.text.fill', stylers: [{ color: '#3e7c5b' }] },
  { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#2a2a3d' }] },
  { featureType: 'road', elementType: 'geometry.stroke', stylers: [{ color: '#1f1f30' }] },
  { featureType: 'road', elementType: 'labels.text.fill', stylers: [{ color: '#9ca3af' }] },
  { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#3a3a55' }] },
  { featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [{ color: '#2a2a3d' }] },
  { featureType: 'road.highway', elementType: 'labels.text.fill', stylers: [{ color: '#d1d5db' }] },
  { featureType: 'transit', elementType: 'geometry', stylers: [{ color: '#252540' }] },
  { featureType: 'transit.station', elementType: 'labels.text.fill', stylers: [{ color: '#9ca3af' }] },
  { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0c1e3a' }] },
  { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#4f7ca8' }] },
];

// Tweak #19 — "Clean" mode strips even more chrome. Drops road labels,
// suppresses POIs entirely, mutes local roads, lifts water + parks. Use
// when the user wants their polygons + markers to read clearly without
// competing with city detail.
export const SMAPPEN_MAP_STYLE_CLEAN: google.maps.MapTypeStyle[] = [
  { elementType: 'geometry', stylers: [{ saturation: -40 }, { lightness: 10 }] },
  { elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
  { elementType: 'labels.text.fill', stylers: [{ color: '#6B6B7B' }] },
  { elementType: 'labels.text.stroke', stylers: [{ color: '#FFFFFF' }, { weight: 3 }] },
  { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#D9E6F0' }] },
  { featureType: 'landscape', elementType: 'geometry', stylers: [{ color: '#F6F6F8' }] },
  { featureType: 'poi', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi.park', elementType: 'geometry', stylers: [{ color: '#DEEAD7' }, { visibility: 'on' }] },
  { featureType: 'road', elementType: 'labels', stylers: [{ visibility: 'off' }] },
  { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#FFFFFF' }] },
  { featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [{ color: '#E0E0E8' }] },
  { featureType: 'road.arterial', elementType: 'geometry', stylers: [{ color: '#FFFFFF' }] },
  { featureType: 'road.local', elementType: 'geometry', stylers: [{ color: '#FAFAFC' }] },
  { featureType: 'road.local', elementType: 'geometry.stroke', stylers: [{ visibility: 'off' }] },
  { featureType: 'transit', stylers: [{ visibility: 'off' }] },
  { featureType: 'administrative.land_parcel', stylers: [{ visibility: 'off' }] },
  { featureType: 'administrative.neighborhood', stylers: [{ visibility: 'off' }] },
];

// Mono — pure grayscale, drains all color so the user's polygons + heatmaps
// are the only chromatic elements on the canvas. Best when overlaying lots
// of analog markers or comparing many areas at once.
export const SMAPPEN_MAP_STYLE_MONO: google.maps.MapTypeStyle[] = [
  { elementType: 'geometry', stylers: [{ saturation: -100 }, { lightness: 5 }] },
  { elementType: 'labels.text.fill', stylers: [{ color: '#5A5A5A' }] },
  { elementType: 'labels.text.stroke', stylers: [{ color: '#FFFFFF' }, { weight: 3 }] },
  { elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
  { featureType: 'poi', stylers: [{ visibility: 'off' }] },
  { featureType: 'transit', stylers: [{ visibility: 'off' }] },
  { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#E8E8EC' }] },
  { featureType: 'landscape', elementType: 'geometry', stylers: [{ color: '#F4F4F5' }] },
  { featureType: 'road', elementType: 'labels', stylers: [{ visibility: 'off' }] },
];

// Satellite (basemap switch) — wraps the existing Google "satellite" map
// type; passed to the GoogleMap component as `mapTypeId` rather than via
// custom styles.

/**
 * Style preset roster. The key is the user-facing label; `id` is what
 * uiPrefsStore.mapStyle stores. Adding a new entry here automatically
 * adds it to the picker.
 */
export type MapStyleId = 'detailed' | 'clean' | 'mono' | 'dark' | 'satellite';

export const MAP_STYLE_PRESETS: Array<{
  id: MapStyleId;
  label: string;
  description: string;
  /** Google Maps style array, or null when the preset uses a different mapTypeId. */
  styles: google.maps.MapTypeStyle[] | null;
  /** Override mapTypeId. Only set for satellite. */
  mapTypeId?: 'satellite' | 'hybrid';
}> = [
  { id: 'detailed', label: 'Detailed', description: 'Roads, POIs, full city detail.', styles: SMAPPEN_MAP_STYLE },
  { id: 'clean',    label: 'Clean',    description: 'Strip labels — focus on your shapes.', styles: SMAPPEN_MAP_STYLE_CLEAN },
  { id: 'mono',     label: 'Mono',     description: 'Pure grayscale — best for overlays.', styles: SMAPPEN_MAP_STYLE_MONO },
  { id: 'dark',     label: 'Dark',     description: 'For dark mode + presentations.', styles: SMAPPEN_MAP_STYLE_DARK },
  { id: 'satellite',label: 'Satellite',description: 'Aerial imagery + hybrid labels.', styles: null, mapTypeId: 'hybrid' },
];

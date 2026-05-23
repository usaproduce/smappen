import { useMapStore } from '../../stores/mapStore';
import { HEATMAP_GRADIENT_CSS } from '../../utils/heatmapColors';

/**
 * Small 64×64 toggle at the bottom-left of the map.
 * Click → flips the heatmap on/off, matching Smappen's pattern.
 * Shows a gradient thumbnail when heatmap is off (preview of what you'd get),
 * a tiny map thumbnail when it's on (preview of going back to plain map).
 */
export default function MiniMapToggle() {
  const { showHeatmap, toggleHeatmap, mapInstance } = useMapStore();

  // Pull a small static thumbnail of the current viewport for the "back to map" state.
  function thumbnailBg(): string {
    if (!mapInstance) return '#F0F0F0';
    const c = mapInstance.getCenter();
    if (!c) return '#F0F0F0';
    const zoom = mapInstance.getZoom() ?? 10;
    const apiKey = (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '';
    if (!apiKey) return '#F0F0F0';
    const url = `https://maps.googleapis.com/maps/api/staticmap?center=${c.lat()},${c.lng()}&zoom=${zoom}&size=64x64&maptype=roadmap&key=${apiKey}`;
    return `center / cover no-repeat url("${url}")`;
  }

  return (
    <button
      onClick={toggleHeatmap}
      className="absolute bottom-4 left-4 w-16 h-16 rounded-lg shadow-float border-2 border-white overflow-hidden z-30 hover:scale-105 transition-transform"
      title={showHeatmap ? 'Hide heatmap (show plain map)' : 'Show population density heatmap'}
      style={{
        background: showHeatmap ? thumbnailBg() : HEATMAP_GRADIENT_CSS,
      }}
    >
      <span className="absolute bottom-0 left-0 right-0 text-[9px] font-bold uppercase text-white bg-black/60 py-0.5 text-center">
        {showHeatmap ? 'Map' : 'Heatmap'}
      </span>
    </button>
  );
}

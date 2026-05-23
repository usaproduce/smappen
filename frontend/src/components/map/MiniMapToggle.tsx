import { useMapStore } from '../../stores/mapStore';
import { HEATMAP_GRADIENT_CSS } from '../../utils/heatmapColors';

/**
 * Bottom-left mini-map toggle. Positioned to the right of the heatmap panel
 * when the panel is open (heatmap card is 340px wide at left-4), otherwise
 * sits at the panel's would-be position.
 */
export default function MiniMapToggle() {
  const { showHeatmap, toggleHeatmap, mapInstance } = useMapStore();

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

  // When the heatmap panel is open, slide the toggle to its right side
  // (panel width 340px + left margin 16px + gap 8px = 364px from left edge).
  const leftPosition = showHeatmap ? 'left-[364px]' : 'left-4';

  return (
    <button
      onClick={toggleHeatmap}
      className={`absolute bottom-4 ${leftPosition} w-16 h-16 rounded-xl shadow-float border-2 border-white overflow-hidden z-30 transition-all duration-300 ease-out hover:scale-105 active:scale-95`}
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

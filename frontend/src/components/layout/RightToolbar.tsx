import { Clock, MapPin, Building, BarChart3, Upload, Star, ZoomIn, ZoomOut, MessageCircle, Map as MapIcon } from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';

interface Props {
  onCreateArea: () => void;
  onImport: () => void;
}

export default function RightToolbar({ onCreateArea, onImport }: Props) {
  const { mapInstance, showHeatmap, toggleHeatmap } = useMapStore();

  function zoom(by: number) {
    if (!mapInstance) return;
    mapInstance.setZoom((mapInstance.getZoom() ?? 10) + by);
  }

  return (
    <aside className="w-12 bg-white flex flex-col items-center py-2 gap-1 border-l border-slate-200">
      <button className="toolbar-btn" title="Create isochrone area" onClick={onCreateArea}>
        <Clock size={20} />
      </button>
      <button className="toolbar-btn" title="Place pin" onClick={onCreateArea}>
        <MapPin size={20} />
      </button>
      <button
        className={`toolbar-btn ${showHeatmap ? 'active' : ''}`}
        title="Toggle population density heatmap"
        onClick={toggleHeatmap}
      >
        <MapIcon size={20} />
      </button>
      <button className="toolbar-btn" title="Demographics">
        <Building size={20} />
      </button>
      <button className="toolbar-btn" title="Analytics">
        <BarChart3 size={20} />
      </button>
      <button className="toolbar-btn" title="Import" onClick={onImport}>
        <Upload size={20} />
      </button>
      <button className="toolbar-btn" title="Saved">
        <Star size={20} />
      </button>

      <div className="flex-1" />

      <button className="toolbar-btn" title="Zoom in" onClick={() => zoom(1)}>
        <ZoomIn size={20} />
      </button>
      <button className="toolbar-btn" title="Zoom out" onClick={() => zoom(-1)}>
        <ZoomOut size={20} />
      </button>
      <button className="toolbar-btn" title="Help">
        <MessageCircle size={20} />
      </button>
    </aside>
  );
}

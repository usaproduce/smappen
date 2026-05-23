import {
  PieChart, MapPin, Building, ClipboardList, DatabaseZap, Star,
  ZoomIn, ZoomOut, MessageCircle, Map as MapIcon, Sparkles,
} from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';

interface Props {
  onCreateArea: () => void;
  onImport: () => void;
  onOpenAdvanced?: () => void;
  advancedOpen?: boolean;
}

export default function RightToolbar({ onCreateArea, onImport, onOpenAdvanced, advancedOpen }: Props) {
  const { mapInstance, showHeatmap, toggleHeatmap, selectArea } = useMapStore();

  function zoom(by: number) {
    if (!mapInstance) return;
    mapInstance.setZoom((mapInstance.getZoom() ?? 10) + by);
  }

  return (
    <aside className="absolute top-4 right-4 w-12 max-h-[calc(100%-2rem)] bg-white rounded-xl shadow-float border border-slate-200 flex flex-col items-center py-2 gap-0.5 z-20">
      <button className="toolbar-btn" title="Overview" onClick={() => selectArea(null)}>
        <PieChart size={20} />
      </button>
      <button className="toolbar-btn" title="Address / pin" onClick={onCreateArea}>
        <MapPin size={20} />
      </button>
      <button
        className={`toolbar-btn ${showHeatmap ? 'active' : ''}`}
        title="Population density heatmap"
        onClick={toggleHeatmap}
      >
        <MapIcon size={20} />
      </button>
      <button className="toolbar-btn" title="Demographics">
        <Building size={20} />
      </button>
      <button className="toolbar-btn" title="Reports">
        <ClipboardList size={20} />
      </button>
      <button className="toolbar-btn" title="Add data" onClick={onImport}>
        <DatabaseZap size={20} />
      </button>
      <button className="toolbar-btn" title="Favorites">
        <Star size={20} />
      </button>
      <button
        className={`toolbar-btn ${advancedOpen ? 'active' : ''}`}
        title="Advanced: territories, segments, competitors, field…"
        onClick={onOpenAdvanced}
      >
        <Sparkles size={20} />
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

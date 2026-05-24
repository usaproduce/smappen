import {
  PieChart, MapPin, Building, ClipboardList, DatabaseZap, Star,
  ZoomIn, ZoomOut, MessageCircle, Map as MapIcon, Sparkles, Camera,
} from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
import { HEATMAP_GRADIENT_CSS } from '../../utils/heatmapColors';
import toast from 'react-hot-toast';

interface Props {
  onCreateArea: () => void;
  onImport: () => void;
  onOpenAdvanced?: () => void;
  advancedOpen?: boolean;
  onScreenshot?: () => void;
}

export default function RightToolbar({ onCreateArea, onImport, onOpenAdvanced, advancedOpen, onScreenshot }: Props) {
  const {
    mapInstance, showHeatmap, toggleHeatmap, selectArea, favoritesOnly,
    toggleFavoritesOnly, selectedAreaId, setRightPanelTab, rightPanelTab,
  } = useMapStore();

  function zoom(by: number) {
    if (!mapInstance) return;
    mapInstance.setZoom((mapInstance.getZoom() ?? 10) + by);
  }

  // Deep-link to a right-panel tab. Requires an area selection — if no
  // area is selected, hint the user that they need to pick one first.
  function goToTab(t: 'demographics' | 'businesses' | 'data') {
    if (!selectedAreaId) {
      toast('Select an area first', { icon: '👆', position: 'top-right' });
      return;
    }
    setRightPanelTab(t);
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
        className={`toolbar-btn relative ${showHeatmap ? 'active' : ''}`}
        title={showHeatmap ? 'Hide heatmap' : 'Show population density heatmap'}
        onClick={toggleHeatmap}
      >
        <MapIcon size={20} />
        {/* Subtle rainbow gradient ring when active so users see the heatmap
            is on at a glance — replaces the old bottom-left HEATMAP preview tile. */}
        {showHeatmap && (
          <span
            className="absolute inset-0 rounded-md pointer-events-none"
            style={{ background: HEATMAP_GRADIENT_CSS, opacity: 0.2, mixBlendMode: 'multiply' }}
          />
        )}
      </button>
      <button
        className={`toolbar-btn ${selectedAreaId && rightPanelTab === 'demographics' ? 'active' : ''}`}
        title="Demographics for selected area"
        onClick={() => goToTab('demographics')}
      >
        <Building size={20} />
      </button>
      <button
        className={`toolbar-btn ${selectedAreaId && rightPanelTab === 'businesses' ? 'active' : ''}`}
        title="Businesses inside the selected area"
        onClick={() => goToTab('businesses')}
      >
        <ClipboardList size={20} />
      </button>
      <button className="toolbar-btn" title="Add data" onClick={onImport}>
        <DatabaseZap size={20} />
      </button>
      <button
        className={`toolbar-btn ${favoritesOnly ? 'active' : ''}`}
        title={favoritesOnly ? 'Show all areas' : 'Show favorites only'}
        onClick={toggleFavoritesOnly}
      >
        <Star size={20} fill={favoritesOnly ? 'currentColor' : 'none'} />
      </button>
      <button
        className={`toolbar-btn ${advancedOpen ? 'active' : ''}`}
        title="Advanced: territories, segments, competitors, field…"
        onClick={onOpenAdvanced}
      >
        <Sparkles size={20} />
      </button>
      <button className="toolbar-btn" title="Download map screenshot" onClick={onScreenshot}>
        <Camera size={20} />
      </button>

      <div className="flex-1" />

      <button className="toolbar-btn" title="Zoom in" onClick={() => zoom(1)}>
        <ZoomIn size={20} />
      </button>
      <button className="toolbar-btn" title="Zoom out" onClick={() => zoom(-1)}>
        <ZoomOut size={20} />
      </button>
      <button
        className="toolbar-btn"
        title="Keyboard shortcuts (?)"
        onClick={() => useUiPrefsStore.getState().toggleShortcutsModal()}
      >
        <MessageCircle size={20} />
      </button>
    </aside>
  );
}

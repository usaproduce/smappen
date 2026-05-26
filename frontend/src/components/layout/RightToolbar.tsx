import { useState } from 'react';
import {
  PieChart, MapPin, Building, ClipboardList, DatabaseZap, Star,
  ZoomIn, ZoomOut, MessageCircle, Map as MapIcon, Sparkles, Camera,
  ChevronUp, ChevronDown, Box,
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
  // VT23 — collapse the toolbar into the 3 most-used buttons; expand reveals
  // the rest. Persists for the session only (resets on reload).
  const [collapsed, setCollapsed] = useState(false);
  // NF5 — 3D tilt toggle. Toggles the map's tilt between 0 (flat) and 45
  // (pitched). True 3D polygon extrusion requires WebGLOverlayView which
  // is a v2 build; the pitched view alone is the most-visible part of the
  // "3D" feeling for presentations.
  const [tilted, setTilted] = useState(false);
  function toggleTilt() {
    if (!mapInstance) return;
    const next = !tilted;
    setTilted(next);
    mapInstance.setTilt(next ? 45 : 0);
  }

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

  // Tweak #20 — uses data-tooltip (not the bare title attr) so we get styled
  // pill labels via CSS pseudo-elements with a 250ms hover delay. title is
  // dropped here because the OS tooltip would race the CSS one.
  // rounded-r-xl rounded-l-none + border-l-0 so this strip merges
  // seamlessly with the RightPanel's flat right edge when an area is
  // selected. The flat-left look reads fine standalone too — it's a
  // sidebar against the right edge of the map. z-30 keeps the toolbar
  // above the panel just in case any sub-pixel rounding edges through.
  return (
    <aside className="absolute top-4 right-4 w-12 max-h-[calc(100%-2rem)] bg-white rounded-r-xl rounded-l-none shadow-float border border-l-0 border-slate-200 flex flex-col items-center py-2 gap-0.5 z-30">
      <button className="toolbar-btn" data-tooltip="Overview" onClick={() => selectArea(null)}>
        <PieChart size={20} />
      </button>
      <button className="toolbar-btn" data-tooltip="Address / pin" onClick={onCreateArea}>
        <MapPin size={20} />
      </button>
      {/* VT23 — collapse/expand. Always shows: Overview, Pin, Heatmap, Collapse.
          Other buttons hide behind the collapse so the toolbar can drop from
          ~12 buttons down to 3 for users who want max map real estate. */}
      <button
        className="toolbar-btn"
        data-tooltip={collapsed ? 'Show more tools' : 'Hide tools'}
        onClick={() => setCollapsed((v) => !v)}
      >
        {collapsed ? <ChevronDown size={20} /> : <ChevronUp size={20} />}
      </button>
      <button
        className={`toolbar-btn ${showHeatmap ? 'active' : ''}`}
        data-tooltip={showHeatmap ? 'Hide heatmap' : 'Population density'}
        onClick={toggleHeatmap}
      >
        <MapIcon size={20} />
        {/* Subtle rainbow gradient overlay when active so users see the heatmap
            is on at a glance — replaces the old bottom-left HEATMAP preview tile. */}
        {showHeatmap && (
          <span
            className="absolute inset-0 rounded-md pointer-events-none"
            style={{ background: HEATMAP_GRADIENT_CSS, opacity: 0.2, mixBlendMode: 'multiply' }}
          />
        )}
      </button>
      {!collapsed && (
        <>
          <button
            className={`toolbar-btn ${selectedAreaId && rightPanelTab === 'demographics' ? 'active' : ''}`}
            data-tooltip="Demographics"
            onClick={() => goToTab('demographics')}
          >
            <Building size={20} />
          </button>
          <button
            className={`toolbar-btn ${selectedAreaId && rightPanelTab === 'businesses' ? 'active' : ''}`}
            data-tooltip="Businesses"
            onClick={() => goToTab('businesses')}
          >
            <ClipboardList size={20} />
          </button>
          <button className="toolbar-btn" data-tooltip="Add data" onClick={onImport}>
            <DatabaseZap size={20} />
          </button>
          <button
            className={`toolbar-btn ${favoritesOnly ? 'active' : ''}`}
            data-tooltip={favoritesOnly ? 'Show all areas' : 'Favorites only'}
            onClick={toggleFavoritesOnly}
          >
            <Star size={20} fill={favoritesOnly ? 'currentColor' : 'none'} />
          </button>
          <button
            className={`toolbar-btn ${advancedOpen ? 'active' : ''}`}
            data-tooltip="Advanced tools"
            onClick={onOpenAdvanced}
          >
            <Sparkles size={20} />
          </button>
          <button className="toolbar-btn" data-tooltip="Screenshot map" onClick={onScreenshot}>
            <Camera size={20} />
          </button>
          {/* NF5 — 3D tilt */}
          <button
            className={`toolbar-btn ${tilted ? 'active' : ''}`}
            data-tooltip={tilted ? '2D flat view' : '3D tilt view'}
            onClick={toggleTilt}
          >
            <Box size={20} />
          </button>
        </>
      )}

      <div className="flex-1" />

      <button className="toolbar-btn" data-tooltip="Zoom in" onClick={() => zoom(1)}>
        <ZoomIn size={20} />
      </button>
      <button className="toolbar-btn" data-tooltip="Zoom out" onClick={() => zoom(-1)}>
        <ZoomOut size={20} />
      </button>
      <button
        className="toolbar-btn"
        data-tooltip="Shortcuts (?)"
        onClick={() => useUiPrefsStore.getState().toggleShortcutsModal()}
      >
        <MessageCircle size={20} />
      </button>
    </aside>
  );
}

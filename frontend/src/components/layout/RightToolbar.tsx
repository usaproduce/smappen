import { useEffect, useRef, useState } from 'react';
import {
  PieChart, MapPin, Building, ClipboardList, DatabaseZap, Star,
  ZoomIn, ZoomOut, MessageCircle, Map as MapIcon, Sparkles, Camera,
  ChevronUp, ChevronDown, Box, RectangleHorizontal, Square, RectangleVertical, Monitor,
  Clipboard, Download as DownloadIcon, Type as TypeIcon, FileImage,
} from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
import { HEATMAP_GRADIENT_CSS } from '../../utils/heatmapColors';
import toast from 'react-hot-toast';

export interface ScreenshotMenuOpts {
  target?: 'download' | 'clipboard';
  /** "viewport" matches the live map's aspect ratio + pose (WYSIWYG, the
   *  sensible default). The named ratios are fixed-shape presets for when
   *  the user explicitly wants square, portrait, or 16:9 landscape. */
  aspect?: 'viewport' | 'landscape' | 'square' | 'portrait';
  format?: 'png' | 'jpeg';
  caption?: string;
  promptCaption?: boolean;
}

interface Props {
  onCreateArea: () => void;
  onImport: () => void;
  onOpenAdvanced?: () => void;
  advancedOpen?: boolean;
  onScreenshot?: (opts?: ScreenshotMenuOpts) => void;
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
  return (
    <aside className="absolute top-4 right-4 w-12 max-h-[calc(100%-2rem)] bg-white rounded-xl shadow-float border border-slate-200 flex flex-col items-center py-2 gap-0.5 z-30">
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
          {/* Quick-click → download landscape PNG. Modifiers:
                Shift  → copy PNG to clipboard
                Alt    → prompt for a caption first, then download
                Right-click → open format/aspect popover for full control
              The popover is the discoverable affordance; modifiers are the
              power-user shortcut. */}
          <ScreenshotButton onScreenshot={onScreenshot} />
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

/**
 * Camera button with built-in popover menu. The popover is the discoverable
 * face of the format/aspect/clipboard/caption options that the keyboard
 * modifiers cover for power users. Anchored to the camera button, closes on
 * Esc + click-outside.
 */
function ScreenshotButton({ onScreenshot }: { onScreenshot?: (opts?: ScreenshotMenuOpts) => void }) {
  const [menuOpen, setMenuOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  // Default to WYSIWYG "viewport" — match the live map's framing exactly.
  // The named presets stay available for explicit aspect choices.
  const [aspect, setAspect] = useState<'viewport' | 'landscape' | 'square' | 'portrait'>('viewport');
  const [format, setFormat] = useState<'png' | 'jpeg'>('png');

  // Click-outside + Esc to close.
  useEffect(() => {
    if (!menuOpen) return;
    function onDown(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setMenuOpen(false);
    }
    function onKey(e: KeyboardEvent) { if (e.key === 'Escape') setMenuOpen(false); }
    document.addEventListener('mousedown', onDown);
    window.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDown); window.removeEventListener('keydown', onKey); };
  }, [menuOpen]);

  function handleQuickClick(e: React.MouseEvent) {
    // Modifier shortcuts: Shift=clipboard, Alt=caption prompt.
    if (e.shiftKey) {
      onScreenshot?.({ target: 'clipboard', aspect, format });
    } else if (e.altKey) {
      onScreenshot?.({ target: 'download', aspect, format, promptCaption: true });
    } else {
      onScreenshot?.({ target: 'download', aspect, format });
    }
  }

  return (
    <div ref={ref} className="relative w-full flex flex-col items-center">
      <button
        className="toolbar-btn"
        data-tooltip="Screenshot (Shift=copy, Alt=caption, ▸=menu)"
        onClick={handleQuickClick}
        onContextMenu={(e) => { e.preventDefault(); setMenuOpen((v) => !v); }}
      >
        <Camera size={20} />
      </button>
      {/* Tiny chevron under the camera so the popover is discoverable
          even for users who never right-click. Sits inside the same column
          width so the toolbar doesn't bloat. */}
      <button
        type="button"
        aria-label="Screenshot options"
        className="text-slate-400 hover:text-slate-700 -mt-1 mb-0.5"
        onClick={() => setMenuOpen((v) => !v)}
        style={{ lineHeight: 0 }}
      >
        <ChevronDown size={10} />
      </button>

      {menuOpen && (
        <div
          className="absolute right-full mr-2 top-0 w-60 bg-white rounded-xl shadow-float border border-slate-200 p-2 z-40"
          style={{ color: '#1A1A2E' }}
        >
          {/* "Match map" is the WYSIWYG default — captures the live map's
              exact framing (no auto-fit, no recenter). The fixed ratios
              stay available for explicit social/deck shapes. */}
          <div className="text-[10px] uppercase font-bold tracking-wider text-slate-500 px-1 mb-1">Aspect</div>
          <div className="grid grid-cols-4 gap-1 mb-2">
            <AspectPick label="Match"  icon={<Monitor size={14} />}             active={aspect === 'viewport'}  onClick={() => setAspect('viewport')} />
            <AspectPick label="16:9"   icon={<RectangleHorizontal size={14} />} active={aspect === 'landscape'} onClick={() => setAspect('landscape')} />
            <AspectPick label="1:1"    icon={<Square size={14} />}              active={aspect === 'square'}    onClick={() => setAspect('square')} />
            <AspectPick label="9:16"   icon={<RectangleVertical size={14} />}   active={aspect === 'portrait'}  onClick={() => setAspect('portrait')} />
          </div>

          <div className="text-[10px] uppercase font-bold tracking-wider text-slate-500 px-1 mb-1">Format</div>
          <div className="grid grid-cols-2 gap-1 mb-2">
            <FormatPick label="PNG" sub="lossless" active={format === 'png'}  onClick={() => setFormat('png')} />
            <FormatPick label="JPEG" sub="smaller"  active={format === 'jpeg'} onClick={() => setFormat('jpeg')} />
          </div>

          <div className="h-px bg-slate-100 my-2" />

          <MenuRow
            icon={<DownloadIcon size={14} />}
            label={`Download ${format.toUpperCase()}`}
            onClick={() => { setMenuOpen(false); onScreenshot?.({ target: 'download', aspect, format }); }}
          />
          <MenuRow
            icon={<Clipboard size={14} />}
            label="Copy to clipboard"
            hint="PNG"
            onClick={() => { setMenuOpen(false); onScreenshot?.({ target: 'clipboard', aspect, format: 'png' }); }}
          />
          <MenuRow
            icon={<TypeIcon size={14} />}
            label="Add caption…"
            onClick={() => { setMenuOpen(false); onScreenshot?.({ target: 'download', aspect, format, promptCaption: true }); }}
          />
          <div className="mt-2 px-1 text-[10px] text-slate-400 inline-flex items-center gap-1">
            <FileImage size={10} /> Captures everything visible on the map.
          </div>
        </div>
      )}
    </div>
  );
}

function AspectPick({
  label, icon, active, onClick,
}: { label: string; icon: React.ReactNode; active: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex flex-col items-center justify-center gap-0.5 rounded-md py-1.5 text-[10px] font-semibold border transition-colors ${
        active
          ? 'bg-violet-50 border-violet-300 text-violet-700'
          : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
      }`}
    >
      {icon}
      <span>{label}</span>
    </button>
  );
}

function FormatPick({
  label, sub, active, onClick,
}: { label: string; sub: string; active: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex items-baseline justify-between gap-1 rounded-md px-2 py-1.5 text-xs font-bold border transition-colors ${
        active
          ? 'bg-violet-50 border-violet-300 text-violet-700'
          : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
      }`}
    >
      <span>{label}</span>
      <span className="text-[9px] font-medium text-slate-400">{sub}</span>
    </button>
  );
}

function MenuRow({
  icon, label, hint, onClick,
}: { icon: React.ReactNode; label: string; hint?: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-xs font-semibold text-slate-700 hover:bg-slate-50"
    >
      <span className="text-slate-500">{icon}</span>
      <span className="flex-1 text-left">{label}</span>
      {hint && <span className="text-[10px] text-slate-400">{hint}</span>}
    </button>
  );
}

import { useEffect, useRef, useState } from 'react';
import {
  Trash2, MoreHorizontal, Car, Bike, Footprints, Circle, Edit3,
  Copy, FolderInput, Crosshair, Eye, Palette,
} from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import { areasApi } from '../../api/areas';
import { AREA_PALETTE } from '../../utils/colors';
import type { Area } from '../../types';
import toast from 'react-hot-toast';

const modeIcon: Record<string, any> = {
  'driving-car': Car,
  'cycling-regular': Bike,
  'foot-walking': Footprints,
};

export default function AreaCard({ area }: { area: Area }) {
  const { selectedAreaId, selectArea, fitBoundsToArea } = useMapStore();
  const { removeArea, addArea, updateArea } = useProjectStore();
  const [menuOpen, setMenuOpen] = useState(false);
  const [renaming, setRenaming] = useState(false);
  const [renameVal, setRenameVal] = useState(area.name);
  const [colorPickerOpen, setColorPickerOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  const isSelected = area.id === selectedAreaId;
  const ModeIcon = modeIcon[area.travel_mode ?? ''] ?? Circle;
  const isIsochrone = area.area_type === 'isochrone' || area.area_type === 'isodistance';
  const pop = (area as any).demographics_cache?.population?.total as number | undefined;

  // Click outside closes the menu
  useEffect(() => {
    if (!menuOpen && !colorPickerOpen) return;
    function onClick(e: MouseEvent) {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setMenuOpen(false);
        setColorPickerOpen(false);
      }
    }
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [menuOpen, colorPickerOpen]);

  async function onDelete() {
    if (!confirm(`Delete "${area.name}"?`)) return;
    try {
      await areasApi.delete(area.id);
      removeArea(area.id);
      toast.success('Deleted');
    } catch {
      toast.error('Delete failed');
    }
  }

  async function onRename() {
    if (renameVal === area.name || !renameVal.trim()) { setRenaming(false); return; }
    try {
      const updated = await areasApi.update(area.id, { name: renameVal.trim() });
      updateArea({ ...area, ...updated });
      toast.success('Renamed');
    } catch { toast.error('Rename failed'); }
    setRenaming(false);
  }

  async function onColor(c: string) {
    setColorPickerOpen(false);
    setMenuOpen(false);
    try {
      const updated = await areasApi.update(area.id, { fill_color: c, stroke_color: c });
      updateArea({ ...area, ...updated, geometry: area.geometry });
    } catch { toast.error('Color change failed'); }
  }

  async function onDuplicate() {
    setMenuOpen(false);
    try {
      const dup = await areasApi.create(area.project_id, {
        name: area.name + ' (copy)',
        area_type: area.area_type,
        center_lat: area.center_lat,
        center_lng: area.center_lng,
        center_address: area.center_address,
        travel_mode: area.travel_mode,
        travel_time_minutes: area.travel_time_minutes,
        travel_distance_km: area.travel_distance_km,
        fill_color: area.fill_color,
        stroke_color: area.stroke_color,
        geometry: area.geometry,
      } as any);
      addArea({ ...dup, geometry: area.geometry } as any);
      toast.success('Duplicated');
    } catch { toast.error('Duplicate failed'); }
  }

  function onZoomTo() {
    setMenuOpen(false);
    selectArea(area.id);
    if (area.geometry) fitBoundsToArea(area.geometry);
  }

  return (
    <div
      className={`area-row group relative ${isSelected ? 'selected' : ''}`}
      onClick={() => {
        if (renaming) return;
        selectArea(area.id);
        if (area.geometry) fitBoundsToArea(area.geometry);
      }}
    >
      <span className="area-color-dot" style={{ background: area.fill_color || '#7848BB' }} />
      {isIsochrone ? (
        <span className="area-meta">
          <ModeIcon size={14} />
          {area.travel_time_minutes ? `${area.travel_time_minutes} min` : ''}
        </span>
      ) : (
        <Circle size={14} style={{ color: area.fill_color }} />
      )}

      {renaming ? (
        <input
          autoFocus
          className="flex-1 min-w-0 text-sm px-1 py-0.5 border border-violet-400 rounded outline-none"
          value={renameVal}
          onChange={(e) => setRenameVal(e.target.value)}
          onClick={(e) => e.stopPropagation()}
          onKeyDown={(e) => {
            if (e.key === 'Enter') onRename();
            if (e.key === 'Escape') { setRenaming(false); setRenameVal(area.name); }
          }}
          onBlur={onRename}
        />
      ) : (
        <span className="area-name">{area.name}</span>
      )}

      {pop != null && !renaming && (
        <span className="text-[10px] text-emerald-700 bg-emerald-50 px-1 py-0.5 rounded font-semibold whitespace-nowrap">
          {pop.toLocaleString()}
        </span>
      )}

      <div ref={menuRef} className="relative" onClick={(e) => e.stopPropagation()}>
        <button
          className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-100 opacity-0 group-hover:opacity-100 transition-opacity"
          onClick={() => { setMenuOpen(!menuOpen); setColorPickerOpen(false); }}
          title="Actions"
        >
          <MoreHorizontal size={14} />
        </button>
        {menuOpen && (
          <div className="absolute right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg z-20 min-w-[180px] py-1">
            <button className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 flex items-center gap-2" onClick={() => { setMenuOpen(false); setRenaming(true); }}>
              <Edit3 size={13} /> Rename
            </button>
            <button className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 flex items-center gap-2" onClick={() => setColorPickerOpen(!colorPickerOpen)}>
              <Palette size={13} /> Change color
            </button>
            {colorPickerOpen && (
              <div className="px-3 py-2 border-t border-slate-100 flex flex-wrap gap-1.5">
                {AREA_PALETTE.map((c) => (
                  <button
                    key={c}
                    className={`w-5 h-5 rounded-full ${area.fill_color === c ? 'ring-2 ring-slate-700 ring-offset-1' : ''}`}
                    style={{ background: c }}
                    onClick={() => onColor(c)}
                  />
                ))}
              </div>
            )}
            <button className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 flex items-center gap-2" onClick={onZoomTo}>
              <Crosshair size={13} /> Zoom to area
            </button>
            <button className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 flex items-center gap-2" onClick={onDuplicate}>
              <Copy size={13} /> Duplicate
            </button>
            <button className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 flex items-center gap-2" onClick={() => { setMenuOpen(false); selectArea(area.id); }}>
              <Eye size={13} /> View details
            </button>
            <button className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 flex items-center gap-2 text-slate-400 cursor-not-allowed" disabled title="Coming soon">
              <FolderInput size={13} /> Move to folder
            </button>
            <button className="w-full text-left px-3 py-1.5 text-sm hover:bg-red-50 text-red-600 border-t border-slate-100 flex items-center gap-2" onClick={onDelete}>
              <Trash2 size={13} /> Delete
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

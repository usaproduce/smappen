import { useEffect, useRef, useState } from 'react';
import {
  Trash2, MoreHorizontal, Car, Bike, Footprints, Circle, Edit3,
  Copy, FolderInput, Crosshair, Eye, Palette, Star,
} from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import { useUndoStore } from '../../stores/undoStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
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
  // Demographics shape has drifted across endpoints — try both the nested
  // {population:{total:N}} and the flat {population:N} shapes that different
  // services use, so the area card stat doesn't go blank arbitrarily.
  const dc: any = (area as any).demographics_cache ?? {};
  const pop = typeof dc.population?.total === 'number' ? dc.population.total
    : typeof dc.population === 'number' ? dc.population
    : undefined;

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
    // Capture enough state to recreate the area on undo. Cheaper than a real
    // soft-delete since this only lives in the user's session and the server
    // can rebuild geometry from the snapshot.
    const snapshot = { ...area };
    try {
      await areasApi.delete(area.id);
      removeArea(area.id);
      toast.success('Deleted · ⌘Z to undo');
      useUndoStore.getState().do({
        label: `Delete ${area.name}`,
        async reverse() {
          // Recreate via the create endpoint; the server returns a fresh id.
          const restored = await areasApi.create(area.project_id, {
            name: snapshot.name,
            area_type: snapshot.area_type,
            geometry: snapshot.geometry,
            fill_color: snapshot.fill_color,
            stroke_color: snapshot.stroke_color,
            fill_opacity: snapshot.fill_opacity,
            stroke_weight: snapshot.stroke_weight,
            center_lat: snapshot.center_lat,
            center_lng: snapshot.center_lng,
            center_address: snapshot.center_address,
            travel_mode: snapshot.travel_mode,
            travel_time_minutes: snapshot.travel_time_minutes,
            travel_distance_km: snapshot.travel_distance_km,
            notes: snapshot.notes,
          } as any);
          addArea(restored);
          toast.success('Restored');
        },
        async forward() {
          // The first delete already ran; redo just re-deletes the (now possibly
          // re-created) area. We don't know the new id, so this is best-effort.
          try {
            await areasApi.delete(area.id);
            removeArea(area.id);
          } catch {}
        },
      });
    } catch {
      toast.error('Delete failed');
    }
  }

  async function onRename() {
    if (renameVal === area.name || !renameVal.trim()) { setRenaming(false); return; }
    const previousName = area.name;
    const nextName = renameVal.trim();
    try {
      const updated = await areasApi.update(area.id, { name: nextName });
      updateArea({ ...area, ...updated });
      toast.success('Renamed · ⌘Z to undo');
      useUndoStore.getState().do({
        label: `Rename to ${nextName}`,
        async reverse() {
          const undone = await areasApi.update(area.id, { name: previousName });
          updateArea({ ...area, ...undone });
        },
        async forward() {
          const redone = await areasApi.update(area.id, { name: nextName });
          updateArea({ ...area, ...redone });
        },
      });
    } catch { toast.error('Rename failed'); }
    setRenaming(false);
  }

  async function onColor(c: string) {
    setColorPickerOpen(false);
    setMenuOpen(false);
    try {
      const updated = await areasApi.update(area.id, { fill_color: c, stroke_color: c });
      updateArea({ ...area, ...updated, geometry: area.geometry });
      // Track recent picks so the dropdown's "Recent" row shows the user's
      // actual recent choices, not just the brand palette.
      useUiPrefsStore.getState().pushRecentColor(c);
    } catch { toast.error('Color change failed'); }
  }

  async function onDuplicate(offsetKm = 0, bearingDeg = 90) {
    setMenuOpen(false);
    try {
      // Optional offset: shift center by (offsetKm, bearingDeg) so users can
      // duplicate "5 miles east" to systematically survey a corridor. Offset
      // is approximate equirectangular — accurate enough for 0-50km shifts
      // without bringing in turf.
      let lat = area.center_lat as number | null;
      let lng = area.center_lng as number | null;
      let geom = area.geometry;
      if (offsetKm > 0 && lat != null && lng != null) {
        const rad = (bearingDeg * Math.PI) / 180;
        const dLat = (offsetKm * Math.cos(rad)) / 111.0;
        const dLng = (offsetKm * Math.sin(rad)) / (111.0 * Math.max(0.1, Math.cos((lat * Math.PI) / 180)));
        lat += dLat;
        lng += dLng;
        // Shift the polygon ring by the same delta so the duplicate appears
        // correctly placed before the user recomputes its isochrone.
        if (geom?.coordinates?.[0]) {
          geom = {
            ...geom,
            coordinates: [geom.coordinates[0].map(([x, y]: number[]) => [x + dLng, y + dLat])],
          };
        }
      }
      const suffix = offsetKm > 0 ? ` (+${offsetKm}km ${compassFromBearing(bearingDeg)})` : ' (copy)';
      const dup = await areasApi.create(area.project_id, {
        name: area.name + suffix,
        area_type: area.area_type,
        center_lat: lat,
        center_lng: lng,
        center_address: offsetKm > 0 ? null : area.center_address,
        travel_mode: area.travel_mode,
        travel_time_minutes: area.travel_time_minutes,
        travel_distance_km: area.travel_distance_km,
        fill_color: area.fill_color,
        stroke_color: area.stroke_color,
        geometry: geom,
      } as any);
      addArea({ ...dup, geometry: geom } as any);
      toast.success('Duplicated');
    } catch { toast.error('Duplicate failed'); }
  }

  function onZoomTo() {
    setMenuOpen(false);
    selectArea(area.id);
    if (area.geometry) fitBoundsToArea(area.geometry);
  }

  async function onToggleFavorite(e: React.MouseEvent) {
    e.stopPropagation();
    const next = !(area as any).is_favorite;
    try {
      const updated = await areasApi.update(area.id, { is_favorite: next } as any);
      updateArea({ ...area, ...updated });
    } catch { toast.error('Failed'); }
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

      <button
        onClick={onToggleFavorite}
        className={`p-1 rounded hover:bg-slate-100 transition ${
          (area as any).is_favorite
            ? 'text-amber-500'
            : 'text-slate-300 opacity-0 group-hover:opacity-100'
        }`}
        title={(area as any).is_favorite ? 'Unfavorite' : 'Mark as favorite'}
      >
        <Star size={13} fill={(area as any).is_favorite ? 'currentColor' : 'none'} />
      </button>
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
              <ColorSwatches current={area.fill_color} onPick={onColor} />
            )}
            <button className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 flex items-center gap-2" onClick={onZoomTo}>
              <Crosshair size={13} /> Zoom to area
            </button>
            <button className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 flex items-center gap-2" onClick={() => onDuplicate()}>
              <Copy size={13} /> Duplicate
            </button>
            <button
              className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 flex items-center gap-2"
              onClick={() => {
                const km = parseFloat(prompt('Offset distance (km):', '5') ?? '0');
                if (!km || km <= 0) return;
                const deg = parseFloat(prompt('Bearing (0=N, 90=E, 180=S, 270=W):', '90') ?? '90');
                onDuplicate(km, deg);
              }}
              title="Duplicate this area shifted by N km in a given compass direction"
            >
              <Copy size={13} /> Duplicate with offset…
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

/**
 * Two-row color picker: "Recent" (last 5 colors the user picked, MRU first),
 * and "Brand" (the canonical Smappen palette). Rendered inline inside the
 * AreaCard menu so users never deal with hex codes.
 */
/** Convert a 0-360° bearing into a short compass label for the area name. */
function compassFromBearing(deg: number): string {
  const dirs = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
  return dirs[Math.floor(((deg + 22.5) % 360) / 45)];
}

function ColorSwatches({ current, onPick }: { current?: string | null; onPick: (c: string) => void }) {
  const recents = useUiPrefsStore((s) => s.recentColors);
  const Row = ({ label, colors }: { label: string; colors: string[] }) => (
    <div className="mb-1.5 last:mb-0">
      <div className="text-[9px] uppercase tracking-wider text-slate-400 font-bold mb-1">{label}</div>
      <div className="flex flex-wrap gap-1.5">
        {colors.map((c) => (
          <button
            key={c}
            className={`w-5 h-5 rounded-full ${current?.toLowerCase() === c.toLowerCase() ? 'ring-2 ring-slate-700 ring-offset-1' : ''}`}
            style={{ background: c }}
            onClick={() => onPick(c)}
            title={c}
          />
        ))}
      </div>
    </div>
  );
  return (
    <div className="px-3 py-2 border-t border-slate-100">
      {recents.length > 0 && <Row label="Recent" colors={recents} />}
      <Row label="Brand" colors={AREA_PALETTE} />
    </div>
  );
}

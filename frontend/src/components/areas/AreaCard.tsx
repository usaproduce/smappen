import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  Trash2, MoreHorizontal, Car, Bike, Footprints, Circle, Edit3,
  Copy, FolderInput, Crosshair, Eye, EyeOff, Palette, Star, Sparkles,
} from 'lucide-react';
import { useMapStore } from '../../stores/mapStore';
import { useProjectStore } from '../../stores/projectStore';
import { useUndoStore } from '../../stores/undoStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
import { areasApi } from '../../api/areas';
import { AREA_PALETTE } from '../../utils/colors';
import { allOuterRings, polygonBounds } from '../../utils/geo';
import type { Area } from '../../types';
import toast from 'react-hot-toast';

/**
 * Tweak #7 — tiny inline SVG thumbnail of the area's polygon shape, fitted
 * to a small box. No Google static-map call required → no API cost, instant
 * render. When there's no geometry (radius areas with only center+km), we
 * draw a circle instead.
 */
function AreaThumbnail({ area, size = 28 }: { area: Area; size?: number }) {
  const fill = area.fill_color || '#7848BB';
  const path = useMemo(() => {
    const g: any = area.geometry;
    if (!g) return null;
    const rings = allOuterRings(g);
    if (rings.length === 0) return null;

    // BF3 — antimeridian + Alaska/Hawaii fix. polygonBounds() returns the raw
    // [-180,180] bounds, which span the entire world for any polygon that
    // crosses the dateline (Aleutian Islands, Far East Russia). Detect: if
    // bbox width > 180°, shift negative longitudes by +360 so the polygon
    // becomes contiguous in the projected space.
    let coords = rings.map((r) => r.map((p) => [...p] as [number, number]));
    const raw = polygonBounds(g);
    if (raw.maxLng - raw.minLng > 180) {
      coords = coords.map((r) => r.map(([lng, lat]) => [lng < 0 ? lng + 360 : lng, lat] as [number, number]));
    }
    // Recompute bbox from the (possibly shifted) coords.
    let minLat = Infinity, minLng = Infinity, maxLat = -Infinity, maxLng = -Infinity;
    for (const ring of coords) for (const [lng, lat] of ring) {
      if (lat < minLat) minLat = lat; if (lat > maxLat) maxLat = lat;
      if (lng < minLng) minLng = lng; if (lng > maxLng) maxLng = lng;
    }

    const W = maxLng - minLng || 1;
    const H = maxLat - minLat || 1;
    const pad = 1.5;
    const inner = size - pad * 2;
    // Compensate for longitude pinch at high latitudes — Alaska polygons at
    // 65°N stretch east-west visually if we don't multiply the lng axis by
    // cos(centerLat). Fine to skip for low-latitude (most US) shapes.
    const centerLat = (minLat + maxLat) / 2;
    const lngScale = Math.cos((centerLat * Math.PI) / 180);
    const Weff = W * lngScale || W;
    const sx = inner / Weff;
    const sy = inner / H;
    const s = Math.min(sx, sy);
    const offsetX = (size - Weff * s) / 2;
    const offsetY = (size - H * s) / 2;
    const project = (lng: number, lat: number) => {
      const x = (lng - minLng) * lngScale * s + offsetX;
      const y = size - ((lat - minLat) * s + offsetY);
      return [x.toFixed(2), y.toFixed(2)] as const;
    };
    return coords
      .map((ring) => {
        if (!ring.length) return '';
        let d = '';
        for (let i = 0; i < ring.length; i++) {
          const [lng, lat] = ring[i];
          const [x, y] = project(lng, lat);
          d += (i === 0 ? 'M' : 'L') + x + ',' + y + ' ';
        }
        return d + 'Z';
      })
      .join(' ');
  }, [area.geometry, size]);

  if (!path) {
    // No geometry — show a filled dot as fallback (radius areas).
    return (
      <span
        className="shrink-0 inline-block rounded-full border border-black/10"
        style={{ width: size * 0.45, height: size * 0.45, background: fill }}
      />
    );
  }
  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="shrink-0 rounded border border-slate-200 bg-slate-50">
      <path d={path} fill={fill} fillOpacity={0.35} stroke={fill} strokeWidth={1.2} strokeLinejoin="round" />
    </svg>
  );
}

const modeIcon: Record<string, any> = {
  'driving-car': Car,
  'cycling-regular': Bike,
  'foot-walking': Footprints,
};

export default function AreaCard({ area }: { area: Area }) {
  const { selectedAreaId, selectArea, fitBoundsToArea, hiddenAreaIds, toggleAreaVisibility } = useMapStore();
  const isHidden = hiddenAreaIds.has(area.id);
  const { removeArea, addArea, updateArea } = useProjectStore();
  const [menuOpen, setMenuOpen] = useState(false);
  const [renaming, setRenaming] = useState(false);
  const [renameVal, setRenameVal] = useState(area.name);
  const [colorPickerOpen, setColorPickerOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const menuButtonRef = useRef<HTMLButtonElement>(null);

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

  // Outside-click handling lives inside PortalMenu (since the menu is rendered
  // into document.body, this component's local menuRef can't see the portal
  // content — every menu item click would look "outside" and prematurely
  // close the menu BEFORE the item's own onClick could fire. That's why
  // Delete + every other menu action quietly stopped working).

  async function onDelete() {
    setMenuOpen(false); // close the menu before the blocking confirm() so the
                        // portal doesn't sit visually orphaned during the dialog
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

  async function onRebuildBoundary() {
    setMenuOpen(false);
    const t = toast.loading('Dissolving tracts into a clean boundary…');
    try {
      await areasApi.rebuildBoundary(area.id);
      // Refetch the area to pick up the new geometry.
      const refreshed = await areasApi.findById(area.id);
      updateArea({ ...area, ...refreshed });
      if (refreshed.geometry) fitBoundsToArea(refreshed.geometry);
      toast.success('Boundary rebuilt', { id: t });
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Rebuild failed', { id: t });
    }
  }

  // Identify auto-generated territories so we can offer them the rebuild
  // action — the convex-hull shape from generation often looks knife-like
  // when source tracts aren't contiguous; rebuildBoundary computes a real
  // ST_Union over the source tracts for a clean perimeter.
  const isTerritory = !!(area as any).generation_job_id
    || !!(area as any).demographics_cache?.tract_geoids?.length;

  return (
    <div
      className={`area-row group relative ${isSelected ? 'selected' : ''} ${isHidden ? 'opacity-60' : ''}`}
      onClick={() => {
        if (renaming) return;
        selectArea(area.id);
        if (area.geometry) fitBoundsToArea(area.geometry);
      }}
      // VT21 — bump the polygon on the map when the row is hovered, so
      // the user can scan the list and visually trace each row to its
      // shape on the map.
      onMouseEnter={() => useMapStore.getState().setHoveredAreaId(area.id)}
      onMouseLeave={() => useMapStore.getState().setHoveredAreaId(null)}
    >
      {/* Tweak #7 — thumbnail of the area's actual polygon shape, much more
          recognizable than a flat color dot once you have 30+ areas. */}
      <AreaThumbnail area={area} size={28} />
      {isIsochrone && (
        <span className="area-meta">
          <ModeIcon size={14} />
          {area.travel_time_minutes ? `${area.travel_time_minutes} min` : ''}
        </span>
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
        // VT10 — double-click name to rename without opening the menu.
        // stopPropagation so the outer row's single-click select doesn't
        // also fire and steal focus.
        <span
          className="area-name"
          onDoubleClick={(e) => { e.stopPropagation(); setRenameVal(area.name); setRenaming(true); }}
          title="Double-click to rename"
        >
          {area.name}
        </span>
      )}

      {pop != null && !renaming && (
        <span className="text-[10px] text-emerald-700 bg-emerald-50 px-1 py-0.5 rounded font-semibold whitespace-nowrap">
          {pop.toLocaleString()}
        </span>
      )}

      <button
        onClick={(e) => { e.stopPropagation(); toggleAreaVisibility(area.id); }}
        className={`p-1 rounded hover:bg-slate-100 transition ${
          isHidden ? 'text-slate-400' : 'text-slate-500 hover:text-violet-700'
        }`}
        title={isHidden ? 'Show on map' : 'Hide on map'}
      >
        {isHidden ? <EyeOff size={14} /> : <Eye size={14} />}
      </button>
      <button
        onClick={onToggleFavorite}
        className={`p-1 rounded hover:bg-slate-100 transition ${
          (area as any).is_favorite ? 'text-amber-500' : 'text-slate-300 hover:text-amber-500'
        }`}
        title={(area as any).is_favorite ? 'Unfavorite' : 'Mark as favorite'}
      >
        <Star size={14} fill={(area as any).is_favorite ? 'currentColor' : 'none'} />
      </button>
      <div ref={menuRef} className="relative" onClick={(e) => e.stopPropagation()}>
        <button
          ref={menuButtonRef}
          className="text-slate-500 hover:text-slate-800 p-1 rounded hover:bg-slate-100 transition-colors"
          onClick={() => { setMenuOpen(!menuOpen); setColorPickerOpen(false); }}
          title="Actions"
        >
          <MoreHorizontal size={16} />
        </button>
        {menuOpen && (
          <PortalMenu
            anchor={menuButtonRef.current}
            onClose={() => { setMenuOpen(false); setColorPickerOpen(false); }}
          >
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
            {isTerritory && (
              <button
                className="w-full text-left px-3 py-1.5 text-sm hover:bg-violet-50 flex items-center gap-2 font-semibold"
                style={{ color: '#7848BB' }}
                onClick={onRebuildBoundary}
                title="Replace the rough convex-hull boundary with a precise ST_Union over the territory's source tracts (slower but produces a real geographic shape)"
              >
                <Sparkles size={13} /> Rebuild clean boundary
              </button>
            )}
            <button className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 flex items-center gap-2" onClick={() => { setMenuOpen(false); onDuplicate(); }}>
              <Copy size={13} /> Duplicate
            </button>
            <button
              className="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 flex items-center gap-2"
              onClick={() => {
                setMenuOpen(false);
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
          </PortalMenu>
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

/**
 * Floating menu rendered via createPortal at document.body so it can escape
 * the AreaList's `overflow-y-auto` clip. Positioned with `fixed` coordinates
 * computed from the anchor button's getBoundingClientRect — and flipped above
 * the trigger when it would otherwise overflow the viewport bottom.
 *
 *   - Closes on outside click (mousedown, before any inner button click fires)
 *   - Closes on scroll/resize so it doesn't float disconnected from the row
 *   - z-50 so it sits above panels (which are z-20/30)
 */
function PortalMenu({
  anchor,
  children,
  onClose,
}: {
  anchor: HTMLElement | null;
  children: React.ReactNode;
  onClose: () => void;
}) {
  const menuRef = useRef<HTMLDivElement>(null);
  const [pos, setPos] = useState<{ top: number; left: number; flipUp: boolean }>({ top: 0, left: 0, flipUp: false });

  // Position before paint so the menu never visibly jumps. Re-positions
  // on scroll/resize (BF6 — old code closed on scroll, which felt brittle
  // when the left panel scrolled even a pixel; following the anchor instead
  // keeps the menu usable). Closes only when the anchor is fully off-screen.
  useLayoutEffect(() => {
    if (!anchor) return;
    const place = () => {
      const r = anchor.getBoundingClientRect();
      // Anchor scrolled out of view entirely — close.
      if (r.bottom < 0 || r.top > window.innerHeight) {
        onClose();
        return;
      }
      const menuH = menuRef.current?.offsetHeight ?? 280;
      const menuW = 200;
      const margin = 6;
      const wouldOverflowBottom = r.bottom + menuH + margin > window.innerHeight;
      const right = Math.min(window.innerWidth - 8, r.right);
      const left = Math.max(8, right - menuW);
      const top = wouldOverflowBottom ? Math.max(8, r.top - menuH - margin) : r.bottom + margin;
      setPos({ top, left, flipUp: wouldOverflowBottom });
    };
    place();
    // Capture-phase listener so scrolls inside nested overflow containers
    // (left panel, right panel) all reach us. Without `true`, only the
    // window scroll fires and the menu detaches visually when an inner
    // scrollable parent moves.
    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', place);
    return () => {
      window.removeEventListener('scroll', place, true);
      window.removeEventListener('resize', place);
    };
  }, [anchor, onClose]);

  // Outside-click handler — uses mousedown so it fires before any inner click.
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (menuRef.current?.contains(e.target as Node)) return;
      if (anchor?.contains(e.target as Node)) return;
      onClose();
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [anchor, onClose]);

  return createPortal(
    <div
      ref={menuRef}
      className="fixed bg-white border border-slate-200 rounded-lg shadow-lg z-50 min-w-[200px] max-w-[260px] py-1 card-expand"
      style={{
        top: pos.top,
        left: pos.left,
        // When auto-flipping above the trigger, anchor the expand origin at
        // the bottom so the menu unfurls upward (not downward into the row).
        transformOrigin: pos.flipUp ? 'bottom' : 'top',
      }}
    >
      {children}
    </div>,
    document.body
  );
}

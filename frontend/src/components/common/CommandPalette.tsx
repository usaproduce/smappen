import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Search, MapPin, Layers, Settings, Star, Building2 } from 'lucide-react';
import { createPortal } from 'react-dom';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
import { smoothFlyTo } from '../../utils/mapAnim';

/**
 * VT8 — global command palette opened with Ctrl/Cmd+/ (Edge-safe; the
 * project switcher uses g+p instead since Ctrl+K is hijacked by Edge).
 *
 * Searches:
 *   • Areas in the current project (jumps to + selects)
 *   • Projects (switches current project)
 *   • Quick actions ("Toggle heatmap", "Open settings", "Clean style")
 *
 * Result list is keyboard-driven (↑↓ + Enter); Esc closes.
 */
type ItemKind = 'area' | 'project' | 'action';
interface Item {
  kind: ItemKind;
  id: string;
  label: string;
  sub?: string;
  run: () => void;
  icon?: any;
}

export default function CommandPalette() {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [cursor, setCursor] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const navigate = useNavigate();
  const projectStore = useProjectStore();
  const mapStore = useMapStore();
  const uiPrefs = useUiPrefsStore();

  // Keyboard: Ctrl+/ or Cmd+/ opens. Esc closes.
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key === '/') {
        e.preventDefault();
        setOpen((v) => !v);
        setQ('');
        setCursor(0);
        return;
      }
      if (e.key === 'Escape' && open) {
        e.preventDefault();
        setOpen(false);
      }
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  useEffect(() => { if (open) setTimeout(() => inputRef.current?.focus(), 30); }, [open]);

  const items: Item[] = useMemo(() => {
    const out: Item[] = [];
    // Areas — current project only (keep result list short).
    for (const a of projectStore.areas ?? []) {
      out.push({
        kind: 'area',
        id: a.id,
        label: a.name,
        sub: `${a.area_type ?? ''}${a.travel_time_minutes ? ` · ${a.travel_time_minutes} min` : ''}`,
        icon: MapPin,
        run: () => {
          mapStore.selectArea(a.id);
          if (a.center_lat != null && a.center_lng != null) {
            smoothFlyTo(mapStore.mapInstance, { lat: a.center_lat, lng: a.center_lng, zoom: 12 });
          }
        },
      });
    }
    // Projects.
    for (const p of (projectStore as any).projects ?? []) {
      out.push({
        kind: 'project',
        id: p.id,
        label: p.name,
        sub: 'Project',
        icon: Layers,
        run: () => {
          projectStore.setCurrentProject?.(p);
        },
      });
    }
    // Quick actions.
    const actions: Item[] = [
      { kind: 'action', id: 'toggle-heatmap', label: 'Toggle population heatmap', icon: Layers, run: () => mapStore.toggleHeatmap() },
      { kind: 'action', id: 'favorites-only', label: 'Favorites only', icon: Star, run: () => mapStore.toggleFavoritesOnly() },
      { kind: 'action', id: 'map-clean', label: 'Map: Clean style', icon: Layers, run: () => uiPrefs.setMapStyle('clean') },
      { kind: 'action', id: 'map-detailed', label: 'Map: Detailed style', icon: Layers, run: () => uiPrefs.setMapStyle('detailed') },
      { kind: 'action', id: 'toggle-labels', label: 'Toggle polygon labels', icon: Building2, run: () => uiPrefs.togglePolygonLabels() },
      { kind: 'action', id: 'settings', label: 'Open settings', icon: Settings, run: () => navigate('/settings/profile') },
      { kind: 'action', id: 'billing', label: 'Open billing', icon: Settings, run: () => navigate('/settings/billing') },
    ];
    out.push(...actions);
    return out;
  }, [projectStore, mapStore, uiPrefs, navigate]);

  const filtered = useMemo(() => {
    if (!q) return items.slice(0, 12);
    const needle = q.toLowerCase();
    return items
      .filter((it) => it.label.toLowerCase().includes(needle) || it.sub?.toLowerCase().includes(needle))
      .slice(0, 20);
  }, [items, q]);

  useEffect(() => { setCursor(0); }, [q, open]);

  function onKeyDown(e: React.KeyboardEvent) {
    if (e.key === 'ArrowDown') { e.preventDefault(); setCursor((c) => Math.min(filtered.length - 1, c + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setCursor((c) => Math.max(0, c - 1)); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      const it = filtered[cursor];
      if (it) { it.run(); setOpen(false); }
    }
  }

  if (!open) return null;
  return createPortal(
    <div
      className="fixed inset-0 z-[200] bg-black/30 backdrop-blur-sm flex items-start justify-center pt-[18vh]"
      onClick={() => setOpen(false)}
    >
      <div
        className="bg-white rounded-xl shadow-2xl border border-slate-200 w-[min(560px,90vw)] max-h-[60vh] overflow-hidden card-expand"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center gap-2 px-3 py-2.5 border-b border-slate-100">
          <Search size={14} className="text-slate-400" />
          <input
            ref={inputRef}
            value={q}
            onChange={(e) => setQ(e.target.value)}
            onKeyDown={onKeyDown}
            placeholder="Jump to area, project, or action…"
            className="flex-1 outline-none text-sm"
          />
          <kbd className="text-[10px] bg-slate-100 px-1.5 py-0.5 rounded text-slate-500">Esc</kbd>
        </div>
        <ul className="overflow-y-auto max-h-[52vh] py-1">
          {filtered.length === 0 && (
            <li className="px-4 py-6 text-center text-xs text-slate-500">No matches</li>
          )}
          {filtered.map((it, i) => {
            const Icon = it.icon ?? Search;
            const active = i === cursor;
            return (
              <li key={`${it.kind}-${it.id}`}>
                <button
                  className={`w-full text-left flex items-center gap-2.5 px-3 py-2 text-sm ${
                    active ? 'bg-violet-50 text-violet-800' : 'text-slate-700 hover:bg-slate-50'
                  }`}
                  onMouseEnter={() => setCursor(i)}
                  onClick={() => { it.run(); setOpen(false); }}
                >
                  <Icon size={14} className="text-slate-400 shrink-0" />
                  <span className="flex-1 truncate font-medium">{it.label}</span>
                  {it.sub && <span className="text-[11px] text-slate-400 shrink-0">{it.sub}</span>}
                </button>
              </li>
            );
          })}
        </ul>
        <div className="border-t border-slate-100 px-3 py-1.5 flex items-center justify-between text-[10px] text-slate-400">
          <span>
            <kbd className="bg-slate-100 px-1 py-0.5 rounded">↑↓</kbd> navigate
            <span className="ml-2"><kbd className="bg-slate-100 px-1 py-0.5 rounded">↵</kbd> select</span>
          </span>
          <span>Open with <kbd className="bg-slate-100 px-1 py-0.5 rounded">Ctrl+/</kbd></span>
        </div>
      </div>
    </div>,
    document.body
  );
}

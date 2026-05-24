import { useMemo, useState } from 'react';
import { Search, X, Layers, ChevronDown, ChevronRight, GripVertical, MapPin, Star, Hexagon, Compass, Trash2, Palette } from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { useUiPrefsStore, type AreaListFilter, type AreaListGroupBy } from '../../stores/uiPrefsStore';
import { areasApi } from '../../api/areas';
import AreaCard from './AreaCard';
import type { Area } from '../../types';

type Sort = 'recent' | 'name' | 'time' | 'population';

const FILTER_CHIPS: { key: AreaListFilter; label: string; icon: any }[] = [
  { key: 'all',        label: 'All',        icon: Layers },
  { key: 'favorites',  label: 'Favorites',  icon: Star },
  { key: 'isochrone',  label: 'Travel',     icon: Compass },
  { key: 'radius',     label: 'Radius',     icon: Hexagon },
  { key: 'manual',     label: 'Drawn',      icon: MapPin },
  { key: 'territory',  label: 'Territory',  icon: Hexagon },
];

const GROUP_BY_OPTS: { key: AreaListGroupBy; label: string }[] = [
  { key: 'none',  label: 'None'  },
  { key: 'type',  label: 'Type'  },
  { key: 'date',  label: 'Date'  },
  { key: 'color', label: 'Color' },
];

// Tweak #8 — illustrated empty state with arrow pointing at the map.
// Lives inline; consumer hides if a search/filter is active (those use
// the simpler "no matches" prompt instead).
function FirstAreaIllustration() {
  return (
    <div className="px-4 py-8 text-center relative">
      <div className="mx-auto mb-3 relative" style={{ width: 88, height: 88 }}>
        <svg viewBox="0 0 88 88" width="88" height="88" aria-hidden="true">
          <defs>
            <radialGradient id="iso-g" cx="50%" cy="40%" r="55%">
              <stop offset="0%" stopColor="#7848BB" stopOpacity="0.35" />
              <stop offset="100%" stopColor="#7848BB" stopOpacity="0.05" />
            </radialGradient>
          </defs>
          <path d="M14,52 C18,38 32,30 44,30 C58,30 72,38 74,52 C76,66 60,74 44,74 C28,74 12,66 14,52 Z"
                fill="url(#iso-g)" stroke="#7848BB" strokeWidth="1.6" strokeDasharray="3 3" />
          <circle cx="44" cy="48" r="4" fill="#7848BB" />
          <path d="M44,48 L44,30" stroke="#7848BB" strokeWidth="1.6" strokeDasharray="2 2" />
          <path d="M44,18 L40,28 L48,28 Z" fill="#7848BB" />
        </svg>
        <span
          className="absolute -right-3 top-1/2 -translate-y-1/2 text-violet-500 text-2xl"
          style={{ animation: 'point-bounce 1.4s ease-in-out infinite' }}
        >
          →
        </span>
      </div>
      <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>Map your first area</div>
      <div className="text-xs text-slate-500 mt-1 max-w-[240px] mx-auto leading-snug">
        Click anywhere on the map to drop a pin, draw a polygon, or set a drive-time radius.
      </div>
      <style>{`
        @keyframes point-bounce { 0%,100% { transform: translate(0,-50%); } 50% { transform: translate(6px,-50%); } }
        @media (prefers-reduced-motion: reduce) {
          /* BF9 — pause the arrow nudge for users who asked the OS not to animate. */
          [style*="point-bounce"] { animation: none !important; }
        }
      `}</style>
    </div>
  );
}

export default function AreaList() {
  const { areas, currentProject } = useProjectStore() as any;
  const favoritesOnly = useMapStore((s) => s.favoritesOnly);
  const {
    areaListFilter: filter, setAreaListFilter,
    areaListGroupBy: groupBy, setAreaListGroupBy,
    areaOrder, setAreaOrder,
  } = useUiPrefsStore();
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<Sort>('recent');
  const [groupMenuOpen, setGroupMenuOpen] = useState(false);
  const [collapsedGroups, setCollapsedGroups] = useState<Record<string, boolean>>({});
  const [draggingId, setDraggingId] = useState<string | null>(null);
  const [dragOverId, setDragOverId] = useState<string | null>(null);
  const [shapeFilter, setShapeFilter] = useState<'all' | 'compact' | 'elongated' | 'multi'>('all');
  // OP1 — multi-select state. Shift+click an area row adds it to the
  // selection set; the bulk-action bar appears whenever at least one
  // area is selected.
  const [bulkSelection, setBulkSelection] = useState<Set<string>>(new Set());
  const { removeArea } = useProjectStore() as any;

  const projectId = currentProject?.id ?? '__none__';
  const savedOrder = areaOrder[projectId] ?? [];

  const filtered = useMemo(() => {
    const passesFilter = (a: Area) => {
      if (filter === 'all') return true;
      if (filter === 'favorites') return !!(a as any).is_favorite;
      if (filter === 'isochrone') return a.area_type === 'isochrone' || a.area_type === 'isodistance';
      if (filter === 'territory') return !!(a as any).tract_geoids || (a as any).source === 'territory';
      return a.area_type === filter;
    };
    return areas
      .filter((a: Area) => !search || a.name.toLowerCase().includes(search.toLowerCase()))
      .filter((a: Area) => !favoritesOnly || (a as any).is_favorite)
      .filter(passesFilter)
      // VT16 — optional shape filter ("compact / elongated / multi"). Useful
      // for spotting routing-unfriendly polygons at a glance.
      .filter((a: Area) => shapeFilter === 'all' || shapeClass(a) === shapeFilter)
      .sort((a: Area, b: Area) => {
        // User-driven order via drag wins over computed sort (only when sort=recent
        // — explicitly chosen sorts always reflect the user's intent).
        if (sort === 'recent' && savedOrder.length > 0) {
          const ai = savedOrder.indexOf(a.id);
          const bi = savedOrder.indexOf(b.id);
          if (ai !== -1 && bi !== -1) return ai - bi;
          if (ai !== -1) return -1;
          if (bi !== -1) return 1;
        }
        const af = (a as any).is_favorite ? 1 : 0;
        const bf = (b as any).is_favorite ? 1 : 0;
        if (af !== bf) return bf - af;
        if (sort === 'name') return a.name.localeCompare(b.name);
        if (sort === 'time') return (a.travel_time_minutes ?? 0) - (b.travel_time_minutes ?? 0);
        if (sort === 'population') {
          const ap = (a as any).demographics_cache?.population?.total ?? 0;
          const bp = (b as any).demographics_cache?.population?.total ?? 0;
          return bp - ap;
        }
        return 0;
      });
  }, [areas, search, filter, favoritesOnly, sort, savedOrder, shapeFilter]);

  // Group buckets — only computed when groupBy != 'none'.
  const groups = useMemo(() => {
    if (groupBy === 'none') return [{ key: '__all__', label: '', items: filtered }];
    const buckets = new Map<string, { key: string; label: string; items: Area[] }>();
    const push = (key: string, label: string, a: Area) => {
      if (!buckets.has(key)) buckets.set(key, { key, label, items: [] });
      buckets.get(key)!.items.push(a);
    };
    for (const a of filtered) {
      if (groupBy === 'type') {
        const t = a.area_type === 'isochrone' || a.area_type === 'isodistance'
          ? 'Travel time'
          : a.area_type === 'radius' ? 'Radius' : 'Drawn';
        push(t, t, a);
      } else if (groupBy === 'date') {
        const d = (a as any).created_at ? new Date((a as any).created_at) : null;
        const label = !d ? 'Undated' : timeBucket(d);
        push(label, label, a);
      } else if (groupBy === 'color') {
        const c = (a.fill_color ?? '#7848BB').toUpperCase();
        push(c, c, a);
      }
    }
    return Array.from(buckets.values());
  }, [filtered, groupBy]);

  // VT16 — cheap shape classification from the bbox + ring count. Compact:
  // bbox is square-ish. Elongated: aspect > 2. Multi: MultiPolygon w/ >1 ring.
  function shapeClass(a: Area): 'compact' | 'elongated' | 'multi' {
    const g: any = a.geometry;
    if (g?.type === 'MultiPolygon' && (g.coordinates?.length ?? 0) > 1) return 'multi';
    if (!g?.coordinates) return 'compact';
    const ring = g.type === 'Polygon' ? g.coordinates[0] : g.coordinates?.[0]?.[0];
    if (!ring || ring.length < 3) return 'compact';
    let minLat = Infinity, minLng = Infinity, maxLat = -Infinity, maxLng = -Infinity;
    for (const [lng, lat] of ring) {
      if (lat < minLat) minLat = lat; if (lat > maxLat) maxLat = lat;
      if (lng < minLng) minLng = lng; if (lng > maxLng) maxLng = lng;
    }
    const w = maxLng - minLng;
    const h = maxLat - minLat;
    const aspect = w > 0 && h > 0 ? Math.max(w / h, h / w) : 1;
    return aspect > 2 ? 'elongated' : 'compact';
  }

  function toggleBulk(id: string, multi = false) {
    setBulkSelection((cur) => {
      const next = new Set(multi ? cur : []);
      if (cur.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  async function bulkDelete() {
    if (!confirm(`Delete ${bulkSelection.size} areas?`)) return;
    const ids = Array.from(bulkSelection);
    setBulkSelection(new Set());
    // Optimistic — remove locally first, then fire the DELETEs in parallel.
    for (const id of ids) removeArea?.(id);
    let failures = 0;
    await Promise.allSettled(ids.map((id) => areasApi.delete(id).catch(() => { failures++; })));
    toast.success(`${ids.length - failures} deleted${failures ? ` (${failures} failed)` : ''}`);
  }

  function onDrop(targetId: string) {
    if (!draggingId || draggingId === targetId) return;
    const current = filtered.map((a: Area) => a.id);
    const from = current.indexOf(draggingId);
    const to = current.indexOf(targetId);
    if (from === -1 || to === -1) return;
    current.splice(to, 0, current.splice(from, 1)[0]);
    setAreaOrder(projectId, current);
    setDraggingId(null);
    // BF7 — persist to the server so the new order survives a reload.
    // Optimistic — local order is already set; server call is fire-and-forget.
    if (currentProject?.id) {
      // Build the FULL project area order (not just the filtered view) so
      // hidden items don't lose their relative positions.
      const all = areas.map((a: Area) => a.id);
      const visible = new Set(current);
      const merged = [
        ...current,
        ...all.filter((id: string) => !visible.has(id)),
      ];
      areasApi.reorder(currentProject.id, merged).catch(() => {
        // Stay silent — the local order remains in localStorage as fallback.
      });
    }
  }

  return (
    <div className="py-1">
      <div className="px-3 mb-2">
        <div className="relative">
          <span className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none flex items-center">
            <Search size={14} />
          </span>
          <input
            className="w-full h-10 pl-9 pr-9 text-sm rounded-lg border border-slate-300 bg-white focus:outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100 transition"
            style={{ color: 'var(--ink)' }}
            placeholder="Search areas or folder…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          {search && (
            <button
              className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-100 transition"
              onClick={() => setSearch('')}
              title="Clear"
            >
              <X size={13} />
            </button>
          )}
        </div>
      </div>

      {/* Tweak #5 — sticky filter chip row. Sits at the top of the scrollable
          list so it stays visible as the user scans long lists. */}
      <div className="sticky top-0 z-10 bg-white/95 backdrop-blur px-3 pt-1 pb-2 border-b border-slate-100">
        <div className="flex items-center gap-1 overflow-x-auto -mx-1 px-1 scroll-x">
          {FILTER_CHIPS.map((c) => {
            const Active = filter === c.key;
            const Icon = c.icon;
            return (
              <button
                key={c.key}
                onClick={() => setAreaListFilter(c.key)}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold whitespace-nowrap transition-colors ${
                  Active
                    ? 'bg-violet-100 text-violet-700 ring-1 ring-violet-300'
                    : 'text-slate-500 hover:bg-slate-100'
                }`}
              >
                <Icon size={11} />
                {c.label}
              </button>
            );
          })}
        </div>

        <div className="flex items-center gap-1 mt-1.5">
          <span className="text-[10px] uppercase tracking-wider font-bold text-slate-400 mr-0.5">Sort</span>
          {(['recent', 'name', 'time', 'population'] as Sort[]).map((s) => (
            <button
              key={s}
              onClick={() => setSort(s)}
              className={`text-[11px] px-2 py-0.5 rounded-full font-semibold capitalize ${
                sort === s ? 'bg-violet-50 text-violet-700' : 'text-slate-500 hover:bg-slate-100'
              }`}
            >
              {s === 'population' ? 'pop' : s}
            </button>
          ))}
          <div className="ml-auto relative">
            <button
              onClick={() => setGroupMenuOpen((v) => !v)}
              className={`text-[11px] px-2 py-0.5 rounded font-semibold flex items-center gap-1 ${
                groupBy !== 'none' ? 'text-violet-700 bg-violet-50' : 'text-slate-500 hover:bg-slate-100'
              }`}
              title="Group by"
            >
              Group: {GROUP_BY_OPTS.find((g) => g.key === groupBy)?.label ?? 'None'}
              <ChevronDown size={11} />
            </button>
            {groupMenuOpen && (
              <div className="absolute right-0 top-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg z-20 py-1 min-w-[120px] card-expand">
                {GROUP_BY_OPTS.map((g) => (
                  <button
                    key={g.key}
                    onClick={() => { setAreaListGroupBy(g.key); setGroupMenuOpen(false); }}
                    className={`block w-full text-left px-3 py-1.5 text-xs hover:bg-slate-50 ${
                      groupBy === g.key ? 'text-violet-700 font-semibold' : 'text-slate-700'
                    }`}
                  >
                    {g.label}
                  </button>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* VT16 — shape filter row (only renders if there's something to filter from). */}
        {areas.length >= 4 && (
          <div className="flex items-center gap-1 mt-1.5 overflow-x-auto -mx-1 px-1 scroll-x">
            <span className="text-[10px] uppercase tracking-wider font-bold text-slate-400 mr-0.5 shrink-0">Shape</span>
            {(['all', 'compact', 'elongated', 'multi'] as const).map((s) => (
              <button
                key={s}
                onClick={() => setShapeFilter(s)}
                className={`text-[11px] px-2 py-0.5 rounded-full font-semibold capitalize whitespace-nowrap shrink-0 ${
                  shapeFilter === s ? 'bg-violet-50 text-violet-700' : 'text-slate-500 hover:bg-slate-100'
                }`}
              >
                {s === 'multi' ? 'multi-piece' : s}
              </button>
            ))}
          </div>
        )}

        <div className="flex items-center justify-between mt-1 text-[11px] text-slate-400 font-medium">
          <span>{filtered.length} of {areas.length} {areas.length === 1 ? 'area' : 'areas'}</span>
          {(search || filter !== 'all') && (
            <button
              className="text-[11px] text-violet-600 hover:underline"
              onClick={() => { setSearch(''); setAreaListFilter('all'); }}
            >
              Clear
            </button>
          )}
        </div>
      </div>

      {/* OP1 — bulk action bar. Visible whenever the selection set is non-empty. */}
      {bulkSelection.size > 0 && (
        <div className="sticky top-[120px] z-10 mx-3 my-2 bg-violet-700 text-white rounded-lg shadow-lg flex items-center gap-2 px-3 py-1.5 text-xs">
          <span className="font-bold">{bulkSelection.size} selected</span>
          <span className="flex-1" />
          <button
            className="inline-flex items-center gap-1 hover:bg-white/10 px-2 py-0.5 rounded"
            onClick={bulkDelete}
            title="Delete selected"
          >
            <Trash2 size={11} /> Delete
          </button>
          <button
            className="inline-flex items-center gap-1 hover:bg-white/10 px-2 py-0.5 rounded opacity-50 cursor-not-allowed"
            disabled
            title="Bulk recolor — coming soon"
          >
            <Palette size={11} /> Recolor
          </button>
          <button
            className="inline-flex items-center gap-1 hover:bg-white/10 px-2 py-0.5 rounded"
            onClick={() => setBulkSelection(new Set())}
            title="Clear selection"
          >
            <X size={11} />
          </button>
        </div>
      )}

      {filtered.length === 0 ? (
        search || filter !== 'all' || favoritesOnly ? (
          <div className="text-center py-8 px-4">
            <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>No matches</div>
            <div className="text-xs text-slate-500 mt-1">Try clearing your filters.</div>
          </div>
        ) : (
          <FirstAreaIllustration />
        )
      ) : (
        <div>
          {groups.map((g) => {
            const collapsed = !!collapsedGroups[g.key];
            return (
              <div key={g.key}>
                {groupBy !== 'none' && (
                  <button
                    className="flex items-center gap-1 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500 hover:text-slate-700 w-full text-left"
                    onClick={() =>
                      setCollapsedGroups((prev) => ({ ...prev, [g.key]: !prev[g.key] }))
                    }
                  >
                    {collapsed ? <ChevronRight size={11} /> : <ChevronDown size={11} />}
                    {groupBy === 'color' ? (
                      <span className="inline-block w-2.5 h-2.5 rounded-full border border-black/10" style={{ background: g.key }} />
                    ) : null}
                    <span>{g.label}</span>
                    <span className="ml-auto text-slate-400 font-medium normal-case">{g.items.length}</span>
                  </button>
                )}
                {!collapsed && g.items.map((a: Area, i: number) => (
                  <div
                    key={a.id}
                    draggable
                    onDragStart={() => setDraggingId(a.id)}
                    onDragOver={(e) => { e.preventDefault(); setDragOverId(a.id); }}
                    onDragLeave={() => setDragOverId((cur) => (cur === a.id ? null : cur))}
                    onDrop={() => { onDrop(a.id); setDragOverId(null); }}
                    onDragEnd={() => { setDraggingId(null); setDragOverId(null); }}
                    onClickCapture={(e) => {
                      // OP1 — Shift-click toggles inclusion in the bulk selection
                      // without firing the row's normal selectArea handler.
                      if (e.shiftKey) { e.preventDefault(); e.stopPropagation(); toggleBulk(a.id, true); }
                    }}
                    className={`stagger-in group/row relative ${draggingId === a.id ? 'opacity-50' : ''} ${
                      dragOverId === a.id && draggingId !== a.id ? 'ring-2 ring-violet-400 ring-offset-1 rounded-md bg-violet-50/60' : ''
                    } ${bulkSelection.has(a.id) ? 'ring-2 ring-violet-500 bg-violet-100/40' : ''}`}
                    style={{ ['--stagger-i' as any]: i }}
                  >
                    {/* Drag handle visible on hover. Whole row is draggable so
                        the handle is just an affordance, not a hit target. */}
                    <span
                      className="absolute left-0 top-1/2 -translate-y-1/2 text-slate-300 opacity-0 group-hover/row:opacity-100 transition-opacity cursor-grab pl-0.5"
                      aria-hidden="true"
                    >
                      <GripVertical size={12} />
                    </span>
                    <AreaCard area={a} />
                  </div>
                ))}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

function timeBucket(d: Date): string {
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  const sevenDaysAgo = today - 7 * 86400000;
  const thirtyDaysAgo = today - 30 * 86400000;
  const t = d.getTime();
  if (t >= today) return 'Today';
  if (t >= today - 86400000) return 'Yesterday';
  if (t >= sevenDaysAgo) return 'This week';
  if (t >= thirtyDaysAgo) return 'This month';
  if (d.getFullYear() === now.getFullYear()) return d.toLocaleString('en-US', { month: 'long' });
  return String(d.getFullYear());
}

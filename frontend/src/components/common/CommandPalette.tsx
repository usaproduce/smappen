import { useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import {
  Search, MapPin, Layers, Settings, Star, Building2,
  ChefHat, DollarSign, Target, Users2, BookOpen, Heart, RefreshCw, FileText,
  Home, ArrowLeftRight,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { createPortal } from 'react-dom';
import { useProjectStore } from '../../stores/projectStore';
import { useMapStore } from '../../stores/mapStore';
import { useUiPrefsStore } from '../../stores/uiPrefsStore';
import { useRestaurantStore } from '../../stores/restaurantStore';
import { smoothFlyTo } from '../../utils/mapAnim';
import { restaurantsApi, posApi } from '../../api/restaurants';
import { api } from '../../api/client';
import { studyTradeAreaForRestaurant } from '../../utils/studyTradeArea';

/**
 * Global command palette. Mounted once in App.tsx; available on every
 * authed surface — Carafe restaurant workspace, vendor map, the map
 * app, settings.
 *
 * Open with Ctrl/Cmd + /.
 * Chord g→r opens the palette pre-filtered to restaurant switching.
 *
 * Surface-aware: the items list is augmented based on `useLocation()`,
 * so the Carafe restaurant workspace sees its tab navigation + sync /
 * report commands, while the map app keeps area / project / heatmap
 * commands. Both surfaces always have the global Carafe quick-switcher.
 */

type ItemKind = 'area' | 'project' | 'restaurant' | 'nav' | 'action';

interface Item {
  kind: ItemKind;
  id: string;
  label: string;
  sub?: string;
  /** Section heading shown above the first item with this group. */
  group: string;
  /** Run the command. Return false to keep the palette open (e.g. async). */
  run: () => void | Promise<void> | boolean;
  icon?: any;
  /** Keywords that match in addition to label/sub (e.g. "sync" → "pos"). */
  keywords?: string;
}

const RESTAURANT_ID_RE = /^\/app\/restaurants\/([^/]+)/;

export default function CommandPalette() {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [cursor, setCursor] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const navigate = useNavigate();
  const location = useLocation();
  const projectStore = useProjectStore();
  const mapStore = useMapStore();
  const uiPrefs = useUiPrefsStore();
  const restaurants = useRestaurantStore((s) => s.restaurants);
  const setRestaurants = useRestaurantStore((s) => s.setRestaurants);
  const currentRestaurant = useRestaurantStore((s) => s.currentRestaurant);

  const currentRestaurantId = useMemo(() => {
    const m = location.pathname.match(RESTAURANT_ID_RE);
    return m ? m[1] : null;
  }, [location.pathname]);

  // ── Keyboard: Ctrl/Cmd+/ toggles. Chord g→r opens + pre-filters to
  //    restaurants. Esc closes. Typing inside an input never triggers. ─
  useEffect(() => {
    let gPressed = false;
    let gTimer: number | null = null;

    function isTyping(target: EventTarget | null): boolean {
      const el = target as HTMLElement | null;
      if (!el) return false;
      const tag = el.tagName;
      return tag === 'INPUT' || tag === 'TEXTAREA' || (el as any).isContentEditable === true;
    }

    function onKey(e: KeyboardEvent) {
      // Toggle palette — Ctrl/Cmd+/
      if ((e.metaKey || e.ctrlKey) && e.key === '/') {
        e.preventDefault();
        setOpen((v) => !v);
        setQ('');
        setCursor(0);
        return;
      }
      // Esc closes when open
      if (e.key === 'Escape' && open) {
        e.preventDefault();
        setOpen(false);
        return;
      }
      // Chord g → r — pre-filter to restaurants. Single-key triggers
      // are off-limits while typing in a field; the chord state itself
      // is also bypassed when the user is typing.
      if (isTyping(e.target)) { gPressed = false; if (gTimer) window.clearTimeout(gTimer); return; }
      if (e.metaKey || e.ctrlKey || e.altKey) return;
      if (!gPressed && (e.key === 'g' || e.key === 'G')) {
        gPressed = true;
        if (gTimer) window.clearTimeout(gTimer);
        gTimer = window.setTimeout(() => { gPressed = false; }, 900);
        return;
      }
      if (gPressed && (e.key === 'r' || e.key === 'R')) {
        e.preventDefault();
        gPressed = false;
        if (gTimer) window.clearTimeout(gTimer);
        setOpen(true);
        setQ('restaurant');
        setCursor(0);
      }
    }
    window.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('keydown', onKey);
      if (gTimer) window.clearTimeout(gTimer);
    };
  }, [open]);

  // Focus the input when the palette opens (one paint after to ensure
  // the portal node is in the tree).
  useEffect(() => {
    if (open) {
      const t = window.setTimeout(() => inputRef.current?.focus(), 30);
      return () => window.clearTimeout(t);
    }
    return undefined;
  }, [open]);

  // Pull restaurants when the palette opens if the store is empty —
  // operators on the map app shouldn't have to navigate to /restaurants
  // first to see the switcher work.
  useEffect(() => {
    if (!open) return;
    if (restaurants.length > 0) return;
    let cancelled = false;
    restaurantsApi.list().then((list) => {
      if (!cancelled) setRestaurants(list);
    }).catch(() => { /* silent — the rest of the palette still works */ });
    return () => { cancelled = true; };
  }, [open, restaurants.length, setRestaurants]);

  // ── Item set ─────────────────────────────────────────────────────
  const items: Item[] = useMemo(() => {
    const out: Item[] = [];

    // ── Carafe global: restaurant quick-switcher (always available) ─
    for (const r of restaurants) {
      const isCurrent = r.id === currentRestaurantId;
      out.push({
        kind: 'restaurant',
        id: r.id,
        label: r.name,
        sub: isCurrent ? 'current restaurant' : 'switch restaurant',
        icon: ChefHat,
        group: 'Switch restaurant',
        keywords: 'restaurant switch carafe',
        run: () => {
          if (!isCurrent) navigate(`/app/restaurants/${r.id}`);
        },
      });
    }

    // ── Carafe per-restaurant tab nav (only when inside a restaurant) ─
    if (currentRestaurantId) {
      const tabs: Array<{ label: string; sub: string; href: string; icon: any; keywords?: string }> = [
        { label: 'Open Overview',        sub: 'war-room',                 href: `/app/restaurants/${currentRestaurantId}`,           icon: Home,        keywords: 'war room dashboard overview' },
        { label: 'Open Menu',            sub: 'items, plate cost',        href: `/app/restaurants/${currentRestaurantId}/menu`,      icon: ChefHat,     keywords: 'menu items pos' },
        { label: 'Open Recipes',         sub: 'ingredients, plate cost',  href: `/app/restaurants/${currentRestaurantId}/recipes`,   icon: BookOpen,    keywords: 'recipes ingredients' },
        { label: 'Open Costs',           sub: 'food cost, overpay flags', href: `/app/restaurants/${currentRestaurantId}/costs`,     icon: DollarSign,  keywords: 'costs cogs food cost overpay' },
        { label: 'Open Labor',           sub: 'staffing vs demand',       href: `/app/restaurants/${currentRestaurantId}/labor`,     icon: Users2,      keywords: 'labor daypart staffing' },
        { label: 'Open Goals',           sub: 'targets + snapshots',      href: `/app/restaurants/${currentRestaurantId}/goals`,     icon: Target,      keywords: 'goals targets snapshot' },
      ];
      for (const t of tabs) {
        out.push({
          kind: 'nav',
          id: t.href,
          label: t.label,
          sub: t.sub,
          icon: t.icon,
          group: currentRestaurant ? currentRestaurant.name : 'Restaurant',
          keywords: t.keywords,
          run: () => navigate(t.href),
        });
      }

      // ── Per-restaurant actions ────────────────────────────────────
      out.push({
        kind: 'action',
        id: 'view-roi',
        label: "View this month's ROI",
        sub: 'jump to the war-room headline',
        icon: DollarSign,
        group: currentRestaurant ? currentRestaurant.name : 'Restaurant',
        keywords: 'roi money found ledger',
        run: () => navigate(`/app/restaurants/${currentRestaurantId}`),
      });
      out.push({
        kind: 'action',
        id: 'pos-sync',
        label: 'Sync POS now',
        sub: 'pull latest Square data',
        icon: RefreshCw,
        group: currentRestaurant ? currentRestaurant.name : 'Restaurant',
        keywords: 'sync pos square pull',
        run: async () => {
          const t = toast.loading('Syncing Square…');
          try {
            await posApi.sync(currentRestaurantId, 'square');
            toast.success('Sync queued', { id: t });
          } catch (e: any) {
            toast.error(e?.response?.data?.error ?? 'Sync failed', { id: t });
          }
        },
      });
      out.push({
        kind: 'action',
        id: 'money-found-pdf',
        label: 'Download money-found report',
        sub: 'this-month PDF',
        icon: FileText,
        group: currentRestaurant ? currentRestaurant.name : 'Restaurant',
        keywords: 'pdf report download money found',
        run: async () => {
          const t = toast.loading('Generating report…');
          try {
            const resp = await api.get(
              `/api/restaurants/${currentRestaurantId}/reports/money-found.pdf`,
              { responseType: 'blob' },
            );
            const blob = new Blob([resp.data], { type: 'application/pdf' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `carafe-money-found-${new Date().toISOString().slice(0, 7)}.pdf`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            toast.success('Report downloaded', { id: t });
          } catch (e: any) {
            toast.error(e?.response?.data?.error ?? 'Report failed', { id: t });
          }
        },
      });
      // Planning sandbox — handled by the existing util. Lives on the
      // utility layer so we don't duplicate the dedupe + project create
      // logic; just navigate to /app and the map picks up the new area.
      out.push({
        kind: 'action',
        id: 'planning-sandbox',
        label: 'Open planning sandbox',
        sub: '15-min drive · demographics · competitors',
        icon: MapPin,
        group: currentRestaurant ? currentRestaurant.name : 'Restaurant',
        keywords: 'planning sandbox trade area study',
        run: async () => {
          const t = toast.loading('Building trade area…');
          try {
            const ok = await studyTradeAreaForRestaurant(currentRestaurantId);
            toast.dismiss(t);
            if (ok) navigate('/app');
          } catch (e: any) {
            toast.error(e?.response?.data?.error ?? 'Could not open sandbox', { id: t });
          }
        },
      });
    }

    // ── Carafe top-level navigation (always available) ───────────────
    const topNav: Array<{ label: string; sub: string; href: string; icon: any; keywords?: string }> = [
      { label: 'All restaurants',      sub: 'Carafe restaurants list',  href: '/app/restaurants',         icon: ChefHat,    keywords: 'restaurants list carafe' },
      { label: 'Vendor map',           sub: 'who serves me / coverage', href: '/app/vendors/map',         icon: Building2,  keywords: 'vendors map suppliers' },
      { label: 'Saved vendors',        sub: 'your shortlist',           href: '/app/vendors/saved',       icon: Heart,      keywords: 'vendors saved shortlist' },
      { label: 'Vendor list',          sub: 'tabular view',             href: '/app/vendors/list',       icon: Building2,  keywords: 'vendors list table' },
      { label: 'Dashboard',            sub: 'cross-product home',       href: '/dashboard',               icon: Layers,     keywords: 'dashboard home' },
      { label: 'Map workspace',        sub: 'isochrones, territories',  href: '/app',                     icon: MapPin,     keywords: 'map smappen workspace' },
    ];
    for (const t of topNav) {
      out.push({
        kind: 'nav',
        id: t.href,
        label: t.label,
        sub: t.sub,
        icon: t.icon,
        group: 'Navigate',
        keywords: t.keywords,
        run: () => navigate(t.href),
      });
    }

    // ── Map app: areas + projects (only meaningful on /app/*) ────────
    const onMap = location.pathname === '/app' || location.pathname.startsWith('/app/');
    if (onMap) {
      for (const a of projectStore.areas ?? []) {
        out.push({
          kind: 'area',
          id: a.id,
          label: a.name,
          sub: `${a.area_type ?? ''}${a.travel_time_minutes ? ` · ${a.travel_time_minutes} min` : ''}`,
          icon: MapPin,
          group: 'Areas (current project)',
          run: () => {
            mapStore.selectArea(a.id);
            if (a.center_lat != null && a.center_lng != null) {
              smoothFlyTo(mapStore.mapInstance, { lat: a.center_lat, lng: a.center_lng, zoom: 12 });
            }
          },
        });
      }
      for (const p of (projectStore as any).projects ?? []) {
        out.push({
          kind: 'project',
          id: p.id,
          label: p.name,
          sub: 'Project',
          icon: Layers,
          group: 'Projects',
          run: () => { projectStore.setCurrentProject?.(p); },
        });
      }
      // Map-only actions
      out.push(
        { kind: 'action', id: 'toggle-heatmap', label: 'Toggle population heatmap',     sub: '',                icon: Layers,    group: 'Map',     run: () => mapStore.toggleHeatmap() },
        { kind: 'action', id: 'favorites-only', label: 'Favorites only',                sub: '',                icon: Star,      group: 'Map',     run: () => mapStore.toggleFavoritesOnly() },
        { kind: 'action', id: 'map-clean',      label: 'Map: Clean style',              sub: '',                icon: Layers,    group: 'Map',     run: () => uiPrefs.setMapStyle('clean') },
        { kind: 'action', id: 'map-detailed',   label: 'Map: Detailed style',           sub: '',                icon: Layers,    group: 'Map',     run: () => uiPrefs.setMapStyle('detailed') },
        { kind: 'action', id: 'toggle-labels',  label: 'Toggle polygon labels',         sub: '',                icon: Building2, group: 'Map',     run: () => uiPrefs.togglePolygonLabels() },
      );
    }

    // ── Settings (always) ────────────────────────────────────────────
    out.push(
      { kind: 'action', id: 'settings',    label: 'Open settings',  sub: 'profile',         icon: Settings, group: 'Account', run: () => navigate('/settings/profile') },
      { kind: 'action', id: 'billing',     label: 'Open billing',   sub: 'plans + invoices', icon: Settings, group: 'Account', run: () => navigate('/settings/billing') },
    );

    return out;
  }, [
    restaurants, currentRestaurantId, currentRestaurant,
    projectStore, mapStore, uiPrefs, navigate, location.pathname,
  ]);

  // ── Filter + grouping ─────────────────────────────────────────────
  const filtered = useMemo(() => {
    if (!q) return items.slice(0, 24);
    const needle = q.toLowerCase();
    return items
      .filter((it) =>
        it.label.toLowerCase().includes(needle) ||
        it.sub?.toLowerCase().includes(needle) ||
        it.keywords?.toLowerCase().includes(needle) ||
        it.group.toLowerCase().includes(needle),
      )
      .slice(0, 30);
  }, [items, q]);

  useEffect(() => { setCursor(0); }, [q, open]);

  function onKeyDown(e: React.KeyboardEvent) {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setCursor((c) => Math.min(filtered.length - 1, c + 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setCursor((c) => Math.max(0, c - 1));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      runAt(cursor);
    }
  }

  async function runAt(i: number) {
    const it = filtered[i];
    if (!it) return;
    const r = it.run();
    // Allow `run` to be async — close once the work kicks off.
    setOpen(false);
    if (r instanceof Promise) {
      try { await r; } catch { /* surfaced via toast in the handler */ }
    }
  }

  if (!open) return null;

  // ── Render with group headers ─────────────────────────────────────
  let lastGroup: string | null = null;

  return createPortal(
    <div
      className="fixed inset-0 z-[200] flex items-start justify-center pt-[14vh] px-3"
      style={{ background: 'rgba(15, 23, 42, 0.45)', backdropFilter: 'blur(4px)' }}
      onClick={() => setOpen(false)}
      role="dialog"
      aria-modal="true"
      aria-label="Command palette"
    >
      <div
        className="card-expand w-[min(620px,100%)] max-h-[72vh] overflow-hidden rounded-xl shadow-float flex flex-col"
        style={{ background: 'white', border: '1px solid var(--line-soft)' }}
        onClick={(e) => e.stopPropagation()}
      >
        <div
          className="flex items-center gap-2 px-3 py-2.5 border-b"
          style={{ borderColor: 'var(--line-soft)' }}
        >
          <Search size={14} style={{ color: 'var(--slate)' }} aria-hidden />
          <input
            ref={inputRef}
            value={q}
            onChange={(e) => setQ(e.target.value)}
            onKeyDown={onKeyDown}
            aria-label="Search commands"
            placeholder={currentRestaurantId
              ? `Jump anywhere · sync · download report · ${currentRestaurant?.name ?? 'restaurant'}…`
              : 'Switch restaurant · sync POS · open vendor map…'}
            className="flex-1 outline-none text-sm bg-transparent"
            style={{ color: 'var(--ink)' }}
          />
          <kbd
            className="text-[10px] px-1.5 py-0.5 rounded"
            style={{ background: 'var(--bg-panel)', color: 'var(--slate)' }}
          >Esc</kbd>
        </div>

        <ul className="overflow-y-auto flex-1 py-1" role="listbox" aria-label="Commands">
          {filtered.length === 0 && (
            <li className="px-4 py-6 text-center text-xs" style={{ color: 'var(--slate)' }}>
              No matches
            </li>
          )}
          {filtered.map((it, i) => {
            const Icon = it.icon ?? Search;
            const active = i === cursor;
            const showHeader = it.group !== lastGroup;
            lastGroup = it.group;
            return (
              <li key={`${it.kind}-${it.id}`}>
                {showHeader && (
                  <div
                    aria-hidden
                    className="px-3 pt-2 pb-1 text-[10px] font-bold uppercase tracking-wider"
                    style={{ color: 'var(--slate)' }}
                  >
                    {it.group}
                  </div>
                )}
                <button
                  type="button"
                  role="option"
                  aria-selected={active}
                  className="w-full text-left flex items-center gap-2.5 px-3 py-2 text-sm"
                  style={{
                    background: active ? 'var(--brand-light)' : 'transparent',
                    color: active ? 'var(--brand)' : 'var(--ink)',
                  }}
                  onMouseEnter={() => setCursor(i)}
                  onClick={() => runAt(i)}
                >
                  <Icon
                    size={14}
                    className="shrink-0"
                    style={{ color: active ? 'var(--brand)' : 'var(--slate)' }}
                    aria-hidden
                  />
                  <span className="flex-1 truncate font-medium">{it.label}</span>
                  {it.sub && (
                    <span
                      className="text-[11px] shrink-0"
                      style={{ color: active ? 'var(--brand)' : 'var(--slate)' }}
                    >
                      {it.sub}
                    </span>
                  )}
                  {it.kind === 'restaurant' && it.id === currentRestaurantId && (
                    <span aria-hidden style={{ color: 'var(--money-positive)' }}>✓</span>
                  )}
                </button>
              </li>
            );
          })}
        </ul>

        <div
          className="border-t px-3 py-1.5 flex items-center justify-between text-[10px]"
          style={{ borderColor: 'var(--line-soft)', color: 'var(--slate)' }}
        >
          <span className="flex items-center gap-2">
            <kbd className="px-1 py-0.5 rounded" style={{ background: 'var(--bg-panel)' }}>↑↓</kbd> navigate
            <kbd className="px-1 py-0.5 rounded" style={{ background: 'var(--bg-panel)' }}>↵</kbd> select
            <span className="hidden sm:inline">
              · <kbd className="px-1 py-0.5 rounded" style={{ background: 'var(--bg-panel)' }}>g</kbd> →
              <kbd className="ml-0.5 px-1 py-0.5 rounded" style={{ background: 'var(--bg-panel)' }}>r</kbd>
              <ArrowLeftRight size={9} className="inline ml-1 align-baseline" aria-hidden />
              <span className="ml-1">switch</span>
            </span>
          </span>
          <span>
            Open <kbd className="px-1 py-0.5 rounded" style={{ background: 'var(--bg-panel)' }}>Ctrl+/</kbd>
          </span>
        </div>
      </div>
    </div>,
    document.body,
  );
}

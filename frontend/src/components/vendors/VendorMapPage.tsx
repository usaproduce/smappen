import { useEffect, useMemo, useRef, useState } from 'react';
import toast from 'react-hot-toast';
import { GoogleMap, useJsApiLoader, Marker, InfoWindow } from '@react-google-maps/api';
import {
  Search, Crosshair, X, Star, List, Map as MapIcon, SlidersHorizontal, ChevronRight,
} from 'lucide-react';
import AppNav from '../layout/AppNav';
import {
  vendorMapApi,
  type VendorPin, type VendorServesRow, type VendorDetail,
} from '../../api/vendorMap';
import { useVendorMapStore, type VendorTypeFilter, type VendorCategoryFilter } from '../../stores/vendorMapStore';
import VendorSidePanel from './VendorSidePanel';
import VendorCoveragePolygons from './VendorCoveragePolygons';
import AffiliatedBadge from './AffiliatedBadge';
import { SkeletonBlock } from '../carafe';
import { GOOGLE_MAPS_LIBRARIES } from '../../utils/mapsLoader';

/**
 * Spec §6.1 + §6.2.
 *
 * Default view: full-bleed Google Map, US-centered. Pins fetch via
 * /api/vendors/map/bbox on idle. A "drop a pin" mode fires
 * /api/vendors/map/serves to answer "who actually reaches THIS point?"
 *
 * Pin styling encodes vendor type. Affiliated vendors get a brand-violet
 * halo + a tasteful AffiliatedBadge in the side panel and in result rows.
 * When a vendor is selected, that vendor's coverage geometry paints over
 * the map — Douglas-Peucker simplified tiers swap by zoom so detail
 * holds at street level and metro-level reads cleanly.
 */

const US_CENTER = { lat: 39.8283, lng: -98.5795 };
const US_DEFAULT_ZOOM = 4;

const TYPES: Array<{ k: VendorTypeFilter; label: string; color: string }> = [
  { k: '',                       label: 'All types',  color: '#94a3b8' },
  { k: 'broadline',              label: 'Broadline',  color: '#7848BB' },
  { k: 'warehouse',              label: 'Warehouse',  color: '#0ea5e9' },
  { k: 'produce',                label: 'Produce',    color: '#10b981' },
  { k: 'protein',                label: 'Meat',       color: '#dc2626' },
  { k: 'seafood',                label: 'Seafood',    color: '#06b6d4' },
  { k: 'specialty',              label: 'Specialty',  color: '#f59e0b' },
  { k: 'grocery',                label: 'Grocery',    color: '#64748b' },
  { k: 'bakery_dairy_beverage',  label: 'Bakery/Dairy/Bev', color: '#a855f7' },
];

const CATEGORIES: Array<{ k: VendorCategoryFilter; label: string }> = [
  { k: '',                    label: 'Any category' },
  { k: 'produce',             label: 'Produce' },
  { k: 'meat',                label: 'Meat' },
  { k: 'poultry',             label: 'Poultry' },
  { k: 'seafood',             label: 'Seafood' },
  { k: 'dairy',               label: 'Dairy' },
  { k: 'dry_goods',           label: 'Dry goods' },
  { k: 'frozen',              label: 'Frozen' },
  { k: 'bakery',              label: 'Bakery' },
  { k: 'beverage',            label: 'Beverage' },
  { k: 'paper_disposables',   label: 'Paper / disposables' },
  { k: 'cleaning_chemical',   label: 'Cleaning' },
  { k: 'specialty_imported',  label: 'Specialty / imported' },
];

export default function VendorMapPage() {
  const apiKey = (import.meta as any).env?.VITE_GOOGLE_MAPS_API_KEY ?? '';
  const { isLoaded, loadError } = useJsApiLoader({ googleMapsApiKey: apiKey, libraries: GOOGLE_MAPS_LIBRARIES });

  const {
    q, type, category, minRating, affiliatedOnly,
    pins, setPins,
    servesPin, servesResults, servesLoading, setServes, setServesLoading,
    selectedVendorId, selectVendor,
    setFilter,
  } = useVendorMapStore();

  const [map, setMap] = useState<google.maps.Map | null>(null);
  const [zoom, setZoom] = useState(US_DEFAULT_ZOOM);
  const [pinMode, setPinMode] = useState(false);
  const [view, setView] = useState<'map' | 'list'>('map');
  const [hoveredPinId, setHoveredPinId] = useState<string | null>(null);
  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false);
  const [selectedDetail, setSelectedDetail] = useState<VendorDetail | null>(null);
  const bboxFetchTimer = useRef<number | null>(null);

  // Track zoom so coverage polygons pick the right simplified tier.
  useEffect(() => {
    if (!map) return;
    const listener = map.addListener('zoom_changed', () => {
      const z = map.getZoom();
      if (typeof z === 'number') setZoom(z);
    });
    return () => google.maps.event.removeListener(listener);
  }, [map]);

  // Bbox-debounced fetch of pins.
  useEffect(() => {
    if (!map) return;
    const handler = () => {
      if (bboxFetchTimer.current) window.clearTimeout(bboxFetchTimer.current);
      bboxFetchTimer.current = window.setTimeout(async () => {
        const b = map.getBounds();
        if (!b) return;
        const ne = b.getNorthEast(); const sw = b.getSouthWest();
        let minLat = sw.lat(), maxLat = ne.lat();
        let minLng = sw.lng(), maxLng = ne.lng();
        if (minLng >= maxLng) { minLng = -180; maxLng = 180; }
        if (minLat >= maxLat) { minLat = -85;  maxLat = 85;  }
        minLat = Math.max(-85, Math.min(85, minLat));
        maxLat = Math.max(-85, Math.min(85, maxLat));
        minLng = Math.max(-180, Math.min(180, minLng));
        maxLng = Math.max(-180, Math.min(180, maxLng));

        try {
          const rows = await vendorMapApi.bbox({
            minLat, minLng, maxLat, maxLng,
            type: type || undefined,
            category: category || undefined,
          });
          setPins(rows);
        } catch (e: any) {
          if (!String(e?.response?.data?.error ?? '').includes('bbox')) {
            toast.error(e?.response?.data?.error ?? 'Failed to load vendors');
          } else if (import.meta.env.DEV) {
            console.warn('bbox query skipped:', e?.response?.data?.error);
          }
        }
      }, 350);
    };
    const idle = map.addListener('idle', handler);
    handler();
    return () => {
      google.maps.event.removeListener(idle);
      if (bboxFetchTimer.current) window.clearTimeout(bboxFetchTimer.current);
    };
  }, [map, type, category, setPins]);

  // Fetch vendor detail when a vendor is selected — paints the coverage
  // polygon overlay on the map. The side panel also fetches its own detail
  // for the rest of the surface (reviews, locations, etc.), but pulling
  // here keeps the polygon overlay decoupled from panel mount/unmount.
  useEffect(() => {
    if (!selectedVendorId) { setSelectedDetail(null); return; }
    let cancelled = false;
    (async () => {
      try {
        const d = await vendorMapApi.detail(selectedVendorId);
        if (!cancelled) setSelectedDetail(d);
      } catch {
        if (!cancelled) setSelectedDetail(null);
      }
    })();
    return () => { cancelled = true; };
  }, [selectedVendorId]);

  // Client-side filter chain (rating + affiliated). q is a name-substring filter.
  const visiblePins = useMemo(() => {
    const qLower = q.trim().toLowerCase();
    return pins.filter((p) => {
      if (qLower && !p.vendor_name.toLowerCase().includes(qLower)) return false;
      if (affiliatedOnly && !p.is_affiliated) return false;
      if (minRating > 0 && (!p.aggregate_rating || p.aggregate_rating < minRating)) return false;
      return true;
    });
  }, [pins, q, affiliatedOnly, minRating]);

  // Drop-a-pin handler — fires the "who serves me" query.
  async function handleMapClick(ev: google.maps.MapMouseEvent) {
    if (!pinMode || !ev.latLng) return;
    const lat = ev.latLng.lat();
    const lng = ev.latLng.lng();
    setServesLoading(true);
    // Optimistically open the panel BEFORE the network round-trip so the
    // operator sees the pin land + the skeleton appear inside ~16ms —
    // hits the spec's "feels instant" criterion even on slow links.
    setServes({ lat, lng }, []);
    try {
      const r = await vendorMapApi.serves({ lat, lng });
      setServes(r.pin, r.vendors);
      setPinMode(false);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Who-serves-me query failed');
    } finally {
      setServesLoading(false);
    }
  }

  if (loadError) {
    return (
      <div className="min-h-screen" style={{ background: 'var(--bg)' }}>
        <AppNav />
        <div className="max-w-7xl mx-auto px-6 py-8 text-center" style={{ color: 'var(--slate)' }}>
          Could not load Google Maps.
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col" style={{ background: 'var(--bg)' }}>
      <AppNav />

      <FilterStrip
        q={q}
        type={type}
        category={category}
        minRating={minRating}
        affiliatedOnly={affiliatedOnly}
        setFilter={setFilter}
        pinMode={pinMode}
        onPinMode={() => setPinMode((v) => !v)}
        view={view}
        onView={setView}
        mobileOpen={mobileFiltersOpen}
        onMobileOpen={setMobileFiltersOpen}
      />

      <div className="flex-1 relative">
        {!isLoaded ? (
          <div className="absolute inset-0 grid place-items-center text-sm" style={{ color: 'var(--slate)' }}>
            Loading map…
          </div>
        ) : (
          <>
            <div style={{ position: 'absolute', inset: 0, visibility: view === 'map' ? 'visible' : 'hidden' }}>
              <GoogleMap
                mapContainerStyle={{ width: '100%', height: '100%' }}
                center={US_CENTER}
                zoom={US_DEFAULT_ZOOM}
                onLoad={setMap}
                onUnmount={() => setMap(null)}
                onClick={handleMapClick}
                options={{
                  mapTypeControl: false,
                  streetViewControl: false,
                  fullscreenControl: false,
                  draggableCursor: pinMode ? 'crosshair' : undefined,
                }}
              >
                {/* Selected vendor's coverage geometry — picks tier by zoom */}
                {selectedDetail && selectedDetail.coverage.length > 0 && (
                  <VendorCoveragePolygons
                    vendor={selectedDetail.vendor}
                    coverage={selectedDetail.coverage}
                    zoom={zoom}
                  />
                )}

                {visiblePins.map((p) => (
                  <Marker
                    key={p.id}
                    position={{ lat: p.lat, lng: p.lng }}
                    onClick={() => selectVendor(p.vendor_id)}
                    onMouseOver={() => setHoveredPinId(p.id)}
                    onMouseOut={() => setHoveredPinId(null)}
                    icon={pinIcon(p)}
                    title={p.vendor_name}
                  />
                ))}

                {hoveredPinId && (() => {
                  const p = visiblePins.find((x) => x.id === hoveredPinId);
                  if (!p) return null;
                  return (
                    <InfoWindow position={{ lat: p.lat, lng: p.lng }}>
                      <div className="text-xs">
                        <div className="font-bold" style={{ color: 'var(--ink)' }}>{p.vendor_name}</div>
                        {p.label && <div style={{ color: 'var(--slate)' }}>{p.label}</div>}
                        {!!p.is_affiliated && (
                          <div className="text-[10px] font-bold uppercase tracking-wider mt-0.5" style={{ color: 'var(--brand)' }}>
                            Affiliated
                          </div>
                        )}
                        {p.aggregate_rating !== null && (
                          <div className="mt-0.5" style={{ color: '#b45309' }}>
                            ★ {p.aggregate_rating.toFixed(1)} ({p.rating_count})
                          </div>
                        )}
                      </div>
                    </InfoWindow>
                  );
                })()}

                {servesPin && (
                  <Marker
                    position={servesPin}
                    icon={{
                      path: google.maps.SymbolPath.CIRCLE,
                      scale: 10,
                      fillColor: 'var(--cta)' as any,
                      fillOpacity: 0.9,
                      strokeColor: '#fff',
                      strokeWeight: 3,
                    }}
                    zIndex={999}
                  />
                )}
              </GoogleMap>
            </div>

            {view === 'list' && <ListView pins={visiblePins} onSelect={selectVendor} />}

            {servesPin && view === 'map' && (
              <ServesPanel
                rows={servesResults}
                loading={servesLoading}
                onClose={() => setServes(null, [])}
                onSelect={selectVendor}
              />
            )}

            {selectedVendorId && (
              <VendorSidePanel
                vendorId={selectedVendorId}
                onClose={() => selectVendor(null)}
              />
            )}
          </>
        )}
      </div>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────────────
 * Filter strip — full-width toolbar on desktop, collapses to a single
 * "Filters" button on phones. Search + type stay visible at any width
 * so the operator can find a vendor without opening the disclosure.
 * ────────────────────────────────────────────────────────────────────── */
function FilterStrip({
  q, type, category, minRating, affiliatedOnly,
  setFilter,
  pinMode, onPinMode,
  view, onView,
  mobileOpen, onMobileOpen,
}: {
  q: string;
  type: VendorTypeFilter;
  category: VendorCategoryFilter;
  minRating: number;
  affiliatedOnly: boolean;
  setFilter: ReturnType<typeof useVendorMapStore.getState>['setFilter'];
  pinMode: boolean;
  onPinMode: () => void;
  view: 'map' | 'list';
  onView: (v: 'map' | 'list') => void;
  mobileOpen: boolean;
  onMobileOpen: (v: boolean) => void;
}) {
  const activeFilterCount =
    (category ? 1 : 0) +
    (minRating > 0 ? 1 : 0) +
    (affiliatedOnly ? 1 : 0);

  return (
    <div className="border-b" style={{ background: 'white', borderColor: 'var(--line-soft)' }}>
      <div className="max-w-7xl mx-auto px-3 sm:px-6 py-2 flex items-center gap-2 flex-wrap">
        {/* Search — visible everywhere */}
        <div className="relative flex-1 min-w-0" style={{ maxWidth: 360 }}>
          <Search
            size={14}
            className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
            style={{ color: 'var(--slate)' }}
          />
          <input
            type="text"
            aria-label="Search vendors by name"
            className="h-11 text-sm w-full pl-9 pr-3 rounded-lg focus:outline-none focus:ring-2"
            style={{
              border: '1px solid var(--line)',
              background: 'white',
              color: 'var(--ink)',
            }}
            placeholder="Search vendor by name…"
            value={q}
            onChange={(e) => setFilter('q', e.target.value)}
          />
        </div>

        {/* Type — visible everywhere */}
        <select
          aria-label="Vendor type"
          className="h-11 text-sm rounded-lg px-3 focus:outline-none focus:ring-2 flex-shrink-0"
          style={{ border: '1px solid var(--line)', background: 'white', color: 'var(--ink)' }}
          value={type}
          onChange={(e) => setFilter('type', e.target.value as VendorTypeFilter)}
        >
          {TYPES.map((t) => <option key={t.k} value={t.k}>{t.label}</option>)}
        </select>

        {/* Desktop-only extra filters */}
        <div className="hidden md:flex items-center gap-2 flex-wrap">
          <select
            aria-label="Category"
            className="h-11 text-sm rounded-lg px-3 focus:outline-none focus:ring-2"
            style={{ border: '1px solid var(--line)', background: 'white', color: 'var(--ink)' }}
            value={category}
            onChange={(e) => setFilter('category', e.target.value as VendorCategoryFilter)}
          >
            {CATEGORIES.map((c) => <option key={c.k} value={c.k}>{c.label}</option>)}
          </select>
          <select
            aria-label="Minimum rating"
            className="h-11 text-sm rounded-lg px-3 focus:outline-none focus:ring-2"
            style={{ border: '1px solid var(--line)', background: 'white', color: 'var(--ink)' }}
            value={String(minRating)}
            onChange={(e) => setFilter('minRating', Number(e.target.value))}
          >
            <option value="0">Any rating</option>
            <option value="3">3★+</option>
            <option value="4">4★+</option>
            <option value="4.5">4.5★+</option>
          </select>
          <label
            className="inline-flex items-center gap-1.5 text-xs font-semibold cursor-pointer whitespace-nowrap px-2 h-11"
            style={{ color: 'var(--ink)' }}
          >
            <input
              type="checkbox"
              className="rounded focus:ring-violet-400"
              style={{ accentColor: 'var(--brand)' }}
              checked={affiliatedOnly}
              onChange={(e) => setFilter('affiliatedOnly', e.target.checked)}
            />
            Affiliated only
          </label>
        </div>

        {/* Mobile filters disclosure */}
        <button
          type="button"
          aria-expanded={mobileOpen}
          aria-controls="mobile-filters"
          onClick={() => onMobileOpen(!mobileOpen)}
          className="md:hidden inline-flex items-center gap-1.5 h-11 px-3 rounded-lg text-sm font-semibold relative"
          style={{ border: '1px solid var(--line)', background: 'white', color: 'var(--ink)' }}
        >
          <SlidersHorizontal size={14} />
          Filters
          {activeFilterCount > 0 && (
            <span
              className="inline-flex items-center justify-center h-4 min-w-[16px] px-1 rounded-full text-[10px] font-bold ml-0.5"
              style={{ background: 'var(--brand)', color: 'white' }}
            >
              {activeFilterCount}
            </span>
          )}
        </button>

        <div className="flex-1 hidden md:block" />

        <button
          type="button"
          onClick={onPinMode}
          aria-pressed={pinMode}
          aria-label={pinMode ? 'Cancel drop-a-pin' : 'Drop a pin to find vendors that serve a point'}
          className="inline-flex items-center gap-1.5 h-11 px-3 rounded-lg text-sm font-bold flex-shrink-0"
          style={{
            background: pinMode ? 'var(--brand)' : 'white',
            color: pinMode ? 'white' : 'var(--ink)',
            border: pinMode ? '1px solid var(--brand)' : '1px solid var(--line)',
          }}
          title="Drop a pin → who serves me?"
        >
          <Crosshair size={14} />
          <span className="hidden sm:inline">{pinMode ? 'Click the map…' : 'Who serves me?'}</span>
          <span className="sm:hidden">{pinMode ? 'Click map' : 'Who?'}</span>
        </button>

        <div
          role="tablist"
          aria-label="View"
          className="rounded-lg p-0.5 flex items-center flex-shrink-0"
          style={{ background: 'var(--bg-panel)' }}
        >
          <button
            role="tab"
            aria-selected={view === 'map'}
            aria-label="Map view"
            className="inline-flex items-center gap-1 h-10 px-3 rounded-md text-xs font-bold"
            style={{
              background: view === 'map' ? 'white' : 'transparent',
              color: view === 'map' ? 'var(--ink)' : 'var(--slate)',
              boxShadow: view === 'map' ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
            }}
            onClick={() => onView('map')}
          >
            <MapIcon size={12} /> Map
          </button>
          <button
            role="tab"
            aria-selected={view === 'list'}
            aria-label="List view"
            className="inline-flex items-center gap-1 h-10 px-3 rounded-md text-xs font-bold"
            style={{
              background: view === 'list' ? 'white' : 'transparent',
              color: view === 'list' ? 'var(--ink)' : 'var(--slate)',
              boxShadow: view === 'list' ? '0 1px 2px rgba(0,0,0,0.05)' : 'none',
            }}
            onClick={() => onView('list')}
          >
            <List size={12} /> List
          </button>
        </div>
      </div>

      {/* Mobile filters disclosure panel */}
      {mobileOpen && (
        <div
          id="mobile-filters"
          className="md:hidden border-t px-3 py-3 flex flex-col gap-3"
          style={{ borderColor: 'var(--line-soft)', background: 'white' }}
        >
          <label className="flex flex-col gap-1">
            <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>Category</span>
            <select
              className="h-11 text-sm rounded-lg px-3 w-full"
              style={{ border: '1px solid var(--line)', background: 'white', color: 'var(--ink)' }}
              value={category}
              onChange={(e) => setFilter('category', e.target.value as VendorCategoryFilter)}
            >
              {CATEGORIES.map((c) => <option key={c.k} value={c.k}>{c.label}</option>)}
            </select>
          </label>
          <label className="flex flex-col gap-1">
            <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--slate)' }}>Minimum rating</span>
            <select
              className="h-11 text-sm rounded-lg px-3 w-full"
              style={{ border: '1px solid var(--line)', background: 'white', color: 'var(--ink)' }}
              value={String(minRating)}
              onChange={(e) => setFilter('minRating', Number(e.target.value))}
            >
              <option value="0">Any rating</option>
              <option value="3">3★+</option>
              <option value="4">4★+</option>
              <option value="4.5">4.5★+</option>
            </select>
          </label>
          <label
            className="inline-flex items-center gap-2 text-sm font-semibold cursor-pointer min-h-[44px]"
            style={{ color: 'var(--ink)' }}
          >
            <input
              type="checkbox"
              style={{ accentColor: 'var(--brand)' }}
              checked={affiliatedOnly}
              onChange={(e) => setFilter('affiliatedOnly', e.target.checked)}
            />
            Affiliated only
          </label>
          <button
            type="button"
            onClick={() => onMobileOpen(false)}
            className="inline-flex items-center justify-center h-11 rounded-lg font-bold text-sm text-white"
            style={{ background: 'var(--brand)' }}
          >
            Done
          </button>
        </div>
      )}
    </div>
  );
}

/* ── pin icon ───────────────────────────────────────────────────────── */
function pinIcon(p: VendorPin): google.maps.Symbol {
  const color = TYPES.find((t) => t.k === (p.type as any))?.color ?? '#64748b';
  if (p.is_affiliated) {
    return {
      path: google.maps.SymbolPath.CIRCLE,
      scale: 9,
      fillColor: color,
      fillOpacity: 0.95,
      strokeColor: '#7848BB',
      strokeWeight: 3,
    };
  }
  return {
    path: google.maps.SymbolPath.CIRCLE,
    scale: 6,
    fillColor: color,
    fillOpacity: 0.85,
    strokeColor: '#fff',
    strokeWeight: 1.5,
  };
}

/* ── List view ──────────────────────────────────────────────────────── */
function ListView({ pins, onSelect }: { pins: VendorPin[]; onSelect: (id: string) => void }) {
  if (pins.length === 0) {
    return (
      <div className="absolute inset-0 grid place-items-center text-sm" style={{ color: 'var(--slate)' }}>
        No vendors visible. Pan the map or relax your filters.
      </div>
    );
  }
  return (
    <div className="absolute inset-0 overflow-y-auto px-3 sm:px-6 py-6" style={{ background: 'var(--bg)' }}>
      <ul className="max-w-3xl mx-auto space-y-2">
        {pins.map((p, i) => (
          <li key={p.id} className="stagger-in" style={{ ['--stagger-i' as any]: i }}>
            <button
              onClick={() => onSelect(p.vendor_id)}
              className="w-full text-left rounded-xl p-3 flex items-center gap-3 transition border"
              style={{ background: 'white', borderColor: 'var(--line-soft)' }}
            >
              <span
                className="inline-flex items-center justify-center w-11 h-11 rounded-lg font-bold text-white flex-shrink-0"
                style={{ background: TYPES.find((t) => t.k === (p.type as any))?.color ?? '#64748b' }}
              >
                {p.vendor_name.charAt(0).toUpperCase()}
              </span>
              <div className="flex-1 min-w-0">
                <div className="font-bold text-sm flex items-center gap-2" style={{ color: 'var(--ink)' }}>
                  <span className="truncate">{p.vendor_name}</span>
                  {!!p.is_affiliated && <AffiliatedBadge variant="icon" />}
                </div>
                <div className="text-xs" style={{ color: 'var(--slate)' }}>
                  {p.label} · {p.type ?? p.primary_category}
                  {p.aggregate_rating !== null && (
                    <> · <Star size={10} className="inline" style={{ color: '#b45309' }} /> {p.aggregate_rating.toFixed(1)} ({p.rating_count})</>
                  )}
                </div>
              </div>
              <ChevronRight size={16} style={{ color: 'var(--slate)' }} className="flex-shrink-0" />
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────────────
 * "Who serves this pin" panel — slides in from the right when a pin is
 * dropped. Shows a layout-shaped skeleton during the ~1-2s query.
 * ────────────────────────────────────────────────────────────────────── */
function ServesPanel({
  rows, loading, onClose, onSelect,
}: { rows: VendorServesRow[]; loading: boolean; onClose: () => void; onSelect: (id: string) => void }) {
  return (
    <aside
      className="panel-slide-right absolute top-3 right-3 sm:top-4 sm:right-4 w-[min(96vw,380px)] max-h-[calc(100%-1.5rem)] overflow-hidden rounded-xl shadow-float flex flex-col z-20"
      style={{ background: 'white', border: '1px solid var(--line-soft)' }}
    >
      <header
        className="flex items-center justify-between px-4 py-3 border-b"
        style={{ borderColor: 'var(--line-soft)' }}
      >
        <div className="min-w-0">
          <div
            className="text-[10px] font-bold uppercase tracking-wider"
            style={{ color: 'var(--brand)' }}
          >
            Who serves this pin
          </div>
          <div className="font-bold text-sm" style={{ color: 'var(--ink)' }}>
            {loading
              ? 'Searching…'
              : `${rows.length} vendor${rows.length === 1 ? '' : 's'}`}
          </div>
        </div>
        <button
          aria-label="Close"
          className="inline-flex items-center justify-center w-11 h-11 rounded-lg hover:bg-slate-50"
          style={{ color: 'var(--slate)' }}
          onClick={onClose}
        >
          <X size={16} />
        </button>
      </header>

      <div className="overflow-y-auto p-3 space-y-2">
        {loading ? (
          // Layout-shaped skeleton: three placeholder rows that match the
          // real result row's silhouette so the panel doesn't pop on load.
          [0, 1, 2].map((i) => (
            <div
              key={i}
              className="rounded-lg p-3 flex items-center gap-3"
              style={{ background: 'var(--bg-panel)' }}
              aria-hidden
            >
              <SkeletonBlock className="rounded-md flex-shrink-0" style={{ width: 36, height: 36 }} />
              <div className="flex-1 flex flex-col gap-1.5">
                <SkeletonBlock className="h-3 w-2/3" />
                <SkeletonBlock className="h-2.5 w-1/2" />
              </div>
              <SkeletonBlock className="h-3 w-10" />
            </div>
          ))
        ) : rows.length === 0 ? (
          <div className="text-sm text-center py-6" style={{ color: 'var(--slate)' }}>
            No vendors cover this point. Try a denser metro or check filters.
          </div>
        ) : (
          rows.map((r, i) => (
            <button
              key={r.vendor_id}
              onClick={() => onSelect(r.vendor_id)}
              className="stagger-in w-full text-left rounded-lg p-2.5 transition flex items-center gap-2"
              style={{ background: 'var(--bg-panel)', ['--stagger-i' as any]: i }}
              onMouseEnter={(e) => { (e.currentTarget as HTMLButtonElement).style.background = 'var(--brand-light)'; }}
              onMouseLeave={(e) => { (e.currentTarget as HTMLButtonElement).style.background = 'var(--bg-panel)'; }}
            >
              <div className="flex-1 min-w-0">
                <div className="font-bold text-sm flex items-center gap-1.5 min-w-0" style={{ color: 'var(--ink)' }}>
                  <span className="truncate">{r.vendor_name}</span>
                  {!!r.is_affiliated && <AffiliatedBadge variant="icon" />}
                </div>
                <div className="text-[11px] mt-0.5" style={{ color: 'var(--slate)' }}>
                  {r.type ?? r.primary_category}
                  {r.distance_miles !== null && <> · {r.distance_miles.toFixed(1)} mi</>}
                  <> · {r.coverage_type.replace('_', ' ')}</>
                </div>
              </div>
              {r.aggregate_rating !== null && (
                <div
                  className="text-xs font-bold whitespace-nowrap"
                  style={{ color: '#b45309' }}
                >
                  ★ {r.aggregate_rating.toFixed(1)}
                </div>
              )}
            </button>
          ))
        )}
      </div>
    </aside>
  );
}

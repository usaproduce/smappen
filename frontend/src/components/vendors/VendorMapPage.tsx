import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { GoogleMap, useJsApiLoader, Marker, InfoWindow } from '@react-google-maps/api';
import { Search, MapPin, Crosshair, ShieldCheck, X, Star, List, Map as MapIcon } from 'lucide-react';
import AppNav from '../layout/AppNav';
import { vendorMapApi, type VendorPin, type VendorServesRow } from '../../api/vendorMap';
import { useVendorMapStore, type VendorTypeFilter, type VendorCategoryFilter } from '../../stores/vendorMapStore';
import VendorSidePanel from './VendorSidePanel';

/**
 * The map-first vendor product (spec §6.1 + §6.2).
 *
 * Default view: full-bleed Google Map, full-US-centered. As the viewport
 * settles, pins fetch via /api/vendors/map/bbox. A "drop a pin" mode
 * fires /api/vendors/map/serves to answer the spec's central question:
 * who actually reaches THIS point?
 *
 * Pin styling encodes vendor type. Affiliated vendors get a violet halo
 * + a "USA Produce — affiliated supplier" label in the InfoWindow.
 */

const LIBRARIES: any[] = ['geometry'];
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
  const { isLoaded, loadError } = useJsApiLoader({ googleMapsApiKey: apiKey, libraries: LIBRARIES });

  const {
    q, type, category, minRating, affiliatedOnly,
    pins, setPins,
    servesPin, servesResults, servesLoading, setServes, setServesLoading,
    selectedVendorId, selectVendor,
    setFilter,
  } = useVendorMapStore();

  const [map, setMap] = useState<google.maps.Map | null>(null);
  const [pinMode, setPinMode] = useState(false);
  const [view, setView] = useState<'map' | 'list'>('map');
  const [hoveredPinId, setHoveredPinId] = useState<string | null>(null);
  const bboxFetchTimer = useRef<number | null>(null);

  // Fetch pins for the current viewport, debounced.
  useEffect(() => {
    if (!map) return;
    const handler = () => {
      if (bboxFetchTimer.current) window.clearTimeout(bboxFetchTimer.current);
      bboxFetchTimer.current = window.setTimeout(async () => {
        const b = map.getBounds();
        if (!b) return;
        const ne = b.getNorthEast(); const sw = b.getSouthWest();
        try {
          const rows = await vendorMapApi.bbox({
            minLat: sw.lat(), minLng: sw.lng(),
            maxLat: ne.lat(), maxLng: ne.lng(),
            type: type || undefined,
            category: category || undefined,
          });
          setPins(rows);
        } catch (e: any) {
          toast.error(e?.response?.data?.error ?? 'Failed to load vendors');
        }
      }, 350);
    };
    const idle = map.addListener('idle', handler);
    handler(); // initial
    return () => {
      google.maps.event.removeListener(idle);
      if (bboxFetchTimer.current) window.clearTimeout(bboxFetchTimer.current);
    };
  }, [map, type, category, setPins]);

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
      <div className="min-h-screen bg-slate-50">
        <AppNav />
        <div className="max-w-7xl mx-auto px-6 py-8 text-center text-slate-500">Could not load Google Maps.</div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col">
      <AppNav />

      {/* Filter strip */}
      <div className="bg-white border-b border-slate-200">
        <div className="max-w-7xl mx-auto px-6 py-2 flex items-center gap-2 flex-wrap">
          <div className="relative flex-1 min-w-[200px] max-w-md">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              className="input h-9 text-sm w-full pl-9"
              placeholder="Search vendor by name…"
              value={q}
              onChange={(e) => setFilter('q', e.target.value)}
            />
          </div>
          <select
            className="input h-9 text-sm"
            value={type}
            onChange={(e) => setFilter('type', e.target.value as VendorTypeFilter)}
          >
            {TYPES.map((t) => <option key={t.k} value={t.k}>{t.label}</option>)}
          </select>
          <select
            className="input h-9 text-sm"
            value={category}
            onChange={(e) => setFilter('category', e.target.value as VendorCategoryFilter)}
          >
            {CATEGORIES.map((c) => <option key={c.k} value={c.k}>{c.label}</option>)}
          </select>
          <select
            className="input h-9 text-sm"
            value={String(minRating)}
            onChange={(e) => setFilter('minRating', Number(e.target.value))}
            title="Minimum rating"
          >
            <option value="0">Any rating</option>
            <option value="3">3★+</option>
            <option value="4">4★+</option>
            <option value="4.5">4.5★+</option>
          </select>
          <label className="flex items-center gap-1 text-xs font-semibold text-slate-700 cursor-pointer">
            <input type="checkbox" checked={affiliatedOnly} onChange={(e) => setFilter('affiliatedOnly', e.target.checked)} />
            Affiliated only
          </label>

          <div className="flex-1" />

          <button
            className={`btn h-9 px-3 text-sm ${pinMode ? 'btn-primary' : ''}`}
            onClick={() => setPinMode((v) => !v)}
            title="Drop a pin → who serves me?"
          >
            <Crosshair size={14} /> {pinMode ? 'Click the map…' : 'Who serves me?'}
          </button>
          <div className="bg-slate-100 rounded p-0.5 flex items-center">
            <button
              className={`px-2 py-1 rounded text-xs font-semibold flex items-center gap-1 ${view === 'map' ? 'bg-white text-slate-900' : 'text-slate-500'}`}
              onClick={() => setView('map')}
            ><MapIcon size={12} /> Map</button>
            <button
              className={`px-2 py-1 rounded text-xs font-semibold flex items-center gap-1 ${view === 'list' ? 'bg-white text-slate-900' : 'text-slate-500'}`}
              onClick={() => setView('list')}
            ><List size={12} /> List</button>
          </div>
        </div>
      </div>

      {/* Map + side panel */}
      <div className="flex-1 relative">
        {!isLoaded ? (
          <div className="absolute inset-0 grid place-items-center text-slate-500 text-sm">Loading map…</div>
        ) : (
          <>
            {/* Map is ALWAYS mounted so the bbox fetch keeps running even
                while the user is in list view (otherwise list shows empty
                because `map` would be null and the useEffect wouldn't fire).
                Visibility toggles via CSS so the GoogleMap instance stays
                stable across view switches. */}
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
                        <div className="font-bold" style={{ color: '#1A1A2E' }}>{p.vendor_name}</div>
                        {p.label && <div className="text-slate-500">{p.label}</div>}
                        {!!p.is_affiliated && (
                          <div className="text-violet-700 font-bold text-[10px] uppercase tracking-wider mt-0.5">Affiliated</div>
                        )}
                        {p.aggregate_rating !== null && (
                          <div className="text-amber-600 mt-0.5">★ {p.aggregate_rating.toFixed(1)} ({p.rating_count})</div>
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
                      fillColor: '#dc2626',
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

            {/* Drop-a-pin result panel */}
            {servesPin && view === 'map' && (
              <ServesPanel
                rows={servesResults}
                loading={servesLoading}
                onClose={() => setServes(null, [])}
                onSelect={selectVendor}
              />
            )}

            {/* Vendor detail side panel */}
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

// ─────────────────────────────── helpers ───────────────────────────────

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

function ListView({ pins, onSelect }: { pins: VendorPin[]; onSelect: (id: string) => void }) {
  if (pins.length === 0) {
    return <div className="absolute inset-0 grid place-items-center text-sm text-slate-500">No vendors visible. Pan the map or relax your filters.</div>;
  }
  return (
    <div className="absolute inset-0 overflow-y-auto bg-slate-50 px-6 py-6">
      <ul className="max-w-3xl mx-auto space-y-2">
        {pins.map((p) => (
          <li key={p.id}>
            <button
              onClick={() => onSelect(p.vendor_id)}
              className="w-full text-left bg-white border border-slate-200 rounded-xl p-3 flex items-center gap-3 hover:border-violet-300 hover:shadow-sm transition"
            >
              <span
                className="inline-flex items-center justify-center w-10 h-10 rounded-lg font-bold text-white flex-shrink-0"
                style={{ background: TYPES.find((t) => t.k === (p.type as any))?.color ?? '#64748b' }}
              >
                {p.vendor_name.charAt(0).toUpperCase()}
              </span>
              <div className="flex-1 min-w-0">
                <div className="font-bold text-sm flex items-center gap-2" style={{ color: '#1A1A2E' }}>
                  {p.vendor_name}
                  {!!p.is_affiliated && <ShieldCheck size={12} className="text-violet-700" />}
                </div>
                <div className="text-xs text-slate-500">
                  {p.label} · {p.type ?? p.primary_category}
                  {p.aggregate_rating !== null && (
                    <> · <Star size={10} className="inline text-amber-500" /> {p.aggregate_rating.toFixed(1)} ({p.rating_count})</>
                  )}
                </div>
              </div>
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}

function ServesPanel({
  rows, loading, onClose, onSelect,
}: { rows: VendorServesRow[]; loading: boolean; onClose: () => void; onSelect: (id: string) => void }) {
  return (
    <aside className="absolute top-4 right-4 w-96 max-h-[calc(100%-2rem)] overflow-hidden bg-white border border-slate-200 rounded-xl shadow-lg flex flex-col z-20">
      <header className="flex items-center justify-between px-4 py-3 border-b border-slate-200">
        <div>
          <div className="text-[10px] font-bold uppercase tracking-wider text-violet-700">Who serves this pin</div>
          <div className="font-bold text-sm" style={{ color: '#1A1A2E' }}>{rows.length} vendor{rows.length === 1 ? '' : 's'}</div>
        </div>
        <button className="text-slate-400 hover:text-slate-700 p-1 rounded hover:bg-slate-50" onClick={onClose}><X size={14} /></button>
      </header>
      <div className="overflow-y-auto p-3 space-y-2">
        {loading ? (
          <div className="text-sm text-slate-500 text-center py-4">Looking…</div>
        ) : rows.length === 0 ? (
          <div className="text-sm text-slate-500 text-center py-4">No vendors cover this point. Try a denser metro or check filters.</div>
        ) : rows.map((r) => (
          <button
            key={r.vendor_id}
            onClick={() => onSelect(r.vendor_id)}
            className="w-full text-left bg-slate-50 hover:bg-slate-100 rounded-lg p-2 transition"
          >
            <div className="flex items-center gap-2">
              <div className="flex-1 min-w-0">
                <div className="font-bold text-sm flex items-center gap-1" style={{ color: '#1A1A2E' }}>
                  {r.vendor_name}
                  {!!r.is_affiliated && <ShieldCheck size={11} className="text-violet-700" />}
                </div>
                <div className="text-[11px] text-slate-500">
                  {r.type ?? r.primary_category}
                  {r.distance_miles !== null && <> · {r.distance_miles.toFixed(1)} mi</>}
                  <> · {r.coverage_type.replace('_', ' ')}</>
                </div>
              </div>
              {r.aggregate_rating !== null && (
                <div className="text-amber-600 text-xs font-bold whitespace-nowrap">★ {r.aggregate_rating.toFixed(1)}</div>
              )}
            </div>
          </button>
        ))}
      </div>
    </aside>
  );
}


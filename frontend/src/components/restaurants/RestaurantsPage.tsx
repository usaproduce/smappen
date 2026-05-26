import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Plus, Store, ChefHat } from 'lucide-react';
import { restaurantsApi } from '../../api/restaurants';
import { useRestaurantStore, type Restaurant } from '../../stores/restaurantStore';
import AppNav from '../layout/AppNav';
import GooglePlaceAutocomplete from '../common/GooglePlaceAutocomplete';

/**
 * Carafe restaurant gallery. Reuses the dashboard's card grid pattern.
 * Lives outside AppLayout because Phase 1 restaurants don't need the
 * map chrome.
 */
export default function RestaurantsPage() {
  const restaurants = useRestaurantStore((s) => s.restaurants);
  const setRestaurants = useRestaurantStore((s) => s.setRestaurants);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const rs = await restaurantsApi.list();
        if (!cancelled) setRestaurants(rs);
      } catch (e: any) {
        if (!cancelled) toast.error(e?.response?.data?.error ?? 'Failed to load restaurants');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [setRestaurants]);

  async function onCreated(restaurant: Restaurant) {
    setRestaurants([restaurant, ...restaurants]);
    setShowCreate(false);
    toast.success('Restaurant created');
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <AppNav />

      <main className="max-w-7xl mx-auto px-6 py-8">
        <div className="flex items-end justify-between mb-8">
          <div>
            <h1 className="text-3xl font-extrabold flex items-center gap-2" style={{ color: '#1A1A2E' }}>
              <Store size={26} style={{ color: '#7848BB' }} />
              Restaurants
            </h1>
            <p className="text-slate-600 mt-1">
              Connect your POS, see real plate cost on every item, get dollar-quantified pricing moves.
            </p>
          </div>
          <button className="btn btn-primary h-10 px-4 text-sm" onClick={() => setShowCreate(true)}>
            <Plus size={14} /> New restaurant
          </button>
        </div>

        {showCreate && <CreateRestaurantCard onCreated={onCreated} onCancel={() => setShowCreate(false)} />}

        {loading && (
          <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-4 mt-6">
            <div className="skeleton h-32" />
            <div className="skeleton h-32" />
            <div className="skeleton h-32" />
          </div>
        )}

        {!loading && restaurants.length === 0 && !showCreate && (
          <div className="bg-slate-50 rounded-xl p-10 text-center mt-6">
            <ChefHat size={32} className="mx-auto text-slate-400 mb-2" />
            <div className="font-semibold text-slate-700">No restaurants yet</div>
            <div className="text-sm text-slate-500 mt-1">
              Add your first restaurant to start finding money in your menu.
            </div>
          </div>
        )}

        {!loading && restaurants.length > 0 && (
          <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-4 mt-6">
            {restaurants.map((r) => (
              <Link
                key={r.id}
                to={`/app/restaurants/${r.id}`}
                className="bg-white border border-slate-200 rounded-xl p-4 hover:border-violet-300 hover:shadow-sm transition"
              >
                <div className="flex items-center gap-2 mb-2">
                  <span
                    className="inline-flex items-center justify-center w-9 h-9 rounded-lg text-white font-bold"
                    style={{ background: r.is_sample ? '#7848BB' : '#1A1A2E' }}
                  >
                    {r.name.charAt(0).toUpperCase()}
                  </span>
                  <div className="flex-1 min-w-0">
                    <div className="font-bold truncate" style={{ color: '#1A1A2E' }}>{r.name}</div>
                    {r.address && <div className="text-xs text-slate-500 truncate">{r.address}</div>}
                  </div>
                </div>
                {r.is_sample === 1 && (
                  <span className="inline-block text-[10px] font-bold uppercase tracking-wider text-violet-700 bg-violet-50 rounded px-1.5 py-0.5">
                    Sample
                  </span>
                )}
              </Link>
            ))}
          </div>
        )}
      </main>
    </div>
  );
}

function CreateRestaurantCard({
  onCreated,
  onCancel,
}: {
  onCreated: (r: Restaurant) => void;
  onCancel: () => void;
}) {
  const [mode, setMode] = useState<'search' | 'manual'>('search');
  const [name, setName] = useState('');
  const [address, setAddress] = useState('');
  const [region, setRegion] = useState('US');
  // Populated by Google autocomplete OR left null on manual entry.
  const [placeId, setPlaceId] = useState<string | null>(null);
  const [lat, setLat] = useState<number | null>(null);
  const [lng, setLng] = useState<number | null>(null);
  const [phone, setPhone] = useState<string | null>(null);
  const [website, setWebsite] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    if (!name.trim()) {
      toast.error('Name required');
      return;
    }
    setBusy(true);
    try {
      const result = await restaurantsApi.create({
        name: name.trim(),
        address:         address.trim() || undefined,
        region:          region.trim() || undefined,
        lat:             lat ?? undefined,
        lng:             lng ?? undefined,
        google_place_id: placeId ?? undefined,
        phone:           phone ?? undefined,
        website:         website ?? undefined,
      });
      if (result.already_exists) {
        toast(`Already in your workspace — opening it.`, { icon: 'ℹ️' });
      }
      const full = await restaurantsApi.show(result.id);
      onCreated(full);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to create');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="bg-slate-50 rounded-xl p-4 mt-6">
      <div className="flex items-center justify-between mb-3">
        <h2 className="font-bold text-base" style={{ color: '#1A1A2E' }}>New restaurant</h2>
        {/* Mode toggle */}
        <div className="bg-white rounded-md p-0.5 flex items-center text-xs font-semibold border border-slate-200">
          <button
            type="button"
            className={`px-3 py-1 rounded ${mode === 'search' ? 'bg-violet-100 text-violet-800' : 'text-slate-500 hover:text-slate-800'}`}
            onClick={() => setMode('search')}
          >
            Search Google
          </button>
          <button
            type="button"
            className={`px-3 py-1 rounded ${mode === 'manual' ? 'bg-violet-100 text-violet-800' : 'text-slate-500 hover:text-slate-800'}`}
            onClick={() => setMode('manual')}
          >
            Manual
          </button>
        </div>
      </div>

      {mode === 'search' ? (
        <>
          <GooglePlaceAutocomplete
            placeholder="Search restaurants — name or address"
            autoFocus
            onChange={(raw) => {
              // User is editing — clear any previously-picked place so the
              // submit doesn't write stale lat/lng for a different name.
              if (placeId !== null) {
                setPlaceId(null);
                setLat(null); setLng(null);
                setPhone(null); setWebsite(null);
                setAddress('');
              }
              setName(raw);
            }}
            onPlace={(p) => {
              setName(p.name || p.address);
              setAddress(p.address);
              setLat(p.lat);
              setLng(p.lng);
              setPlaceId(p.place_id);
              setPhone(p.phone);
              setWebsite(p.website);
            }}
          />

          {/* Selected-place preview — only shows after the user picks a suggestion. */}
          {placeId && (
            <div className="mt-3 bg-white border border-violet-200 rounded-md p-3 text-sm">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="font-bold truncate" style={{ color: '#1A1A2E' }}>{name}</div>
                  <div className="text-xs text-slate-500 truncate">{address}</div>
                  <div className="text-[11px] text-slate-500 mt-1 flex gap-3 flex-wrap">
                    {lat !== null && lng !== null && <span>{lat.toFixed(4)}, {lng.toFixed(4)}</span>}
                    {phone && <span>{phone}</span>}
                    {website && <a href={website} target="_blank" rel="noreferrer" className="text-violet-700 hover:underline truncate max-w-[200px] inline-block">{website.replace(/^https?:\/\//, '')}</a>}
                  </div>
                </div>
                <button
                  type="button"
                  className="text-[11px] text-slate-500 hover:text-rose-700 flex-shrink-0"
                  onClick={() => { setPlaceId(null); setLat(null); setLng(null); setAddress(''); setPhone(null); setWebsite(null); setName(''); }}
                >
                  Clear
                </button>
              </div>
            </div>
          )}

          <label className="block mt-3">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Region (for COGS lookup)</span>
            <input
              className="h-10 text-sm w-full px-3 rounded-md border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-violet-400"
              value={region}
              onChange={(e) => setRegion(e.target.value)}
              placeholder="US"
            />
          </label>
        </>
      ) : (
        <div className="grid md:grid-cols-3 gap-2">
          <label className="block">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Name</span>
            <input
              className="h-10 text-sm w-full px-3 rounded-md border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-violet-400"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Trattoria Verde"
              autoFocus
            />
          </label>
          <label className="block">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Address</span>
            <input
              className="h-10 text-sm w-full px-3 rounded-md border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-violet-400"
              value={address}
              onChange={(e) => setAddress(e.target.value)}
              placeholder="1234 W Division, Chicago IL"
            />
          </label>
          <label className="block">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Region</span>
            <input
              className="h-10 text-sm w-full px-3 rounded-md border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-violet-400"
              value={region}
              onChange={(e) => setRegion(e.target.value)}
              placeholder="US"
            />
          </label>
        </div>
      )}

      <div className="flex gap-2 mt-4">
        <button
          className="btn btn-primary h-10 px-4 text-sm"
          disabled={busy || !name.trim()}
          onClick={submit}
        >
          {placeId ? 'Add from Google' : 'Create'}
        </button>
        <button className="btn h-10 px-4 text-sm" onClick={onCancel} disabled={busy}>Cancel</button>
      </div>
    </div>
  );
}

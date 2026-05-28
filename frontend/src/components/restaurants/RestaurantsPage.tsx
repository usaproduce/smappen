import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Plus, Store, ChefHat, Sparkles, Trash2, Loader2 } from 'lucide-react';
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
  const [seedingSample, setSeedingSample] = useState(false);
  const [removingSample, setRemovingSample] = useState(false);
  const navigate = useNavigate();

  const hasSample = restaurants.some((r) => r.is_sample === 1);

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

  async function exploreWithSample() {
    setSeedingSample(true);
    const t = toast.loading('Building sample restaurant…');
    try {
      const result = await restaurantsApi.createSample();
      toast.success(result.created ? 'Sample restaurant ready' : 'Sample restaurant refreshed', { id: t });
      // Refresh list, then drop straight into the war-room.
      const rs = await restaurantsApi.list();
      setRestaurants(rs);
      navigate(`/app/restaurants/${result.id}`);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Could not seed sample', { id: t });
    } finally {
      setSeedingSample(false);
    }
  }

  async function removeSample() {
    if (!window.confirm('Remove all sample data? This deletes the sample restaurant and everything attached to it.')) return;
    setRemovingSample(true);
    const t = toast.loading('Removing sample data…');
    try {
      await restaurantsApi.removeSample();
      const rs = await restaurantsApi.list();
      setRestaurants(rs);
      toast.success('Sample data removed', { id: t });
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Could not remove sample', { id: t });
    } finally {
      setRemovingSample(false);
    }
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
          <div className="flex items-center gap-2">
            {hasSample && (
              <button
                className="btn btn-ghost h-10 px-3 text-xs font-bold inline-flex items-center gap-1.5"
                onClick={removeSample}
                disabled={removingSample}
                style={{ color: 'var(--money-negative)' }}
              >
                {removingSample ? <Loader2 size={12} className="animate-spin" /> : <Trash2 size={12} />}
                Remove sample data
              </button>
            )}
            <button className="btn btn-primary h-10 px-4 text-sm" onClick={() => setShowCreate(true)}>
              <Plus size={14} /> New restaurant
            </button>
          </div>
        </div>

        {/* "Explore with sample data" CTA — visible only when the user has
            no restaurants yet AND no sample exists. Lands them on a fully
            populated war-room in one click. */}
        {!loading && !hasSample && restaurants.length === 0 && !showCreate && (
          <div
            className="border rounded-xl p-5 mb-5 flex items-center gap-4"
            style={{
              background: 'var(--carafe-accent-50)',
              borderColor: 'var(--carafe-accent-light)',
            }}
          >
            <div
              className="w-10 h-10 rounded-lg flex items-center justify-center"
              style={{ background: 'var(--carafe-accent-light)', color: 'var(--carafe-accent)' }}
            >
              <Sparkles size={18} />
            </div>
            <div className="flex-1 min-w-0">
              <div className="font-bold" style={{ color: 'var(--ink)' }}>
                Explore with sample data
              </div>
              <div className="text-sm mt-0.5" style={{ color: 'var(--body)' }}>
                A fully-populated demo restaurant with 24 menu items, 60 days of synthetic sales,
                open moves, and real plate cost — so you can see the war-room before connecting your POS.
              </div>
            </div>
            <button
              onClick={exploreWithSample}
              disabled={seedingSample}
              className="btn btn-primary h-10 px-4 text-sm inline-flex items-center gap-1.5"
            >
              {seedingSample ? <Loader2 size={14} className="animate-spin" /> : <Sparkles size={14} />}
              {seedingSample ? 'Building…' : 'Try with sample'}
            </button>
          </div>
        )}

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
                  <span
                    className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider rounded px-1.5 py-0.5"
                    style={{
                      color: 'var(--carafe-accent)',
                      background: 'var(--carafe-accent-50)',
                    }}
                  >
                    <Sparkles size={9} /> Sample data
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
  const [manual, setManual] = useState(false);
  const [name, setName] = useState('');
  const [address, setAddress] = useState('');
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

  function reset() {
    setPlaceId(null); setLat(null); setLng(null);
    setPhone(null); setWebsite(null);
    setName(''); setAddress('');
  }

  if (manual) {
    return (
      <div className="mt-4 flex flex-wrap items-end gap-2">
        <input
          className="h-10 text-sm flex-1 min-w-[200px] px-3 rounded-md border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-violet-400"
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Restaurant name"
          autoFocus
        />
        <input
          className="h-10 text-sm flex-[2] min-w-[240px] px-3 rounded-md border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-violet-400"
          value={address}
          onChange={(e) => setAddress(e.target.value)}
          placeholder="Address (optional)"
        />
        <button
          className="btn btn-primary h-10 px-4 text-sm"
          disabled={busy || !name.trim()}
          onClick={submit}
        >
          Add
        </button>
        <button className="h-10 px-3 text-sm text-slate-500 hover:text-slate-800" onClick={() => setManual(false)} disabled={busy}>
          Back to search
        </button>
        <button className="h-10 px-3 text-sm text-slate-400 hover:text-slate-700" onClick={onCancel} disabled={busy}>
          Cancel
        </button>
      </div>
    );
  }

  return (
    <div className="mt-4">
      {!placeId ? (
        <>
          <GooglePlaceAutocomplete
            placeholder="Start typing your restaurant — name or address"
            autoFocus
            onChange={(raw) => setName(raw)}
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
          <div className="mt-2 flex items-center justify-between text-xs text-slate-500">
            <button type="button" className="hover:text-violet-700" onClick={() => setManual(true)}>
              Can't find it? Add manually →
            </button>
            <button type="button" className="hover:text-slate-700" onClick={onCancel}>
              Cancel
            </button>
          </div>
        </>
      ) : (
        <div className="flex items-center gap-3 bg-white border border-violet-200 rounded-md p-3">
          <div className="flex-1 min-w-0">
            <div className="font-bold truncate" style={{ color: '#1A1A2E' }}>{name}</div>
            <div className="text-xs text-slate-500 truncate">{address}</div>
            {(phone || website) && (
              <div className="text-[11px] text-slate-500 mt-0.5 flex gap-3 flex-wrap">
                {phone && <span>{phone}</span>}
                {website && (
                  <a href={website} target="_blank" rel="noreferrer" className="text-violet-700 hover:underline truncate max-w-[200px] inline-block">
                    {website.replace(/^https?:\/\//, '')}
                  </a>
                )}
              </div>
            )}
          </div>
          <button
            className="btn btn-primary h-9 px-3 text-sm flex-shrink-0"
            disabled={busy}
            onClick={submit}
          >
            Add
          </button>
          <button
            className="text-xs text-slate-400 hover:text-slate-700 flex-shrink-0"
            onClick={reset}
            disabled={busy}
          >
            Clear
          </button>
        </div>
      )}
    </div>
  );
}

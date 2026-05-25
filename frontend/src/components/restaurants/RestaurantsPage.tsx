import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Plus, Store, ChefHat } from 'lucide-react';
import { restaurantsApi } from '../../api/restaurants';
import { useRestaurantStore, type Restaurant } from '../../stores/restaurantStore';

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
    <div className="min-h-screen bg-white">
      <header className="bg-white border-b border-slate-200">
        <div className="max-w-6xl mx-auto px-6 py-3 flex items-center justify-between">
          <Link to="/dashboard" className="flex items-center gap-2 font-extrabold text-[16px]" style={{ color: '#1A1A2E' }}>
            <span
              className="inline-flex items-center justify-center w-8 h-8 rounded-lg text-white font-extrabold text-base shadow-sm"
              style={{ background: 'linear-gradient(135deg, #F57C00 0%, #E53935 50%, #7848BB 100%)' }}
            >S</span>
            smappen
          </Link>
          <nav className="flex items-center gap-4 text-sm font-semibold text-slate-700">
            <Link to="/projects" className="hover:text-violet-700">Projects</Link>
            <Link to="/app/restaurants" className="text-violet-700">Restaurants</Link>
            <Link to="/app/vendors" className="hover:text-violet-700">Vendors</Link>
            <Link to="/settings/profile" className="hover:text-violet-700">Settings</Link>
          </nav>
        </div>
      </header>

      <main className="max-w-6xl mx-auto px-6 py-8">
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
                to={`/app/restaurants/${r.id}/menu`}
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
  const [name, setName] = useState('');
  const [address, setAddress] = useState('');
  const [region, setRegion] = useState('US');
  const [busy, setBusy] = useState(false);

  async function submit() {
    if (!name.trim()) return;
    setBusy(true);
    try {
      const { id } = await restaurantsApi.create({
        name: name.trim(),
        address: address.trim() || undefined,
        region: region.trim() || undefined,
      });
      const full = await restaurantsApi.show(id);
      onCreated(full);
    } catch (e: any) {
      toast.error(e?.response?.data?.error ?? 'Failed to create');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="bg-slate-50 rounded-xl p-4 mt-6">
      <h2 className="font-bold text-base mb-3" style={{ color: '#1A1A2E' }}>New restaurant</h2>
      <div className="grid md:grid-cols-3 gap-2">
        <label className="block">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Name</span>
          <input
            className="input h-10 text-sm w-full"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Trattoria Verde"
            autoFocus
          />
        </label>
        <label className="block">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Address</span>
          <input
            className="input h-10 text-sm w-full"
            value={address}
            onChange={(e) => setAddress(e.target.value)}
            placeholder="1234 W Division, Chicago IL"
          />
        </label>
        <label className="block">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Region</span>
          <input
            className="input h-10 text-sm w-full"
            value={region}
            onChange={(e) => setRegion(e.target.value)}
            placeholder="US"
          />
        </label>
      </div>
      <div className="flex gap-2 mt-3">
        <button className="btn btn-primary h-10 px-4 text-sm" disabled={busy || !name.trim()} onClick={submit}>Create</button>
        <button className="btn h-10 px-4 text-sm" onClick={onCancel} disabled={busy}>Cancel</button>
      </div>
    </div>
  );
}

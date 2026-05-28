import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ChevronDown, ChefHat, Plus, Check, Search } from 'lucide-react';
import { restaurantsApi } from '../../api/restaurants';
import { useRestaurantStore, type Restaurant } from '../../stores/restaurantStore';
import { useClickOutside } from '../../hooks/useClickOutside';

/**
 * Carafe restaurant switcher — slots into AppNav's context. A 44px-tall
 * pill button that opens a dropdown listing the user's other restaurants
 * plus an "All restaurants" / "New restaurant" footer. Multi-location
 * operators can swap one-handed during service.
 *
 * Data: hydrates from useRestaurantStore.restaurants; fetches on first
 * open if the list is empty.
 */

export default function RestaurantSwitcher() {
  const navigate = useNavigate();
  const { id: routeId } = useParams<{ id: string }>();
  const current = useRestaurantStore((s) => s.currentRestaurant);
  const restaurants = useRestaurantStore((s) => s.restaurants);
  const setRestaurants = useRestaurantStore((s) => s.setRestaurants);

  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [filter, setFilter] = useState('');
  const ref = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  useClickOutside(ref, () => setOpen(false), open);

  useEffect(() => {
    if (!open) return;
    if (restaurants.length > 0) {
      requestAnimationFrame(() => inputRef.current?.focus());
      return;
    }
    let cancelled = false;
    setLoading(true);
    (async () => {
      try {
        const list = await restaurantsApi.list();
        if (!cancelled) setRestaurants(list);
      } catch {
        /* silent — switcher still works for the current restaurant */
      } finally {
        if (!cancelled) {
          setLoading(false);
          requestAnimationFrame(() => inputRef.current?.focus());
        }
      }
    })();
    return () => { cancelled = true; };
  }, [open, restaurants.length, setRestaurants]);

  const display = current?.name ?? 'Restaurant';
  const initial = (current?.name ?? '?').charAt(0).toUpperCase();

  const filtered = filter
    ? restaurants.filter((r) => r.name.toLowerCase().includes(filter.toLowerCase()))
    : restaurants;

  function pick(r: Restaurant) {
    setOpen(false);
    setFilter('');
    if (r.id === routeId) return;
    navigate(`/app/restaurants/${r.id}`);
  }

  return (
    <div ref={ref} className="relative min-w-0 flex-1">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label="Switch restaurant"
        className="w-full flex items-center gap-2 min-w-0 h-11 px-2.5 rounded-lg font-semibold text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-400"
        style={{
          background: open ? 'var(--brand-light)' : 'transparent',
          color: 'var(--ink)',
        }}
      >
        <span
          aria-hidden
          className="inline-flex items-center justify-center w-7 h-7 rounded-md flex-shrink-0 font-extrabold text-[12px]"
          style={{ background: 'var(--brand-light)', color: 'var(--brand)' }}
        >
          {initial}
        </span>
        <span className="truncate flex-1 text-left">{display}</span>
        <ChevronDown
          size={14}
          className="flex-shrink-0 transition-transform"
          style={{ transform: open ? 'rotate(180deg)' : 'none', color: 'var(--slate)' }}
        />
      </button>

      {open && (
        <div
          role="listbox"
          aria-label="Choose restaurant"
          className="absolute left-0 top-full mt-1 w-[280px] max-w-[calc(100vw-32px)] rounded-xl shadow-lg z-50 overflow-hidden"
          style={{ background: 'white', border: '1px solid var(--line-soft)' }}
        >
          {(restaurants.length > 4 || filter) && (
            <div className="px-2.5 pt-2.5 pb-1.5">
              <div className="relative">
                <Search
                  size={13}
                  className="absolute left-2.5 top-1/2 -translate-y-1/2"
                  style={{ color: 'var(--slate)' }}
                />
                <input
                  ref={inputRef}
                  type="text"
                  value={filter}
                  onChange={(e) => setFilter(e.target.value)}
                  placeholder="Find a restaurant"
                  className="w-full h-9 pl-8 pr-2 rounded-md text-sm outline-none"
                  style={{
                    border: '1px solid var(--line-soft)',
                    background: 'var(--bg)',
                    color: 'var(--ink)',
                  }}
                />
              </div>
            </div>
          )}

          <ul className="max-h-[60vh] overflow-y-auto py-1">
            {loading && (
              <li className="px-3 py-2 text-xs" style={{ color: 'var(--slate)' }}>Loading…</li>
            )}
            {!loading && filtered.length === 0 && (
              <li className="px-3 py-2 text-xs" style={{ color: 'var(--slate)' }}>
                {filter ? 'No matches' : 'No other restaurants'}
              </li>
            )}
            {filtered.map((r) => {
              const isCurrent = r.id === routeId;
              return (
                <li key={r.id}>
                  <button
                    type="button"
                    role="option"
                    aria-selected={isCurrent}
                    onClick={() => pick(r)}
                    className="w-full text-left flex items-center gap-2 h-11 px-3 text-sm font-semibold"
                    style={{
                      background: isCurrent ? 'var(--brand-light)' : 'transparent',
                      color: isCurrent ? 'var(--brand)' : 'var(--ink)',
                    }}
                    onMouseEnter={(e) => {
                      if (!isCurrent) e.currentTarget.style.background = 'var(--bg-panel)';
                    }}
                    onMouseLeave={(e) => {
                      if (!isCurrent) e.currentTarget.style.background = 'transparent';
                    }}
                  >
                    <span
                      aria-hidden
                      className="inline-flex items-center justify-center w-7 h-7 rounded-md flex-shrink-0 font-extrabold text-[12px]"
                      style={{
                        background: isCurrent ? 'var(--brand)' : 'var(--bg-panel)',
                        color: isCurrent ? 'white' : 'var(--body)',
                      }}
                    >
                      {(r.name ?? '?').charAt(0).toUpperCase()}
                    </span>
                    <span className="truncate flex-1">{r.name}</span>
                    {isCurrent && <Check size={14} aria-hidden />}
                  </button>
                </li>
              );
            })}
          </ul>

          <div className="border-t" style={{ borderColor: 'var(--line-soft)' }}>
            <Link
              to="/app/restaurants"
              onClick={() => setOpen(false)}
              className="flex items-center gap-2 h-11 px-3 text-sm font-semibold"
              style={{ color: 'var(--slate)' }}
            >
              <ChefHat size={14} /> All restaurants
            </Link>
            <Link
              to="/app/restaurants?new=1"
              onClick={() => setOpen(false)}
              className="flex items-center gap-2 h-11 px-3 text-sm font-semibold border-t"
              style={{ color: 'var(--brand)', borderColor: 'var(--line-soft)' }}
            >
              <Plus size={14} /> New restaurant
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}

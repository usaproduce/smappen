import { useState } from 'react';
import toast from 'react-hot-toast';
import {
  Building, Search as SearchIcon, Utensils, Coffee, ShoppingBag, Pill,
  Dumbbell, GraduationCap, Stethoscope, Landmark, Fuel, Sparkles,
} from 'lucide-react';
import { placesApi } from '../../api/places';
import { useMapStore } from '../../stores/mapStore';
import EmptyState from '../common/EmptyState';
import type { Area, Place } from '../../types';

// Tweak #13 — replace the plain category <select> with a horizontal chip
// row of recognizable icons. Tapping a chip both selects and runs search.
const CATEGORIES: { key: string; label: string; icon: any }[] = [
  { key: '',             label: 'Any',         icon: Sparkles },
  { key: 'restaurant',   label: 'Food',        icon: Utensils },
  { key: 'cafe',         label: 'Cafes',       icon: Coffee },
  { key: 'store',        label: 'Retail',      icon: ShoppingBag },
  { key: 'pharmacy',     label: 'Pharmacy',    icon: Pill },
  { key: 'gym',          label: 'Gyms',        icon: Dumbbell },
  { key: 'school',       label: 'Schools',     icon: GraduationCap },
  { key: 'hospital',     label: 'Health',      icon: Stethoscope },
  { key: 'bank',         label: 'Banks',       icon: Landmark },
  { key: 'gas_station',  label: 'Gas',         icon: Fuel },
];

export default function POISearchPanel({ area }: { area: Area }) {
  const [type, setType] = useState('');
  const [keyword, setKeyword] = useState('');
  const [loading, setLoading] = useState(false);
  const [results, setResults] = useState<Place[]>([]);
  const { setPoiResults } = useMapStore();

  async function search() {
    if (!area.center_lat || !area.center_lng) return toast.error('Area has no center');
    setLoading(true);
    try {
      const r = await placesApi.nearby({
        lat: area.center_lat, lng: area.center_lng,
        radius_meters: 5000, type: type || undefined, keyword: keyword || undefined,
        area_id: area.id,
      });
      setResults(r.places);
      setPoiResults(r.places);
      toast.success(`${r.count} results`);
    } catch (e: any) {
      const err = e?.response?.data?.error ?? 'Search failed';
      const enableUrl = e?.response?.data?.details?.enable_url;
      if (enableUrl) {
        // Toast with an embedded clickable Enable link, so the user doesn't
        // have to copy the URL out of the message into a new tab.
        toast.error(
          (t) => (
            <div className="text-sm leading-snug">
              <div className="font-semibold mb-1">{err}</div>
              <a
                href={enableUrl}
                target="_blank"
                rel="noreferrer"
                className="text-violet-700 underline font-semibold text-xs"
                onClick={() => toast.dismiss(t.id)}
              >
                Open Google Cloud Console →
              </a>
            </div>
          ) as any,
          { duration: 10000 }
        );
      } else {
        toast.error(err);
      }
    } finally { setLoading(false); }
  }

  return (
    <div className="p-4 space-y-3">
      <div className="space-y-2">
        {/* Horizontal scrollable chip strip. Each chip combines an icon
            recognizable at a glance with a 1-word label. Tapping toggles
            selection without immediately firing the API — user can tweak
            keyword first, then Search. */}
        <div className="flex gap-1.5 overflow-x-auto -mx-1 px-1 pb-1 scroll-x">
          {CATEGORIES.map((c) => {
            const Active = type === c.key;
            const Icon = c.icon;
            return (
              <button
                key={c.key || 'any'}
                onClick={() => setType(c.key)}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold whitespace-nowrap shrink-0 transition-colors ${
                  Active
                    ? 'bg-violet-100 text-violet-700 ring-1 ring-violet-300'
                    : 'bg-slate-50 text-slate-600 hover:bg-slate-100'
                }`}
              >
                <Icon size={12} />
                {c.label}
              </button>
            );
          })}
        </div>
        <div className="relative">
          <SearchIcon size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
          <input className="input pl-8" placeholder="Keyword (optional)" value={keyword} onChange={(e) => setKeyword(e.target.value)} />
        </div>
        <button className="btn btn-primary w-full justify-center" disabled={loading} onClick={search}>
          {loading ? 'Searching…' : 'Search'}
        </button>
      </div>
      {loading && (
        // Skeleton cards while the Places API request is in flight — better
        // than a single spinner because users see the result shape forming.
        <div className="space-y-2">
          <div className="skeleton h-3 w-20" />
          <div className="skeleton h-16" />
          <div className="skeleton h-16" />
          <div className="skeleton h-16" />
        </div>
      )}
      {!loading && results.length === 0 && (
        <EmptyState
          icon={<Building size={28} />}
          title="No businesses yet"
          subtitle="Pick a category or enter a keyword, then Search to find businesses inside this area."
          compact
        />
      )}
      {!loading && results.length > 0 && (
        <>
          <div className="text-xs font-semibold text-slate-500 uppercase">{results.length} found</div>
          <div className="space-y-2">
            {results.map((p) => (
              <div key={p.id} className="card">
                <div className="font-semibold text-sm">{p.displayName?.text}</div>
                <div className="text-xs text-slate-500">{p.formattedAddress}</div>
                {p.rating && <div className="text-xs">⭐ {p.rating} ({p.userRatingCount})</div>}
                {p.nationalPhoneNumber && <a href={`tel:${p.nationalPhoneNumber}`} className="text-xs text-violet-700">{p.nationalPhoneNumber}</a>}
                {p.websiteUri && <a href={p.websiteUri} target="_blank" rel="noreferrer" className="text-xs text-violet-700 block">Website ↗</a>}
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  );
}

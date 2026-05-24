import { useState } from 'react';
import toast from 'react-hot-toast';
import { Building, Search as SearchIcon } from 'lucide-react';
import { placesApi } from '../../api/places';
import { useMapStore } from '../../stores/mapStore';
import EmptyState from '../common/EmptyState';
import type { Area, Place } from '../../types';

const CATEGORIES = ['', 'restaurant', 'cafe', 'store', 'pharmacy', 'gym', 'school', 'hospital', 'bank', 'gas_station'];

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
      toast.error(e?.response?.data?.error ?? 'Search failed');
    } finally { setLoading(false); }
  }

  return (
    <div className="p-4 space-y-3">
      <div className="space-y-2">
        <select className="select" value={type} onChange={(e) => setType(e.target.value)}>
          {CATEGORIES.map((c) => <option key={c} value={c}>{c || 'Any category'}</option>)}
        </select>
        <input className="input" placeholder="Keyword (optional)" value={keyword} onChange={(e) => setKeyword(e.target.value)} />
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

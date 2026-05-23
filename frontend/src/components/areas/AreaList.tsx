import { useState } from 'react';
import { Search, X, Filter } from 'lucide-react';
import { useProjectStore } from '../../stores/projectStore';
import AreaCard from './AreaCard';

type Sort = 'recent' | 'name' | 'time' | 'population';
type TypeFilter = 'all' | 'isochrone' | 'radius' | 'manual';

export default function AreaList() {
  const { areas } = useProjectStore();
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<Sort>('recent');
  const [typeFilter, setTypeFilter] = useState<TypeFilter>('all');
  const [showFilters, setShowFilters] = useState(false);

  const filtered = areas
    .filter((a) => !search || a.name.toLowerCase().includes(search.toLowerCase()))
    .filter((a) => {
      if (typeFilter === 'all') return true;
      if (typeFilter === 'isochrone') return a.area_type === 'isochrone' || a.area_type === 'isodistance';
      return a.area_type === typeFilter;
    })
    .sort((a, b) => {
      if (sort === 'name') return a.name.localeCompare(b.name);
      if (sort === 'time') return (a.travel_time_minutes ?? 0) - (b.travel_time_minutes ?? 0);
      if (sort === 'population') {
        const ap = (a as any).demographics_cache?.population?.total ?? 0;
        const bp = (b as any).demographics_cache?.population?.total ?? 0;
        return bp - ap;
      }
      return 0;
    });

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

      <div className="flex items-center gap-1 px-3 pb-1">
        <span className="text-[10px] uppercase tracking-wider font-bold text-slate-400 mr-0.5">Sort</span>
        {(['recent', 'name', 'time', 'population'] as Sort[]).map((s) => (
          <button
            key={s}
            onClick={() => setSort(s)}
            className={`text-[11px] px-2 py-0.5 rounded-full font-semibold capitalize ${
              sort === s ? 'bg-violet-100 text-violet-700' : 'text-slate-500 hover:bg-slate-100'
            }`}
            title={s === 'population' ? 'Areas need demographics loaded for this' : undefined}
          >
            {s === 'population' ? 'pop' : s}
          </button>
        ))}
        <button
          className={`ml-auto p-1 rounded hover:bg-slate-100 ${typeFilter !== 'all' ? 'text-violet-700' : 'text-slate-400'}`}
          onClick={() => setShowFilters(!showFilters)}
          title="Filter"
        >
          <Filter size={13} />
        </button>
      </div>

      {showFilters && (
        <div className="px-3 pb-2 flex items-center gap-1 flex-wrap">
          <span className="text-[10px] uppercase tracking-wider font-bold text-slate-400 mr-0.5">Type</span>
          {(['all', 'isochrone', 'radius', 'manual'] as TypeFilter[]).map((t) => (
            <button
              key={t}
              onClick={() => setTypeFilter(t)}
              className={`text-[11px] px-2 py-0.5 rounded-full font-semibold capitalize ${
                typeFilter === t ? 'bg-violet-100 text-violet-700' : 'text-slate-500 hover:bg-slate-100'
              }`}
            >
              {t === 'isochrone' ? 'travel' : t}
            </button>
          ))}
        </div>
      )}

      <div className="flex items-center justify-between px-3 pb-1 text-[11px] text-slate-400 font-medium">
        <span>{filtered.length} of {areas.length} {areas.length === 1 ? 'area' : 'areas'}</span>
        {(search || typeFilter !== 'all') && (
          <button
            className="text-[11px] text-violet-600 hover:underline"
            onClick={() => { setSearch(''); setTypeFilter('all'); }}
          >
            Clear
          </button>
        )}
      </div>

      {filtered.length === 0 ? (
        <div className="text-sm text-slate-400 text-center py-8 px-4">
          {search || typeFilter !== 'all'
            ? <>No areas match these filters.</>
            : <>No areas yet. Click <b>Create new area</b> above.</>}
        </div>
      ) : (
        <div>
          {filtered.map((a) => <AreaCard key={a.id} area={a} />)}
        </div>
      )}
    </div>
  );
}
